<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um passo da régua (KB 4.9).
 *
 * @property int $id
 * @property int $journey_id
 * @property int $ordem
 * @property string $tipo
 * @property ?string $template_nome
 * @property ?string $template_idioma
 * @property ?array<string, mixed> $variaveis
 * @property ?int $aguardar_horas
 * @property ?string $condicao
 * @property ?int $tag_id
 */
class JourneyStep extends Model
{
    use HasFactory;

    public const TIPO_TEMPLATE = 'template';

    public const TIPO_AGUARDAR = 'aguardar';

    public const TIPO_CONDICAO = 'condicao';

    public const TIPO_TAG = 'tag';

    public const TIPO_ENCERRAR = 'encerrar';

    /** Texto livre com link, só dentro da janela de 24 h aberta pela resposta do contato (uma vez). */
    public const TIPO_TEXTO = 'texto';

    public const CONDICAO_SEMPRE = 'sempre';

    public const CONDICAO_SE_NAO_RESPONDEU = 'se_nao_respondeu';

    public const CONDICAO_SE_RESPONDEU = 'se_respondeu';

    public const CONDICAO_SE_NAO_ENTREGUE = 'se_nao_entregue';

    public const CONDICAO_SE_NAO_LEU = 'se_nao_leu';

    protected $fillable = ['journey_id', 'ordem', 'tipo', 'template_nome', 'template_idioma', 'variaveis', 'aguardar_horas', 'condicao', 'tag_id'];

    protected function casts(): array
    {
        return ['variaveis' => 'array', 'ordem' => 'integer', 'aguardar_horas' => 'integer'];
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /** @return array<string, string> */
    public static function tipoLabels(): array
    {
        return [
            self::TIPO_TEMPLATE => 'Enviar template',
            self::TIPO_TEXTO => 'Texto na resposta (janela de 24 h)',
            self::TIPO_AGUARDAR => 'Aguardar',
            self::TIPO_CONDICAO => 'Continuar só se...',
            self::TIPO_TAG => 'Adicionar tag',
            self::TIPO_ENCERRAR => 'Encerrar',
        ];
    }

    /** @return array<string, string> */
    public static function condicaoLabels(): array
    {
        return [
            self::CONDICAO_SE_NAO_RESPONDEU => 'não respondeu ao último template',
            self::CONDICAO_SE_RESPONDEU => 'respondeu ao último template',
            self::CONDICAO_SE_NAO_ENTREGUE => 'o último template não foi entregue',
            self::CONDICAO_SE_NAO_LEU => 'não leu o último template',
        ];
    }
}
