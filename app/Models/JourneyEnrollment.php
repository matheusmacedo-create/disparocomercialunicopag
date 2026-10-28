<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um contato dentro de uma régua (KB 4.9).
 *
 * @property int $id
 * @property int $journey_id
 * @property int $contact_id
 * @property int $passo_atual
 * @property string $status
 * @property ?CarbonInterface $proximo_em
 * @property ?string $motivo_saida
 * @property ?string $ultimo_wamid
 * @property ?CarbonInterface $iniciada_em
 * @property ?CarbonInterface $encerrada_em
 */
class JourneyEnrollment extends Model
{
    use HasFactory;

    public const STATUS_ATIVA = 'ativa';

    public const STATUS_CONCLUIDA = 'concluida';

    public const STATUS_SAIU = 'saiu';

    public const STATUS_PAUSADA = 'pausada';

    public const STATUS_FALHOU = 'falhou';

    protected $fillable = ['journey_id', 'contact_id', 'passo_atual', 'status', 'chave_ativa', 'inscrito_por', 'proximo_em', 'motivo_saida', 'ultimo_wamid', 'iniciada_em', 'encerrada_em', 'convertido_em', 'conversao_origem'];

    public static function chaveAtiva(int $journeyId, int $contactId): string
    {
        return $journeyId.':'.$contactId;
    }

    protected function casts(): array
    {
        return ['passo_atual' => 'integer', 'proximo_em' => 'datetime', 'iniciada_em' => 'datetime', 'encerrada_em' => 'datetime', 'convertido_em' => 'datetime'];
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
}
