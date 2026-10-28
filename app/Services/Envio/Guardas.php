<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Elegibilidade do envio (KB 5): o primeiro motivo vale. "Do remetente" vale para a rodada inteira
 * (pausa, qualidade, template, janela, teto); "do contato" vale para um destinatário.
 *
 * O interruptor global (DISPARO_ENVIO_HABILITADO) NÃO é guarda: a prévia e a execução avaliam as mesmas
 * regras, e com ele desligado o executor marca `simulado` em vez de enviar. Assim a prévia mostra o que
 * sairia de verdade (achado da revisão de 28/10/2026).
 */
final class Guardas
{
    public const REMETENTE_PAUSADO = 'remetente_pausado';

    public const QUALIDADE_RED = 'qualidade_red';

    public const FORA_DA_JANELA = 'fora_da_janela';

    public const TETO_DIARIO = 'teto_diario';

    public const CONTATO_INVALIDO = 'contato_invalido';

    public const OPTOUT = 'optout';

    public const SUPRIMIDO = 'suprimido';

    public const CATEGORIA_NAO_PERMITIDA = 'categoria_nao_permitida';

    public const TEMPLATE_NAO_APROVADO = 'template_nao_aprovado';

    public const TEMPLATE_QUALIDADE = 'template_qualidade';

    public const SEM_WHATSAPP = 'sem_whatsapp';

    public const SEM_CONSENTIMENTO = 'sem_consentimento';

    public const MARKETING_CARENCIA = 'marketing_carencia';

    public const RECUSOU = 'recusou';

    public const PROVAVEL_BLOQUEIO = 'provavel_bloqueio';

    public const CONVERSA_ABERTA = 'conversa_aberta';

    public const FREQUENCIA = 'frequencia';

    public const DUPLICADO = 'duplicado';

    public const VARIAVEL_VAZIA = 'variavel_vazia';

    public const DIA_DA_SEMANA = 'dia_da_semana';

    public const FORA_DA_JANELA_CONTATO = 'fora_da_janela_contato';

    /** Motivos que só adiam (a rodada volta a tentar), não descartam o destinatário. */
    public const MOTIVOS_QUE_ADIAM = [
        self::FORA_DA_JANELA, self::TETO_DIARIO, self::REMETENTE_PAUSADO, self::QUALIDADE_RED,
        self::DIA_DA_SEMANA, self::FORA_DA_JANELA_CONTATO,
    ];

    /** Origem do envio para a regra de frequência. */
    public const ORIGEM_CAMPANHA = 'campanha';

    public const ORIGEM_REGUA = 'regua';

    /**
     * Histórico de um telefone sem nenhuma mensagem (o que {@see self::historicoPorTelefone()} devolve
     * para quem não aparece no resultado). `null` em `$historico` significa "consulte o banco".
     */
    public const HISTORICO_VAZIO = ['ultimo_template_em' => null, 'regua_ultimo_em' => null, 'templates_30d' => 0, 'marketing_30d' => 0, 'marketing_90d' => 0];

    /**
     * O que impede a RODADA de um remetente agora (null = pode rodar). Na prévia (`$simulacao`) janela e
     * teto não contam: ela diz o que sairia quando a janela abrir.
     */
    public static function motivoDoRemetente(Sender $sender, ?MessageTemplate $template = null, bool $simulacao = false): ?string
    {
        if ($sender->pausado()) {
            return self::REMETENTE_PAUSADO;
        }

        if ($sender->qualidade === Sender::QUALIDADE_RED) {
            return self::QUALIDADE_RED;
        }

        if ($template !== null && ($motivo = self::motivoDoTemplate($sender, $template)) !== null) {
            return $motivo;
        }

        if (! $simulacao && ! $sender->dentroDaJanela()) {
            return self::FORA_DA_JANELA;
        }

        if (! $simulacao && $sender->templatesNasUltimas24h() >= $sender->tetoEfetivo()) {
            return self::TETO_DIARIO;
        }

        return null;
    }

