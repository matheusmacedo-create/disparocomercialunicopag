<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $nome
 * @property ?string $cor
 */
class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['nome', 'cor'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->using(ContactTag::class)->withPivot('created_at');
    }
}
