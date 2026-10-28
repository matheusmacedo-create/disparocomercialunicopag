<?php

declare(strict_types=1);

namespace App\Services\Reguas;

use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\Segment;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Models\WhatsappMessage;
use App\Services\DiscordNotifier;
use App\Services\Envio\Despachante;
use App\Services\Envio\Disjuntor;
use App\Services\Envio\Guardas;
use App\Services\Envio\TravaDoRemetente;
use App\Services\Segmentos\ConsultaDeSegmento;
use App\Support\Variaveis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Uma rodada das réguas de nutrição (KB 4.9, 8): a cada 10 min (`disparo:processar-reguas`), para cada
 * régua ativa, as inscrições com `proximo_em` vencido, remetente a remetente sob a MESMA trava de quem
 * manda campanha ({@see TravaDoRemetente}) - remetente ocupado é pulado nesta rodada.
 *
 * O que a rodada respeita, nesta ordem: o interruptor global (em simulação a régua não roda - a régua é a
 * mensagem esperada, não há prévia a tirar dela), pausa/qualidade/janela/teto do remetente
 * ({@see Guardas::motivoDoRemetente()}), o orçamento do número (teto efetivo menos o que já saiu; a reserva
 * das réguas é delas, por isso não é descontada), o teto de MARKETING, o `max_por_dia` da régua, e por
 * contato as guardas de sempre com origem `regua` (intervalo mínimo da régua no lugar da frequência de
 * campanha; conversa aberta ignorada em UTILITY quando a régua pede).
 *
 * Passos: `template` envia (guarda que adia → `proximo_em` na próxima abertura; guarda que descarta → o
 * passo é pulado e fica registrado em `passos_pulados`); `aguardar` marca `proximo_em`; `condicao` olha o
 * último template da inscrição e a última resposta do contato; `tag` anexa; `texto` manda texto livre só
 * com resposta do contato nas últimas 24 h (uma vez); `encerrar`/fim → concluída. `passo_atual` aponta
 * sempre para o PRÓXIMO passo a executar.
 *
 * Recusa de conta ou de qualidade passa pelo mesmo {@see Disjuntor} das campanhas e para a rodada.
 */
final class ProcessadorDeReguas
{
    public const PAROU_TEMPO = 'tempo_da_rodada';

    public const PAROU_TETO = 'teto_diario';

    public function __construct(
        private readonly Despachante $despachante,
        private readonly Disjuntor $disjuntor,
        private readonly DiscordNotifier $discord,
    ) {}

    /**
     * @return array{simulacao: bool, reguas: int, inscricoes: int, enviados: int, incertos: int, textos: int, pulados: int, adiados: int, aguardando: int, concluidas: int, encerradas: int, falhas: int, inscricoes_tags: int, remetentes_pulados: array<int, string>, reguas_no_teto: list<int>, parou: ?string}
     */
    public function rodar(?Journey $apenas = null, ?int $tempoMaximoSegundos = null): array
    {
        $resultado = [
            'simulacao' => false, 'reguas' => 0, 'inscricoes' => 0, 'enviados' => 0, 'incertos' => 0, 'textos' => 0,
            'pulados' => 0, 'adiados' => 0, 'aguardando' => 0, 'concluidas' => 0, 'encerradas' => 0, 'falhas' => 0,
            'inscricoes_tags' => 0, 'remetentes_pulados' => [], 'reguas_no_teto' => [], 'parou' => null,
        ];

        $reguas = Journey::query()
            ->where('status', Journey::STATUS_ATIVA)
            ->where('ativa', true)
            ->when($apenas !== null, fn (Builder $q) => $q->whereKey($apenas->id))
            ->with('sender')
            ->orderBy('id')
            ->get();

        $resultado['reguas'] = $reguas->count();

        if (! config('disparo.envio_habilitado')) {
            // Régua só roda com o interruptor ligado: não há "prévia" de régua, e marcar passos como
            // simulados avançaria a inscrição sem nada ter chegado ao contato.
            $vencidas = (int) JourneyEnrollment::query()->whereIn('journey_id', $reguas->pluck('id'))->where('status', JourneyEnrollment::STATUS_ATIVA)->where('proximo_em', '<=', now())->count();
            Log::info('Réguas em simulação (DISPARO_ENVIO_HABILITADO=false): nenhuma inscrição é processada', ['reguas_ativas' => $reguas->count(), 'inscricoes_vencidas' => $vencidas]);
            $resultado['simulacao'] = true;
            $resultado['inscricoes'] = $vencidas;

            return $resultado;
        }

        $inicio = microtime(true);
        $tempoMaximo = $tempoMaximoSegundos ?? (int) config('disparo.reguas.tempo_maximo_segundos', 540);

        foreach ($reguas->groupBy('sender_id') as $senderId => $doRemetente) {
            $sender = $doRemetente->first()?->sender;

            if ($sender === null) {
                continue;
            }

            if ((microtime(true) - $inicio) > $tempoMaximo) {
                $resultado['parou'] = self::PAROU_TEMPO;
                break;
            }

            $rodada = TravaDoRemetente::executar($sender, fn (): array => $this->processarRemetente($sender->fresh() ?? $sender, $doRemetente, $resultado, $inicio, $tempoMaximo));

            if ($rodada === false) {
                $resultado['remetentes_pulados'][(int) $senderId] = TravaDoRemetente::EM_ANDAMENTO;

                continue;
            }

            $resultado = $rodada;

            if ($resultado['parou'] === self::PAROU_TEMPO) {
                break;
            }

            // Disjuntor ou teto pararam ESTE remetente; os outros seguem (o motivo fica no resumo).
            if ($resultado['parou'] !== null) {
                $resultado['remetentes_pulados'][(int) $senderId] = $resultado['parou'];
            }
        }

        Log::info('Rodada das réguas', array_diff_key($resultado, ['simulacao' => true]));

        return $resultado;
    }

