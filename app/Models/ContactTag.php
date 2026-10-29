<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ContactTagObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Tag num contato (`contact_tag`). Classe própria para o `attach()`/`sync()` disparar evento: é o gatilho
 * "ao receber uma tag" das réguas (KB 4.9). A tabela só tem `created_at`.
 *
 * @property int $contact_id
 * @property int $tag_id
 */
#[ObservedBy([ContactTagObserver::class])]
class ContactTag extends Pivot
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'contact_tag';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** O pivot herdaria o `updated_at` do model pai; a tabela não tem a coluna. */
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }
}
