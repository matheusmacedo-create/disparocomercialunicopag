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

        if ($template !== null) {
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
        }

        if (! $simulacao && ! $sender->dentroDaJanela()) {
            return self::FORA_DA_JANELA;
        }

        if (! $simulacao && $sender->templatesNasUltimas24h() >= $sender->tetoEfetivo()) {
            return self::TETO_DIARIO;
        }

        return null;
    }

    /**
     * O que impede o envio a ESTE contato por este remetente (null = pode).
     *
     * @param  array<string, mixed>  $contexto  `regras` da campanha (ignorar_conversa_aberta, dias_da_semana),
     *                                          `exige_consentimento` (bool), `objetivo` (Campaign::OBJETIVO_*),
     *                                          `origem` (ORIGEM_CAMPANHA|ORIGEM_REGUA), `simulacao` (bool)
     */
    public static function motivoDoContato(Contact $contato, Sender $sender, ?MessageTemplate $template, array $contexto = []): ?string
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

        if ($marketing && ($contexto['exige_consentimento'] ?? false || $contato->origemExterna()) && ! $contato->temConsentimento()) {
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

        if (! $ignoraConversaAberta && self::conversaAberta($contato, $sender)) {
            return self::CONVERSA_ABERTA;
        }

        if (self::frequenciaEstourada($contato, $sender, $marketing, (string) ($contexto['origem'] ?? self::ORIGEM_CAMPANHA))) {
            return self::FREQUENCIA;
        }

        return null;
    }

    /** Template do mesmo remetente ao telefone nos últimos N dias, que a Meta não disse ter falhado. */
    public static function conversaAberta(Contact $contato, Sender $sender): bool
    {
        return self::templates($contato, $sender->id)
            ->where('enviado_em', '>=', now()->subDays((int) config('disparo.guardas.conversa_aberta_dias', 7)))
            ->exists();
    }

    /**
     * MARKETING: por telefone em TODOS os remetentes (1/30 d e 3/90 d). Régua: intervalo mínimo entre
     * templates da régua e total em 30 d. Campanha UTILITY: N por remetente em 30 d.
     */
    public static function frequenciaEstourada(Contact $contato, Sender $sender, bool $marketing, string $origem = self::ORIGEM_CAMPANHA): bool
    {
        if ($marketing) {
            $max30 = (int) config('disparo.guardas.marketing_max_30d', 1);
            $max90 = (int) config('disparo.guardas.marketing_max_90d', 3);

            return ($max30 > 0 && self::templates($contato, null)->where('payload->categoria', Sender::CATEGORIA_MARKETING)->where('enviado_em', '>=', now()->subDays(30))->count() >= $max30)
                || ($max90 > 0 && self::templates($contato, null)->where('payload->categoria', Sender::CATEGORIA_MARKETING)->where('enviado_em', '>=', now()->subDays(90))->count() >= $max90);
        }

        if ($origem === self::ORIGEM_REGUA) {
            $minDias = (int) config('disparo.guardas.regua_min_dias', 5);
            $max30 = (int) config('disparo.guardas.regua_max_30d', 4);

            return ($minDias > 0 && self::templates($contato, $sender->id)->where('origem', WhatsappMessage::ORIGEM_REGUA)->where('enviado_em', '>=', now()->subDays($minDias))->exists())
                || ($max30 > 0 && self::templates($contato, $sender->id)->where('enviado_em', '>=', now()->subDays(30))->count() >= $max30);
        }

        $max = (int) config('disparo.guardas.frequencia_max', 2);
        $dias = (int) config('disparo.guardas.frequencia_janela_dias', 30);

        return $max > 0 && self::templates($contato, $sender->id)->where('enviado_em', '>=', now()->subDays($dias))->count() >= $max;
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
