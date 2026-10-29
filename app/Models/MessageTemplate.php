<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache de um template da Meta por remetente (KB 4.5).
 *
 * @property int $id
 * @property int $sender_id
 * @property ?string $meta_id
 * @property string $nome
 * @property string $idioma
 * @property string $categoria
 * @property string $status
 * @property ?string $parameter_format
 * @property array<int, array<string, mixed>> $componentes
 * @property ?array<string, mixed> $qualidade
 * @property ?CarbonInterface $sincronizado_em
 */
class MessageTemplate extends Model
{
    use HasFactory;

    public const STATUS_APPROVED = 'APPROVED';

    protected $fillable = ['sender_id', 'meta_id', 'nome', 'idioma', 'categoria', 'status', 'parameter_format', 'componentes', 'qualidade', 'sincronizado_em'];

    protected function casts(): array
    {
        return ['componentes' => 'array', 'qualidade' => 'array', 'sincronizado_em' => 'datetime'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function aprovado(): bool
    {
        return strtoupper($this->status) === self::STATUS_APPROVED;
    }

    /** A definição no formato da Meta, como o montador espera. @return array<string, mixed> */
    public function definicao(): array
    {
        return [
            'id' => $this->meta_id,
            'name' => $this->nome,
            'language' => $this->idioma,
            'status' => $this->status,
            'category' => $this->categoria,
            'parameter_format' => $this->parameter_format,
            'components' => $this->componentes,
        ];
    }

    /** Texto do corpo (para prévia e busca). */
    public function corpo(): string
    {
        foreach ($this->componentes as $componente) {
            if (strtoupper((string) ($componente['type'] ?? '')) === 'BODY') {
                return (string) ($componente['text'] ?? '');
            }
        }

        return '';
    }

    /** Variáveis que o corpo pede: ["1","2"] ou ["nome","oferta"]. @return list<string> */
    public function variaveisDoCorpo(): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $this->corpo(), $achados);

        return array_values(array_unique($achados[1]));
    }

    /** Cabeçalho: TEXT | IMAGE | DOCUMENT | VIDEO | null. */
    public function formatoDoCabecalho(): ?string
    {
        foreach ($this->componentes as $componente) {
            if (strtoupper((string) ($componente['type'] ?? '')) === 'HEADER') {
                return strtoupper((string) ($componente['format'] ?? 'TEXT'));
            }
        }

        return null;
    }

    public function temBotaoDeUrlDinamica(): bool
    {
        foreach ($this->componentes as $componente) {
            if (strtoupper((string) ($componente['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }

            foreach ((array) ($componente['buttons'] ?? []) as $botao) {
                if (strtoupper((string) ($botao['type'] ?? '')) === 'URL' && str_contains((string) ($botao['url'] ?? ''), '{{')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Por que este template não pode ir por este remetente (null = pode). */
    public function motivoNaoEnviavel(Sender $sender): ?string
    {
        if (! $this->aprovado()) {
            return sprintf('Template "%s" está "%s" na Meta: só template aprovado é enviado.', $this->nome, $this->status ?: 'sem status');
        }

        if (! $sender->permiteCategoria($this->categoria)) {
            return sprintf('Template "%s" é %s e o remetente "%s" só envia %s.', $this->nome, $this->categoria, $sender->nome, implode('/', $sender->categoriasPermitidas()));
        }

        return null;
    }

    /**
     * Grava (ou atualiza) o cache a partir da definição que a Meta devolveu.
     *
     * @param  array<string, mixed>  $definicao
     */
    public static function sincronizar(Sender $sender, array $definicao): self
    {
        return self::query()->updateOrCreate(
            ['sender_id' => $sender->id, 'nome' => (string) $definicao['name'], 'idioma' => (string) ($definicao['language'] ?? 'pt_BR')],
            [
                'meta_id' => isset($definicao['id']) ? (string) $definicao['id'] : null,
                'categoria' => strtoupper((string) ($definicao['category'] ?? '')),
                'status' => strtoupper((string) ($definicao['status'] ?? '')),
                'parameter_format' => isset($definicao['parameter_format']) ? strtoupper((string) $definicao['parameter_format']) : null,
                'componentes' => array_values((array) ($definicao['components'] ?? [])),
                'qualidade' => isset($definicao['quality_score']) && is_array($definicao['quality_score']) ? $definicao['quality_score'] : null,
                'sincronizado_em' => now(),
            ],
        );
    }
}
