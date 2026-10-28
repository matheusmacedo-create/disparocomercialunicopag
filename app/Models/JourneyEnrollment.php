<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um contato dentro de uma régua (KB 4.9). `passo_atual` é a ordem do PRÓXIMO passo a executar: durante
 * um "aguardar" ele já aponta para o passo seguinte, e é assim que o webhook sabe que o passo de texto
 * está esperando a resposta do contato.
 *
 * @property int $id
 * @property int $journey_id
 * @property int $contact_id
 * @property int $passo_atual
 * @property string $status
 * @property ?CarbonInterface $proximo_em
 * @property ?string $motivo_saida
 * @property ?string $ultimo_wamid
 * @property ?list<array<string, mixed>> $passos_pulados
 * @property ?string $ultimo_motivo
 * @property ?CarbonInterface $iniciada_em
 * @property ?CarbonInterface $encerrada_em
 * @property ?CarbonInterface $convertido_em
 * @property ?string $conversao_origem
 */
class JourneyEnrollment extends Model
{
    use HasFactory;

    public const STATUS_ATIVA = 'ativa';

    public const STATUS_CONCLUIDA = 'concluida';

    public const STATUS_SAIU = 'saiu';

    public const STATUS_PAUSADA = 'pausada';

    public const STATUS_FALHOU = 'falhou';

    /** Motivo de saída de quem vendeu / abriu cadastro depois do envio (KB 2.9). */
    public const MOTIVO_CONVERTEU = 'converteu';

    /** Quantos passos pulados ficam guardados por inscrição (os mais antigos saem). */
    public const MAX_PASSOS_PULADOS = 50;

    protected $fillable = ['journey_id', 'contact_id', 'passo_atual', 'status', 'chave_ativa', 'inscrito_por', 'proximo_em', 'motivo_saida', 'ultimo_wamid', 'passos_pulados', 'ultimo_motivo', 'iniciada_em', 'encerrada_em', 'convertido_em', 'conversao_origem'];

    public static function chaveAtiva(int $journeyId, int $contactId): string
    {
        return $journeyId.':'.$contactId;
    }

    protected function casts(): array
    {
        return ['passo_atual' => 'integer', 'passos_pulados' => 'array', 'proximo_em' => 'datetime', 'iniciada_em' => 'datetime', 'encerrada_em' => 'datetime', 'convertido_em' => 'datetime'];
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function ativa(): bool
    {
        return $this->status === self::STATUS_ATIVA;
    }

    public function encerrar(string $status, string $motivo): void
    {
        $this->forceFill([
            'status' => $status,
            'motivo_saida' => mb_substr($motivo, 0, 200),
            'proximo_em' => null,
            'encerrada_em' => now(),
            // Libera a chave: o contato pode ser reinscrito depois de `reinscrever_apos_dias`.
            'chave_ativa' => null,
        ])->save();
    }

    /**
     * O contato converteu (vendeu, abriu cadastro no KYC): a régua não cobra quem já voltou. Grava a
     * conversão sempre (a primeira fica) e, se a inscrição está ativa e a régua sai ao converter, encerra
     * com `converteu`. True = encerrou agora.
     */
    public function converter(string $origem, ?CarbonInterface $quando = null): bool
    {
        if ($this->convertido_em === null) {
            $this->forceFill(['convertido_em' => $quando ?? now(), 'conversao_origem' => mb_substr($origem, 0, 30)])->save();
        }

        if (! $this->ativa() || ! ($this->journey?->sair_ao_converter ?? true)) {
            return false;
        }

        $this->encerrar(self::STATUS_SAIU, self::MOTIVO_CONVERTEU);

        return true;
    }

    /** Passo barrado por guarda ou recusa: fica na inscrição, com o motivo, em vez de sumir. */
    public function registrarPassoPulado(JourneyStep $passo, string $motivo): void
    {
        $pulados = array_values((array) ($this->passos_pulados ?? []));
        $pulados[] = ['passo' => $passo->ordem, 'tipo' => $passo->tipo, 'motivo' => mb_substr($motivo, 0, 100), 'em' => now()->toIso8601String()];

        $this->forceFill([
            'passos_pulados' => array_slice($pulados, -self::MAX_PASSOS_PULADOS),
            'ultimo_motivo' => mb_substr($motivo, 0, 100),
        ])->save();
    }

    /** O último template desta inscrição que saiu (a Meta não disse ter falhado), para as condições. */
    public function ultimoTemplate(): ?WhatsappMessage
    {
        return $this->messages()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
            ->orderByDesc('enviado_em')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_ATIVA => 'Ativa',
            self::STATUS_CONCLUIDA => 'Concluída',
            self::STATUS_SAIU => 'Saiu',
            self::STATUS_PAUSADA => 'Pausada',
            self::STATUS_FALHOU => 'Falhou',
        ];
    }

    /** @return array<string, string> motivo de saída => rótulo */
    public static function motivoSaidaLabels(): array
    {
        return [
            'respondeu' => 'Respondeu (conversa humana)',
            'pediu_para_parar' => 'Pediu para parar',
            self::MOTIVO_CONVERTEU => 'Converteu (vendeu / abriu cadastro)',
            'condicao_nao_atendida' => 'Condição do passo não atendida',
            'encerrar' => 'Passo "encerrar"',
            'fim_dos_passos' => 'Fim dos passos',
            'manual' => 'Inscrição manual',
            'lista' => 'Entrou na base',
            'tag' => 'Recebeu a tag',
            'segmento' => 'Bateu no segmento',
        ];
    }
}
