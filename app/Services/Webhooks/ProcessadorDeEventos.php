<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Envio\Despachante;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Telefone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * O que um evento de WhatsApp muda na plataforma (KB 10): entrega que só avança, falha tardia com as
 * consequências no contato, resposta do cliente (classificada: parar, agora não, opt-in, outra),
 * preferência de marketing da Meta, e status de mensagem que NÃO saiu daqui (painel da Kapso, KYC) -
 * que vira linha `origem=webhook` para o teto diário enxergar.
 *
 * Idempotente: a Kapso reenvia o que não confirmamos, e a Meta não garante a ordem.
 */
final class ProcessadorDeEventos
{
    /** Mensagem que chega depois disto não é mais tratada como resposta ao template. */
    private const JANELA_DA_RESPOSTA_DIAS = 7;

    private const BOTOES_DE_OPTIN = ['quero receber', 'sim quero', 'sim quero receber', 'aceito', 'quero sim', 'pode mandar', 'pode enviar', 'tenho interesse', 'quero saber mais'];

    public function __construct(private readonly Despachante $despachante) {}

    /** @return array<string, mixed> resumo do que mudou (sem PII) */
    public function processar(Sender $sender, EventoDeMensagem $evento): array
    {
        return match ($evento->tipo) {
            EventoDeMensagem::STATUS => $this->status($sender, $evento),
            EventoDeMensagem::RECEBIDA => $this->recebida($sender, $evento),
            EventoDeMensagem::PREFERENCIA => $this->preferencia($sender, $evento),
            default => ['ignorado' => true],
        };
    }

    /** @return array<string, mixed> */
    private function status(Sender $sender, EventoDeMensagem $evento): array
    {
        if ($evento->wamid === null || $evento->status === null) {
            return ['ignorado' => 'sem wamid ou status'];
        }

        $quando = $this->momento($evento->timestamp);
        $mensagem = WhatsappMessage::query()->where('wamid', $evento->wamid)->first();

        if ($mensagem === null) {
            return $this->mensagemDesconhecida($sender, $evento, $quando);
        }

        $antes = $mensagem->status;

        if ($evento->status === WhatsappMessage::STATUS_FAILED) {
            $this->marcarFalha($sender, $mensagem, $evento);
        } else {
            $this->avancar($mensagem, $evento->status, $quando);
        }

        if ($mensagem->isDirty()) {
            $mensagem->saveQuietly();
            $this->refletir($mensagem, $antes);
        }

        return ['mensagem_id' => $mensagem->id, 'status' => $mensagem->status, 'mudou' => $antes !== $mensagem->status];
    }

    /**
     * A entrega só avança (sent < delivered < read); "lida" implica entregue; "saiu" atrasado não apaga
     * uma falha, mas entregue/lida apagam (a mensagem chegou).
     */
    private function avancar(WhatsappMessage $mensagem, string $status, CarbonImmutable $quando): void
    {
        if ($status === WhatsappMessage::STATUS_READ) {
            $mensagem->lido_em ??= $quando;
            $mensagem->entregue_em ??= $quando;
        }

        if ($status === WhatsappMessage::STATUS_DELIVERED && ($mensagem->entregue_em === null || $quando->lt($mensagem->entregue_em))) {
            $mensagem->entregue_em = $quando;
        }

        $falhou = $mensagem->status === WhatsappMessage::STATUS_FAILED;

        if ($falhou && $status === WhatsappMessage::STATUS_SENT) {
            return;
        }

        if (WhatsappMessage::rank($status) > WhatsappMessage::rank($mensagem->status) || $mensagem->status === WhatsappMessage::STATUS_PENDING) {
            $mensagem->status = $status;

            if ($falhou || $mensagem->codigo_erro === 'timeout_envio') {
                $mensagem->codigo_erro = null;
                $mensagem->erro = null;
            }
        }
    }

