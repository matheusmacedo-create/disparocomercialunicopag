<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Suppression;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/contacts/{telefone} - o estado de um telefone na plataforma, sem PII: telefone mascarado,
 * status, consentimento, última resposta, bloqueios. Nome, e-mail, documento e atributos ficam fora (só
 * `view pii` no painel).
 */
final class ContactsController extends Controller
{
    public function show(string $telefone): JsonResponse
    {
        $canonico = Telefone::canonico($telefone);

        if ($canonico === null) {
            return response()->json(['error' => 'Telefone não parece um número brasileiro válido.'], 422);
        }

        $contato = Contact::query()->porTelefone($canonico)->first();

        if ($contato === null) {
            return response()->json(['error' => 'Contato não encontrado.'], 404);
        }

        $bloqueios = Suppression::query()->vigentes()->where('telefone_hash', $contato->telefone_hash)->get();

        return response()->json([
            'id' => $contato->id,
            'telefone' => Telefone::mascarar($contato->telefone),
            'status' => $contato->status,
            'tipo' => $contato->tipo,
            'origem' => $contato->origem,
            'consentimento' => [
                'registrado' => $contato->temConsentimento(),
                'origem' => $contato->consentimento_origem,
                'canal' => $contato->consentimento_canal,
                'em' => $contato->consentimento_em?->toIso8601String(),
            ],
            'optout_em' => $contato->optout_em?->toIso8601String(),
            'sem_whatsapp_em' => $contato->sem_whatsapp_em?->toIso8601String(),
            'marketing_carencia_ate' => $contato->marketing_carencia_ate?->toIso8601String(),
            'recusou_em' => $contato->recusou_em?->toIso8601String(),
            'ultimo_envio_em' => $contato->ultimo_envio_em?->toIso8601String(),
            'ultimo_template_em' => $contato->ultimo_template_em?->toIso8601String(),
            'ultima_resposta_em' => $contato->ultima_resposta_em?->toIso8601String(),
            'contadores' => [
                'envios' => $contato->envios_total,
                'entregues' => $contato->entregues_total,
                'lidos' => $contato->lidos_total,
                'respostas' => $contato->respostas_total,
                'falhas' => $contato->falhas_total,
            ],
            'bloqueios' => $bloqueios->map(fn (Suppression $s): array => [
                'sender_id' => $s->sender_id,
                'escopo' => $s->escopo,
                'motivo' => $s->motivo,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
