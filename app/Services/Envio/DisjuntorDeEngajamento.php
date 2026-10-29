<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Suppression;

/**
 * O que de fato derruba um número - bloqueio e denúncia - nunca chega como erro de API; a qualidade da
 * Meta é polling e atrasa. Este disjuntor lê os sinais indiretos nos webhooks da própria campanha (KB 5):
 *  - template `sent` sem `delivered` 30 min depois (quem bloqueou não recebe);
 *  - pedidos para parar / preferência de marketing sobre os entregues;
 *  - 131049 (saturação do usuário) e 131026 (sem WhatsApp) sobre as tentativas;
 *  - lidos sobre entregues 2 h depois.
 * Com amostra abaixo do mínimo não julga. Devolve o motivo que estourou, ou null.
 */
final class DisjuntorDeEngajamento
{
    /** @return array{motivo: ?string, metricas: array<string, mixed>} */
    public static function avaliar(Campaign $campaign): array
    {
        $cfg = (array) config('disparo.engajamento');
        $minimo = (int) ($cfg['amostra_minima'] ?? 20);
        $enviados = CampaignRecipient::STATUS_ENVIADOS;

        $base = fn () => $campaign->recipients();

        $tentativas = (int) $base()->whereIn('status', array_merge($enviados, [CampaignRecipient::STATUS_FALHOU]))->count();
        $saidos = (int) $base()->whereIn('status', $enviados)->count();

        $maduros = (int) $base()->whereIn('status', $enviados)->where('enviado_em', '<=', now()->subMinutes((int) ($cfg['nao_entregues_apos_minutos'] ?? 30)))->count();
        $naoEntregues = (int) $base()->whereIn('status', [CampaignRecipient::STATUS_ENVIADO, CampaignRecipient::STATUS_ENVIADO_INCERTO])->where('enviado_em', '<=', now()->subMinutes((int) ($cfg['nao_entregues_apos_minutos'] ?? 30)))->count();

        $entregues = (int) $base()->whereIn('status', [CampaignRecipient::STATUS_ENTREGUE, CampaignRecipient::STATUS_LIDO, CampaignRecipient::STATUS_RESPONDIDO])->count();
        $entreguesMaduros = (int) $base()->whereIn('status', [CampaignRecipient::STATUS_ENTREGUE, CampaignRecipient::STATUS_LIDO, CampaignRecipient::STATUS_RESPONDIDO])->where('enviado_em', '<=', now()->subMinutes((int) ($cfg['lidos_apos_minutos'] ?? 120)))->count();
        $lidosMaduros = (int) $base()->whereIn('status', [CampaignRecipient::STATUS_LIDO, CampaignRecipient::STATUS_RESPONDIDO])->where('enviado_em', '<=', now()->subMinutes((int) ($cfg['lidos_apos_minutos'] ?? 120)))->count();

        $carencias = (int) $base()->where('codigo_erro', '131049')->count();
        $semWhatsApp = (int) $base()->where('codigo_erro', '131026')->count();

        $optouts = $campaign->iniciada_em === null ? 0 : (int) Suppression::query()
            ->where('created_at', '>=', $campaign->iniciada_em)
            ->whereIn('telefone_hash', $base()->whereIn('campaign_recipients.status', $enviados)->join('contacts', 'contacts.id', '=', 'campaign_recipients.contact_id')->select('contacts.telefone_hash'))
            ->count();

        $metricas = [
            'tentativas' => $tentativas,
            'saidos' => $saidos,
            'nao_entregues_30min' => $maduros > 0 ? round($naoEntregues / $maduros, 3) : null,
            'optouts' => $entregues > 0 ? round($optouts / $entregues, 3) : null,
            'carencia_131049' => $tentativas > 0 ? round($carencias / $tentativas, 3) : null,
            'sem_whatsapp_131026' => $tentativas > 0 ? round($semWhatsApp / $tentativas, 3) : null,
            'lidos_2h' => $entreguesMaduros > 0 ? round($lidosMaduros / $entreguesMaduros, 3) : null,
        ];

        $motivo = match (true) {
            $maduros >= $minimo && $naoEntregues / $maduros >= (float) ($cfg['nao_entregues_max'] ?? 0.1) => sprintf('%d%% dos templates enviados há 30 min não foram entregues (provável bloqueio em massa)', (int) round(100 * $naoEntregues / $maduros)),
            $entregues >= $minimo && $optouts / $entregues >= (float) ($cfg['optouts_max'] ?? 0.01) => sprintf('%d pedidos para parar em %d entregues', $optouts, $entregues),
            $tentativas >= $minimo && $carencias / $tentativas >= (float) ($cfg['carencia_131049_max'] ?? 0.05) => sprintf('%d%% de 131049: a Meta está limitando marketing a esta base', (int) round(100 * $carencias / $tentativas)),
            $tentativas >= $minimo && $semWhatsApp / $tentativas >= (float) ($cfg['sem_whatsapp_131026_max'] ?? 0.05) => sprintf('%d%% de 131026: base com números sem WhatsApp', (int) round(100 * $semWhatsApp / $tentativas)),
            $entreguesMaduros >= $minimo && $lidosMaduros / $entreguesMaduros < (float) ($cfg['lidos_min'] ?? 0.3) => sprintf('só %d%% dos entregues há 2 h foram lidos', (int) round(100 * $lidosMaduros / $entreguesMaduros)),
            default => null,
        };

        return ['motivo' => $motivo, 'metricas' => $metricas];
    }
}
