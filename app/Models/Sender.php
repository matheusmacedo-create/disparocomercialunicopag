<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Remetente: um número de WhatsApp com provedor, credencial e política própria (KB 4.1, 5).
 *
 * @property int $id
 * @property string $nome
 * @property string $provider
 * @property string $finalidade
 * @property string $phone_number_id
 * @property ?string $waba_id
 * @property ?string $display_phone_number
 * @property ?string $verified_name
 * @property ?string $api_key
 * @property ?string $webhook_secret
 * @property ?array<int, string> $categorias_permitidas
 * @property int $teto_diario
 * @property int $msgs_por_minuto
 * @property string $janela_inicio
 * @property string $janela_fim
 * @property string $fuso
 * @property ?CarbonInterface $aquecimento_inicio
 * @property ?int $aquecimento_teto_inicial
 * @property ?float $aquecimento_fator
 * @property ?CarbonInterface $pausado_em
 * @property ?string $pausado_motivo
 * @property ?CarbonInterface $sonda_em
 * @property string $qualidade
 * @property ?string $limite_tier
 * @property ?string $status_meta
 * @property ?array<string, mixed> $saude
 * @property ?CarbonInterface $sincronizado_em
 * @property ?CarbonInterface $templates_sincronizados_em
 * @property ?CarbonInterface $historico_importado_ate
 * @property bool $ativo
 */
class Sender extends Model
{
    use HasFactory;

    public const PROVIDER_KAPSO = 'kapso';

    public const PROVIDER_META = 'meta';

    public const FINALIDADE_COMERCIAL = 'comercial';

    public const FINALIDADE_AVISOS = 'avisos';

    public const FINALIDADE_KYC = 'kyc';

    public const FINALIDADE_TESTE = 'teste';

    public const QUALIDADE_GREEN = 'GREEN';

    public const QUALIDADE_YELLOW = 'YELLOW';

    public const QUALIDADE_RED = 'RED';

    public const QUALIDADE_UNKNOWN = 'UNKNOWN';

    public const CATEGORIA_UTILITY = 'UTILITY';

    public const CATEGORIA_MARKETING = 'MARKETING';

    protected $fillable = [
        'nome', 'provider', 'finalidade', 'phone_number_id', 'waba_id', 'display_phone_number', 'verified_name',
        'api_key', 'categorias_permitidas', 'teto_diario', 'msgs_por_minuto', 'janela_inicio',
        'janela_fim', 'fuso', 'aquecimento_inicio', 'aquecimento_teto_inicial', 'aquecimento_fator',
        'sonda_intervalo_minutos', 'ativo',
    ];

    protected $hidden = ['api_key', 'webhook_secret'];