    /** A API aceitou, a Meta não entregou: falha com o motivo traduzido - a menos que já tenha chegado. */
    private function marcarFalha(Sender $sender, WhatsappMessage $mensagem, EventoDeMensagem $evento): void
    {
        if (WhatsappMessage::rank($mensagem->status) >= WhatsappMessage::rank(WhatsappMessage::STATUS_DELIVERED)) {
            return;
        }

        $codigo = $evento->codigoErro;
        $original = (string) $evento->erro;
        $motivo = $codigo === null && $original === ''
            ? 'a Meta não informou o motivo.'
            : WhatsAppException::traduzir(0, $codigo, $original).sprintf(' [Meta %s: %s]', $codigo ?? 'sem código', $original ?: 'sem detalhe');

        $mensagem->status = WhatsappMessage::STATUS_FAILED;
        $mensagem->codigo_erro = $codigo !== null ? mb_substr($codigo, 0, 40) : null;
        $mensagem->erro = mb_substr('Não entregue: '.$motivo, 0, 1000);

        if ($codigo !== null && $mensagem->contact !== null) {
            $this->despachante->aplicarConsequencias($sender, $mensagem->contact, new WhatsAppException($motivo, 0, $codigo), $mensagem->wamid);
        }
    }

    /** O destinatário da campanha, a inscrição na régua e os contadores do contato seguem a mensagem. */
    /** Reflete a mudança de status da mensagem no contato e no destinatário/inscrição ligados (webhook e importação). */
    public function refletir(WhatsappMessage $mensagem, ?string $antes): void
    {
        $contato = $mensagem->contact;

        if ($contato !== null) {
            if ($mensagem->status === WhatsappMessage::STATUS_DELIVERED && WhatsappMessage::rank($antes) < 2) {
                $contato->increment('entregues_total');
            } elseif ($mensagem->status === WhatsappMessage::STATUS_READ && WhatsappMessage::rank($antes) < 3) {
                $contato->increment('lidos_total');

                if (WhatsappMessage::rank($antes) < 2) {
                    $contato->increment('entregues_total');
                }
            } elseif ($mensagem->status === WhatsappMessage::STATUS_FAILED && $antes !== WhatsappMessage::STATUS_FAILED) {
                $contato->increment('falhas_total');
            }
        }

        if ($mensagem->campaign_recipient_id !== null && ($destinatario = CampaignRecipient::query()->find($mensagem->campaign_recipient_id)) !== null) {
            $novo = match ($mensagem->status) {
                WhatsappMessage::STATUS_SENT => CampaignRecipient::STATUS_ENVIADO,
                WhatsappMessage::STATUS_DELIVERED => CampaignRecipient::STATUS_ENTREGUE,
                WhatsappMessage::STATUS_READ => CampaignRecipient::STATUS_LIDO,
                WhatsappMessage::STATUS_FAILED => CampaignRecipient::STATUS_FALHOU,
                default => null,
            };
            $anterior = $destinatario->status;
            $campanha = $destinatario->campaign;

            if ($novo === CampaignRecipient::STATUS_FALHOU) {
                if (! in_array($anterior, [CampaignRecipient::STATUS_RESPONDIDO, CampaignRecipient::STATUS_LIDO, CampaignRecipient::STATUS_ENTREGUE], true)) {
                    $destinatario->forceFill(['status' => CampaignRecipient::STATUS_FALHOU, 'motivo' => mb_substr((string) ($mensagem->codigo_erro ?? 'nao_entregue'), 0, 100), 'erro' => $mensagem->erro, 'codigo_erro' => $mensagem->codigo_erro])->save();
                    // Contadores atômicos: o executor e o webhook mexem na mesma campanha ao mesmo tempo.
                    if (in_array($anterior, CampaignRecipient::STATUS_ENVIADOS, true)) {
                        $campanha?->decrement('enviados');
                    }
                    $campanha?->increment('falhas');
                }
            } elseif ($novo !== null && CampaignRecipient::rank($novo) > CampaignRecipient::rank($anterior)) {
                $destinatario->forceFill(array_filter([
                    'status' => $novo,
                    'entregue_em' => $mensagem->entregue_em,
                    'lido_em' => $mensagem->lido_em,
                    'erro' => null,
                    'codigo_erro' => null,
                    'motivo' => null,
                ], fn ($v, $k) => in_array($k, ['status'], true) || $v !== null || in_array($k, ['erro', 'codigo_erro', 'motivo'], true), ARRAY_FILTER_USE_BOTH))->save();

                if ($campanha !== null) {
                    if (CampaignRecipient::rank($anterior) < 2 && CampaignRecipient::rank($novo) >= 2) {
                        $campanha->increment('entregues');
                    }
                    if (CampaignRecipient::rank($anterior) < 3 && CampaignRecipient::rank($novo) >= 3) {
                        $campanha->increment('lidos');
                    }
                    if ($anterior === CampaignRecipient::STATUS_FALHOU) {
                        $campanha->decrement('falhas');
                        $campanha->increment('enviados');
                    }
                }
            } elseif ($novo === CampaignRecipient::STATUS_ENVIADO && $anterior === CampaignRecipient::STATUS_ENVIADO_INCERTO) {
                $destinatario->forceFill(['status' => CampaignRecipient::STATUS_ENVIADO, 'motivo' => null, 'erro' => null, 'codigo_erro' => null])->save();
            }
        }
    }

