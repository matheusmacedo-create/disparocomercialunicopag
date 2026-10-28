<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Telefone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma mensagem que saiu ou chegou por um remetente (KB 4.7).
 *
 * @property int $id
 * @property int $sender_id
 * @property ?int $contact_id
 * @property string $telefone
 * @property string $direcao
 * @property ?string $wamid
 * @property ?string $tipo
 * @property ?string $template_nome
 * @property ?string $conteudo
 * @property string $status
 * @property ?string $codigo_erro
 * @property ?string $erro
 * @property string $origem
 * @property ?int $campaign_recipient_id
 * @property ?int $journey_enrollment_id
 * @property ?string $context_wamid
 * @property ?string $kapso_conversation_id
 * @property ?CarbonInterface $enviado_em
 * @property ?CarbonInterface $entregue_em
 * @property ?CarbonInterface $lido_em
 * @property ?array<string, mixed> $payload
 */
class WhatsappMessage extends Model
{
    use HasFactory;

    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    public const TIPO_TEMPLATE = 'template';

    public const TIPO_TEXTO = 'text';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECEIVED = 'received';

    public const ORIGEM_CAMPANHA = 'campanha';

    public const ORIGEM_REGUA = 'regua';

    public const ORIGEM_MANUAL = 'manual';

    public const ORIGEM_HISTORICO_KAPSO = 'historico_kapso';

    public const ORIGEM_BROADCAST_KAPSO = 'broadcast_kapso';

    public const ORIGEM_WEBHOOK = 'webhook';

    protected $fillable = [
        'sender_id', 'contact_id', 'telefone', 'direcao', 'wamid', 'external_key', 'tipo', 'template_nome', 'conteudo', 'status',
        'codigo_erro', 'erro', 'origem', 'campaign_recipient_id', 'journey_enrollment_id', 'context_wamid',
        'kapso_conversation_id', 'enviado_em', 'entregue_em', 'lido_em', 'payload', 'tratada_em', 'tratada_por', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'enviado_em' => 'datetime',
            'entregue_em' => 'datetime',
            'lido_em' => 'datetime',
            'tratada_em' => 'datetime',
        ];
    }

    /** Classificação que o processador de eventos gravou no payload (`pedido_lgpd`, `parar`, `agora_nao`...). */
    public const CLASSIFICACAO_PEDIDO_LGPD = 'pedido_lgpd';

    public function tratadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tratada_por');
    }

    /** Autor do texto livre mandado pelo painel. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direcao', self::INBOUND);
    }

    /** Respostas que ninguém tratou ainda (tela Conversas). */
    public function scopeNaoTratadas(Builder $query): Builder
    {
        return $query->whereNull('tratada_em');
    }

    public function classificacao(): ?string
    {
        $classe = data_get($this->payload, 'classificacao');

        return is_string($classe) && $classe !== '' ? $classe : null;
    }

    public function pedidoLgpd(): bool
    {
        return $this->classificacao() === self::CLASSIFICACAO_PEDIDO_LGPD || filled(data_get($this->payload, 'lgpd'));
    }

    /** Prazo para atender o pedido do titular (Art. 18), quando o processador o gravou em `payload.lgpd.prazo`. */
    public function prazoLgpd(): ?CarbonInterface
    {
        $prazo = data_get($this->payload, 'lgpd.prazo');

        if (! is_string($prazo) || $prazo === '') {
            return null;
        }

        try {
            return Carbon::parse($prazo);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Alguém leu e tratou a resposta: fica quem e quando, e a leitura entra na trilha do contato
     * (`conversa_lida`), como a LGPD pede. Idempotente.
     */
    public function marcarTratada(User $user): void
    {
        if ($this->tratada_em !== null) {
            return;
        }

        $this->forceFill(['tratada_em' => now(), 'tratada_por' => $user->id])->save();

        $hash = $this->contact?->telefone_hash ?? Telefone::hash(Telefone::canonico($this->telefone) ?? $this->telefone);

        ContactEvent::registrar($hash, 'conversa_lida', ['mensagem_id' => $this->id, 'sender_id' => $this->sender_id], $user->id, $this->contact_id);
    }

    /** A Meta só aceita texto livre até 24 h depois da última mensagem do cliente. */
    public static function janelaAbertaAte(?CarbonInterface $ultimaResposta): ?CarbonInterface
    {
        if ($ultimaResposta === null) {
            return null;
        }

        $ate = $ultimaResposta->copy()->addDay();

        return $ate->gt(now()) ? $ate : null;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    public function journeyEnrollment(): BelongsTo
    {
        return $this->belongsTo(JourneyEnrollment::class);
    }

    /** A entrega só avança: sent < delivered < read. failed e pending ficam fora da escada. */
    public static function rank(?string $status): int
    {
        return match ($status) {
            self::STATUS_SENT => 1,
            self::STATUS_DELIVERED => 2,
            self::STATUS_READ => 3,
            default => 0,
        };
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_SENT => 'Enviada',
            self::STATUS_DELIVERED => 'Entregue',
            self::STATUS_READ => 'Lida',
            self::STATUS_FAILED => 'Falhou',
            self::STATUS_RECEIVED => 'Recebida',
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_SENT => 'gray',
            self::STATUS_DELIVERED => 'info',
            self::STATUS_READ => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_RECEIVED => 'primary',
            default => 'gray',
        };
    }

    public function falhou(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