    /** O que impede ESTE template de sair por este remetente, independente do contato (null = pode). */
    public static function motivoDoTemplate(Sender $sender, MessageTemplate $template): ?string
    {
        if (! $template->aprovado()) {
            return self::TEMPLATE_NAO_APROVADO;
        }

        if (! $sender->permiteCategoria($template->categoria)) {
            return self::CATEGORIA_NAO_PERMITIDA;
        }

        // Template com qualidade baixa na Meta (quality_score YELLOW/RED) não sai como MARKETING:
        // insistir nele é o que pausa o template (132015) e depois o número.
        if (self::marketing($template) && in_array(strtoupper((string) (($template->qualidade ?? [])['score'] ?? '')), ['YELLOW', 'RED'], true)) {
            return self::TEMPLATE_QUALIDADE;
        }

        return null;
    }

    /**
     * O que impede o envio a ESTE contato por este remetente (null = pode).
     *
     * @param  array<string, mixed>  $contexto  `regras` da campanha (ignorar_conversa_aberta, dias_da_semana),
     *                                          `exige_consentimento` (bool), `objetivo` (Campaign::OBJETIVO_*),
     *                                          `origem` (ORIGEM_CAMPANHA|ORIGEM_REGUA), `simulacao` (bool),
     *                                          `intervalo_minimo_horas` (régua: horas entre templates dela ao
     *                                          telefone, no lugar de `regua_min_dias`)
     * @param  array<string, mixed>|null  $historico  o histórico do telefone já carregado em lote
     *                                                ({@see self::historicoPorTelefone()}); null = consulta o banco
     */
    public static function motivoDoContato(Contact $contato, Sender $sender, ?MessageTemplate $template, array $contexto = [], ?array $historico = null): ?string
    {
        $regras = (array) ($contexto['regras'] ?? []);
        $simulacao = (bool) ($contexto['simulacao'] ?? false);
        $marketing = self::marketing($template);
        $objetivoComercial = in_array($contexto['objetivo'] ?? null, [Campaign::OBJETIVO_RECUPERAR, Campaign::OBJETIVO_OFERTAR], true);

        if ($contato->status === Contact::STATUS_OPTOUT) {
            return self::OPTOUT;
        }

        if (! $contato->ativo()) {
            return self::CONTATO_INVALIDO;
        }

        // Objetivo comercial nunca contorna a preferência de marketing com template de utilidade (KB 2.4).
        if (Suppression::bloqueia($contato->telefone, $sender, ($marketing || $objetivoComercial) ? Sender::CATEGORIA_MARKETING : $template?->categoria) !== null) {
            return self::SUPRIMIDO;
        }

        // Base que exige consentimento OU contato de origem externa (UnicoDrop, CSV): MARKETING só com opt-in
        // registrado em nome da ÚnicoPag (KB 2.13).
        if ($marketing && ((bool) ($contexto['exige_consentimento'] ?? false) || $contato->origemExterna()) && ! $contato->temConsentimento()) {
            return self::SEM_CONSENTIMENTO;
        }

        if ($contato->semWhatsAppRecente()) {
            return self::SEM_WHATSAPP;
        }

        if ($marketing && $contato->provavelBloqueio()) {
            return self::PROVAVEL_BLOQUEIO;
        }

        if (($marketing || $objetivoComercial) && $contato->emCarenciaDeMarketing()) {
            return self::MARKETING_CARENCIA;
        }

        if (($marketing || $objetivoComercial) && $contato->recusouRecentemente()) {
            return self::RECUSOU;
        }

        if (! $simulacao) {
            $dias = array_values(array_filter(array_map('intval', (array) ($regras['dias_da_semana'] ?? []))));

            if ($dias !== [] && ! in_array((int) CarbonImmutable::now($contato->fuso())->isoWeekday(), $dias, true)) {
                return self::DIA_DA_SEMANA;
            }

            if ($contato->fuso() !== ($sender->fuso ?: config('disparo.fuso')) && ! $sender->dentroDaJanela(CarbonImmutable::now($contato->fuso())->setTimezone($sender->fuso ?: config('disparo.fuso'))->addSeconds(self::diferencaDeFuso($contato->fuso(), $sender->fuso ?: config('disparo.fuso'))))) {
                return self::FORA_DA_JANELA_CONTATO;
            }
        }

        $utility = strtoupper((string) $template?->categoria) === Sender::CATEGORIA_UTILITY;
        $ignoraConversaAberta = $utility && (bool) ($regras['ignorar_conversa_aberta'] ?? false);

        if (! $ignoraConversaAberta && self::conversaAberta($contato, $sender, $historico)) {
            return self::CONVERSA_ABERTA;
        }

        $intervalo = isset($contexto['intervalo_minimo_horas']) ? (int) $contexto['intervalo_minimo_horas'] : null;

        if (self::frequenciaEstourada($contato, $sender, $marketing, (string) ($contexto['origem'] ?? self::ORIGEM_CAMPANHA), $intervalo, $historico)) {
            return self::FREQUENCIA;
        }

        return null;
    }

