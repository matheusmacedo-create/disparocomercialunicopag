<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

/**
 * O tick das réguas (KB 8): inscrições com `proximo_em` vencido, a cada 10 min; com `--segmentos`,
 * reavalia os gatilhos por segmento (quem entrou no segmento é inscrito) uma vez por dia.
 *
 * A lógica mora em `App\Services\Reguas\ProcessadorDeReguas` (frente das réguas); este comando só chama
 * `rodar()` / `reavaliarSegmentos()` e imprime o resumo. Sem a classe, avisa e sai sem erro: a agenda não
 * pode quebrar porque uma frente ainda não entrou.
 */
final class ProcessarReguasCommand extends Command
{
    protected $signature = 'disparo:processar-reguas {--segmentos : Reavalia os gatilhos por segmento em vez de processar os passos}';

    protected $description = 'Processa os passos vencidos das réguas (ou, com --segmentos, reavalia os gatilhos por segmento)';

    private const PROCESSADOR = 'App\\Services\\Reguas\\ProcessadorDeReguas';

    public function handle(): int
    {
        if (! app()->bound(self::PROCESSADOR) && ! class_exists(self::PROCESSADOR)) {
            $this->warn('ProcessadorDeReguas ainda não existe neste código: nada a processar.');

            return self::SUCCESS;
        }

        try {
            $processador = app(self::PROCESSADOR);
            $resultado = $this->option('segmentos') ? $processador->reavaliarSegmentos() : $processador->rodar();
        } catch (Throwable $e) {
            report($e);
            $this->error('Falha ao processar as réguas: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('segmentos') ? 'Gatilhos por segmento reavaliados.' : 'Rodada das réguas concluída.');

        foreach ((array) (is_array($resultado) ? $resultado : []) as $chave => $valor) {
            if (is_scalar($valor) || $valor === null) {
                $this->line(sprintf('  %s: %s', $chave, is_bool($valor) ? ($valor ? 'sim' : 'não') : (string) ($valor ?? '-')));
            }
        }

        return self::SUCCESS;
    }
}
