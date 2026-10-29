<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ContactListContactObserver;
use App\Services\Reguas\ProcessadorDeReguas;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pertinência de um contato a uma base (`contact_list_contact`). Existe como classe para o `attach()`/
 * `sync()` disparar evento: é o gatilho "ao entrar numa base" das réguas (KB 4.9). Quem grava a pivot
 * por `DB::table()->insert()` passa por fora - use `$contato->lists()->syncWithoutDetaching()` ou chame
 * {@see ProcessadorDeReguas::inscreverPorGatilho()} à mão.
 *
 * @property int $contact_list_id
 * @property int $contact_id
 * @property ?int $contact_import_id
 */
#[ObservedBy([ContactListContactObserver::class])]
class ContactListContact extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'contact_list_contact';

    protected function casts(): array
    {
        return ['adicionado_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Quem esqueceu o carimbo não deixa a linha sem data.
        static::creating(function (self $pivot): void {
            $pivot->adicionado_em ??= now();
        });
    }
}
