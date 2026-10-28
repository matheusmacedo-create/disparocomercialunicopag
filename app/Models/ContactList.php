<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Base de contatos (KB 4.3): Sellers inativos 90d, Leads UnicoDrop out/2026...
 *
 * @property int $id
 * @property string $nome
 * @property string $slug
 * @property ?string $descricao
 * @property string $dono
 * @property bool $exige_consentimento
 * @property bool $ativa
 */
class ContactList extends Model
{
    use HasFactory;

    public const DONO_UNICOPAG = 'unicopag';

    public const DONO_UNICODROP = 'unicodrop';

    public const DONO_COMPARTILHADA = 'compartilhada';

    protected $fillable = ['nome', 'slug', 'descricao', 'dono', 'exige_consentimento', 'ativa'];

    protected function casts(): array
    {
        return ['exige_consentimento' => 'boolean', 'ativa' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $lista): void {
            if (blank($lista->slug)) {
                $lista->slug = Str::limit(Str::slug((string) $lista->nome), 70, '').'-'.Str::lower(Str::random(4));
            }
        });
    }

    /** @return array<string, string> */
    public static function donoLabels(): array
    {
        return [
            self::DONO_UNICOPAG => 'ÚnicoPag',
            self::DONO_UNICODROP => 'UnicoDrop',
            self::DONO_COMPARTILHADA => 'Compartilhada',
        ];
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_list_contact')->using(ContactListContact::class)->withPivot(['adicionado_em', 'contact_import_id']);
    }
}
