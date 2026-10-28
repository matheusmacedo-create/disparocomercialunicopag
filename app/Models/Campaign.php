<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Campanhas\FluxoDeCampanha;
use App\Support\Ator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campanha: um template, um remetente, um público (KB 4.6).
 *
 * @property int $id
 * @property string $nome
 * @property int $sender_id
 * @property string $template_nome
 * @property string $template_idioma
 * @property ?array<string, mixed> $variaveis
 * @property ?array<string, mixed> $publico
 * @property ?array<string, mixed> $regras
 * @property ?CarbonInterface $agendada_para
 * @property string $status
 * @property ?array<string, mixed> $simulacao
 * @property ?int $criado_por
 * @property ?int $aprovado_por
 * @property ?CarbonInterface $aprovado_em
 * @property ?CarbonInterface $iniciada_em
 * @property ?CarbonInterface $concluida_em
 * @property ?string $pausada_motivo
 * @property int $total_destinatarios
 * @property int $enviados
 * @property int $entregues
 * @property int $lidos
 * @property int $respondidos
 * @property int $falhas
 * @property int $pulados
 * @property ?string $observacoes
 */
class Campaign extends Model
{
    use HasFactory;

    public const STATUS_RASCUNHO = 'rascunho';

    public const STATUS_AGUARDANDO_APROVACAO = 'aguardando_aprovacao';

    public const STATUS_APROVADA = 'aprovada';

    public const STATUS_EM_ANDAMENTO = 'em_andamento';

    public const STATUS_PAUSADA = 'pausada';

    public const STATUS_CONCLUIDA = 'concluida';

    public const STATUS_CANCELADA = 'cancelada';

    public const OBJETIVO_RECUPERAR = 'recuperar';

    public const OBJETIVO_ATIVAR = 'ativar';

    public const OBJETIVO_OFERTAR = 'ofertar';

    public const OBJETIVO_AVISAR = 'avisar';