    /**
     * Inscrição por gatilho (observers de base e tag): réguas ativas com `gatilho` e `gatilho_ref` iguais
     * ao evento. Só contato ativo entra. Devolve quantas inscrições novas.
     */
    public function inscreverPorGatilho(string $gatilho, int $referencia, int $contactId): int
    {
        if ($referencia <= 0 || $contactId <= 0) {
            return 0;
        }

        $reguas = Journey::query()
            ->where('status', Journey::STATUS_ATIVA)
            ->where('ativa', true)
            ->where('gatilho', $gatilho)
            ->where('gatilho_ref', $referencia)
            ->get();

        if ($reguas->isEmpty()) {
            return 0;
        }

        $contato = Contact::query()->find($contactId);

        if ($contato === null || ! $contato->ativo()) {
            return 0;
        }

        $novas = 0;

        foreach ($reguas as $journey) {
            if ($journey->inscrever($contato, $gatilho) !== null) {
                $novas++;
            }
        }

        return $novas;
    }

    /**
     * Gatilho `segmento` (uma vez por dia): inscreve quem bate no segmento e não tem inscrição ativa; quem
     * já passou pela régua só volta depois de `reinscrever_apos_dias` ({@see Journey::inscrever()}).
     *
     * @return array{reguas: int, inscritos: int, por_regua: array<int, int>}
     */
    public function reavaliarSegmentos(?Journey $apenas = null): array
    {
        $resultado = ['reguas' => 0, 'inscritos' => 0, 'por_regua' => []];

        $reguas = Journey::query()
            ->where('status', Journey::STATUS_ATIVA)
            ->where('ativa', true)
            ->where('gatilho', Journey::GATILHO_SEGMENTO)
            ->when($apenas !== null, fn (Builder $q) => $q->whereKey($apenas->id))
            ->orderBy('id')
            ->get();

        foreach ($reguas as $journey) {
            $segmento = Segment::query()->find((int) $journey->gatilho_ref);

            if ($segmento === null) {
                Log::warning('Régua com gatilho de segmento que não existe mais', ['journey_id' => $journey->id, 'segment_id' => $journey->gatilho_ref]);

                continue;
            }

            $resultado['reguas']++;
            $resultado['por_regua'][$journey->id] = 0;

            // Quem está ativo na régua fica fora da consulta; quem concluiu e não pode voltar também.
            $candidatos = ConsultaDeSegmento::doSegmento($segmento)
                ->whereDoesntHave('journeyEnrollments', fn (Builder $q) => $q->where('journey_id', $journey->id)->where('status', JourneyEnrollment::STATUS_ATIVA));

            if ($journey->reinscrever_apos_dias === null) {
                $candidatos->whereDoesntHave('journeyEnrollments', fn (Builder $q) => $q->where('journey_id', $journey->id));
            }

            $candidatos->orderBy('contacts.id')->chunkById(500, function (Collection $contatos) use ($journey, &$resultado): void {
                foreach ($contatos as $contato) {
                    if ($journey->inscrever($contato, Journey::GATILHO_SEGMENTO) !== null) {
                        $resultado['inscritos']++;
                        $resultado['por_regua'][$journey->id]++;
                    }
                }
            }, 'contacts.id', 'id');
        }

        return $resultado;
    }

