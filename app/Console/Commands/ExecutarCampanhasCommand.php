<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Envio\ExecutorDeCampanha;
use App\Services\Envio\TravaDoRemetente;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * O tick das campanhas (KB 8): a cada minuto, para cada remetente, UMA rodada da campanha mais prioritária
 * que esteja pronta (`em_andamento`, ou `aprovada` com `agendada_para` vencido - a espera da auto-aprovação
 * e o agendamento do aprovador caem aqui), em ordem de `prioridade` e `agendada_para`.
 *
 * Orçamento de tempo global de 50 s contado do começo do comando (como o KYC faz com 240 s): com três
 * remetentes a rodada ainda termina antes do próximo tick, e o `withoutOverlapping(5)` da agenda só
 * segura processo que morreu. Saída sem PII: nome de campanha, remetente e contagens.
 */
final class ExecutarCampanhasCommand extends Command
{
    protected $signature = 'disparo:executar-campanhas {--campanha= : Só esta campanha (id)}';

    protected $description = 'Roda uma rodada das campanhas prontas (uma por remetente), respeitando ritmo, janela e teto';

    /**
     * Desfechos em que a rodada nem chegou a ocupar o remetente: a próxima campanha dele ainda pode rodar
     * neste tick. Qualquer outro (enviou, teto, janela, pausa, disjuntor) encerra o remetente por este tick.
     */
    private const NAO_OCUPOU_O_REMETENTE = ['aguardando_tranche', 'categoria_mudou', 'aprovacao_invalidada', 'template_desconhecido'];

    public function handle(ExecutorDeCampanha $executor): int
    {
        $inicio = microtime(true);
        $orcamento = (int) config('disparo.lote.tempo_maximo_segundos', 50);
        $simulacao = ! config('disparo.envio_habilitado');

        if ($simulacao) {
            $this->warn('SIMULAÇÃO (DISPARO_ENVIO_HABILITADO=false): as rodadas marcam `simulado`, nada sai.');
        }

        $campanhas = $this->campanhasProntas($executor);

        if ($campanhas->isEmpty()) {
            $this->info($this->option('campanha') ? 'A campanha pedida não está pronta para rodar (precisa estar em andamento, ou aprovada com o horário vencido).' : 'Nenhuma campanha pronta para rodar.');

            return self::SUCCESS;
        }

        $linhas = [];
        $esgotou = false;

        foreach ($campanhas->groupBy('sender_id') as $doRemetente) {
            foreach ($doRemetente as $campanha) {
                $restante = $orcamento - (microtime(true) - $inicio);

                if ($restante < 2) {
                    $esgotou = true;
                    break 2;
                }

                if ($campanha->status === Campaign::STATUS_APROVADA) {
                    $this->iniciar($campanha);
                }

                $resultado = $executor->rodar($campanha, null, (int) min($restante, (int) config('disparo.lote.tempo_maximo_segundos', 50)));

                $linhas[] = [
                    '#'.$campanha->id.' '.mb_substr($campanha->nome, 0, 40),
                    mb_substr((string) $campanha->sender?->nome, 0, 30),
                    $resultado['enviados'],
                    $resultado['simulados'],
                    $resultado['pulados'],
                    $resultado['falhas'],
                    $resultado['adiados'],
                    $resultado['parou'] ?? 'rodada completa',
                ];

                // Uma rodada por remetente por tick: a trava e o ritmo são do número, não da campanha.
                if ($resultado['parou'] === TravaDoRemetente::EM_ANDAMENTO || ! in_array($resultado['parou'], self::NAO_OCUPOU_O_REMETENTE, true)) {
                    break;
                }
            }
        }

        $this->table(['Campanha', 'Remetente', 'Enviados', 'Simulados', 'Pulados', 'Falhas', 'Adiados', 'Parou em'], $linhas);

        if ($esgotou) {
            $this->warn(sprintf('Orçamento de %d s esgotado: as campanhas restantes ficam para o próximo tick.', $orcamento));
        }

        return self::SUCCESS;
    }

    /**
     * Em andamento, aprovada com o horário vencido e - para a sonda sair - pausada pelo disjuntor (conta
     * impedida) com a sonda do remetente devida: o executor manda 1 e, se passa, retoma a campanha.
     *
     * @return Collection<int, Campaign>
     */
    private function campanhasProntas(ExecutorDeCampanha $executor): Collection
    {
        $id = $this->option('campanha');

        return Campaign::query()
            ->whereIn('status', [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_APROVADA, Campaign::STATUS_PAUSADA])
            ->when(filled($id), fn ($q) => $q->whereKey((int) $id))
            ->orderBy('prioridade')
            ->orderBy('agendada_para')
            ->orderBy('id')
            ->with('sender')
            ->get()
            ->filter(fn (Campaign $c): bool => $c->sender !== null && ($c->prontaParaRodar() || ($c->status === Campaign::STATUS_PAUSADA && $c->sender->sondaDevida() && $executor->pausadaPeloDisjuntor($c))))
            ->values();
    }

    /** Aprovada com o horário vencido: começa agora, com o `sistema` como ator na trilha. */
    private function iniciar(Campaign $campanha): void
    {
        $campanha->forceFill([
            'status' => Campaign::STATUS_EM_ANDAMENTO,
            'iniciada_em' => $campanha->iniciada_em ?? now(),
            'pausada_motivo' => null,
        ])->save();

        $campanha->registrarEvento('iniciada', ['automatico' => true, 'agendada_para' => $campanha->agendada_para?->toIso8601String()], null);
    }
}