    /**
     * Template do mesmo remetente ao telefone nos últimos N dias, que a Meta não disse ter falhado.
     *
     * @param  array<string, mixed>|null  $historico  ver {@see self::historicoPorTelefone()}; null = consulta
     */
    public static function conversaAberta(Contact $contato, Sender $sender, ?array $historico = null): bool
    {
        $desde = now()->subDays((int) config('disparo.guardas.conversa_aberta_dias', 7));

        if ($historico !== null) {
            return self::momento($historico['ultimo_template_em'] ?? null)?->gte($desde) ?? false;
        }

        return self::templates($contato, $sender->id)
            ->where('enviado_em', '>=', $desde)
            ->exists();
    }

    /**
     * MARKETING: por telefone em TODOS os remetentes (1/30 d e 3/90 d). Régua: intervalo mínimo entre
     * templates da régua ao telefone (`$intervaloMinimoHoras` da régua; sem ele, `regua_min_dias`) e total
     * em 30 d. Campanha UTILITY: N por remetente em 30 d.
     *
     * @param  array<string, mixed>|null  $historico  ver {@see self::historicoPorTelefone()}; null = consulta
     */
    public static function frequenciaEstourada(Contact $contato, Sender $sender, bool $marketing, string $origem = self::ORIGEM_CAMPANHA, ?int $intervaloMinimoHoras = null, ?array $historico = null): bool
    {
        if ($marketing) {
            $max30 = (int) config('disparo.guardas.marketing_max_30d', 1);
            $max90 = (int) config('disparo.guardas.marketing_max_90d', 3);

            if ($historico !== null) {
                return ($max30 > 0 && (int) ($historico['marketing_30d'] ?? 0) >= $max30)
                    || ($max90 > 0 && (int) ($historico['marketing_90d'] ?? 0) >= $max90);
            }

            return ($max30 > 0 && self::templates($contato, null)->where('payload->categoria', Sender::CATEGORIA_MARKETING)->where('enviado_em', '>=', now()->subDays(30))->count() >= $max30)
                || ($max90 > 0 && self::templates($contato, null)->where('payload->categoria', Sender::CATEGORIA_MARKETING)->where('enviado_em', '>=', now()->subDays(90))->count() >= $max90);
        }

        if ($origem === self::ORIGEM_REGUA) {
            $max30 = (int) config('disparo.guardas.regua_max_30d', 4);
            // Intervalo mínimo da própria régua (horas) ou o padrão global em dias. Estrito (">"): o passo
            // "aguardar 48 h" seguido de template avaliado exatamente 48 h depois tem de passar.
            $desde = $intervaloMinimoHoras !== null
                ? ($intervaloMinimoHoras > 0 ? now()->subHours($intervaloMinimoHoras) : null)
                : (((int) config('disparo.guardas.regua_min_dias', 5)) > 0 ? now()->subDays((int) config('disparo.guardas.regua_min_dias', 5)) : null);

            if ($historico !== null) {
                return ($desde !== null && (self::momento($historico['regua_ultimo_em'] ?? null)?->gt($desde) ?? false))
                    || ($max30 > 0 && (int) ($historico['templates_30d'] ?? 0) >= $max30);
            }

            return ($desde !== null && self::templates($contato, $sender->id)->where('origem', WhatsappMessage::ORIGEM_REGUA)->where('enviado_em', '>', $desde)->exists())
                || ($max30 > 0 && self::templates($contato, $sender->id)->where('enviado_em', '>=', now()->subDays(30))->count() >= $max30);
        }

        $max = (int) config('disparo.guardas.frequencia_max', 2);
        $dias = (int) config('disparo.guardas.frequencia_janela_dias', 30);

        if ($historico !== null) {
            return $max > 0 && (int) ($historico['templates_30d'] ?? 0) >= $max;
        }

        return $max > 0 && self::templates($contato, $sender->id)->where('enviado_em', '>=', now()->subDays($dias))->count() >= $max;
    }

