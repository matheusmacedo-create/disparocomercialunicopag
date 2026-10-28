<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use App\Services\Campanhas\MontadorDePublico;
use App\Services\DiscordNotifier;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Uma rodada de uma campanha (KB 5, 8): destinatários pendentes do remetente sob trava, ritmo, janela,
 * teto (com a reserva das réguas e o teto de MARKETING), guardas na hora do envio (o contato pode ter
 * pedido para parar depois da montagem), tranches com disjuntor de engajamento, e as três classes de
 * recusa:
 *  - bloqueio por qualidade (131048, 368, 131031): pausa o remetente SEM sonda e cancela as campanhas
 *    MARKETING em andamento dele; só humano religa;
 *  - conta impedida (pagamento, token, saldo, Sandbox): pausa o remetente e manda 1 sonda a cada
 *    `sonda_intervalo_minutos`;
 *  - transitória (429, 5xx, `timeout` antes do POST): espera e tenta de novo (no máximo 2 vezes, 60 s
 *    entre elas); `timeout_envio` (o POST pode ter chegado) é final: `enviado_incerto`.
 *
 * Com `DISPARO_ENVIO_HABILITADO=false` a rodada roda inteira como simulação: os destinatários viram
 * `simulado` (com o motivo real de quem seria pulado), nada chega ao provedor.
 */
final class ExecutorDeCampanha
{
    public function __construct(
        private readonly Despachante $despachante,
        private readonly DiscordNotifier $discord,
        private readonly MontadorDePublico $publico,
        private readonly WhatsAppClientFactory $clientes,
    ) {}

