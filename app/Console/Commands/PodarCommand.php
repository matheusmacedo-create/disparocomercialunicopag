<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookCall;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retenção (LGPD, Art. 15 e 16; KB `retencao`): poda a trilha dos webhooks além de
 * `retencao.webhook_calls_dias` e chama a retenção de contatos, mensagens e importações
 * (`App\Services\Importacao\Retencao`, frente da importação) quando ela existir.
 */
final class PodarCommand extends Command
{
    protected $signature = 'disparo:podar';

    protected $description = 'Apaga webhook_calls antigos e roda a retenção de contatos e mensagens (LGPD)';

    private const RETENCAO = 'App\\Services\\Importacao\\Retencao';

    public function handle(): int
    {
        $dias = max(1, (int) config('disparo.retencao.webhook_calls_dias', 30));
        $apagados = WebhookCall::query()->where('created_at', '<', now()->subDays($dias))->delete();

        $this->info(sprintf('webhook_calls: %d registro(s) com mais de %d dias apagado(s).', $apagados, $dias));

        if (! app()->bound(self::RETENCAO) && ! class_exists(self::RETENCAO)) {
            $this->line('Retenção de contatos e mensagens: a classe App\Services\Importacao\Retencao ainda não existe neste código; só webhook_calls podados.');

            return self::SUCCESS;
        }

        try {
            $resultado = app(self::RETENCAO)->executar();
        } catch (Throwable $e) {
            report($e);
            $this->error('Falha na retenção de contatos e mensagens: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Retenção de contatos e mensagens executada.');

        foreach ((array) (is_array($resultado) ? $resultado : []) as $chave => $valor) {
            if (is_scalar($valor) || $valor === null) {
                $this->line(sprintf('  %s: %s', $chave, is_bool($valor) ? ($valor ? 'sim' : 'não') : (string) ($valor ?? '-')));
            }
        }

        return self::SUCCESS;
    }
}
