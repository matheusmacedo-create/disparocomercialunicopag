<?php

declare(strict_types=1);

namespace App\Services\Metricas;

use App\Models\WhatsappMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Quais templates o contato mais responde (painel). Para cada template enviado na janela: quantos
 * saíram, foram entregues, lidos e tiveram resposta do contato. Resposta = inbound do mesmo
 * telefone no mesmo remetente que cita o template (`context_wamid`) ou chega até `janela_horas`
 * depois dele (a Kapso nem sempre traz a citação). Só metadados: nenhum texto é lido.
 */
class AderenciaDeTemplates
{
    public const JANELA_HORAS = 72;

    /**
     * @return Collection<int, array{template: string, enviados: int, entregues: int, lidos: int, respondidos: int, taxa_entrega: float, taxa_leitura: float, taxa_resposta: float}>
     */
    public function calcular(CarbonInterface $desde, ?int $senderId = null, int $minimoEnviados = 5, int $janelaHoras = self::JANELA_HORAS): Collection
    {
        $templates = WhatsappMessage::query()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->whereNotNull('template_nome')
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
            ->where('enviado_em', '>=', $desde)
            ->when($senderId !== null, fn ($q) => $q->where('sender_id', $senderId))
            ->orderBy('enviado_em')
            ->get(['id', 'sender_id', 'telefone', 'wamid', 'template_nome', 'status', 'enviado_em']);

        if ($templates->isEmpty()) {
            return collect();
        }

        $inbound = WhatsappMessage::query()
            ->where('direcao', WhatsappMessage::INBOUND)
            ->where('enviado_em', '>=', $desde)
            ->when($senderId !== null, fn ($q) => $q->where('sender_id', $senderId))
            ->orderBy('enviado_em')
            ->get(['sender_id', 'telefone', 'context_wamid', 'enviado_em']);

        $citadas = $inbound->whereNotNull('context_wamid')->pluck('context_wamid')->flip();
        $porTelefone = $inbound->groupBy(fn (WhatsappMessage $m): string => $m->sender_id.'|'.$m->telefone);
        $linhas = [];

        foreach ($templates as $template) {
            $nome = (string) $template->template_nome;
            $linha = &$linhas[$nome];
            $linha ??= ['template' => $nome, 'enviados' => 0, 'entregues' => 0, 'lidos' => 0, 'respondidos' => 0];
            $linha['enviados']++;

            if (WhatsappMessage::rank($template->status) >= 2) {
                $linha['entregues']++;
            }

            if (WhatsappMessage::rank($template->status) >= 3) {
                $linha['lidos']++;
            }

            if ($this->respondido($template, $citadas, $porTelefone, $janelaHoras)) {
                $linha['respondidos']++;
            }

            unset($linha);
        }

        return collect($linhas)
            ->filter(fn (array $l): bool => $l['enviados'] >= $minimoEnviados)
            ->map(fn (array $l): array => $l + [
                'taxa_entrega' => round($l['entregues'] / $l['enviados'] * 100, 1),
                'taxa_leitura' => round($l['lidos'] / $l['enviados'] * 100, 1),
                'taxa_resposta' => round($l['respondidos'] / $l['enviados'] * 100, 1),
            ])
            ->sortByDesc(fn (array $l): array => [$l['taxa_resposta'], $l['taxa_leitura'], $l['enviados']])
            ->values();
    }

    /**
     * @param  Collection<string, int>  $citadas
     * @param  Collection<string, Collection<int, WhatsappMessage>>  $porTelefone
     */
    private function respondido(WhatsappMessage $template, Collection $citadas, Collection $porTelefone, int $janelaHoras): bool
    {
        if ($template->wamid !== null && $citadas->has($template->wamid)) {
            return true;
        }

        if ($template->enviado_em === null) {
            return false;
        }

        $limite = $template->enviado_em->copy()->addHours($janelaHoras);

        foreach ($porTelefone->get($template->sender_id.'|'.$template->telefone, collect()) as $resposta) {
            if ($resposta->enviado_em !== null && $resposta->enviado_em->gte($template->enviado_em) && $resposta->enviado_em->lte($limite)) {
                return true;
            }
        }

        return false;
    }
}
