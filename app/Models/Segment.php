<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Segmento: filtro salvo sobre os contatos (KB 4.3). A tradução para SQL é só da
 * {@see \App\Services\Segmentos\ConsultaDeSegmento}.
 *
 * @property int $id
 * @property string $nome
 * @property ?string $descricao
 * @property array<string, mixed> $regras
 */
class Segment extends Model
{
    use HasFactory;

    protected $fillable = ['nome', 'descricao', 'regras'];

    protected function casts(): array
    {
        return ['regras' => 'array'];
    }
}
