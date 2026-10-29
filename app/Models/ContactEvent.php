<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha imutável por contato, chaveada pelo hash do telefone: sobrevive à anonimização (direito de
 * exclusão, pedidos LGPD, bloqueios, leitura de conversa, consentimento).
 *
 * @property string $telefone_hash
 * @property ?int $contact_id
 * @property ?int $user_id
 * @property ?string $ator
 * @property string $evento
 * @property ?array<string, mixed> $detalhes
 */
class ContactEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['telefone_hash', 'contact_id', 'user_id', 'ator', 'evento', 'detalhes', 'created_at'];

    protected function casts(): array
    {
        return ['detalhes' => 'array', 'created_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @param  array<string, mixed>|null  $detalhes */
    public static function registrar(string $telefoneHash, string $evento, ?array $detalhes = null, ?int $userId = null, ?int $contactId = null): self
    {
        $userId ??= auth()->id();
        $user = $userId !== null ? User::query()->find($userId) : null;

        return self::query()->create([
            'telefone_hash' => $telefoneHash,
            'contact_id' => $contactId,
            'user_id' => $userId,
            'ator' => $user !== null ? mb_substr($user->name.' <'.$user->email.'>', 0, 160) : 'sistema',
            'evento' => mb_substr($evento, 0, 40),
            'detalhes' => $detalhes,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'anonimizado' => 'Anonimizado',
            'pedido_lgpd' => 'Pedido do titular (LGPD)',
            'bloqueado' => 'Bloqueado',
            'bloqueio_liberado' => 'Bloqueio desfeito',
            'conversa_lida' => 'Conversa lida',
            'consentimento' => 'Consentimento registrado',
            'texto_enviado' => 'Texto enviado pelo painel',
            'dados_exportados' => 'Dados do titular exportados',
        ];
    }
}
