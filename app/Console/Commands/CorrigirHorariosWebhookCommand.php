<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sender;
use App\Services\WhatsApp\WhatsAppClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparo único (06/11/2026): até essa data o ProcessadorDeEventos gravava a hora dos eventos do
 * webhook em UTC sem converter (o Carbon 3 devolve UTC em createFromTimestamp e o Eloquent grava a
 * hora como está), 3 h à frente do resto da app (America/Sao_Paulo). Isso deslocava o teto de 24 h,
 * a conversa aberta de 7 dias e os horários do painel.
 *
 * Exato, sem heurística: lê na Kapso as mensagens dos remetentes desde `--desde` (o primeiro webhook
 * chegou em 31/10/2026), junta os horários reais de cada uma (envio e cada status: os mesmos que o
 * webhook trouxe), monta a versão errada (hora UTC) e a certa (fuso da app) de cada um e troca só o
 * valor gravado IGUAL a uma versão errada. O que veio da importação (já no fuso certo) ou do envio não
 * bate com nenhuma versão errada e fica como está. (`webhook_calls` não serve: guarda só o resumo.)
 *
 *   (nada)      prévia: conta o que mudaria
 *   --aplicar   grava
 */
final class CorrigirHorariosWebhookCommand extends Command
{
    /** Colunas que recebiam a hora do evento do webhook. */
    private const COLUNAS = [
        'whatsapp_messages' => ['enviado_em', 'entregue_em', 'lido_em', 'tratada_em'],
        'campaign_recipients' => ['entregue_em', 'lido_em', 'respondido_em'],
        'contacts' => ['ultimo_envio_em', 'ultimo_template_em', 'ultima_resposta_em', 'consentimento_em', 'optout_em'],
        'suppressions' => ['created_at'],
    ];

    protected $signature = 'disparo:corrigir-horarios-webhook
        {--aplicar : Grava (sem ele, só conta)}
        {--desde=2026-10-30 : Primeiro dia das mensagens lidas na Kapso}';

    protected $description = 'Corrige os horários do webhook gravados em UTC até 06/11/2026';

    public function handle(WhatsAppClientFactory $clientes): int
    {
        $fuso = (string) config('app.timezone');
        $desde = CarbonImmutable::parse((string) $this->option('desde'), $fuso)->startOfDay();
        $trocas = [];

        foreach (Sender::query()->where('provider', 'kapso')->get() as $sender) {
            $cliente = $clientes->kapso($sender);
            $after = null;

            for ($pagina = 0; $pagina < 200; $pagina++) {
                $resultado = $cliente->mensagens(['since' => $desde->toIso8601String()], $after, 100);

                foreach (self::timestamps((array) $resultado['data']) as $ts) {
                    $trocas[CarbonImmutable::createFromTimestamp($ts, 'UTC')->format('Y-m-d H:i:s')] = CarbonImmutable::createFromTimestamp($ts, 'UTC')->setTimezone($fuso)->format('Y-m-d H:i:s');
                }

                $after = $resultado['after'] ?? null;

                if ($after === null || $resultado['data'] === []) {
                    break;
                }
            }
        }

        $aplicar = (bool) $this->option('aplicar');
        $total = 0;

        foreach (self::COLUNAS as $tabela => $colunas) {
            if (! DB::getSchemaBuilder()->hasTable($tabela)) {
                continue;
            }

            foreach ($colunas as $coluna) {
                if (! DB::getSchemaBuilder()->hasColumn($tabela, $coluna)) {
                    continue;
                }

                $n = 0;

                foreach (array_chunk(array_keys($trocas), 500) as $lote) {
                    $linhas = DB::table($tabela)->whereIn($coluna, $lote)->get(['id', $coluna]);

                    foreach ($linhas as $linha) {
                        $n++;

                        if ($aplicar) {
                            DB::table($tabela)->where('id', $linha->id)->where($coluna, $linha->{$coluna})
                                ->update([$coluna => $trocas[(string) $linha->{$coluna}]]);
                        }
                    }
                }

                $total += $n;
                $this->line(sprintf('%s.%s: %d', $tabela, $coluna, $n));
            }
        }

        $this->info(sprintf('%s | horários do webhook conhecidos: %d; valores em UTC %s: %d.', $aplicar ? 'APLICADO' : 'PRÉVIA', count($trocas), $aplicar ? 'corrigidos' : 'a corrigir', $total));

        return self::SUCCESS;
    }

    /**
     * Horários (segundos Unix) de qualquer chave `timestamp` das mensagens, aninhados ou não (envio e cada status).
     *
     * @param  array<mixed>  $dados
     * @return list<int>
     */
    private static function timestamps(array $dados): array
    {
        $achados = [];

        array_walk_recursive($dados, function ($valor, $chave) use (&$achados): void {
            if ($chave !== 'timestamp' || (! is_int($valor) && ! is_string($valor))) {
                return;
            }

            // Segundos Unix (como a Graph manda) ou ISO 8601.
            $ts = is_int($valor) || ctype_digit($valor) ? (int) $valor : (strtotime($valor) ?: 0);

            if ($ts > 1_600_000_000 && $ts < 2_200_000_000) {
                $achados[] = $ts;
            }
        });

        return array_values(array_unique($achados));
    }
}