    /**
     * Templates que esta régua mandou nas últimas 24 h (para `max_por_dia`): origem régua, inscrições dela,
     * que a Meta não disse ter falhado.
     */
    public function templatesDaReguaNas24h(Journey $journey): int
    {
        return (int) WhatsappMessage::query()
            ->where('origem', WhatsappMessage::ORIGEM_REGUA)
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
            ->where('enviado_em', '>=', now()->subDay())
            ->whereIn('journey_enrollment_id', JourneyEnrollment::query()->where('journey_id', $journey->id)->select('id'))
            ->count();
    }

    /**
     * Um remetente, todas as réguas dele, sob a trava.
     *
     * @param  Collection<int, Journey>  $reguas
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function processarRemetente(Sender $sender, Collection $reguas, array $resultado, float $inicio, int $tempoMaximo): array
    {
        $sonda = $sender->sondaDevida();
        $motivoRemetente = $sonda ? null : Guardas::motivoDoRemetente($sender);

        if ($motivoRemetente !== null || ($sonda && ! $sender->dentroDaJanela())) {
            $resultado['remetentes_pulados'][$sender->id] = $motivoRemetente ?? Guardas::FORA_DA_JANELA;

            return $resultado;
        }

        // Orçamento do número: teto efetivo menos o que já saiu por ele (qualquer origem). A reserva das
        // réguas (KB 8) é para as réguas - aqui ela não é descontada. Na sonda sai 1.
        $estado = [
            'orcamento' => $sonda ? 1 : max(0, $sender->tetoEfetivo() - $sender->templatesNasUltimas24h()),
            'marketing' => max(0, $sender->tetoDeMarketing() - $sender->templatesNasUltimas24h(Sender::CATEGORIA_MARKETING)),
            'sonda' => $sonda,
            'transitorias' => 0,
            'passos_quebrados' => [],
            'templates' => [],
            'intervalo_ms' => (int) ceil(60000 / max(1, (int) $sender->msgs_por_minuto)),
            'parou' => null,
        ];

        if ($estado['orcamento'] <= 0) {
            $resultado['remetentes_pulados'][$sender->id] = Guardas::TETO_DIARIO;

            return $resultado;
        }

        foreach ($reguas as $journey) {
            $passos = $journey->steps()->get()->values();

            if ($passos->isEmpty()) {
                continue;
            }

            $porDia = (int) $journey->max_por_dia > 0 ? max(0, (int) $journey->max_por_dia - $this->templatesDaReguaNas24h($journey)) : PHP_INT_MAX;

            $inscricoes = JourneyEnrollment::query()
                ->where('journey_id', $journey->id)
                ->where('status', JourneyEnrollment::STATUS_ATIVA)
                ->where('proximo_em', '<=', now())
                ->with('contact')
                ->orderBy('id')
                ->lazyById(200);

            foreach ($inscricoes as $inscricao) {
                if ((microtime(true) - $inicio) > $tempoMaximo) {
                    $resultado['parou'] = self::PAROU_TEMPO;

                    return $resultado;
                }

                $resultado['inscricoes']++;
                $desfecho = $this->processarInscricao($journey, $passos, $inscricao, $sender, $estado, $resultado, $porDia);

                if ($estado['parou'] !== null) {
                    $resultado['parou'] = $estado['parou'];

                    return $resultado;
                }

                if ($desfecho === 'sem_orcamento_regua') {
                    // A régua gastou o `max_por_dia`: as outras inscrições esperam a janela de 24 h rolar.
                    $resultado['reguas_no_teto'][] = $journey->id;
                    break;
                }

                if ($desfecho === 'sem_orcamento') {
                    $resultado['parou'] = self::PAROU_TETO;

                    return $resultado;
                }
            }
        }

        return $resultado;
    }

    /**
     * Executa os passos de uma inscrição até um "aguardar", um envio adiado, o fim ou uma parada.
     *
     * @param  Collection<int, JourneyStep>  $passos  ordenados por `ordem`
     * @param  array<string, mixed>  $estado
     * @param  array<string, mixed>  $resultado
     * @return string|null 'sem_orcamento' | 'sem_orcamento_regua' | null
     */
    private function processarInscricao(Journey $journey, Collection $passos, JourneyEnrollment $inscricao, Sender $sender, array &$estado, array &$resultado, int &$porDia): ?string
    {
        $contato = $inscricao->contact;

        if ($contato === null) {
            $inscricao->encerrar(JourneyEnrollment::STATUS_FALHOU, 'contato_inexistente');
            $resultado['encerradas']++;

            return null;
        }

        $indice = $this->indiceDoPasso($passos, (int) $inscricao->passo_atual);

        // Cada volta avança pelo menos um passo; o limite só protege de laço em passo malformado.
        for ($volta = 0; $volta <= $passos->count(); $volta++) {
            $passo = $passos->get($indice);

            if ($passo === null) {
                $inscricao->encerrar(JourneyEnrollment::STATUS_CONCLUIDA, 'fim_dos_passos');
                $resultado['concluidas']++;

                return null;
            }

            switch ($passo->tipo) {
                case JourneyStep::TIPO_ENCERRAR:
                    $inscricao->encerrar(JourneyEnrollment::STATUS_CONCLUIDA, 'encerrar');
                    $resultado['concluidas']++;

                    return null;

                case JourneyStep::TIPO_AGUARDAR:
                    $inscricao->forceFill([
                        'proximo_em' => now()->addHours(max(0, (int) $passo->aguardar_horas)),
                        'passo_atual' => $passos->get($indice + 1)?->ordem ?? ($passo->ordem + 1),
                    ])->save();
                    $resultado['aguardando']++;

                    return null;

                case JourneyStep::TIPO_CONDICAO:
                    if (! $this->condicaoAtendida($passo, $inscricao, $contato)) {
                        $inscricao->encerrar(JourneyEnrollment::STATUS_CONCLUIDA, 'condicao_nao_atendida');
                        $resultado['encerradas']++;

                        return null;
                    }
                    break;

                case JourneyStep::TIPO_TAG:
                    if ((int) $passo->tag_id > 0) {
                        $contato->tags()->syncWithoutDetaching([(int) $passo->tag_id => ['created_at' => now()]]);
                        $resultado['inscricoes_tags']++;
                    } else {
                        $inscricao->registrarPassoPulado($passo, 'sem_tag');
                        $resultado['pulados']++;
                    }
                    break;

                case JourneyStep::TIPO_TEXTO:
                    $this->passoTexto($passo, $inscricao, $contato, $sender, $estado, $resultado);

                    if ($estado['parou'] !== null) {
                        return null;
                    }
                    break;

                case JourneyStep::TIPO_TEMPLATE:
                    $desfecho = $this->passoTemplate($journey, $passo, $inscricao, $contato, $sender, $estado, $resultado, $porDia);

                    if (in_array($desfecho, ['adiado', 'parou', 'sem_orcamento', 'sem_orcamento_regua'], true)) {
                        return in_array($desfecho, ['sem_orcamento', 'sem_orcamento_regua'], true) ? $desfecho : null;
                    }
                    break;

                default:
                    $inscricao->registrarPassoPulado($passo, 'tipo_desconhecido');
                    $resultado['pulados']++;
            }

            $indice++;
            $inscricao->forceFill(['passo_atual' => $passos->get($indice)?->ordem ?? ($passo->ordem + 1), 'proximo_em' => now()])->save();
        }

        Log::warning('Inscrição com laço de passos: parada por segurança', ['journey_id' => $journey->id, 'inscricao_id' => $inscricao->id]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $estado
     * @param  array<string, mixed>  $resultado
     * @return string 'enviado' | 'pulado' | 'adiado' | 'parou' | 'sem_orcamento' | 'sem_orcamento_regua'
     */
    private function passoTemplate(Journey $journey, JourneyStep $passo, JourneyEnrollment $inscricao, Contact $contato, Sender $sender, array &$estado, array &$resultado, int &$porDia): string
    {
        if (isset($estado['passos_quebrados'][$passo->id])) {
            $inscricao->registrarPassoPulado($passo, (string) $estado['passos_quebrados'][$passo->id]);
            $resultado['pulados']++;

            return 'pulado';
        }

        $template = $this->template($sender, $passo, $estado);

        if ($template === null) {
            $estado['passos_quebrados'][$passo->id] = 'template_desconhecido';
            Log::warning('Passo de régua com template fora do cache do remetente: passo pulado nesta rodada', ['journey_id' => $journey->id, 'passo' => $passo->ordem, 'template' => $passo->template_nome]);
            $inscricao->registrarPassoPulado($passo, 'template_desconhecido');
            $resultado['pulados']++;

            return 'pulado';
        }

        if (($motivoTemplate = Guardas::motivoDoTemplate($sender, $template)) !== null) {
            $estado['passos_quebrados'][$passo->id] = $motivoTemplate;
            Log::warning('Passo de régua com template que não pode sair: passo pulado nesta rodada', ['journey_id' => $journey->id, 'passo' => $passo->ordem, 'motivo' => $motivoTemplate]);
            $inscricao->registrarPassoPulado($passo, $motivoTemplate);
            $resultado['pulados']++;

            return 'pulado';
        }

        $marketing = Guardas::marketing($template);

        if ($porDia <= 0) {
            return 'sem_orcamento_regua';
        }

        if ($estado['orcamento'] <= 0 || ($marketing && $estado['marketing'] <= 0)) {
            return 'sem_orcamento';
        }

        $motivo = Guardas::motivoDoContato($contato, $sender, $template, [
            'origem' => Guardas::ORIGEM_REGUA,
            'objetivo' => $journey->objetivo,
            'regras' => ['ignorar_conversa_aberta' => (bool) $journey->ignora_conversa_aberta],
            'exige_consentimento' => false,
            'intervalo_minimo_horas' => (int) $journey->intervalo_minimo_horas,
        ]);

        if ($motivo !== null) {
            if (Guardas::adia($motivo)) {
                $inscricao->forceFill(['proximo_em' => $this->proximaAbertura($sender, $contato, $motivo), 'ultimo_motivo' => $motivo])->save();
                $resultado['adiados']++;

                return 'adiado';
            }

            $inscricao->registrarPassoPulado($passo, $motivo);
            $resultado['pulados']++;

            return 'pulado';
        }

        [$mensagem, $excecao] = $this->despachante->enviarTemplate($sender, $contato, $template, (array) ($passo->variaveis ?? []), WhatsappMessage::ORIGEM_REGUA, [
            'journey_enrollment_id' => $inscricao->id,
        ]);

        if ($excecao === null) {
            $inscricao->forceFill(['ultimo_wamid' => $mensagem->wamid, 'ultimo_motivo' => null])->save();
            $resultado['enviados']++;
            $estado['transitorias'] = 0;
            $this->debitar($estado, $marketing, $porDia);

            if ($estado['sonda']) {
                $sender->despausar();
                SenderEvent::registrar($sender, 'sonda_passou', ['origem' => 'regua', 'journey_id' => $journey->id], null);
                $this->discord->enviar('Remetente voltou a enviar', sprintf('A sonda do remetente "%s" passou numa régua; campanhas e réguas seguem na próxima rodada.', $sender->nome), DiscordNotifier::COR_SUCESSO);
                $estado['parou'] = 'sonda_passou';
            }

            usleep($estado['intervalo_ms'] * 1000);

            return $estado['parou'] !== null ? 'parou' : 'enviado';
        }

        $codigo = (string) ($excecao->codigo ?? 'erro');

        // O POST pode ter chegado: conta como enviado (teto, frequência) e a inscrição avança; reenviar
        // às cegas duplicaria o template.
        if ($codigo === 'timeout_envio') {
            $inscricao->forceFill(['ultimo_wamid' => null, 'ultimo_motivo' => $codigo])->save();
            $resultado['enviados']++;
            $resultado['incertos']++;
            $this->debitar($estado, $marketing, $porDia);

            if (++$estado['transitorias'] >= (int) config('disparo.guardas.max_transitorias_seguidas', 3)) {
                $estado['parou'] = 'instabilidade';

                return 'parou';
            }

            return 'enviado';
        }

        if ($excecao->bloqueioPorQualidade()) {
            $inscricao->forceFill(['ultimo_motivo' => $codigo])->save();
            $this->disjuntor->bloquearPorQualidade($sender, 'A Meta sinalizou o número: '.$excecao->getMessage(), $excecao->codigo, null, 'regua');
            $resultado['falhas']++;
            $estado['parou'] = 'bloqueio_qualidade';

            return 'parou';
        }

        if ($excecao->contaImpedida()) {
            $inscricao->forceFill(['ultimo_motivo' => $codigo])->save();
            $estado['sonda'] ? $sender->reagendarSonda() : $this->disjuntor->impedir($sender, $excecao, null, 'regua');
            $resultado['falhas']++;
            $estado['parou'] = $estado['sonda'] ? 'sonda_falhou' : 'conta_impedida';

            return 'parou';
        }

        if ($excecao->doTemplate() && $codigo !== 'variavel_vazia') {
            // O template está errado para todo mundo: o resto da rodada não insiste nele.
            $estado['passos_quebrados'][$passo->id] = $codigo;
            Log::warning('Template de régua recusado pela Meta: passo pulado nesta rodada', ['journey_id' => $journey->id, 'passo' => $passo->ordem, 'codigo' => $codigo]);
            $inscricao->registrarPassoPulado($passo, $codigo);
            $resultado['falhas']++;

            return 'pulado';
        }

        if ($excecao->transitoria()) {
            $inscricao->forceFill(['proximo_em' => now()->addMinutes((int) config('disparo.reguas.reagendar_transitoria_minutos', 15)), 'ultimo_motivo' => $codigo])->save();
            $resultado['adiados']++;

            if ($estado['sonda']) {
                $sender->reagendarSonda();
                $estado['parou'] = 'sonda_inconclusiva';

                return 'parou';
            }

            if (++$estado['transitorias'] >= (int) config('disparo.guardas.max_transitorias_seguidas', 3)) {
                $estado['parou'] = 'instabilidade';

                return 'parou';
            }

            return 'adiado';
        }

        // Recusa do contato (sem WhatsApp, parou marketing, 131049, variável vazia, telefone): só este passo.
        $inscricao->registrarPassoPulado($passo, $codigo);
        $resultado['falhas']++;
        $estado['transitorias'] = 0;

        if ($estado['sonda']) {
            // A sonda não testou a conta: passa ao próximo na rodada seguinte.
            $estado['parou'] = 'sonda_inconclusiva';

            return 'parou';
        }

        return 'pulado';
    }

    /**
     * Texto livre com link, só dentro da janela de 24 h aberta pela resposta do contato - uma vez.
     *
     * @param  array<string, mixed>  $estado
     * @param  array<string, mixed>  $resultado
     */
    private function passoTexto(JourneyStep $passo, JourneyEnrollment $inscricao, Contact $contato, Sender $sender, array &$estado, array &$resultado): void
    {
        $texto = trim((string) (($passo->variaveis ?? [])['texto'] ?? ''));
        $janelaAberta = $contato->ultima_resposta_em !== null && $contato->ultima_resposta_em->gt(now()->subDay());

        if ($texto === '' || ! $janelaAberta) {
            $inscricao->registrarPassoPulado($passo, $texto === '' ? 'sem_texto' : 'janela_fechada');
            $resultado['pulados']++;

            return;
        }

        [$mensagem, $excecao] = $this->despachante->enviarTexto($sender, $contato, Variaveis::render($texto, Variaveis::doContato($contato)), WhatsappMessage::ORIGEM_REGUA, [
            'journey_enrollment_id' => $inscricao->id,
        ]);

        if ($excecao === null) {
            $resultado['textos']++;
            usleep($estado['intervalo_ms'] * 1000);

            return;
        }

        $codigo = (string) ($excecao->codigo ?? 'erro');
        $inscricao->registrarPassoPulado($passo, $codigo);
        $resultado['falhas']++;

        if ($excecao->bloqueioPorQualidade()) {
            $this->disjuntor->bloquearPorQualidade($sender, 'A Meta sinalizou o número: '.$excecao->getMessage(), $excecao->codigo, null, 'regua');
            $estado['parou'] = 'bloqueio_qualidade';
        } elseif ($excecao->contaImpedida()) {
            $this->disjuntor->impedir($sender, $excecao, null, 'regua');
            $estado['parou'] = 'conta_impedida';
        }
    }

    /**
     * A condição olha o último template da inscrição (status de entrega/leitura) e a última resposta do
     * contato depois dele. Sem template ainda, "não respondeu/entregue/leu" vale.
     */
    private function condicaoAtendida(JourneyStep $passo, JourneyEnrollment $inscricao, Contact $contato): bool
    {
        $ultimo = $inscricao->ultimoTemplate();
        $referencia = $ultimo?->enviado_em ?? $inscricao->iniciada_em ?? $inscricao->created_at;
        $respondeu = $contato->ultima_resposta_em !== null && ($referencia === null || $contato->ultima_resposta_em->gte($referencia));
        $entregue = $ultimo !== null && ($ultimo->entregue_em !== null || WhatsappMessage::rank($ultimo->status) >= WhatsappMessage::rank(WhatsappMessage::STATUS_DELIVERED));
        $leu = $ultimo !== null && ($ultimo->lido_em !== null || $ultimo->status === WhatsappMessage::STATUS_READ);

        return match ($passo->condicao) {
            JourneyStep::CONDICAO_SE_NAO_RESPONDEU => ! $respondeu,
            JourneyStep::CONDICAO_SE_RESPONDEU => $respondeu,
            JourneyStep::CONDICAO_SE_NAO_ENTREGUE => ! $entregue,
            JourneyStep::CONDICAO_SE_NAO_LEU => ! $leu,
            default => true,
        };
    }

    /**
     * Guarda que só adia (fora da janela no fuso do contato, dia da semana): a inscrição volta na próxima
     * abertura da janela do remetente, contada no fuso de quem recebe.
     */
    private function proximaAbertura(Sender $sender, Contact $contato, string $motivo): CarbonImmutable
    {
        $fuso = $motivo === Guardas::FORA_DA_JANELA_CONTATO ? $contato->fuso() : ($sender->fuso ?: (string) config('disparo.fuso'));
        [$hora, $minuto] = array_map('intval', explode(':', ($sender->janela_inicio ?: '00:00').':0'));
        $agora = CarbonImmutable::now($fuso);
        $abertura = $agora->setTime($hora, $minuto);

        if ($abertura->lte($agora)) {
            $abertura = $abertura->addDay();
        }

        // O cast `datetime` grava a hora como está no objeto, sem converter: volta para o fuso da app.
        return $abertura->addMinutes(5)->setTimezone((string) config('app.timezone'));
    }

    /** @param  array<string, mixed>  $estado */
    private function debitar(array &$estado, bool $marketing, int &$porDia): void
    {
        $estado['orcamento']--;
        $porDia--;

        if ($marketing) {
            $estado['marketing']--;
        }
    }

    /**
     * O template do passo, do cache do remetente (a régua foi validada na aprovação; o cache é
     * ressincronizado a cada 6 h). Memorizado por rodada.
     *
     * @param  array<string, mixed>  $estado
     */
    private function template(Sender $sender, JourneyStep $passo, array &$estado): ?MessageTemplate
    {
        $idioma = (string) ($passo->template_idioma ?: 'pt_BR');
        $chave = $passo->template_nome.'|'.$idioma;

        if (! array_key_exists($chave, $estado['templates'])) {
            $estado['templates'][$chave] = MessageTemplate::query()
                ->where('sender_id', $sender->id)
                ->where('nome', (string) $passo->template_nome)
                ->where('idioma', $idioma)
                ->first();
        }

        return $estado['templates'][$chave];
    }

    /** Índice do primeiro passo com `ordem` >= `passo_atual` (a ordem pode ter buracos). */
    private function indiceDoPasso(Collection $passos, int $passoAtual): int
    {
        foreach ($passos as $i => $passo) {
            if ((int) $passo->ordem >= $passoAtual) {
                return $i;
            }
        }

        return $passos->count();
    }
}
