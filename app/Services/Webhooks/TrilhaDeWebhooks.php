<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\Sender;
use App\Models\WebhookCall;

/**
 * A trilha dos webhooks (`webhook_calls`, 30 dias): uma linha por entrega, só com resumo - evento,
 * quantos payloads, o que mudou - nunca o corpo (KB 4.10; achado de LGPD de 28/10).
 *
 * Idempotência pela `X-Idempotency-Key` da Kapso: a linha nasce com `insertOrIgnore` ANTES do
 * processamento e `processado_em` só é gravado no fim. Entrega repetida de algo já processado não roda de
 * novo; entrega repetida de algo que ficou pela metade (processo morreu, 500) roda de novo - o
 * processamento é idempotente, então repetir é seguro e não repetir perderia o resto do lote.
 */
final class TrilhaDeWebhooks
{
    /**
     * @return array{0: WebhookCall, 1: bool} a linha da entrega e se ela já foi processada antes
     */
    public function abrir(string $provider, ?Sender $sender, ?string $evento, ?string $chave): array
    {
        $campos = [
            'sender_id' => $sender?->id,
            'provider' => $provider,
            'evento' => $evento !== null ? mb_substr($evento, 0, 60) : null,
            'created_at' => now(),
        ];

        if ($chave === null || $chave === '') {
            return [WebhookCall::query()->create($campos), false];
        }

        $chave = mb_substr($chave, 0, 120);

        // INSERT IGNORE: a reentrega concorrente não estoura a chave única (seria um 500 e outra reentrega).
        WebhookCall::query()->insertOrIgnore($campos + ['idempotency_key' => $chave]);

        /** @var WebhookCall $registro */
        $registro = WebhookCall::query()->where('idempotency_key', $chave)->firstOrFail();

        return [$registro, $registro->processado_em !== null];
    }

    /**
     * @param  array<string, mixed>  $resumo  contagens e resultados sem PII
     */
    public function concluir(WebhookCall $registro, ?string $evento, array $resumo, ?string $resultado = null): void
    {
        $registro->forceFill([
            'evento' => $evento !== null ? mb_substr($evento, 0, 60) : $registro->evento,
            'payload' => $resumo,
            'resultado' => mb_substr($resultado ?? self::resultadoDe($resumo), 0, 200),
            'processado_em' => now(),
        ])->save();
    }

    /** @param  array<string, mixed>  $resumo */
    public static function resultadoDe(array $resumo): string
    {
        $partes = [];

        foreach (['payloads', 'processados', 'ignorados', 'outro_numero', 'desconhecidos', 'erros'] as $chave) {
            if (isset($resumo[$chave]) && (int) $resumo[$chave] > 0) {
                $partes[] = $chave.'='.(int) $resumo[$chave];
            }
        }

        return $partes === [] ? 'nada a fazer' : implode(' ', $partes);
    }
}