    protected static function booted(): void
    {
        static::creating(function (self $sender): void {
            $sender->webhook_key ??= Str::random(48);
            // Teto que nenhum painel ultrapassa (o incidente foi 650 num dia).
            $sender->teto_diario = min((int) $sender->teto_diario, (int) config('disparo.teto_maximo_absoluto', 1000));
        });

        static::updating(function (self $sender): void {
            $sender->teto_diario = min((int) $sender->teto_diario, (int) config('disparo.teto_maximo_absoluto', 1000));
        });

        // Política alterada no painel vira trilha (de => para), sem segredos.
        static::updated(function (self $sender): void {
            $campos = ['teto_diario', 'msgs_por_minuto', 'janela_inicio', 'janela_fim', 'categorias_permitidas', 'aquecimento_inicio', 'ativo', 'finalidade', 'sonda_intervalo_minutos'];
            $mudancas = [];

            foreach ($campos as $campo) {
                if ($sender->wasChanged($campo)) {
                    $mudancas[$campo] = ['de' => $sender->getOriginal($campo), 'para' => $sender->getAttribute($campo)];
                }
            }

            if ($mudancas !== [] && auth()->check()) {
                SenderEvent::registrar($sender, 'politica_alterada', $mudancas, auth()->id());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'categorias_permitidas' => 'array',
            'saude' => 'array',
            'teto_diario' => 'integer',
            'msgs_por_minuto' => 'integer',
            'aquecimento_inicio' => 'date',
            'aquecimento_teto_inicial' => 'integer',
            'aquecimento_fator' => 'float',
            'pausado_em' => 'datetime',
            'sonda_em' => 'datetime',
            'sonda_intervalo_minutos' => 'integer',
            'bloqueio_qualidade_em' => 'datetime',
            'sincronizado_em' => 'datetime',
            'templates_sincronizados_em' => 'datetime',
            'historico_importado_ate' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    /** @return array<string, string> */
    public static function provedores(): array
    {
        return [self::PROVIDER_KAPSO => 'Kapso (WhatsApp oficial)', self::PROVIDER_META => 'Meta Cloud API (token direto)'];
    }

    /** @return array<string, string> */
    public static function finalidades(): array
    {
        return [
            self::FINALIDADE_COMERCIAL => 'Comercial (ofertas e recuperação)',
            self::FINALIDADE_AVISOS => 'Avisos (só notifica)',
            self::FINALIDADE_KYC => 'KYC (régua própria no repo do KYC)',
            self::FINALIDADE_TESTE => 'Teste / Sandbox',
        ];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function qualitySnapshots(): HasMany
    {
        return $this->hasMany(SenderQualitySnapshot::class);
    }

    /** A credencial do número: a própria, ou a do .env do provedor. */
    public function credencial(): string
    {
        $propria = trim((string) $this->api_key);

        if ($propria !== '') {
            return $propria;
        }

        return trim((string) match ($this->provider) {
            self::PROVIDER_KAPSO => config('disparo.provedores.kapso.api_key'),
            self::PROVIDER_META => config('disparo.provedores.meta.token'),
            default => '',
        });
    }

    /** @return list<string> */
    public function categoriasPermitidas(): array
    {
        $categorias = array_values(array_filter(array_map(
            fn ($c): string => strtoupper(trim((string) $c)),
            (array) ($this->categorias_permitidas ?? []),
        )));

        return $categorias === [] ? [self::CATEGORIA_UTILITY] : $categorias;
    }

    public function permiteCategoria(?string $categoria): bool
    {
        return in_array(strtoupper((string) $categoria), $this->categoriasPermitidas(), true);
    }

    public function pausado(): bool
    {
        return $this->pausado_em !== null || $this->bloqueio_qualidade_em !== null || ! $this->ativo;
    }

    /** Bloqueio por qualidade (131048, 368, 131031, RED, engajamento): sem sonda; só humano religa. */
    public function bloqueadoPorQualidade(): bool
    {
        return $this->bloqueio_qualidade_em !== null;
    }

    /** Conta impedida com a sonda vencida: a próxima rodada manda 1 envio para testar. */
    public function sondaDevida(): bool
    {
        return $this->pausado_em !== null
            && ! $this->bloqueadoPorQualidade()
            && $this->ativo
            && $this->sonda_em !== null
            && $this->sonda_em->lte(now());
    }

    /** Disjuntor "conta impedida": pausa e agenda a sonda. */
    public function impedir(string $motivo): void
    {
        $this->forceFill([
            'pausado_em' => now(),
            'pausado_motivo' => mb_substr($motivo, 0, 500),
            'sonda_em' => now()->addMinutes(max(5, (int) $this->sonda_intervalo_minutos)),
        ])->save();
    }

    /** Sonda que esbarrou de novo na conta: reagenda. */
    public function reagendarSonda(): void
    {
        $this->forceFill(['sonda_em' => now()->addMinutes(max(5, (int) $this->sonda_intervalo_minutos))])->save();
    }

    /** Disjuntor "bloqueio por qualidade": pausa sem sonda. */
    public function bloquearPorQualidade(string $motivo): void
    {
        $this->forceFill([
            'bloqueio_qualidade_em' => now(),
            'bloqueio_qualidade_motivo' => mb_substr($motivo, 0, 500),
            'sonda_em' => null,
        ])->save();
    }

    /** Humano religou: o aquecimento recomeça do zero (o número voltou de um sinal da Meta). */
    public function religar(bool $reaquecer = true): void
    {
        $this->forceFill([
            'pausado_em' => null,
            'pausado_motivo' => null,
            'sonda_em' => null,
            'bloqueio_qualidade_em' => null,
            'bloqueio_qualidade_motivo' => null,
            'aquecimento_inicio' => $reaquecer ? $this->agora()->toDateString() : $this->aquecimento_inicio,
        ])->save();
    }

    /** Limite do tier da Meta (TIER_250, TIER_1K, TIER_10K...) em conversas/24h; null = desconhecido ou ilimitado. */
    public function limiteDoTier(): ?int
    {
        $tier = strtoupper((string) $this->limite_tier);

        if ($tier === '' || str_contains($tier, 'UNLIMITED')) {
            return null;
        }

        if (preg_match('/(\d+)\s*(K)?$/', $tier, $m) === 1) {
            return (int) $m[1] * (isset($m[2]) && $m[2] === 'K' ? 1000 : 1);
        }

        return null;
    }

    /** Agora, no fuso do remetente. */
    public function agora(): CarbonImmutable
    {
        return CarbonImmutable::now($this->fuso ?: config('disparo.fuso'));
    }

    /** Dentro da janela de horário (inclusive o início, exclusive o fim). */
    public function dentroDaJanela(?CarbonInterface $momento = null): bool
    {
        $agora = ($momento ? CarbonImmutable::instance($momento) : $this->agora())->setTimezone($this->fuso ?: config('disparo.fuso'));
        $hora = $agora->format('H:i');

        [$inicio, $fim] = [$this->janela_inicio ?: '00:00', $this->janela_fim ?: '23:59'];

        return $inicio <= $fim ? ($hora >= $inicio && $hora < $fim) : ($hora >= $inicio || $hora < $fim);
    }

    /** Próximo começo de janela, no fuso do remetente (para `proxima_tentativa_em`). */
    public function proximaAberturaDaJanela(): CarbonImmutable
    {
        $agora = $this->agora();
        [$h, $m] = array_map('intval', explode(':', $this->janela_inicio ?: '00:00'));
        $hoje = $agora->setTime($h, $m);

        return $this->dentroDaJanela($agora) ? $agora : ($hoje->gt($agora) ? $hoje : $hoje->addDay());
    }

    /**
     * Teto diário efetivo = min(configurado, aquecimento, fração do tier da Meta), cortado pela metade em
     * YELLOW. RED ou bloqueio por qualidade = 0. O aquecimento só sobe nos dias em que o número esteve
     * GREEN (medido nos snapshots); dia sem leitura não sobe.
     */
    public function tetoEfetivo(): int
    {
        if ($this->qualidade === self::QUALIDADE_RED || $this->bloqueadoPorQualidade()) {
            return 0;
        }

        $teto = max(0, (int) $this->teto_diario);

        if ($this->aquecimento_inicio !== null) {
            $dias = $this->diasDeAquecimentoConcluidos();
            $inicial = $this->aquecimento_teto_inicial ?? (int) config('disparo.remetente.aquecimento_teto_inicial', 50);
            $fator = $this->aquecimento_fator ?? (float) config('disparo.remetente.aquecimento_fator', 1.5);
            $teto = min($teto, (int) floor($inicial * ($fator ** $dias)));
        }

        if (($tier = $this->limiteDoTier()) !== null) {
            $teto = min($teto, (int) floor($tier * (float) config('disparo.fila.fracao_do_tier', 0.8)));
        }

        if ($this->qualidade === self::QUALIDADE_YELLOW) {
            $teto = (int) floor($teto * (float) config('disparo.remetente.fator_yellow', 0.5));
        }

        return $teto;
    }

    /** Gera (ou troca) o segredo do webhook; quem chama registra na Kapso e na trilha. */
    public function rotacionarSegredoDoWebhook(): string
    {
        $segredo = Str::random(48);
        $this->forceFill(['webhook_secret' => $segredo, 'webhook_key' => $this->webhook_key ?: Str::random(48)])->save();

        return $segredo;
    }

    /** Dias com leitura GREEN desde o início do aquecimento (sem contar hoje). */
    public function diasDeAquecimentoConcluidos(): int
    {
        if ($this->aquecimento_inicio === null) {
            return 0;
        }

        $hoje = $this->agora()->toDateString();

        return (int) $this->qualitySnapshots()
            ->where('qualidade', self::QUALIDADE_GREEN)
            ->where('medido_em', '>=', $this->aquecimento_inicio->startOfDay())
            ->get(['medido_em'])
            ->map(fn (SenderQualitySnapshot $s) => $s->medido_em?->setTimezone($this->fuso ?: config('disparo.fuso'))->toDateString())
            ->filter(fn (?string $dia): bool => $dia !== null && $dia < $hoje)
            ->unique()
            ->count();
    }

    /** Teto de MARKETING por dia: fração do teto efetivo (KB 2.11). */
    public function tetoDeMarketing(): int
    {
        return (int) floor($this->tetoEfetivo() * (float) config('disparo.fila.fracao_marketing', 0.3));
    }

    /**
     * Templates que saíram por este número nas últimas 24 h (janela móvel), em qualquer origem - inclusive
     * o que o webhook viu sair pelo painel da Kapso ou pelo KYC (`origem=webhook`) e o histórico importado.
     */
    public function templatesNasUltimas24h(?string $categoria = null, ?string $origem = null): int
    {
        return (int) $this->messages()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
            ->where('enviado_em', '>=', now()->subDay())
            ->when($categoria !== null, fn ($q) => $q->where('payload->categoria', strtoupper($categoria)))
            ->when($origem !== null, fn ($q) => $q->where('origem', $origem))
            ->count();
    }

    public function pausar(string $motivo): void
    {
        $this->forceFill(['pausado_em' => now(), 'pausado_motivo' => mb_substr($motivo, 0, 500)])->save();
    }

    /** Sonda passou (conta impedida resolvida) ou pausa manual desfeita. Não mexe no bloqueio por qualidade. */
    public function despausar(): void
    {
        $this->forceFill(['pausado_em' => null, 'pausado_motivo' => null, 'sonda_em' => null])->save();
    }

    public function rotulo(): string
    {
        return $this->nome.($this->display_phone_number ? ' ('.$this->display_phone_number.')' : '');
    }
}
