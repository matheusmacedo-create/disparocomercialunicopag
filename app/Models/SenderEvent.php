<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha do remetente: mudança de política (teto, janela, categorias), pausa, religamento, webhook,
 * rotação de segredo, mudança de qualidade. Sem segredos nos detalhes.
 *
 * @property int $sender_id
 * @property ?int $user_id
 * @property ?string $ator
 * @property string $evento
 * @property ?array<string, mixed> $detalhes
 */
class SenderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sender_id', 'user_id', 'ator', 'evento', 'detalhes', 'created_at'];

    protected function casts(): array
    {
        return ['detalhes' => 'array', 'created_at' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  array<string, mixed>|null  $detalhes */
    public static function registrar(Sender $sender, string $evento, ?array $detalhes = null, ?int $userId = null): self
    {
        return self::query()->create([
            'sender_id' => $sender->id,
            'user_id' => $userId,
            'ator' => \App\Support\Ator::atual($userId),
            'evento' => mb_substr($evento, 0, 40),
            'detalhes' => $detalhes,
            'created_at' => now(),
        ]);
    }
}
