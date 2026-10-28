<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Services\DiscordNotifier;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Support\Facades\Log;

/**
 * O que acontece com o REMETENTE quando o provedor recusa por motivo que vale para a conta inteira (KB
 * 2.12, 5). Compartilhado por quem envia template (executor de campanhas, réguas): a recusa é do número,
 * não de quem estava mandando.
 *
 *  - bloqueio por qualidade (131048, 368, 131031, RED, disjuntor de engajamento): pausa o remetente SEM
 *    sonda e CANCELA as campanhas MARKETING em andamento dele; só humano com `manage senders` religa;
 *  - conta impedida (pagamento, token, saldo da Kapso, Sandbox, 401-404): pausa o remetente e agenda uma
 *    sonda de 1 envio a cada `sonda_intervalo_minutos`.
 *
 * A campanha de onde veio a recusa (se veio de uma) é pausada com o motivo; a trilha fica em
 * `campaign_events` e `sender_events`, e o alerta vai ao Discord sem PII.
 */
final class Disjuntor
{
    public function __construct(private readonly DiscordNotifier $discord) {}

    /** Conta impedida: pausa com sonda; pausa a campanha de origem, se houver. */
    public function impedir(Sender $sender, WhatsAppException $excecao, ?Campaign $campaign = null, string $origem = 'campanha'): void
    {
        $motivo = mb_substr('Conta impedida: '.$excecao->getMessage(), 0, 500);
        $sender->impedir($motivo);
        SenderEvent::registrar($sender, 'pausado', ['motivo' => $motivo, 'classe' => 'conta', 'codigo' => $excecao->codigo, 'origem' => $origem, 'campaign_id' => $campaign?->id], null);

        if ($campaign !== null) {
            $this->pausarCampanha($campaign, $motivo, 'disjuntor');
        }

        Log::warning('Disjuntor (conta impedida)', ['sender_id' => $sender->id, 'campaign_id' => $campaign?->id, 'origem' => $origem, 'codigo' => $excecao->codigo]);

        $this->discord->enviar(
            'Disjuntor: conta impedida',
            sprintf(
                'O remetente "%s" recusou um envio %s por motivo que vale para a conta inteira. Remetente pausado%s; a próxima sonda (1 envio) sai em %d min.',
                $sender->nome,
                $campaign !== null ? 'da campanha "'.$campaign->nome.'"' : 'da régua',
                $campaign !== null ? ' e campanha pausada' : '',
                (int) $sender->sonda_intervalo_minutos,
            ),
            DiscordNotifier::COR_PERIGO,
            [['name' => 'Motivo', 'value' => mb_substr($excecao->getMessage(), 0, 900)]],
            true,
        );
    }

    /**
     * Bloqueio por qualidade: pausa sem sonda e cancela as campanhas MARKETING em andamento do remetente
     * (insistir com oferta num número sinalizado é o que o derruba). Campanha UTILITY de origem só pausa.
     */
    public function bloquearPorQualidade(Sender $sender, string $motivo, string|int|null $codigo = null, ?Campaign $campaign = null, string $origem = 'campanha'): void
    {
        $sender->bloquearPorQualidade($motivo);
        SenderEvent::registrar($sender, 'bloqueio_qualidade', ['motivo' => mb_substr($motivo, 0, 500), 'codigo' => $codigo, 'origem' => $origem, 'campaign_id' => $campaign?->id], null);

        $afetadas = [];

        foreach ($sender->campaigns()->whereIn('status', [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_PAUSADA, Campaign::STATUS_APROVADA])->get() as $outra) {
            $ehMarketing = $this->marketing($outra);

            if (! $ehMarketing && ($campaign === null || ! $outra->is($campaign))) {
                continue;
            }

            $outra->forceFill(['status' => $ehMarketing ? Campaign::STATUS_CANCELADA : Campaign::STATUS_PAUSADA, 'pausada_motivo' => mb_substr($motivo, 0, 500)])->save();
            $outra->registrarEvento($ehMarketing ? 'cancelada' : 'pausada', ['motivo' => mb_substr($motivo, 0, 500), 'automatico' => true, 'bloqueio_qualidade' => true, 'codigo' => $codigo], null);
            $afetadas[] = $outra->nome;
        }

        Log::warning('Disjuntor (bloqueio por qualidade)', ['sender_id' => $sender->id, 'campaign_id' => $campaign?->id, 'origem' => $origem, 'codigo' => $codigo]);

        $this->discord->enviar(
            'Bloqueio por qualidade: remetente travado',
            sprintf(
                'A Meta sinalizou o remetente "%s"%s. Sem sonda automática: alguém com "manage senders" precisa religar depois de conferir a qualidade no WhatsApp Manager. Campanhas afetadas: %s.',
                $sender->nome,
                $origem === 'regua' ? ' durante uma régua' : '',
                implode(', ', $afetadas) ?: 'nenhuma',
            ),
            DiscordNotifier::COR_PERIGO,
            [['name' => 'Motivo', 'value' => mb_substr($motivo, 0, 900)]],
            true,
        );
    }

    private function pausarCampanha(Campaign $campaign, string $motivo, string $evento): void
    {
        $campaign->forceFill(['status' => Campaign::STATUS_PAUSADA, 'pausada_motivo' => mb_substr($motivo, 0, 500)])->save();
        $campaign->registrarEvento($evento, ['motivo' => mb_substr($motivo, 0, 500), 'automatico' => true], null);
    }

    /** A categoria congelada na aprovação vale; sem ela, o cache do template. */
    private function marketing(Campaign $campaign): bool
    {
        $categoria = $campaign->template_categoria
            ?? MessageTemplate::query()->where('sender_id', $campaign->sender_id)->where('nome', $campaign->template_nome)->where('idioma', $campaign->template_idioma)->value('categoria');

        return strtoupper((string) $categoria) === Sender::CATEGORIA_MARKETING;
    }
}
