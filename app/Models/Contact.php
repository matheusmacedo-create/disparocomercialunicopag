<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Telefone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Contato: um por telefone (KB 4.2).
 *
 * @property int $id
 * @property string $telefone
 * @property string $telefone_hash
 * @property ?string $nome
 * @property ?string $primeiro_nome
 * @property ?string $email
 * @property ?string $documento
 * @property ?string $documento_hash
 * @property ?string $empresa
 * @property string $origem
 * @property string $tipo
 * @property ?string $account_id
 * @property ?array<string, mixed> $atributos
 * @property string $status
 * @property ?CarbonInterface $optout_em
 * @property ?string $optout_motivo
 * @property ?string $optout_origem
 * @property ?CarbonInterface $sem_whatsapp_em
 * @property ?string $consentimento_origem
 * @property ?CarbonInterface $consentimento_em
 * @property int $envios_total
 * @property int $entregues_total
 * @property int $lidos_total
 * @property int $respostas_total
 * @property int $falhas_total
 * @property ?CarbonInterface $ultimo_envio_em
 * @property ?CarbonInterface $ultimo_template_em
 * @property ?CarbonInterface $ultima_resposta_em
 * @property ?string $kapso_contact_id
 * @property ?string $observacoes
 * @property ?CarbonInterface $anonimizado_em
 */
class Contact extends Model
{
    use HasFactory;

    public const STATUS_ATIVO = 'ativo';

    public const STATUS_OPTOUT = 'optout';

    public const STATUS_INVALIDO = 'invalido';

    public const STATUS_ANONIMIZADO = 'anonimizado';

    public const ORIGENS = ['unicodrop', 'unicopag', 'csv', 'kapso', 'unicoapi', 'manual'];

    public const TIPOS = ['seller_inativo', 'seller_ativo', 'lead', 'cliente', 'outro'];

    protected $fillable = [
        'telefone', 'nome', 'primeiro_nome', 'email', 'documento', 'empresa', 'origem', 'tipo', 'account_id',
        'atributos', 'status', 'optout_em', 'optout_motivo', 'optout_origem', 'sem_whatsapp_em',
        'marketing_carencia_ate', 'recusou_em', 'recusas_total', 'provavel_bloqueio_em',
        'consentimento_origem', 'consentimento_em', 'consentimento_canal', 'consentimento_detalhes',
        'kapso_contact_id', 'kapso_conversation_id', 'nome_origem', 'observacoes',
    ];

    /** Origens externas: MARKETING só com consentimento registrado em nome da ÚnicoPag (KB 2.13). */
    public const ORIGENS_EXTERNAS = ['unicodrop', 'csv'];

    /**
     * Precedência do nome entre as fontes (achado da revisão de 28/10): o cadastro do unico-api vale mais
     * que a planilha, que vale mais que o perfil do WhatsApp (apelido, emoji, loja), que vale mais que o
     * que alguém digitou. Vazio nunca apaga; a Kapso só preenche vazio.
     */
    private const PRECEDENCIA_DO_NOME = ['unicoapi' => 4, 'unicopag' => 4, 'csv' => 3, 'unicodrop' => 3, 'kapso' => 2, 'manual' => 1];

    /** @return array<string, string> */
    public static function consentimentoLabels(): array
    {
        return [
            'whatsapp_optin' => 'Respondeu "quero receber" no WhatsApp',
            'formulario' => 'Formulário / landing page (nomeando a ÚnicoPag)',
            'contrato' => 'Contrato / termos de uso da ÚnicoPag',
            'cliente_ativo' => 'Cliente ativo da ÚnicoPag (relação contratual)',
            'cadastro_kyc' => 'Cadastro iniciado no KYC',
            'outro' => 'Outro (descrever)',
        ];
    }

