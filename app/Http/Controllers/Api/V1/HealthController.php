<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Sender;
use App\Support\Batimentos;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health - o estado da plataforma para quem integra (MCP, monitoramento): interruptor de
 * envio, remetentes ativos com a política efetiva, campanhas em andamento e os batimentos do agendador e
 * do worker. Sem PII: nome de remetente e de campanha, contagens.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $agendador = Batimentos::agendador();
        $worker = Batimentos::worker();

        return response()->json([
            'ok' => true,
            'agora' => now()->toIso8601String(),
            'envio_habilitado' => (bool) config('disparo.envio_habilitado'),
            'simulacao' => ! config('disparo.envio_habilitado'),
            'remetentes_ativos' => Sender::query()->where('ativo', true)->orderBy('id')->get()->map(fn (Sender $sender): array => [
                'id' => $sender->id,
                'nome' => $sender->nome,
                'provider' => $sender->provider,
                'finalidade' => $sender->finalidade,
                'qualidade' => $sender->qualidade,
                'limite_tier' => $sender->limite_tier,
                'categorias_permitidas' => $sender->categoriasPermitidas(),
                'teto_diario' => $sender->teto_diario,
                'teto_efetivo' => $sender->tetoEfetivo(),
                'enviados_24h' => $sender->templatesNasUltimas24h(),
                'pausado' => $sender->pausado(),
                'bloqueado_por_qualidade' => $sender->bloqueadoPorQualidade(),
                'dentro_da_janela' => $sender->dentroDaJanela(),
                'webhook_configurado' => filled($sender->webhook_secret),
                'sincronizado_em' => $sender->sincronizado_em?->toIso8601String(),
                'historico_importado_ate' => $sender->historico_importado_ate?->toIso8601String(),
            ])->values(),
            'campanhas_em_andamento' => Campaign::query()->where('status', Campaign::STATUS_EM_ANDAMENTO)->orderBy('prioridade')->orderBy('id')->get()->map(fn (Campaign $campanha): array => [
                'id' => $campanha->id,
                'nome' => $campanha->nome,
                'sender_id' => $campanha->sender_id,
                'prioridade' => $campanha->prioridade,
                'total_destinatarios' => $campanha->total_destinatarios,
                'enviados' => $campanha->enviados,
                'falhas' => $campanha->falhas,
                'pulados' => $campanha->pulados,
                'tranches_enviadas' => $campanha->tranches_enviadas,
                'tranche_aguardando_ate' => $campanha->tranche_aguardando_ate?->toIso8601String(),
                'iniciada_em' => $campanha->iniciada_em?->toIso8601String(),
            ])->values(),
            'batimentos' => [
                'agendador' => $agendador?->toIso8601String(),
                'agendador_ok' => $agendador !== null && $agendador->gt(now()->subMinutes(5)),
                'worker' => $worker?->toIso8601String(),
                'worker_ok' => $worker !== null && $worker->gt(now()->subMinutes(15)),
            ],
        ]);
    }
}