    /**
     * O histórico que as guardas de conversa aberta e frequência precisam, para um lote de telefones, em
     * três consultas agrupadas em vez de três por contato (a prévia de 10 mil contatos fazia 30 mil). Chave
     * = telefone E.164 como está em `whatsapp_messages.telefone`; quem não aparece não tem histórico
     * ({@see self::HISTORICO_VAZIO}).
     *
     * @param  list<string>  $telefones
     * @return array<string, array{ultimo_template_em: ?string, regua_ultimo_em: ?string, templates_30d: int, marketing_30d: int, marketing_90d: int}>
     */
    public static function historicoPorTelefone(Sender $sender, array $telefones): array
    {
        $telefones = array_values(array_unique(array_filter($telefones, fn ($t): bool => is_string($t) && $t !== '')));

        if ($telefones === []) {
            return [];
        }

        $historico = [];
        $ha30 = now()->subDays(30)->toDateTimeString();
        $base = fn (?int $senderId) => WhatsappMessage::query()
            ->when($senderId !== null, fn ($q) => $q->where('sender_id', $senderId))
            ->whereIn('telefone', $telefones)
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED);

        // Do remetente: último template, último da régua e quantos em 30 d.
        $doRemetente = $base($sender->id)
            ->selectRaw('telefone, max(enviado_em) as ultimo, max(case when origem = ? then enviado_em else null end) as regua_ultimo, sum(case when enviado_em >= ? then 1 else 0 end) as n30', [WhatsappMessage::ORIGEM_REGUA, $ha30])
            ->groupBy('telefone')
            ->get();

        foreach ($doRemetente as $linha) {
            $historico[(string) $linha->telefone] = self::HISTORICO_VAZIO;
            $historico[(string) $linha->telefone]['ultimo_template_em'] = $linha->ultimo !== null ? (string) $linha->ultimo : null;
            $historico[(string) $linha->telefone]['regua_ultimo_em'] = $linha->regua_ultimo !== null ? (string) $linha->regua_ultimo : null;
            $historico[(string) $linha->telefone]['templates_30d'] = (int) $linha->n30;
        }

        // MARKETING em todos os remetentes (a mesma empresa): 30 e 90 d.
        $marketing = $base(null)
            ->where('payload->categoria', Sender::CATEGORIA_MARKETING)
            ->where('enviado_em', '>=', now()->subDays(90))
            ->selectRaw('telefone, sum(case when enviado_em >= ? then 1 else 0 end) as n30, count(*) as n90', [$ha30])
            ->groupBy('telefone')
            ->get();

        foreach ($marketing as $linha) {
            $historico[(string) $linha->telefone] ??= self::HISTORICO_VAZIO;
            $historico[(string) $linha->telefone]['marketing_30d'] = (int) $linha->n30;
            $historico[(string) $linha->telefone]['marketing_90d'] = (int) $linha->n90;
        }

        return $historico;
    }

    private static function momento(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $valor instanceof \DateTimeInterface ? CarbonImmutable::instance($valor) : CarbonImmutable::parse((string) $valor);
    }

    public static function adia(?string $motivo): bool
    {
        return $motivo !== null && in_array($motivo, self::MOTIVOS_QUE_ADIAM, true);
    }

    public static function marketing(?MessageTemplate $template): bool
    {
        return strtoupper((string) $template?->categoria) === Sender::CATEGORIA_MARKETING;
    }

    /** Templates que saíram ao telefone (todas as formas) e que a Meta não disse ter falhado. */
    private static function templates(Contact $contato, ?int $senderId): Builder
    {
        return WhatsappMessage::query()
            ->when($senderId !== null, fn ($q) => $q->where('sender_id', $senderId))
            ->where('telefone', $contato->telefone)
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED);
    }

    private static function diferencaDeFuso(string $fusoContato, string $fusoRemetente): int
    {
        $agora = CarbonImmutable::now();

        return $agora->setTimezone($fusoContato)->getOffset() - $agora->setTimezone($fusoRemetente)->getOffset();
    }
}