    /**
     * @return array{enviados: int, simulados: int, pulados: int, falhas: int, adiados: int, parou: ?string}
     */
    public function rodar(Campaign $campaign, ?int $limite = null, ?int $tempoMaximoSegundos = null): array
    {
        $resultado = ['enviados' => 0, 'simulados' => 0, 'pulados' => 0, 'falhas' => 0, 'adiados' => 0, 'parou' => null];
        $sender = $campaign->sender;
        $simulacao = ! config('disparo.envio_habilitado');
        $template = $this->template($campaign);

        if ($template === null) {
            $this->pausarCampanha($campaign, sprintf('Template "%s" (%s) não está no cache do remetente: sincronize os templates.', $campaign->template_nome, $campaign->template_idioma));
            $resultado['parou'] = 'template_desconhecido';

            return $resultado;
        }

        // A Meta reclassificou o template depois da aprovação: muda custo, fila e preferência do usuário.
        if ($campaign->template_categoria !== null && strtoupper($campaign->template_categoria) !== strtoupper($template->categoria)) {
            $campaign->forceFill(['status' => Campaign::STATUS_AGUARDANDO_APROVACAO, 'pausada_motivo' => sprintf('A Meta reclassificou o template de %s para %s: a campanha precisa de nova aprovação.', $campaign->template_categoria, $template->categoria)])->save();
            $campaign->registrarEvento('categoria_mudou', ['de' => $campaign->template_categoria, 'para' => $template->categoria], null);
            $this->discord->enviar('Template reclassificado pela Meta', sprintf('"%s": template "%s" passou de %s para %s. A campanha voltou para aprovação.', $campaign->nome, $template->nome, $campaign->template_categoria, $template->categoria), DiscordNotifier::COR_ALERTA, [], true);
            $resultado['parou'] = 'categoria_mudou';

            return $resultado;
        }

        $sondaDevida = $sender->sondaDevida() && ! $simulacao;
        $motivoDoRemetente = $sondaDevida ? null : Guardas::motivoDoRemetente($sender, $template, $simulacao);

        if ($motivoDoRemetente !== null) {
            $resultado['parou'] = $motivoDoRemetente;

            if (! Guardas::adia($motivoDoRemetente)) {
                $this->pausarCampanha($campaign, 'Remetente ou template não permite enviar: '.$motivoDoRemetente);
            }

            return $resultado;
        }

        // Tranche: a primeira leva saiu e a campanha espera os webhooks antes de seguir.
        if (! $simulacao && $campaign->tranche_aguardando_ate !== null) {
            if ($campaign->tranche_aguardando_ate->gt(now())) {
                $resultado['parou'] = 'aguardando_tranche';

                return $resultado;
            }

            $avaliacao = DisjuntorDeEngajamento::avaliar($campaign);

            if ($avaliacao['motivo'] !== null) {
                $this->bloquearPorEngajamento($campaign, $sender, $avaliacao);
                $resultado['parou'] = 'engajamento';

                return $resultado;
            }

            $campaign->forceFill(['tranche_aguardando_ate' => null, 'tranches_enviadas' => $campaign->tranches_enviadas + 1])->save();
            $campaign->registrarEvento('tranche_liberada', $avaliacao['metricas'], null);
        }

        // O que foi aprovado ainda é o que vai sair? (destinatários, template, política do remetente)
        if (! $simulacao && $campaign->aprovacao_hash !== null && $campaign->aprovacao_hash !== $campaign->hashDaAprovacao($template)) {
            $campaign->forceFill(['status' => Campaign::STATUS_AGUARDANDO_APROVACAO, 'pausada_motivo' => 'Algo mudou depois da aprovação (destinatários, template ou política do remetente): precisa de nova aprovação.'])->save();
            $campaign->registrarEvento('aprovacao_invalidada', ['motivo' => 'hash da aprovação divergente'], null);
            $resultado['parou'] = 'aprovacao_invalidada';

            return $resultado;
        }

        // `enviando` órfão (processo morreu entre o POST e o save): o template pode ter saído. Final.
        $campaign->recipients()
            ->where('status', CampaignRecipient::STATUS_ENVIANDO)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => CampaignRecipient::STATUS_ENVIADO_INCERTO, 'motivo' => 'processo_interrompido', 'enviado_em' => now()]);

        $rodada = TravaDoRemetente::executar($sender, fn (): array => $this->processar($campaign, $sender, $template, $simulacao, $sondaDevida, $limite ?? (int) config('disparo.lote.tamanho', 20), $tempoMaximoSegundos ?? (int) config('disparo.lote.tempo_maximo_segundos', 50), $resultado));

        if ($rodada === false) {
            $resultado['parou'] = TravaDoRemetente::EM_ANDAMENTO;

            return $resultado;
        }

        $resultado = $rodada;
        $campaign->recontar();

        if ($campaign->fresh()->status === Campaign::STATUS_EM_ANDAMENTO && $campaign->pendentes() === 0) {
            $this->concluir($campaign, $simulacao);
        }

        return $resultado;
    }

    /**
     * @param  array{enviados: int, simulados: int, pulados: int, falhas: int, adiados: int, parou: ?string}  $resultado
     * @return array{enviados: int, simulados: int, pulados: int, falhas: int, adiados: int, parou: ?string}
     */
    private function processar(Campaign $campaign, Sender $sender, MessageTemplate $template, bool $simulacao, bool $sonda, int $limite, int $tempoMaximo, array $resultado): array
    {
        $inicio = microtime(true);
        $marketing = Guardas::marketing($template);
        $orcamento = $simulacao ? PHP_INT_MAX : $this->orcamento($campaign, $sender, $marketing);
        $limite = $sonda ? 1 : $limite;

        // Tranche inicial de campanha MARKETING: pequena, depois espera os webhooks.
        $tranche = null;
        if (! $simulacao && ! $sonda && $marketing && $campaign->tranches_enviadas === 0) {
            $tranche = max(1, min((int) config('disparo.tranches.primeira_max', 50), (int) ceil($campaign->total_destinatarios * (float) config('disparo.tranches.primeira_fracao', 0.05))));
            $jaSaiu = (int) $campaign->recipients()->whereIn('status', CampaignRecipient::STATUS_ENVIADOS)->count();
            $limite = min($limite, max(0, $tranche - $jaSaiu));
            $tranche = max(0, $tranche - $jaSaiu);
        }

        $intervaloMs = (int) ceil(60000 / max(1, $sender->msgs_por_minuto));
        $transitoriasSeguidas = 0;
        $contexto = [
            'regras' => (array) ($campaign->regras ?? []),
            'exige_consentimento' => $this->publico->exigeConsentimento($campaign),
            'objetivo' => $campaign->objetivo,
            'origem' => Guardas::ORIGEM_CAMPANHA,
            'simulacao' => $simulacao,
        ];

        $pendentes = $campaign->recipients()
            ->where('status', CampaignRecipient::STATUS_PENDENTE)
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now()))
            ->orderBy('id')
            ->limit(max(0, $limite))
            ->with('contact')
            ->get();

        $saidosNaRodada = 0;

        foreach ($pendentes as $destinatario) {
            if ((microtime(true) - $inicio) > $tempoMaximo) {
                $resultado['parou'] = 'tempo_da_rodada';
                break;
            }

            if ($orcamento <= 0) {
                $resultado['parou'] = Guardas::TETO_DIARIO;
                break;
            }

            if (! $simulacao && ! $sender->dentroDaJanela()) {
                $resultado['parou'] = Guardas::FORA_DA_JANELA;
                break;
            }

            $contato = $destinatario->contact;

            if ($contato === null) {
                $this->pular($destinatario, Guardas::CONTATO_INVALIDO);
                $resultado['pulados']++;

                continue;
            }

            $motivo = Guardas::motivoDoContato($contato, $sender, $template, $contexto);

            if ($motivo !== null) {
                if (Guardas::adia($motivo)) {
                    $destinatario->forceFill(['proxima_tentativa_em' => $sender->proximaAberturaDaJanela()->addMinutes(5), 'motivo' => $motivo])->save();
                    $resultado['adiados']++;

                    continue;
                }

                $this->pular($destinatario, $motivo);
                $resultado['pulados']++;

                continue;
            }

            $destinatario->forceFill(['status' => CampaignRecipient::STATUS_ENVIANDO, 'tentativas' => $destinatario->tentativas + 1])->save();

            [$mensagem, $excecao] = $this->despachante->enviarTemplate($sender, $contato, $template, (array) ($campaign->variaveis ?? []), WhatsappMessage::ORIGEM_CAMPANHA, [
                'campaign_recipient_id' => $destinatario->id,
            ], $simulacao);

            if ($excecao === null) {
                $destinatario->forceFill([
                    'status' => $simulacao ? CampaignRecipient::STATUS_SIMULADO : CampaignRecipient::STATUS_ENVIADO,
                    'motivo' => null,
                    'wamid' => $mensagem->wamid,
                    'whatsapp_message_id' => $mensagem->id,
                    'enviado_em' => now(),
                    'erro' => null,
                    'codigo_erro' => null,
                    'proxima_tentativa_em' => null,
                ])->save();
                $simulacao ? $resultado['simulados']++ : $resultado['enviados']++;
                $orcamento--;
                $saidosNaRodada++;
                $transitoriasSeguidas = 0;

                if ($sonda) {
                    $sender->despausar();
                    $campaign->registrarEvento('sonda_passou', ['sender_id' => $sender->id], null);
                    $this->discord->enviar('Remetente voltou a enviar', sprintf('A sonda do remetente "%s" passou; a campanha "%s" segue na próxima rodada.', $sender->nome, $campaign->nome), DiscordNotifier::COR_SUCESSO);
                    $resultado['parou'] = 'sonda_passou';
                    break;
                }

                if ($tranche !== null && $saidosNaRodada >= $tranche) {
                    $campaign->forceFill(['tranche_aguardando_ate' => now()->addMinutes((int) config('disparo.tranches.espera_minutos', 90))])->save();
                    $campaign->registrarEvento('tranche_enviada', ['enviados' => $saidosNaRodada, 'espera_minutos' => (int) config('disparo.tranches.espera_minutos', 90)], null);
                    $resultado['parou'] = 'tranche_enviada';
                    break;
                }

                if (! $simulacao) {
                    usleep($intervaloMs * 1000);
                }

                continue;
            }

            // `timeout_envio`: o POST pode ter chegado. Final, sem retentativa (reenviar duplicaria).
            if ((string) $excecao->codigo === 'timeout_envio') {
                $destinatario->forceFill([
                    'status' => CampaignRecipient::STATUS_ENVIADO_INCERTO,
                    'motivo' => 'timeout_envio',
                    'whatsapp_message_id' => $mensagem->id,
                    'enviado_em' => now(),
                    'erro' => mb_substr($excecao->getMessage(), 0, 2000),
                    'codigo_erro' => 'timeout_envio',
                    'proxima_tentativa_em' => null,
                ])->save();
                $resultado['enviados']++;
                $orcamento--;
                $transitoriasSeguidas++;

                if ($transitoriasSeguidas >= (int) config('disparo.guardas.max_transitorias_seguidas', 3)) {
                    $this->pausarCampanha($campaign, 'Provedor sem confirmar envios: '.$excecao->getMessage());
                    $resultado['parou'] = 'instabilidade';
                    break;
                }

                continue;
            }

            if ($excecao->bloqueioPorQualidade()) {
                $this->reabrir($destinatario, $mensagem, $excecao);
                $this->bloquearPorQualidade($campaign, $sender, 'A Meta sinalizou o número: '.$excecao->getMessage(), $excecao->codigo);
                $resultado['falhas']++;
                $resultado['parou'] = 'bloqueio_qualidade';
                break;
            }

            if ($excecao->contaImpedida()) {
                $this->reabrir($destinatario, $mensagem, $excecao);
                $sonda ? $sender->reagendarSonda() : $this->impedir($campaign, $sender, $excecao);
                $resultado['falhas']++;
                $resultado['parou'] = $sonda ? 'sonda_falhou' : 'conta_impedida';
                break;
            }

            if ($excecao->doTemplate() && (string) $excecao->codigo !== 'variavel_vazia') {
                $this->reabrir($destinatario, $mensagem, $excecao);
                $this->pausarCampanha($campaign, 'Template recusado pela Meta: '.$excecao->getMessage());
                $resultado['falhas']++;
                $resultado['parou'] = 'template';
                break;
            }

            if ($excecao->transitoria()) {
                $transitoriasSeguidas++;

                if ($destinatario->tentativas < (int) config('disparo.guardas.max_tentativas', 2)) {
                    $destinatario->forceFill([
                        'status' => CampaignRecipient::STATUS_PENDENTE,
                        'erro' => mb_substr($excecao->getMessage(), 0, 2000),
                        'codigo_erro' => mb_substr((string) $excecao->codigo, 0, 40),
                        'proxima_tentativa_em' => now()->addSeconds(max((int) config('disparo.guardas.intervalo_tentativa_segundos', 60), 2 ** $destinatario->tentativas * 30)),
                    ])->save();
                    $resultado['adiados']++;
                } else {
                    $this->falhar($destinatario, $mensagem, $excecao);
                    $resultado['falhas']++;
                }

                if ($sonda) {
                    // Sonda que esbarrou em instabilidade continua sonda.
                    $sender->reagendarSonda();
                    $resultado['parou'] = 'sonda_inconclusiva';
                    break;
                }

                if ($transitoriasSeguidas >= (int) config('disparo.guardas.max_transitorias_seguidas', 3)) {
                    $this->pausarCampanha($campaign, 'Provedor instável: '.$excecao->getMessage());
                    $resultado['parou'] = 'instabilidade';
                    break;
                }

                sleep(min(10, 2 ** $transitoriasSeguidas));

                continue;
            }

            // Recusa do contato (sem WhatsApp, variável vazia, marketing parado, 131049...): só ele.
            $this->falhar($destinatario, $mensagem, $excecao);
            $resultado['falhas']++;
            $transitoriasSeguidas = 0;

            if ($sonda) {
                // A sonda não testou a conta: passa ao próximo na rodada seguinte.
                $resultado['parou'] = 'sonda_inconclusiva';
                break;
            }
        }

        return $resultado;
    }

    /**
     * Quantos templates esta campanha ainda pode mandar hoje: teto efetivo menos o que já saiu pelo número
     * (qualquer origem), menos a reserva das réguas que ainda não foi usada, e o teto de MARKETING.
     */
    private function orcamento(Campaign $campaign, Sender $sender, bool $marketing): int
    {
        $teto = $sender->tetoEfetivo();
        $usado = $sender->templatesNasUltimas24h();
        $reserva = (int) ceil($teto * (float) config('disparo.fila.reserva_reguas', 0.3));
        $reguasHoje = $sender->templatesNasUltimas24h(null, WhatsappMessage::ORIGEM_REGUA);
        $orcamento = $teto - $usado - max(0, $reserva - $reguasHoje);

        if ($marketing) {
            $orcamento = min($orcamento, $sender->tetoDeMarketing() - $sender->templatesNasUltimas24h(Sender::CATEGORIA_MARKETING));
        }

        $maxPorDia = (int) $campaign->regra('max_por_dia', 0);

        if ($maxPorDia > 0) {
            $hoje = $campaign->recipients()->whereIn('status', CampaignRecipient::STATUS_ENVIADOS)->where('enviado_em', '>=', now()->subDay())->count();
            $orcamento = min($orcamento, $maxPorDia - $hoje);
        }

        return max(0, $orcamento);
    }

    /**
     * O template do cache, reconferido na Meta uma vez por dia por campanha (a Meta reclassifica template
     * depois de aprovado; o cache de 6 h não basta para a decisão de envio). Falha ao consultar = fica o
     * cache, com aviso no log.
     */
    private function template(Campaign $campaign): ?MessageTemplate
    {
        $cache = MessageTemplate::query()
            ->where('sender_id', $campaign->sender_id)
            ->where('nome', $campaign->template_nome)
            ->where('idioma', $campaign->template_idioma)
            ->first();

        if ($cache === null || config('disparo.envio_habilitado') !== true) {
            return $cache;
        }

        $chave = 'disparo:template-reconferido:'.$campaign->id.':'.now()->toDateString();

        if (Cache::get($chave) !== null) {
            return $cache;
        }

        try {
            $definicao = $this->clientes->para($campaign->sender)->template($campaign->template_nome, $campaign->template_idioma);

            if ($definicao !== null) {
                $cache = MessageTemplate::sincronizar($campaign->sender, $definicao);
            }

            Cache::put($chave, true, now()->addDay());
        } catch (WhatsAppException $e) {
            Log::warning('Não foi possível reconferir o template na Meta; usando o cache', ['campaign_id' => $campaign->id, 'erro' => $e->getMessage()]);
        }

        return $cache;
    }

    private function pular(CampaignRecipient $destinatario, string $motivo): void
    {
        $destinatario->forceFill(['status' => CampaignRecipient::STATUS_PULADO, 'motivo' => mb_substr($motivo, 0, 100), 'proxima_tentativa_em' => null])->save();
    }

    private function falhar(CampaignRecipient $destinatario, WhatsappMessage $mensagem, WhatsAppException $excecao): void
    {
        $destinatario->forceFill([
            'status' => CampaignRecipient::STATUS_FALHOU,
            'motivo' => mb_substr((string) ($excecao->codigo ?? 'erro'), 0, 100),
            'whatsapp_message_id' => $mensagem->id,
            'erro' => mb_substr($excecao->getMessage(), 0, 2000),
            'codigo_erro' => mb_substr((string) $excecao->codigo, 0, 40),
            'proxima_tentativa_em' => null,
        ])->save();
    }

    /** Falha que não é do destinatário (conta, template): ele volta à fila para a rodada seguinte. */
    private function reabrir(CampaignRecipient $destinatario, WhatsappMessage $mensagem, WhatsAppException $excecao): void
    {
        $destinatario->forceFill([
            'status' => CampaignRecipient::STATUS_PENDENTE,
            'whatsapp_message_id' => $mensagem->id,
            'erro' => mb_substr($excecao->getMessage(), 0, 2000),
            'codigo_erro' => mb_substr((string) $excecao->codigo, 0, 40),
            'proxima_tentativa_em' => null,
        ])->save();
    }

    private function impedir(Campaign $campaign, Sender $sender, WhatsAppException $excecao): void
    {
        $motivo = mb_substr('Conta impedida: '.$excecao->getMessage(), 0, 500);
        $sender->impedir($motivo);
        $this->pausarCampanha($campaign, $motivo, 'disjuntor');

        Log::warning('Disjuntor (conta impedida)', ['sender_id' => $sender->id, 'campaign_id' => $campaign->id, 'codigo' => $excecao->codigo]);

        $this->discord->enviar(
            'Disjuntor: conta impedida',
            sprintf('O remetente "%s" recusou um envio da campanha "%s" por motivo que vale para a conta inteira. Campanha e remetente pausados; a próxima sonda (1 envio) sai em %d min.', $sender->nome, $campaign->nome, (int) $sender->sonda_intervalo_minutos),
            DiscordNotifier::COR_PERIGO,
            [['name' => 'Motivo', 'value' => mb_substr($excecao->getMessage(), 0, 900)]],
            true,
        );
    }

    /** Bloqueio por qualidade: pausa sem sonda e cancela as campanhas MARKETING em andamento do remetente. */
    public function bloquearPorQualidade(Campaign $campaign, Sender $sender, string $motivo, string|int|null $codigo = null): void
    {
        $sender->bloquearPorQualidade($motivo);

        $canceladas = [];

        foreach ($sender->campaigns()->whereIn('status', [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_PAUSADA, Campaign::STATUS_APROVADA])->get() as $outra) {
            $template = $this->template($outra);
            $ehMarketing = $template !== null && Guardas::marketing($template);

            if ($outra->is($campaign) || $ehMarketing) {
                $outra->forceFill(['status' => $ehMarketing ? Campaign::STATUS_CANCELADA : Campaign::STATUS_PAUSADA, 'pausada_motivo' => mb_substr($motivo, 0, 500)])->save();
                $outra->registrarEvento($ehMarketing ? 'cancelada' : 'pausada', ['motivo' => mb_substr($motivo, 0, 500), 'automatico' => true, 'bloqueio_qualidade' => true, 'codigo' => $codigo], null);
                $canceladas[] = $outra->nome;
            }
        }

        Log::warning('Disjuntor (bloqueio por qualidade)', ['sender_id' => $sender->id, 'campaign_id' => $campaign->id, 'codigo' => $codigo]);

        $this->discord->enviar(
            'Bloqueio por qualidade: remetente travado',
            sprintf('A Meta sinalizou o remetente "%s". Sem sonda automática: alguém com "manage senders" precisa religar depois de conferir a qualidade no WhatsApp Manager. Campanhas afetadas: %s.', $sender->nome, implode(', ', $canceladas) ?: $campaign->nome),
            DiscordNotifier::COR_PERIGO,
            [['name' => 'Motivo', 'value' => mb_substr($motivo, 0, 900)]],
            true,
        );
    }

    /** @param  array{motivo: ?string, metricas: array<string, mixed>}  $avaliacao */
    private function bloquearPorEngajamento(Campaign $campaign, Sender $sender, array $avaliacao): void
    {
        $campaign->registrarEvento('engajamento', $avaliacao['metricas'] + ['motivo' => $avaliacao['motivo']], null);
        $this->bloquearPorQualidade($campaign, $sender, 'Disjuntor de engajamento na campanha "'.$campaign->nome.'": '.$avaliacao['motivo']);
    }

    private function pausarCampanha(Campaign $campaign, string $motivo, string $evento = 'pausada'): void
    {
        $campaign->forceFill(['status' => Campaign::STATUS_PAUSADA, 'pausada_motivo' => mb_substr($motivo, 0, 500)])->save();
        $campaign->registrarEvento($evento, ['motivo' => mb_substr($motivo, 0, 500), 'automatico' => true], null);
    }

    private function concluir(Campaign $campaign, bool $simulacao): void
    {
        $campaign->forceFill(['status' => Campaign::STATUS_CONCLUIDA, 'concluida_em' => now()])->save();
        $campaign->registrarEvento('concluida', ['simulacao' => $simulacao, 'enviados' => $campaign->enviados, 'falhas' => $campaign->falhas, 'pulados' => $campaign->pulados], null);

        $this->discord->enviar(
            $simulacao ? 'Campanha concluída em SIMULAÇÃO' : 'Campanha concluída',
            sprintf('"%s" pelo remetente "%s": %d enviados, %d falhas, %d pulados%s.', $campaign->nome, $campaign->sender->nome, $campaign->enviados, $campaign->falhas, $campaign->pulados, $simulacao ? ' (nada saiu: DISPARO_ENVIO_HABILITADO=false)' : ''),
            $simulacao ? DiscordNotifier::COR_ALERTA : DiscordNotifier::COR_SUCESSO,
        );
    }
}
