<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Batimentos do agendador e do worker da fila, no cache (como no MED): sem cron `schedule:run` ou sem
 * `queue:work`, as rotinas "existem e nunca rodam" e ninguém percebe - o `disparo:diagnostico` e o
 * `/api/v1/health` leem daqui.
 */
final class Batimentos
{
    public const AGENDADOR = 'disparo:scheduler:heartbeat';

    public const WORKER = 'disparo:worker:heartbeat';

    public static function registrarAgendador(): void
    {
        Cache::forever(self::AGENDADOR, now()->toIso8601String());
    }

    public static function registrarWorker(): void
    {
        Cache::forever(self::WORKER, now()->toIso8601String());
    }

    public static function agendador(): ?CarbonImmutable
    {
        return self::ler(self::AGENDADOR);
    }

    public static function worker(): ?CarbonImmutable
    {
        return self::ler(self::WORKER);
    }

    private static function ler(string $chave): ?CarbonImmutable
    {
        $valor = Cache::get($chave);

        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