    /**
     * O que o formulário pode gravar. Transições de estado (`status`, aprovação, início, pausa) e o
     * carimbo de quem criou NÃO são preenchíveis: só {@see FluxoDeCampanha}, com
     * `forceFill` e autorização no servidor - esconder a ação no Filament não basta.
     */
    protected $fillable = [
        'nome', 'sender_id', 'objetivo', 'template_nome', 'template_idioma', 'prioridade',
        'variaveis', 'publico', 'regras', 'agendada_para', 'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'variaveis' => 'array',
            'publico' => 'array',
            'regras' => 'array',
            'simulacao' => 'array',
            'custo_estimado' => 'float',
            'prioridade' => 'integer',
            'tranches_enviadas' => 'integer',
            'tranche_aguardando_ate' => 'datetime',
            'agendada_para' => 'datetime',
            'aprovado_em' => 'datetime',
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function objetivoLabels(): array
    {
        return [
            self::OBJETIVO_RECUPERAR => 'Recuperar seller inativo',
            self::OBJETIVO_ATIVAR => 'Ativar seller novo',
            self::OBJETIVO_OFERTAR => 'Ofertar (base externa / UnicoDrop)',
            self::OBJETIVO_AVISAR => 'Avisar (aviso de conta)',
        ];
    }

    /** Objetivo comercial: nunca sai como UTILITY para quem parou marketing ou recusou (KB 2.4). */
    public function objetivoComercial(): bool
    {
        return in_array($this->objetivo, [self::OBJETIVO_RECUPERAR, self::OBJETIVO_OFERTAR], true);
    }

    /** Hash do que define o envio: a prévia só vale se foi gerada depois da última edição disto. */
    public function hashDoEnvio(): string
    {
        return hash('sha256', json_encode([
            $this->sender_id, $this->template_nome, $this->template_idioma, $this->variaveis, $this->publico, $this->regras, $this->objetivo,
        ], JSON_THROW_ON_ERROR));
    }

    public function previaAtual(): bool
    {
        return $this->simulacao !== null && $this->simulacao_hash === $this->hashDoEnvio();
    }

    /**
     * Dias da semana permitidos (1 = segunda ... 7 = domingo). Vazio: MARKETING cai nos dias padrão de
     * marketing (`guardas.dias_marketing`, seg-sex), venha a campanha do painel ou da API; o resto, todos.
     */
    public function diasDaSemana(): array
    {
        $dias = array_values(array_filter(array_map('intval', (array) $this->regra('dias_da_semana', []))));

        if ($dias !== []) {
            return $dias;
        }

        $categoria = strtoupper((string) ($this->template_categoria ?: MessageTemplate::query()->where('sender_id', $this->sender_id)->where('nome', $this->template_nome)->where('idioma', $this->template_idioma)->value('categoria')));

        if ($categoria === Sender::CATEGORIA_MARKETING) {
            $padrao = array_values(array_filter(array_map('intval', (array) config('disparo.guardas.dias_marketing', [1, 2, 3, 4, 5]))));

            return $padrao === [] ? [1, 2, 3, 4, 5, 6, 7] : $padrao;
        }

        return [1, 2, 3, 4, 5, 6, 7];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class)->orderByDesc('id');
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_RASCUNHO => 'Rascunho',
            self::STATUS_AGUARDANDO_APROVACAO => 'Aguardando aprovação',
            self::STATUS_APROVADA => 'Aprovada',
            self::STATUS_EM_ANDAMENTO => 'Em andamento',
            self::STATUS_PAUSADA => 'Pausada',
            self::STATUS_CONCLUIDA => 'Concluída',
            self::STATUS_CANCELADA => 'Cancelada',
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            self::STATUS_RASCUNHO => 'gray',
            self::STATUS_AGUARDANDO_APROVACAO => 'warning',
            self::STATUS_APROVADA => 'info',
            self::STATUS_EM_ANDAMENTO => 'primary',
            self::STATUS_PAUSADA => 'warning',
            self::STATUS_CONCLUIDA => 'success',
            self::STATUS_CANCELADA => 'danger',
            default => 'gray',
        };
    }

    public function editavel(): bool
    {
        return in_array($this->status, [self::STATUS_RASCUNHO, self::STATUS_AGUARDANDO_APROVACAO], true);
    }

    public function aberta(): bool
    {
        return ! in_array($this->status, [self::STATUS_CONCLUIDA, self::STATUS_CANCELADA], true);
    }

    public function emAndamento(): bool
    {
        return $this->status === self::STATUS_EM_ANDAMENTO;
    }

    /**
     * Pode começar a rodar agora: em andamento, ou aprovada com horário agendado já vencido. Aprovada SEM
     * horário espera alguém clicar em "Iniciar" (é o que o painel promete); a auto-aprovação sempre agenda.
     */
    public function prontaParaRodar(): bool
    {
        if ($this->status === self::STATUS_EM_ANDAMENTO) {
            return true;
        }

        return $this->status === self::STATUS_APROVADA && $this->agendada_para !== null && $this->agendada_para->lte(now());
    }

    public function regra(string $chave, mixed $padrao = null): mixed
    {
        return ((array) ($this->regras ?? []))[$chave] ?? $padrao;
    }

    /**
     * Trilha humana. `$userId` nulo = `sistema` (rotina agendada, disjuntor): nunca o usuário logado por
     * tabela, para a pausa automática não ser atribuída a quem estava com o painel aberto.
     */
    public function registrarEvento(string $evento, ?array $detalhes = null, ?int $userId = null): CampaignEvent
    {
        return $this->events()->create([
            'evento' => $evento,
            'detalhes' => $detalhes,
            'user_id' => $userId,
            'ator' => Ator::atual($userId),
            'created_at' => now(),
        ]);
    }

    /** O mesmo registro, com o usuário logado como ator (ações do painel). */
    public function registrarAcao(string $evento, ?array $detalhes = null): CampaignEvent
    {
        return $this->registrarEvento($evento, $detalhes, auth()->id());
    }

    /**
     * O que foi aprovado: destinatários materializados, template (categoria/status) e política do
     * remetente. O executor confere antes de cada rodada (KB 2.2).
     */
    public function hashDaAprovacao(?MessageTemplate $template = null): string
    {
        $template ??= MessageTemplate::query()->where('sender_id', $this->sender_id)->where('nome', $this->template_nome)->where('idioma', $this->template_idioma)->first();

        return hash('sha256', json_encode([
            // Só o que foi materializado na aprovação: o executor pula destinatários em tempo de execução
            // (opt-out tardio, conversa aberta) e isso não é "mudou depois da aprovação".
            'destinatarios' => (int) $this->recipients()->count(),
            'template' => [$this->template_nome, $this->template_idioma, strtoupper((string) $template?->categoria), strtoupper((string) $template?->status)],
            'variaveis' => $this->variaveis,
            'regras' => $this->regras,
            'remetente' => [$this->sender_id, $this->sender?->categoriasPermitidas()],
        ], JSON_THROW_ON_ERROR));
    }

    /** Recalcula os totais a partir dos destinatários. */
    public function recontar(): void
    {
        $porStatus = $this->recipients()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $n = fn (string $s): int => (int) ($porStatus[$s] ?? 0);

        $this->forceFill([
            'total_destinatarios' => (int) $porStatus->sum(),
            'enviados' => array_sum(array_map($n, CampaignRecipient::STATUS_ENVIADOS)),
            'entregues' => $n(CampaignRecipient::STATUS_ENTREGUE) + $n(CampaignRecipient::STATUS_LIDO) + $n(CampaignRecipient::STATUS_RESPONDIDO),
            'lidos' => $n(CampaignRecipient::STATUS_LIDO) + $n(CampaignRecipient::STATUS_RESPONDIDO),
            'respondidos' => $n(CampaignRecipient::STATUS_RESPONDIDO),
            'falhas' => $n(CampaignRecipient::STATUS_FALHOU),
            'pulados' => $n(CampaignRecipient::STATUS_PULADO),
        ])->save();
    }

    public function pendentes(): int
    {
        return (int) $this->recipients()->where('status', CampaignRecipient::STATUS_PENDENTE)->count();
    }
}
