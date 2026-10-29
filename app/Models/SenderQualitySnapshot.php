<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qualidade do número numa leitura (de hora em hora).
 *
 * @property int $sender_id
 * @property string $qualidade
 * @property ?string $limite_tier
 * @property ?string $status_meta
 */
class SenderQualitySnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sender_id', 'qualidade', 'limite_tier', 'status_meta', 'medido_em', 'created_at'];

    protected function casts(): array
    {
        return ['medido_em' => 'datetime', 'created_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }
}
