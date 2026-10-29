<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Mask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alertas da operação de disparos no Discord. Sem webhook configurado vira log - e nunca lança: alarme
 * que quebra o fluxo é pior que alarme que falta (mesmo desenho do MED). Nunca leva PII: nome de
 * campanha, remetente, contagens e telefone mascarado, no máximo.
 */
class DiscordNotifier
{
    public const COR_INFO = 3447003;

    public const COR_ALERTA = 15105570;

    public const COR_PERIGO = 15158332;

    public const COR_SUCESSO = 3066993;

    /**
     * @param  list<array{name: string, value: string, inline?: bool}>  $campos
     */
    public function enviar(string $titulo, string $texto, int $cor = self::COR_INFO, array $campos = [], bool $mencionar = false, ?string $url = null): void
    {
        $webhook = (string) config('disparo.discord.webhook_url');
        $role = (string) config('disparo.discord.role_id');

        // Mecanismo, não convenção: tudo o que sai daqui passa pelo scrubber de PII.
        $titulo = Mask::scrub($titulo);
        $texto = Mask::scrub($texto);
        $campos = array_map(fn (array $campo): array => ['name' => Mask::scrub((string) $campo['name']), 'value' => Mask::scrub((string) $campo['value']), 'inline' => $campo['inline'] ?? false], $campos);

        $embed = array_filter([
            'title' => mb_substr($titulo, 0, 250),
            'description' => mb_substr($texto, 0, 2000),
            'color' => $cor,
            'fields' => array_map(fn (array $campo): array => ['name' => mb_substr($campo['name'], 0, 250), 'value' => mb_substr($campo['value'], 0, 1000), 'inline' => $campo['inline']], $campos),
            'url' => $url,
            'timestamp' => now()->toIso8601String(),
        ], fn ($v): bool => $v !== null && $v !== []);

        if ($webhook === '') {
            Log::info('Discord (sem webhook): '.$titulo, ['texto' => $texto, 'campos' => $campos]);

            return;
        }

        try {
            Http::timeout(5)->post($webhook, [
                'content' => $mencionar && $role !== '' ? "<@&{$role}>" : '',
                'embeds' => [$embed],
            ]);
        } catch (Throwable $e) {
            Log::warning('Discord indisponível: '.$e->getMessage(), ['titulo' => $titulo]);
        }
    }
}
