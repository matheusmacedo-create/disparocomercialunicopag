<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha dos webhooks recebidos (30 dias). Sem PII além do payload enxuto.
 *
 * @property ?int $sender_id
 * @property string $provider
 * @property ?string $evento
 * @property ?string $idempotency_key
 * @property ?array<string, mixed> $payload
 * @property ?string $resultado
 */
class WebhookCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sender_id', 'provider', 'evento', 'idempotency_key', 'payload', 'resultado', 'processado_em', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processado_em' => 'datetime', 'created_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }
}