    /**
     * Status de mensagem que não saiu daqui (painel da Kapso, KYC pelo mesmo número): vira linha outbound
     * `origem=webhook` para o teto diário e a conversa aberta enxergarem (achado da revisão de 28/10).
     *
     * @return array<string, mixed>
     */
    private function mensagemDesconhecida(Sender $sender, EventoDeMensagem $evento, CarbonImmutable $quando): array
    {
        $telefone = $evento->telefone !== null ? Telefone::canonico($evento->telefone) : null;

        if ($telefone === null) {
            return ['ignorado' => 'wamid desconhecido sem telefone'];
        }

        $contato = Contact::query()->porTelefone($telefone)->first();

        $mensagem = WhatsappMessage::query()->firstOrCreate(['wamid' => $evento->wamid], [
            'sender_id' => $sender->id,
            'contact_id' => $contato?->id,
            'telefone' => $telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            // Status da Meta não traz o tipo: conversa iniciada pela empresa (pricing.category) só pode ser template.
            'tipo' => $evento->tipoMensagem ?? ($evento->templateNome !== null || in_array(strtoupper((string) $evento->categoria), ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true) ? WhatsappMessage::TIPO_TEMPLATE : null),
            'template_nome' => $evento->templateNome,
            'status' => $evento->status === WhatsappMessage::STATUS_FAILED ? WhatsappMessage::STATUS_FAILED : $evento->status,
            'codigo_erro' => $evento->codigoErro,
            'erro' => $evento->erro !== null ? mb_substr($evento->erro, 0, 1000) : null,
            'origem' => WhatsappMessage::ORIGEM_WEBHOOK,
            'enviado_em' => $quando,
            'entregue_em' => in_array($evento->status, [WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ], true) ? $quando : null,
            'lido_em' => $evento->status === WhatsappMessage::STATUS_READ ? $quando : null,
            'payload' => array_filter(['categoria' => $evento->categoria, 'conversation_id' => $evento->conversationId]),
        ]);

        if (! $mensagem->wasRecentlyCreated) {
            $antes = $mensagem->status;
            $evento->status === WhatsappMessage::STATUS_FAILED ? $this->marcarFalha($sender, $mensagem, $evento) : $this->avancar($mensagem, (string) $evento->status, $quando);

            if ($mensagem->isDirty()) {
                $mensagem->saveQuietly();
                $this->refletir($mensagem, $antes);
            }
        } elseif ($contato !== null && $evento->status !== WhatsappMessage::STATUS_FAILED) {
            $contato->forceFill(['ultimo_envio_em' => $quando, 'ultimo_template_em' => $mensagem->tipo === WhatsappMessage::TIPO_TEMPLATE ? $quando : $contato->ultimo_template_em])->save();
        }

        return ['mensagem_id' => $mensagem->id, 'origem' => 'webhook', 'nova' => $mensagem->wasRecentlyCreated];
    }

