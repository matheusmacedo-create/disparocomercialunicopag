<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Régua de nutrição (KB 4.9).
 *
 * @property int $id
 * @property string $nome
 * @property ?string $descricao
 * @property int $sender_id
 * @property string $gatilho
 * @property ?int $gatilho_ref
 * @property bool $ativa
 * @property bool $sair_ao_responder
 * @property ?int $max_por_dia
 */
class Journey extends Model
{
    use HasFactory;

    public const GATILHO_MANUAL = 'manual';

    public const GATILHO_LISTA = 'lista';

    public const GATILHO_TAG = 'tag';

    public const GATILHO_SEGMENTO = 'segmento';

    public const STATUS_RASCUNHO = 'rascunho';

    public const STATUS_AGUARDANDO_APROVACAO = 'aguardando_aprovacao';

    public const STATUS_APROVADA = 'aprovada';

    public const STATUS_ATIVA = 'ativa';

    public const STATUS_PAUSADA = 'pausada';

    /** Só o formulário: estado, aprovação e `ativa` mudam pelo {@see \App\Services\Reguas\FluxoDeRegua}. */
    protected $fillable = ['nome', 'descricao', 'sender_id', 'objetivo', 'gatilho', 'gatilho_ref', 'sair_ao_responder', 'sair_ao_converter', 'reinscrever_apos_dias', 'ignora_conversa_aberta', 'intervalo_minimo_horas', 'max_por_dia'];

    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'sair_ao_responder' => 'boolean',
            'sair_ao_converter' => 'boolean',
            'ignora_conversa_aberta' => 'boolean',
            'intervalo_minimo_horas' => 'integer',
            'reinscrever_apos_dias' => 'integer',
            'max_por_dia' => 'integer',
            'gatilho_ref' => 'integer',
            'aprovado_em' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_RASCUNHO => 'Rascunho',
            self::STATUS_AGUARDANDO_APROVACAO => 'Aguardando aprovação',
            self::STATUS_APROVADA => 'Aprovada (parada)',
            self::STATUS_ATIVA => 'Ativa',
            self::STATUS_PAUSADA => 'Pausada',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(JourneyEvent::class)->orderByDesc('id');
    }

    public function registrarEvento(string $evento, ?array $detalhes = null, ?int $userId = null): JourneyEvent
    {
        return $this->events()->create([
            'evento' => $evento,
            'detalhes' => $detalhes,
            'user_id' => $userId,
            'ator' => \App\Support\Ator::atual($userId),
            'created_at' => now(),
        ]);
    }

    public function objetivoComercial(): bool
    {
        return in_array($this->objetivo, [Campaign::OBJETIVO_RECUPERAR, Campaign::OBJETIVO_OFERTAR], true);
    }

    /** @return array<string, string> */
    public static function gatilhoLabels(): array
    {
        return [
            self::GATILHO_MANUAL => 'Manual (inscrição pelo painel)',
            self::GATILHO_LISTA => 'Ao entrar numa base',
            self::GATILHO_TAG => 'Ao receber uma tag',
            self::GATILHO_SEGMENTO => 'Segmento (reavaliado todo dia)',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(JourneyStep::class)->orderBy('ordem');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(JourneyEnrollment::class);
    }

    /**
     * Inscreve o contato (uma inscrição ativa por régua e contato, garantida por `chave_ativa` única mesmo
     * com dois workers). Null = já inscrito, régua sem passos ou fora da janela de reinscrição.
     */
    public function inscrever(Contact $contato, ?string $origem = null, ?int $userId = null): ?JourneyEnrollment
    {
        if ($this->steps()->count() === 0) {
            return null;
        }

        $chave = JourneyEnrollment::chaveAtiva($this->id, $contato->id);

        if ($this->enrollments()->where('chave_ativa', $chave)->exists()) {
            return null;
        }

        // Quem já passou pela régua só volta depois de `reinscrever_apos_dias` (nulo = nunca).
        $ultima = $this->enrollments()->where('contact_id', $contato->id)->latest('id')->first();

        if ($ultima !== null) {
            if ($this->reinscrever_apos_dias === null) {
                return null;
            }

            $referencia = $ultima->encerrada_em ?? $ultima->updated_at;

            if ($referencia !== null && $referencia->gt(now()->subDays($this->reinscrever_apos_dias))) {
                return null;
            }
        }

        $inscricao = JourneyEnrollment::query()->createOrFirst(
            ['chave_ativa' => $chave],
            [
                'journey_id' => $this->id,
                'contact_id' => $contato->id,
                'passo_atual' => 1,
                'status' => JourneyEnrollment::STATUS_ATIVA,
                'proximo_em' => now(),
                'iniciada_em' => now(),
                'motivo_saida' => null,
                'inscrito_por' => $userId,
            ],
        );

        return $inscricao->wasRecentlyCreated ? $inscricao : null;
    }
}
