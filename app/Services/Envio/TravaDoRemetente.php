<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Models\Sender;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * UMA trava por remetente para TODO envio de template (executor de campanhas, réguas, teste, sonda):
 * duas rodadas ao mesmo tempo no mesmo número passariam as duas pela guarda e mandariam dois templates ao
 * mesmo telefone, ou estourariam o teto por um lote (lição literal do `ReguaKapso::TRAVA_DA_RODADA`).
 * No cache da aplicação (em produção, a tabela cache_locks). O TTL só vale para processo que morreu.
 */
final class TravaDoRemetente
{
    public const EM_ANDAMENTO = 'rodada_em_andamento';

    /**
     * Executa `$trabalho` com a trava do remetente; devolve `false` se outra rodada a segura.
     *
     * @template T
     *
     * @param  Closure(): T  $trabalho
     * @return T|false
     */
    public static function executar(Sender $sender, Closure $trabalho, int $esperaSegundos = 0): mixed
    {
        $lock = Cache::lock(self::chave($sender), (int) config('disparo.lote.trava_segundos', 600));

        try {
            $lock->block($esperaSegundos);
        } catch (LockTimeoutException) {
            return false;
        }

        try {
            return $trabalho();
        } finally {
            $lock->release();
        }
    }

    public static function chave(Sender $sender): string
    {
        return 'disparo:remetente:'.$sender->id.':envio';
    }
}
