<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Regra da KB 2.9: template `sent` sem `delivered` por `guardas.provavel_bloqueio_horas` (48) =
 * provável bloqueio, e o contato sai do MARKETING por `provavel_bloqueio_dias` (180). A guarda
 * (`Contact::provavelBloqueio`) existia, mas nada gravava o `provavel_bloqueio_em`: em 05/11/2026,
 * 7 destinatários da campanha 2 seguiam "enviado" sem entrega há mais de 48 h e 0 contatos marcados.
 *
 * Marca o contato quando o template mais recente dele (de qualquer origem) passou do prazo ainda em
 * `sent` e nada do mesmo telefone foi entregue depois. Só templates dos últimos 30 dias: o histórico
 * velho da importação não diz nada sobre hoje.
 *
 *   (nada)   prévia
 *   --send   grava
 */
final class MarcarProvavelBloqueioCommand extends Command
{
    private const JANELA_DIAS = 30;

    protected $signature = 'disparo:marcar-provavel-bloqueio {--send : Grava (sem ele, só conta)}';

    protected $description = 'Marca provável bloqueio: template sem entrega há 48 h (KB 2.9)';

    public function handle(): int
    {
        $horas = max(1, (int) config('disparo.guardas.provavel_bloqueio_horas', 48));
        $limite = CarbonImmutable::now()->subHours($horas);
        $gravar = (bool) $this->option('send');
        $marcados = 0;
        $vistos = 0;

        $suspeitos = WhatsappMessage::query()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', WhatsappMessage::STATUS_SENT)
            ->whereNull('entregue_em')
            ->whereNotNull('contact_id')
            ->whereNotNull('enviado_em')
            ->where('enviado_em', '<=', $limite)
            ->where('enviado_em', '>=', CarbonImmutable::now()->subDays(self::JANELA_DIAS))
            ->orderBy('enviado_em')
            ->get(['id', 'contact_id', 'enviado_em'])
            ->groupBy('contact_id');

        foreach ($suspeitos as $contatoId => $mensagens) {
            $vistos++;
            $ultima = $mensagens->last();

            // Algo do mesmo contato foi entregue (ou lido) depois: o número recebe.
            $entregouDepois = WhatsappMessage::query()
                ->where('contact_id', $contatoId)
                ->where('direcao', WhatsappMessage::OUTBOUND)
                ->whereNotNull('entregue_em')
                ->where('enviado_em', '>=', $ultima->enviado_em)
                ->exists();
            $respondeuDepois = WhatsappMessage::query()
                ->where('contact_id', $contatoId)
                ->where('direcao', WhatsappMessage::INBOUND)
                ->where('enviado_em', '>=', $ultima->enviado_em)
                ->exists();

            if ($entregouDepois || $respondeuDepois) {
                continue;
            }

            $contato = Contact::query()->find($contatoId);
            $quando = CarbonImmutable::instance($ultima->enviado_em)->addHours($horas);

            if ($contato === null || ($contato->provavel_bloqueio_em !== null && $contato->provavel_bloqueio_em->gte($quando))) {
                continue;
            }

            $marcados++;

            if ($gravar) {
                $contato->forceFill(['provavel_bloqueio_em' => $quando])->save();
                ContactEvent::registrar((string) $contato->telefone_hash, 'provavel_bloqueio', ['mensagem_id' => $ultima->id, 'horas_sem_entrega' => $horas], null, $contato->id);
            }
        }

        $this->info(sprintf('%s | contatos com template sem entrega há %d h: %d; marcados como provável bloqueio: %d.', $gravar ? 'GRAVADO' : 'PRÉVIA', $horas, $vistos, $marcados));

        return self::SUCCESS;
    }
}