    /**
     * O cliente mandou mensagem: trilha, contadores, classificação (parar / agora não / opt-in / outra),
     * destinatário e inscrição respondidos, régua sai ou segue ao passo de texto.
     *
     * @return array<string, mixed>
     */
    private function recebida(Sender $sender, EventoDeMensagem $evento): array
    {
        $telefone = $evento->telefone !== null ? Telefone::canonico($evento->telefone) : null;

        if ($telefone === null) {
            return ['ignorado' => 'remetente sem telefone (BSUID)'];
        }

        if ($evento->wamid !== null && WhatsappMessage::query()->where('wamid', $evento->wamid)->exists()) {
            return ['ignorado' => 'mensagem já registrada'];
        }

        $quando = $this->momento($evento->timestamp);
        $contato = Contact::query()->porTelefone($telefone)->first()
            ?? Contact::query()->create([
                'telefone' => $telefone,
                'nome' => $evento->nomeDoPerfil,
                'origem' => $sender->provider === Sender::PROVIDER_KAPSO ? 'kapso' : 'manual',
                'tipo' => 'outro',
                'status' => Contact::STATUS_ATIVO,
            ]);

        $texto = trim(implode(' ', array_filter($evento->textos)));

        $mensagem = WhatsappMessage::query()->create([
            'sender_id' => $sender->id,
            'contact_id' => $contato->id,
            'telefone' => $telefone,
            'direcao' => WhatsappMessage::INBOUND,
            'wamid' => $evento->wamid,
            'tipo' => $evento->tipoMensagem,
            'conteudo' => $texto !== '' ? mb_substr($texto, 0, 4000) : null,
            'status' => WhatsappMessage::STATUS_RECEIVED,
            'origem' => WhatsappMessage::ORIGEM_WEBHOOK,
            'context_wamid' => $evento->contextWamid,
            'kapso_conversation_id' => $evento->conversationId,
            'enviado_em' => $quando,
            'payload' => $evento->bruto,
        ]);

        $contato->forceFill(['ultima_resposta_em' => $quando, 'respostas_total' => $contato->respostas_total + 1])->save();

        // A que template nosso ela responde: o citado (qualquer data), ou o último ao telefone em 7 dias.
        $template = $this->templateRespondido($sender, $telefone, $evento->contextWamid);
        $respondeATemplate = $template !== null;
        $classificacao = ClassificadorDeResposta::classificar($evento->textos, $respondeATemplate || $evento->foiBotao);
        $resultado = ['mensagem_id' => $mensagem->id, 'classificacao' => $classificacao, 'responde_a' => $template?->id];

        if ($classificacao === ClassificadorDeResposta::PARAR || $classificacao === ClassificadorDeResposta::PEDIDO_LGPD) {
            $motivoTexto = ClassificadorDeResposta::motivo($evento->textos);
            $motivo = str_contains($motivoTexto, 'denunc') ? Suppression::MOTIVO_DENUNCIOU : Suppression::MOTIVO_PEDIU_PARA_PARAR;
            Suppression::suprimir($telefone, $motivo, 'resposta', null, Suppression::ESCOPO_TUDO, $evento->wamid, $motivoTexto, null, $quando);
            $resultado['suprimido'] = $motivo;

            if ($classificacao === ClassificadorDeResposta::PEDIDO_LGPD) {
                // Pedido do titular: bloqueia já e fica em Conversas com prazo até alguém atender (anonimizar / exportar).
                $prazo = $quando->addDays((int) config('disparo.lgpd.prazo_dias', 15));
                $mensagem->forceFill(['payload' => ['lgpd' => true, 'prazo' => $prazo->toIso8601String()]])->saveQuietly();
                ContactEvent::registrar($contato->telefone_hash, 'pedido_lgpd', ['mensagem_id' => $mensagem->id, 'prazo' => $prazo->toIso8601String()], null, $contato->id);
                $resultado['lgpd'] = true;
            } else {
                // Pedido para parar atendido pelo sistema: não precisa de gente.
                $mensagem->forceFill(['tratada_em' => $quando])->saveQuietly();
            }
        }

        if ($template !== null) {
            $marketing = strtoupper((string) (($template->payload ?? [])['categoria'] ?? '')) === Sender::CATEGORIA_MARKETING;

            if ($classificacao === ClassificadorDeResposta::NAO_AGORA && $marketing) {
                $contato->registrarRecusa($sender->id, $evento->wamid);
                $mensagem->forceFill(['tratada_em' => $quando])->saveQuietly();
                $resultado['recusa'] = true;
            }

            $normal = ClassificadorDeResposta::normalizar($evento->textos[0] ?? '');

            if ($evento->foiBotao && in_array($normal, self::BOTOES_DE_OPTIN, true) && ! $contato->temConsentimento()) {
                $contato->registrarConsentimento('whatsapp_optin', 'whatsapp', 'botão "'.$normal.'" no template '.$template->template_nome.' (wamid '.$template->wamid.')', $quando);
                $resultado['optin'] = true;
            }

            $this->refletirResposta($template, $contato, $classificacao, $quando);
        }

        return $resultado;
    }

