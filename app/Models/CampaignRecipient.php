<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um contato numa campanha (KB 4.6).
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $contact_id
 * @property string $telefone
 * @property ?array<string, string> $variaveis
 * @property string $status
 * @property ?string $motivo
 * @property ?string $wamid
 * @property ?int $whatsapp_message_id
 * @property ?CarbonInterface $enviado_em
 * @property ?CarbonInterface $entregue_em
 * @property ?CarbonInterface $lido_em
 * @property ?CarbonInterface $respondido_em
 * @property ?string $erro
 * @property ?string $codigo_erro
 * @property int $tentativas
 * @property ?CarbonInterface $proxima_tentativa_em
 */
class CampaignRecipient extends Model
{
    use HasFactory;

    public const STATUS_PENDENTE = 'pendente';

    public const STATUS_ENVIANDO = 'enviando';

    public const STATUS_ENVIADO = 'enviado';

    public const STATUS_ENTREGUE = 'entregue';

    public const STATUS_LIDO = 'lido';

    public const STATUS_RESPONDIDO = 'respondido';

    public const STATUS_FALHOU = 'falhou';

    public const STATUS_PULADO = 'pulado';

    /** Envio real desligado (DISPARO_ENVIO_HABILITADO=false): a campanha rodou como prévia executada. */
    public const STATUS_SIMULADO = 'simulado';

    /** O provedor não confirmou o POST: a mensagem pode ter saído. Final; conta em teto e frequência. */
    public const STATUS_ENVIADO_INCERTO = 'enviado_incerto';

    protected $fillable = [
        'campaign_id', 'contact_id', 'telefone', 'variaveis', 'status', 'motivo', 'wamid', 'whatsapp_message_id',
        'enviado_em', 'entregue_em', 'lido_em', 'respondido_em', 'convertido_em', 'conversao_origem', 'erro',
        'codigo_erro', 'tentativas', 'proxima_tentativa_em',
    ];

    protected function casts(): array
    {
        return [
            'variaveis' => 'array',
            'enviado_em' => 'datetime',
            'entregue_em' => 'datetime',
            'lido_em' => 'datetime',
            'respondido_em' => 'datetime',
            'convertido_em' => 'datetime',
            'proxima_tentativa_em' => 'datetime',
            'tentativas' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsappMessage::class, 'whatsapp_message_id');
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDENTE => 'Pendente',
            self::STATUS_ENVIANDO => 'Enviando',
            self::STATUS_ENVIADO => 'Enviado',
            self::STATUS_ENTREGUE => 'Entregue',
            self::STATUS_LIDO => 'Lido',
            self::STATUS_RESPONDIDO => 'Respondeu',
            self::STATUS_FALHOU => 'Falhou',
            self::STATUS_PULADO => 'Pulado',
            self::STATUS_SIMULADO => 'Simulado (envio desligado)',
            self::STATUS_ENVIADO_INCERTO => 'Enviado? (sem confirmação)',
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_PENDENTE => 'gray',
            self::STATUS_ENVIANDO => 'warning',
            self::STATUS_ENVIADO => 'info',
            self::STATUS_ENTREGUE => 'info',
            self::STATUS_LIDO => 'success',
            self::STATUS_RESPONDIDO => 'success',
            self::STATUS_FALHOU => 'danger',
            self::STATUS_PULADO => 'gray',
            self::STATUS_SIMULADO => 'warning',
            self::STATUS_ENVIADO_INCERTO => 'warning',
            default => 'gray',
        };
    }

    /** Status que contam como "saiu" (para totais e para o público "quem recebeu"). */
    public const STATUS_ENVIADOS = [self::STATUS_ENVIADO, self::STATUS_ENTREGUE, self::STATUS_LIDO, self::STATUS_RESPONDIDO, self::STATUS_ENVIADO_INCERTO];

    /** Escada da entrega no destinatário: enviado < entregue < lido < respondido. */
    public static function rank(?string $status): int
    {
        return match ($status) {
            self::STATUS_ENVIADO => 1,
            self::STATUS_ENTREGUE => 2,
            self::STATUS_LIDO => 3,
            self::STATUS_RESPONDIDO => 4,
            default => 0,
        };
    }

    /** @return array<string, string> motivo do guard => rótulo */
    public static function motivoLabels(): array
    {
        return [
            'envio_desabilitado' => 'Envio desabilitado (simulação)',
            'remetente_pausado' => 'Remetente pausado',
            'qualidade_red' => 'Qualidade do número RED',
            'fora_da_janela' => 'Fora da janela de horário',
            'teto_diario' => 'Teto diário atingido',
            'optout' => 'Pediu para parar (opt-out)',
            'contato_invalido' => 'Contato inválido / anonimizado',
            'suprimido' => 'Telefone bloqueado',
            'categoria_nao_permitida' => 'Categoria do template não permitida no remetente',
            'template_nao_aprovado' => 'Template não aprovado',
            'sem_whatsapp' => 'Sem WhatsApp (quarentena)',
            'conversa_aberta' => 'Conversa aberta (template recente)',
            'frequencia' => 'Frequência máxima atingida',
            'duplicado' => 'Telefone repetido na campanha',
            'variavel_vazia' => 'Variável do template vazia',
            'sem_consentimento' => 'Sem consentimento registrado (base externa)',
            'marketing_carencia' => 'Meta limitou marketing a este usuário (carência)',
            'recusou' => 'Recusou oferta há menos de 90 dias',
            'provavel_bloqueio' => 'Provável bloqueio (template nunca entregue)',
            'dia_da_semana' => 'Fora dos dias da semana da campanha',
            'fora_da_janela_contato' => 'Fora da janela no fuso do contato',
            'template_qualidade' => 'Template com qualidade baixa na Meta',
            'historico_pendente' => 'Histórico da Kapso desatualizado',
        ];
    }
}
