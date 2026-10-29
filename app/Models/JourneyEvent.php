<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha humana da régua: quem criou, enviou para aprovação, aprovou, ativou, pausou, inscreveu à mão.
 *
 * @property int $journey_id
 * @property ?int $user_id
 * @property ?string $ator
 * @property string $evento
 * @property ?array<string, mixed> $detalhes
 */
class JourneyEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['journey_id', 'user_id', 'ator', 'evento', 'detalhes', 'created_at'];

    protected function casts(): array
    {
        return ['detalhes' => 'array', 'created_at' => 'datetime'];
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
