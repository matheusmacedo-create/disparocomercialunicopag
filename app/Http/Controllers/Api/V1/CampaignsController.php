<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campanhas pela API (MCP): só o que não fura o quatro olhos (KB 2.2).
 *
 *  - POST /api/v1/campaigns  cria um RASCUNHO com os campos do $fillable (`criado_por` nulo: a API não é
 *    uma pessoa); prévia, aprovação, início, pausa e cancelamento continuam SÓ no painel, por gente com
 *    `manage campaigns`/`approve campaigns` - uma chave de API não aprova nem dispara nada;
 *  - GET  /api/v1/campaigns/{id}  status, totais e a última prévia (sem a amostra de contatos: tem nome).
 */
final class CampaignsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'sender_id' => ['required', 'integer', Rule::exists('senders', 'id')],
            'objetivo' => ['nullable', Rule::in(array_keys(Campaign::objetivoLabels()))],
            'template_nome' => ['required', 'string', 'max:512'],
            'template_idioma' => ['nullable', 'string', 'max:10'],
            'prioridade' => ['nullable', 'integer', 'between:1,9'],
            'variaveis' => ['nullable', 'array'],
            'publico' => ['nullable', 'array'],
            'regras' => ['nullable', 'array'],
            'agendada_para' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string', 'max:5000'],
        ]);

        $campanha = Campaign::query()->create(array_filter($dados, fn ($v): bool => $v !== null) + [
            'objetivo' => Campaign::OBJETIVO_OFERTAR,
            'template_idioma' => 'pt_BR',
            'prioridade' => 5,
        ]);
        $campanha->registrarEvento('criada', ['via' => 'api'], null);

        return response()->json($this->representar($campanha->refresh()), 201);
    }

    public function show(int $id): JsonResponse
    {
        $campanha = Campaign::query()->find($id);

        if ($campanha === null) {
            return response()->json(['error' => 'Campanha não encontrada.'], 404);
        }

        return response()->json($this->representar($campanha));
    }

    /** @return array<string, mixed> */
    private function representar(Campaign $campanha): array
    {
        $previa = $campanha->simulacao;

        if (is_array($previa)) {
            unset($previa['amostra']);
        }

        return [
            'id' => $campanha->id,
            'nome' => $campanha->nome,
            'status' => $campanha->status,
            'status_label' => Campaign::statusLabels()[$campanha->status] ?? $campanha->status,
            'objetivo' => $campanha->objetivo,
            'sender' => ['id' => $campanha->sender_id, 'nome' => $campanha->sender?->nome],
            'template_nome' => $campanha->template_nome,
            'template_idioma' => $campanha->template_idioma,
            'template_categoria' => $campanha->template_categoria,
            'prioridade' => $campanha->prioridade,
            'variaveis' => $campanha->variaveis,
            'publico' => $campanha->publico,
            'regras' => $campanha->regras,
            'agendada_para' => $campanha->agendada_para?->toIso8601String(),
            'totais' => [
                'destinatarios' => $campanha->total_destinatarios,
                'enviados' => $campanha->enviados,
                'entregues' => $campanha->entregues,
                'lidos' => $campanha->lidos,
                'respondidos' => $campanha->respondidos,
                'falhas' => $campanha->falhas,
                'pulados' => $campanha->pulados,
            ],
            'previa' => $previa,
            'previa_atual' => $campanha->previaAtual(),
            'custo_estimado' => $campanha->custo_estimado,
            'tranches_enviadas' => $campanha->tranches_enviadas,
            'tranche_aguardando_ate' => $campanha->tranche_aguardando_ate?->toIso8601String(),
            'aprovado_em' => $campanha->aprovado_em?->toIso8601String(),
            'iniciada_em' => $campanha->iniciada_em?->toIso8601String(),
            'concluida_em' => $campanha->concluida_em?->toIso8601String(),
            'pausada_motivo' => $campanha->pausada_motivo,
            'criada_em' => $campanha->created_at?->toIso8601String(),
            'acoes_pela_api' => 'Aprovar, iniciar, pausar e cancelar ficam fora da API: quatro olhos só no painel.',
        ];
    }
}