    /** O template outbound nosso que a mensagem responde, ou null. */
    private function templateRespondido(Sender $sender, string $telefone, ?string $contextWamid): ?WhatsappMessage
    {
        $formas = Telefone::formas($telefone);
        $base = fn () => WhatsappMessage::query()
            ->where('sender_id', $sender->id)
            ->whereIn('telefone', $formas ?: [$telefone])
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED);

        if ($contextWamid !== null) {
            $citado = $base()->where('wamid', $contextWamid)->first();

            if ($citado !== null) {
                return $citado;
            }
        }

        return $base()->where('enviado_em', '>=', now()->subDays(self::JANELA_DA_RESPOSTA_DIAS))->latest('enviado_em')->latest('id')->first();
    }

    /** Destinatário da campanha vira `respondido`; inscrição na régua sai (ou segue ao passo de texto). */
    /** Resposta do contato a um template nosso: destinatário `respondido`, contadores da campanha, saída/avanço da régua. */
    public function refletirResposta(WhatsappMessage $template, Contact $contato, ?string $classificacao, CarbonImmutable $quando): void
    {
        if ($template->campaign_recipient_id !== null && ($destinatario = CampaignRecipient::query()->find($template->campaign_recipient_id)) !== null) {
            $anterior = $destinatario->status;

            if (CampaignRecipient::rank(CampaignRecipient::STATUS_RESPONDIDO) > CampaignRecipient::rank($anterior)) {
                $destinatario->forceFill([
                    'status' => CampaignRecipient::STATUS_RESPONDIDO,
                    'respondido_em' => $quando,
                    'entregue_em' => $destinatario->entregue_em ?? $quando,
                    'motivo' => $classificacao,
                ])->save();

                if (($campanha = $destinatario->campaign) !== null) {
                    $campanha->increment('respondidos');
                    if (CampaignRecipient::rank($anterior) < 2) {
                        $campanha->increment('entregues');
                    }
                    if (CampaignRecipient::rank($anterior) < 3) {
                        $campanha->increment('lidos');
                    }
                }
            }
        }

        if ($template->journey_enrollment_id !== null && ($inscricao = JourneyEnrollment::query()->with('journey')->find($template->journey_enrollment_id)) !== null && $inscricao->status === JourneyEnrollment::STATUS_ATIVA) {
            if ($classificacao === ClassificadorDeResposta::PARAR || $classificacao === ClassificadorDeResposta::PEDIDO_LGPD) {
                $inscricao->encerrar(JourneyEnrollment::STATUS_SAIU, 'pediu_para_parar');
            } elseif ($inscricao->journey?->sair_ao_responder && $classificacao !== ClassificadorDeResposta::NAO_AGORA) {
                $proximo = $inscricao->journey->steps()->where('ordem', $inscricao->passo_atual)->first();

                // Passo de texto na resposta: a resposta abriu a janela de 24 h, o texto sai agora.
                if ($proximo !== null && $proximo->tipo === JourneyStep::TIPO_TEXTO) {
                    $inscricao->forceFill(['proximo_em' => now()])->save();
                } else {
                    $inscricao->encerrar(JourneyEnrollment::STATUS_SAIU, 'respondeu');
                }
            }
        }
    }

    /** Preferência de marketing da Meta: stop = supressão de marketing no remetente; resume = só ela cai. */
    private function preferencia(Sender $sender, EventoDeMensagem $evento): array
    {
        $telefone = $evento->telefone !== null ? Telefone::canonico($evento->telefone) : null;

        if ($telefone === null || $evento->preferencia === null) {
            return ['ignorado' => 'preferência sem telefone'];
        }

        if ($evento->preferencia === 'stop') {
            $novo = Suppression::suprimir($telefone, Suppression::MOTIVO_META_MARKETING_STOPPED, 'webhook', $sender->id, Suppression::ESCOPO_MARKETING, $evento->wamid, $evento->erro, null, $this->momento($evento->timestamp));

            return ['preferencia' => 'stop', 'nova' => $novo];
        }

        Suppression::retomarMarketing($telefone, $sender->id);

        return ['preferencia' => 'resume'];
    }

    private function momento(?int $timestamp): CarbonImmutable
    {
        if ($timestamp === null || $timestamp <= 0) {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::createFromTimestamp($timestamp);
        } catch (\Throwable $e) {
            Log::debug('timestamp ilegível no webhook', ['erro' => $e->getMessage()]);

            return CarbonImmutable::now();
        }
    }
}