    protected function casts(): array
    {
        return [
            'documento' => 'encrypted',
            'atributos' => 'array',
            'optout_em' => 'datetime',
            'sem_whatsapp_em' => 'datetime',
            'marketing_carencia_ate' => 'datetime',
            'recusou_em' => 'datetime',
            'recusas_total' => 'integer',
            'provavel_bloqueio_em' => 'datetime',
            'consentimento_em' => 'datetime',
            'ultimo_envio_em' => 'datetime',
            'ultimo_template_em' => 'datetime',
            'ultima_resposta_em' => 'datetime',
            'anonimizado_em' => 'datetime',
            'envios_total' => 'integer',
            'entregues_total' => 'integer',
            'lidos_total' => 'integer',
            'respostas_total' => 'integer',
            'falhas_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $contato): void {
            // Depois da anonimização o telefone é um marcador ("anon:..."): o hash do número real fica, é
            // ele que mantém a supressão e a trilha (KB 2.13).
            if (! Telefone::anonimizado((string) $contato->telefone)) {
                $contato->telefone_hash = Telefone::hash((string) $contato->telefone);
            }

            if ($contato->isDirty('documento')) {
                $digitos = preg_replace('/\D/', '', (string) $contato->documento) ?? '';
                $contato->documento = $digitos === '' ? null : $digitos;
                $contato->documento_hash = $digitos === '' ? null : Telefone::hashDocumento($digitos);
            }

            if (blank($contato->primeiro_nome) && filled($contato->nome)) {
                $contato->primeiro_nome = self::primeiroNomeDe((string) $contato->nome);
            }
        });
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_ATIVO => 'Ativo',
            self::STATUS_OPTOUT => 'Pediu para parar',
            self::STATUS_INVALIDO => 'Inválido / sem WhatsApp',
            self::STATUS_ANONIMIZADO => 'Anonimizado',
        ];
    }

    /** @return array<string, string> */
    public static function origemLabels(): array
    {
        return [
            'unicodrop' => 'UnicoDrop',
            'unicopag' => 'ÚnicoPag',
            'csv' => 'CSV',
            'kapso' => 'Histórico Kapso',
            'unicoapi' => 'unico-api',
            'manual' => 'Manual',
        ];
    }

    /** @return array<string, string> */
    public static function tipoLabels(): array
    {
        return [
            'seller_inativo' => 'Seller inativo',
            'seller_ativo' => 'Seller ativo',
            'lead' => 'Lead',
            'cliente' => 'Cliente',
            'outro' => 'Outro',
        ];
    }

    /** Primeiro nome sem dígito (razão social de MEI "45.678.901 JOAO" não serve; KYC 8.23). */
    public static function primeiroNomeDe(string $nome): ?string
    {
        foreach (preg_split('/\s+/', trim($nome)) ?: [] as $parte) {
            $parte = trim($parte, " \t\n\r\0\x0B-.,;:");

            // Só emoji/símbolo ("🌸") também não serve: precisa ter letra.
            if ($parte !== '' && preg_match('/\d/', $parte) !== 1 && preg_match('/\p{L}/u', $parte) === 1 && mb_strlen($parte) > 1) {
                return mb_substr(Str::ucfirst(Str::lower($parte)), 0, 80);
            }
        }

        return null;
    }

    /**
     * Nome como vem da planilha ou do cadastro, limpo: espaços colapsados e CAIXA ALTA virando Title Case
     * (com as partículas em minúsculas) - "MARIA DAS DORES" num template fica gritando. Null = vazio.
     */
    public static function nomeHigienizado(?string $nome): ?string
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $nome));

        // Sem letra nenhuma (só emoji, símbolo ou número) não é nome: num template ficaria "Oi 🌸".
        if ($nome === '' || preg_match('/\p{L}/u', $nome) !== 1) {
            return null;
        }

        if (mb_strtoupper($nome) === $nome && preg_match('/\p{L}/u', $nome) === 1) {
            $nome = mb_convert_case(mb_strtolower($nome), MB_CASE_TITLE, 'UTF-8');
            $nome = (string) preg_replace_callback('/(?<=\s)(Da|De|Do|Das|Dos|Di|Du|E|Del|Della|Van|Von)(?=\s)/u', fn (array $m): string => mb_strtolower($m[1]), $nome);
        }

        return mb_substr($nome, 0, 255);
    }

    public static function precedenciaDoNome(?string $origem): int
    {
        return self::PRECEDENCIA_DO_NOME[(string) $origem] ?? 0;
    }

    /**
     * Preenche ou troca o nome respeitando a precedência das fontes: vazio não apaga; fonte mais fraca que a
     * do nome atual não sobrescreve; a Kapso (perfil do WhatsApp) só preenche vazio. Não salva. True = mudou.
     */
    public function atualizarNome(?string $nome, string $origem): bool
    {
        $nome = self::nomeHigienizado($nome);

        if ($nome === null || $nome === $this->nome) {
            return false;
        }

        if (filled($this->nome)) {
            if ($origem === 'kapso' || self::precedenciaDoNome($origem) < self::precedenciaDoNome($this->nome_origem ?? $this->origem)) {
                return false;
            }
        }

        $this->nome = $nome;
        $this->nome_origem = mb_substr($origem, 0, 20);
        $this->primeiro_nome = self::primeiroNomeDe($nome);

        return true;
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('created_at');
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(ContactList::class, 'contact_list_contact')->withPivot(['adicionado_em', 'contact_import_id']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class)->orderByDesc('enviado_em')->orderByDesc('id');
    }

    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function journeyEnrollments(): HasMany
    {
        return $this->hasMany(JourneyEnrollment::class);
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ATIVO);
    }

    public function scopePorTelefone(Builder $query, string $bruto): Builder
    {
        $formas = Telefone::formas($bruto);
        $normal = Telefone::normalizar($bruto);

        if ($normal !== null && ! in_array($normal, $formas, true)) {
            $formas[] = $normal;
        }

        return $query->whereIn('telefone', $formas ?: ['-']);
    }

    public function ativo(): bool
    {
        return $this->status === self::STATUS_ATIVO;
    }

    public function semWhatsAppRecente(): bool
    {
        return $this->sem_whatsapp_em !== null
            && $this->sem_whatsapp_em->gt(now()->subDays((int) config('disparo.guardas.sem_whatsapp_dias', 90)));
    }

    /** Pedido para parar (vale para sempre). Idempotente: o primeiro momento fica. */
    public function marcarOptout(string $motivo, string $origem, ?CarbonInterface $quando = null): void
    {
        if ($this->status === self::STATUS_ANONIMIZADO) {
            return;
        }

        if ($this->status !== self::STATUS_OPTOUT) {
            $this->forceFill([
                'status' => self::STATUS_OPTOUT,
                'optout_em' => $quando ?? now(),
                'optout_motivo' => mb_substr($motivo, 0, 100),
                'optout_origem' => mb_substr($origem, 0, 30),
            ])->save();
        }
    }

    public function marcarSemWhatsApp(?CarbonInterface $quando = null): void
    {
        $this->forceFill(['sem_whatsapp_em' => $quando ?? now()])->save();
    }

    public function origemExterna(): bool
    {
        return in_array($this->origem, self::ORIGENS_EXTERNAS, true);
    }

    public function temConsentimento(): bool
    {
        return filled($this->consentimento_origem);
    }

    public function emCarenciaDeMarketing(): bool
    {
        return $this->marketing_carencia_ate !== null && $this->marketing_carencia_ate->gt(now());
    }

    public function recusouRecentemente(): bool
    {
        return $this->recusou_em !== null && $this->recusou_em->gt(now()->subDays((int) config('disparo.guardas.recusa_carencia_dias', 90)));
    }

    public function provavelBloqueio(): bool
    {
        return $this->provavel_bloqueio_em !== null && $this->provavel_bloqueio_em->gt(now()->subDays((int) config('disparo.guardas.provavel_bloqueio_dias', 180)));
    }

    /** 131049: a Meta limitou marketing a este usuário por ora. */
    public function marcarCarenciaDeMarketing(): void
    {
        $this->forceFill(['marketing_carencia_ate' => now()->addDays((int) config('disparo.guardas.marketing_carencia_dias', 7))])->save();
    }

    /**
     * "Não"/"agora não" a template MARKETING: carência; na segunda recusa, supressão de marketing. `$quando`
     * é o momento da resposta (o histórico importado da Kapso traz recusas antigas: a carência conta de lá,
     * e uma recusa mais antiga que a já registrada não rejuvenesce a carência).
     */
    public function registrarRecusa(?int $senderId, ?string $wamid = null, ?CarbonInterface $quando = null): void
    {
        $quando ??= now();
        $recusouEm = $this->recusou_em !== null && $this->recusou_em->gt($quando) ? $this->recusou_em : $quando;

        $this->forceFill(['recusou_em' => $recusouEm, 'recusas_total' => $this->recusas_total + 1])->save();

        if ($this->recusas_total >= (int) config('disparo.guardas.recusas_para_suprimir', 2)) {
            Suppression::suprimir($this->telefone, Suppression::MOTIVO_RECUSAS, 'resposta', null, Suppression::ESCOPO_MARKETING, $wamid, 'recusou ofertas '.$this->recusas_total.' vezes', null, $quando);
        }
    }

    public function anonimizado(): bool
    {
        return $this->status === self::STATUS_ANONIMIZADO;
    }

    /** Opt-in colhido no WhatsApp ("Quero receber" num template nosso). */
    public function registrarConsentimento(string $origem, string $canal, ?string $detalhes = null, ?CarbonInterface $quando = null): void
    {
        $this->forceFill([
            'consentimento_origem' => mb_substr($origem, 0, 30),
            'consentimento_em' => $quando ?? now(),
            'consentimento_canal' => mb_substr($canal, 0, 30),
            'consentimento_detalhes' => $detalhes !== null ? mb_substr($detalhes, 0, 500) : $this->consentimento_detalhes,
        ])->save();
    }

    /** Fuso onde o contato está (pelo DDD), para a janela de horário. */
    public function fuso(): string
    {
        return Telefone::fusoDoDdd($this->telefone) ?? (string) config('disparo.fuso');
    }

    public function rotulo(): string
    {
        return trim((string) ($this->nome ?: $this->empresa ?: '')) ?: Telefone::formatar($this->telefone);
    }
}
