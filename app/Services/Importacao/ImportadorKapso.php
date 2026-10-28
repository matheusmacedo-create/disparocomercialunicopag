<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\ContactImport;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Webhooks\ClassificadorDeResposta;
use App\Services\WhatsApp\KapsoClient;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Mask;
use App\Support\Telefone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Importa o histórico de um número da Kapso (KB 2.7 e 7): conversas, mensagens, contatos e broadcasts
 * viram contatos, trilha em `whatsapp_messages` e supressões - quem já recebeu o quê, quem respondeu,
 * quem pediu para parar, quem não tem WhatsApp. Antes de qualquer campanha para uma base velha.
 *
 * O que NÃO entra: o texto das mensagens. Ele passa em memória pelo classificador (com o contexto do
 * template respondido, como o webhook ao vivo) e é descartado - histórico de atendimento não é
 * finalidade desta ferramenta (achado de LGPD de 28/10). `conteudo` fica nulo para `historico_kapso`.
 *
 * O formato das respostas da Kapso ainda é [inferido] (KB 7): a leitura dos campos é tolerante
 * (`kapso.direction`, `from`/`to`, `timestamp`, `context.id`, `template.name`...), e a primeira
 * importação real deve anotar na KB o que veio de verdade.
 */
final class ImportadorKapso
{
    private Closure $dormir;

    public function __construct(private readonly WhatsAppClientFactory $clientes)
    {
        $this->dormir = static function (int $segundos): void {
            sleep($segundos);
        };
    }

    /** Troca a espera do rate limit (os testes não dormem). @param  Closure(int): void  $dormir */
    public function aoDormir(Closure $dormir): static
    {
        $this->dormir = $dormir;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $opcoes  desde (CarbonInterface|string|null), ate (idem), incremental (bool),
     *                                        contatos (bool), broadcasts (bool), user_id (?int)
     */
    public function importar(Sender $sender, array $opcoes = []): ContactImport
    {
        // Uma importação por remetente por vez (painel, agenda completa e incremental): a segunda espera a próxima.
        $trava = Cache::lock('disparo:importar-historico:'.$sender->id, 7200);

        if (! $trava->get()) {
            throw new RuntimeException(sprintf('Já existe uma importação do histórico do remetente "%s" em andamento.', $sender->nome));
        }

        try {
            return $this->importarTravado($sender, $opcoes);
        } finally {
            $trava->release();
        }
    }

    /** @param  array<string, mixed>  $opcoes */
    private function importarTravado(Sender $sender, array $opcoes): ContactImport
    {
        $cliente = $this->clientes->kapso($sender);
        [$desde, $ate, $incremental] = $this->janela($sender, $opcoes);

        $importacao = ContactImport::query()->create([
            'tipo' => ContactImport::TIPO_KAPSO,
            'sender_id' => $sender->id,
            'origem' => 'kapso',
            'status' => ContactImport::STATUS_PROCESSANDO,
            'parametros' => [
                'desde' => $desde->toIso8601String(),
                'ate' => $ate->toIso8601String(),
                'incremental' => $incremental,
                'contatos' => (bool) ($opcoes['contatos'] ?? false),
                'broadcasts' => (bool) ($opcoes['broadcasts'] ?? false),
            ],
            'user_id' => $opcoes['user_id'] ?? null,
            'iniciado_em' => now(),
        ]);

        $c = $this->contagemVazia();

        try {
            $this->conversas($sender, $cliente, $desde, $c);
            $this->mensagens($sender, $cliente, $desde, $ate, WhatsappMessage::OUTBOUND, $c);
            $this->mensagens($sender, $cliente, $desde, $ate, WhatsappMessage::INBOUND, $c);

            if ($opcoes['contatos'] ?? false) {
                $this->contatos($sender, $cliente, $c);
            }

            if ($opcoes['broadcasts'] ?? false) {
                $this->broadcasts($sender, $cliente, $desde, $ate, $c);
            }

            // Cursor da próxima incremental. Nunca volta: uma importação com --ate antigo não apaga o avanço.
            if ($sender->historico_importado_ate === null || $ate->gt($sender->historico_importado_ate)) {
                $sender->forceFill(['historico_importado_ate' => $ate])->save();
            }

            $importacao->forceFill([
                'status' => ContactImport::STATUS_CONCLUIDA,
                'concluido_em' => now(),
                'total_linhas' => $c['mensagens']['lidas'] + $c['conversas']['lidas'] + $c['contatos']['lidos'] + $c['broadcasts']['destinatarios'],
                'criados' => $c['contatos']['criados'],
                'atualizados' => $c['contatos']['atualizados'],
                'optouts' => array_sum($c['supressoes']),
                'resultado' => $c,
            ])->save();
        } catch (Throwable $e) {
            $importacao->forceFill([
                'status' => ContactImport::STATUS_FALHOU,
                'concluido_em' => now(),
                'mensagem' => mb_substr(Mask::scrub($e->getMessage()), 0, 1000),
                'resultado' => $c,
            ])->save();

            throw $e;
        }

        return $importacao;
    }

    /** @return array<string, mixed> */
    private function contagemVazia(): array
    {
        return [
            'paginas' => 0,
            'esperas' => 0,
            'conversas' => ['lidas' => 0, 'sem_telefone' => 0],
            'contatos' => ['criados' => 0, 'atualizados' => 0, 'lidos' => 0],
            'mensagens' => ['lidas' => 0, 'novas' => 0, 'ja_existiam' => 0, 'inbound' => 0, 'outbound' => 0, 'templates' => 0, 'sem_wamid' => 0, 'sem_telefone' => 0, 'status_avancado' => 0],
            'classificadas' => ['parar' => 0, 'pedido_lgpd' => 0, 'nao_agora' => 0, 'em_resposta_a_template' => 0],
            'recusas' => 0,
            'sem_whatsapp' => 0,
            'carencias' => 0,
            'supressoes' => [],
            'broadcasts' => ['lidos' => 0, 'fora_da_janela' => 0, 'destinatarios' => 0, 'casados' => 0, 'criados' => 0, 'ja_existiam' => 0, 'sem_telefone' => 0],
        ];
    }

    /**
     * A janela [desde, ate]: explícita, incremental (`historico_importado_ate` menos a folga) ou a janela
     * inicial da configuração (180 dias) para o número que nunca importou.
     *
     * @param  array<string, mixed>  $opcoes
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}
     */
    private function janela(Sender $sender, array $opcoes): array
    {
        $ate = isset($opcoes['ate']) && $opcoes['ate'] !== null ? CarbonImmutable::parse($opcoes['ate']) : CarbonImmutable::now();
        $incremental = (bool) ($opcoes['incremental'] ?? false);

        if (isset($opcoes['desde']) && $opcoes['desde'] !== null) {
            $desde = CarbonImmutable::parse($opcoes['desde']);
        } elseif ($incremental && $sender->historico_importado_ate !== null) {
            $desde = CarbonImmutable::instance($sender->historico_importado_ate)->subMinutes((int) config('disparo.historico.folga_incremental_minutos', 60));
        } else {
            $desde = $ate->subDays(max(1, (int) config('disparo.historico.janela_inicial_dias', 180)));
            $incremental = false;
        }

        if ($desde->gte($ate)) {
            $desde = $ate->subMinutes((int) config('disparo.historico.folga_incremental_minutos', 60));
        }

        return [$desde, $ate, $incremental];
    }

    /** @param  array<string, mixed>  $c */
    private function conversas(Sender $sender, KapsoClient $cliente, CarbonImmutable $desde, array &$c): void
    {
        $filtros = ['last_active_since' => $desde->toIso8601String()];

        foreach ($this->paginas(fn (?string $after): array => $cliente->conversas($filtros, $after, $this->porPagina()), $cliente, $c) as $conversa) {
            $c['conversas']['lidas']++;
            $telefone = $this->telefoneDe($conversa, ['kapso.phone_number', 'kapso.contact.phone_number', 'kapso.contact.wa_id', 'kapso.wa_id', 'phone_number', 'wa_id', 'contact.wa_id', 'contact.phone_number', 'from']);

            if ($telefone === null) {
                $c['conversas']['sem_telefone']++;

                continue;
            }

            $contato = $this->contato($sender, $telefone, $this->texto($conversa, ['kapso.contact_name', 'contact_name', 'kapso.contact.name', 'contact.name', 'contact.profile_name']), $c);

            if ($contato === null) {
                continue;
            }

            $mudancas = [];

            if (($conversaId = $this->texto($conversa, ['id', 'kapso.id', 'kapso.conversation_id'])) !== null && $contato->kapso_conversation_id !== $conversaId) {
                $mudancas['kapso_conversation_id'] = mb_substr($conversaId, 0, 64);
            }

            if (($contatoId = $this->texto($conversa, ['kapso.contact_id', 'kapso.whatsapp_contact_id', 'kapso.contact.id', 'contact.id'])) !== null && blank($contato->kapso_contact_id)) {
                $mudancas['kapso_contact_id'] = mb_substr($contatoId, 0, 64);
            }

            if (($inbound = $this->momento($conversa, ['kapso.last_inbound_at', 'last_inbound_at'])) !== null && ($contato->ultima_resposta_em === null || $inbound->gt($contato->ultima_resposta_em))) {
                $mudancas['ultima_resposta_em'] = $inbound;
            }

            if (($outbound = $this->momento($conversa, ['kapso.last_outbound_at', 'last_outbound_at'])) !== null && ($contato->ultimo_envio_em === null || $outbound->gt($contato->ultimo_envio_em))) {
                $mudancas['ultimo_envio_em'] = $outbound;
            }

            if ($mudancas !== []) {
                $contato->forceFill($mudancas)->save();
            }
        }
    }

    /**
     * Mensagens da janela, uma direção por vez: outbound primeiro, para o contexto ("responde a um template
     * nosso?") já estar no banco quando as inbound forem classificadas.
     *
     * @param  array<string, mixed>  $c
     */
    private function mensagens(Sender $sender, KapsoClient $cliente, CarbonImmutable $desde, CarbonImmutable $ate, string $direcao, array &$c): void
    {
        $filtros = ['since' => $desde->toIso8601String(), 'until' => $ate->toIso8601String(), 'direction' => $direcao];
        $pagina = fn (?string $after): array => $cliente->mensagens($filtros, $after, $this->porPagina());

        foreach ($this->paginasOrdenadas($pagina, $cliente, $c) as $item) {
            $direcaoDoItem = $this->direcaoDe($sender, $item) ?? $direcao;

            // Se a Kapso ignorar o filtro de direção, cada mensagem ainda é tratada só na passada certa.
            if ($direcaoDoItem !== $direcao) {
                continue;
            }

            $c['mensagens']['lidas']++;
            $this->registrarMensagem($sender, $item, $direcao, $c);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $c
     */
    private function registrarMensagem(Sender $sender, array $item, string $direcao, array &$c): void
    {
        $wamid = $this->texto($item, ['id', 'wamid', 'message_id', 'kapso.wamid']);

        if ($wamid === null) {
            $c['mensagens']['sem_wamid']++;

            return;
        }

        $inbound = $direcao === WhatsappMessage::INBOUND;
        $telefone = $this->telefoneDe($item, $inbound ? ['from', 'kapso.phone_number', 'kapso.from', 'kapso.contact.phone_number'] : ['to', 'kapso.phone_number', 'kapso.to', 'kapso.contact.phone_number', 'recipient_id']);

        if ($telefone === null) {
            $c['mensagens']['sem_telefone']++;

            return;
        }

        $quando = $this->momento($item, ['timestamp', 'kapso.timestamp', 'kapso.created_at', 'created_at', 'kapso.sent_at']) ?? CarbonImmutable::now();
        $tipo = mb_substr((string) ($this->texto($item, ['type', 'kapso.type', 'kapso.message_type']) ?? ($this->templateNomeDe($item) !== null ? WhatsappMessage::TIPO_TEMPLATE : 'text')), 0, 20);
        $templateNome = $tipo === WhatsappMessage::TIPO_TEMPLATE || $this->templateNomeDe($item) !== null ? $this->templateNomeDe($item) : null;

        if ($templateNome !== null) {
            $tipo = WhatsappMessage::TIPO_TEMPLATE;
        }

        $categoria = $this->categoriaDe($sender, $item, $templateNome);
        $codigoErro = $this->codigoDeErro($item);
        $statusKapso = strtolower((string) ($this->texto($item, ['kapso.status', 'status']) ?? ''));
        $status = $inbound
            ? WhatsappMessage::STATUS_RECEIVED
            : (in_array($statusKapso, [WhatsappMessage::STATUS_PENDING, WhatsappMessage::STATUS_SENT, WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ, WhatsappMessage::STATUS_FAILED], true) ? $statusKapso : ($codigoErro !== null ? WhatsappMessage::STATUS_FAILED : WhatsappMessage::STATUS_SENT));

        $contato = $this->contato($sender, $telefone, $this->texto($item, ['kapso.contact_name', 'contact_name', 'profile.name', 'kapso.profile_name']), $c);

        if ($contato === null) {
            return;
        }

        $contextWamid = $this->texto($item, ['context.id', 'kapso.context_id', 'context_wamid']);

        $mensagem = WhatsappMessage::query()->firstOrCreate(['wamid' => mb_substr($wamid, 0, 120)], [
            'sender_id' => $sender->id,
            'contact_id' => $contato->id,
            'telefone' => $telefone,
            'direcao' => $direcao,
            'tipo' => $tipo,
            'template_nome' => $templateNome !== null ? mb_substr($templateNome, 0, 512) : null,
            // Texto nunca é gravado no histórico importado (LGPD): só passa pelo classificador em memória.
            'conteudo' => null,
            'status' => $status,
            'codigo_erro' => $codigoErro !== null ? mb_substr($codigoErro, 0, 40) : null,
            'origem' => WhatsappMessage::ORIGEM_HISTORICO_KAPSO,
            'context_wamid' => $contextWamid !== null ? mb_substr($contextWamid, 0, 120) : null,
            'kapso_conversation_id' => ($conversaId = $this->texto($item, ['kapso.whatsapp_conversation_id', 'kapso.conversation_id', 'conversation_id'])) !== null ? mb_substr($conversaId, 0, 64) : null,
            'enviado_em' => $quando,
            'entregue_em' => $this->momento($item, ['kapso.delivered_at', 'delivered_at']) ?? (in_array($status, [WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ], true) ? $quando : null),
            'lido_em' => $this->momento($item, ['kapso.read_at', 'read_at']) ?? ($status === WhatsappMessage::STATUS_READ ? $quando : null),
            'payload' => $categoria !== null ? ['categoria' => $categoria] : null,
        ]);

        if (! $mensagem->wasRecentlyCreated) {
            $c['mensagens']['ja_existiam']++;
            $this->reconciliar($mensagem, $status, $quando, $contato, $c);

            return;
        }

        $c['mensagens']['novas']++;
        $c['mensagens'][$direcao]++;

        if ($tipo === WhatsappMessage::TIPO_TEMPLATE) {
            $c['mensagens']['templates']++;
        }

        if ($inbound) {
            $contato->forceFill([
                'respostas_total' => $contato->respostas_total + 1,
                'ultima_resposta_em' => $contato->ultima_resposta_em === null || $quando->gt($contato->ultima_resposta_em) ? $quando : $contato->ultima_resposta_em,
            ])->save();

            $this->classificar($sender, $contato, $mensagem, $item, $quando, $c);

            return;
        }

        if ($status !== WhatsappMessage::STATUS_FAILED) {
            $contato->forceFill([
                'envios_total' => $contato->envios_total + 1,
                'ultimo_envio_em' => $contato->ultimo_envio_em === null || $quando->gt($contato->ultimo_envio_em) ? $quando : $contato->ultimo_envio_em,
                'ultimo_template_em' => $tipo === WhatsappMessage::TIPO_TEMPLATE && ($contato->ultimo_template_em === null || $quando->gt($contato->ultimo_template_em)) ? $quando : $contato->ultimo_template_em,
            ])->save();
        }

        $this->consequenciasDoErro($contato, $codigoErro, $quando, $c);
    }

    /**
     * Mensagem que já estava na trilha (saiu daqui, ou importação anterior): a entrega só avança. Serve
     * para reconciliar o `enviado_incerto` (timeout depois do POST) com o que a Kapso diz que aconteceu.
     *
     * @param  array<string, mixed>  $c
     */
    private function reconciliar(WhatsappMessage $mensagem, string $status, CarbonImmutable $quando, Contact $contato, array &$c): void
    {
        if ($mensagem->direcao !== WhatsappMessage::OUTBOUND || $status === WhatsappMessage::STATUS_FAILED || $status === WhatsappMessage::STATUS_RECEIVED) {
            return;
        }

        if (WhatsappMessage::rank($status) > WhatsappMessage::rank($mensagem->status) || ($mensagem->status === WhatsappMessage::STATUS_PENDING && $status !== WhatsappMessage::STATUS_PENDING)) {
            $mensagem->forceFill(array_filter([
                'status' => $status,
                'codigo_erro' => null,
                'erro' => null,
                'entregue_em' => $mensagem->entregue_em ?? (WhatsappMessage::rank($status) >= 2 ? $quando : null),
                'lido_em' => $mensagem->lido_em ?? ($status === WhatsappMessage::STATUS_READ ? $quando : null),
                'contact_id' => $mensagem->contact_id ?? $contato->id,
            ], fn ($v, $k): bool => $v !== null || in_array($k, ['codigo_erro', 'erro'], true), ARRAY_FILTER_USE_BOTH))->save();
            $c['mensagens']['status_avancado']++;
        }
    }

    /**
     * Inbound com texto: classificação completa COM contexto, como o webhook ao vivo (achado de 28/10 - no
     * modo "só parar sempre" quem respondeu "não quero" aos 650 envios frios seguia ativo). O texto fica
     * só na memória.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $c
     */
    private function classificar(Sender $sender, Contact $contato, WhatsappMessage $mensagem, array $item, CarbonImmutable $quando, array &$c): void
    {
        $textos = $this->textosDe($item);

        if ($textos === []) {
            return;
        }

        $template = $this->templateRespondido($sender, $contato->telefone, $mensagem->context_wamid, $quando);
        $foiBotao = in_array($mensagem->tipo, ['button', 'interactive'], true);

        if ($template !== null) {
            $c['classificadas']['em_resposta_a_template']++;
        }

        $classificacao = ClassificadorDeResposta::classificar($textos, $template !== null || $foiBotao);

        if ($classificacao === null) {
            return;
        }

        $c['classificadas'][$classificacao] = ($c['classificadas'][$classificacao] ?? 0) + 1;

        if ($classificacao === ClassificadorDeResposta::PARAR || $classificacao === ClassificadorDeResposta::PEDIDO_LGPD) {
            // Só a classificação: o texto do cliente nunca sai da memória (KB 7).
            $nova = Suppression::suprimir($contato->telefone, Suppression::MOTIVO_HISTORICO, 'historico_kapso', null, Suppression::ESCOPO_TUDO, $mensagem->wamid, 'classificado no histórico como '.$classificacao, null, $quando);

            if ($nova) {
                $c['supressoes'][Suppression::MOTIVO_HISTORICO] = ($c['supressoes'][Suppression::MOTIVO_HISTORICO] ?? 0) + 1;
            }

            if ($classificacao === ClassificadorDeResposta::PEDIDO_LGPD) {
                $prazo = $quando->addDays((int) config('disparo.lgpd.prazo_dias', 15));
                $mensagem->forceFill(['payload' => ['lgpd' => true, 'prazo' => $prazo->toIso8601String()]])->saveQuietly();
                ContactEvent::registrar($contato->telefone_hash, 'pedido_lgpd', ['mensagem_id' => $mensagem->id, 'prazo' => $prazo->toIso8601String(), 'origem' => 'historico_kapso'], null, $contato->id);
            } else {
                $mensagem->forceFill(['tratada_em' => $quando])->saveQuietly();
            }

            return;
        }

        if ($classificacao === ClassificadorDeResposta::NAO_AGORA && $template !== null && $this->categoriaDoTemplate($sender, $template) === Sender::CATEGORIA_MARKETING) {
            $contato->registrarRecusa($sender->id, $mensagem->wamid, $quando);
            $mensagem->forceFill(['tratada_em' => $quando])->saveQuietly();
            $c['recusas']++;
        }
    }

    /**
     * O template nosso que a inbound responde: o citado (`context.id`, qualquer data) ou o último template
     * outbound do mesmo remetente ao telefone nos 7 dias ANTERIORES à inbound (não a hoje: é histórico).
     */
    private function templateRespondido(Sender $sender, string $telefone, ?string $contextWamid, CarbonImmutable $quando): ?WhatsappMessage
    {
        $formas = Telefone::formas($telefone);
        $base = fn () => WhatsappMessage::query()
            ->where('sender_id', $sender->id)
            ->whereIn('telefone', $formas !== [] ? $formas : [$telefone])
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED);

        if ($contextWamid !== null) {
            $citado = $base()->where('wamid', $contextWamid)->first();

            if ($citado !== null) {
                return $citado;
            }
        }

        $dias = (int) config('disparo.historico.resposta_janela_dias', 7);

        return $base()->whereBetween('enviado_em', [$quando->subDays($dias), $quando])->latest('enviado_em')->latest('id')->first();
    }

    /** Categoria do template respondido: do payload (veio da Kapso) ou do cache de templates do remetente. */
    private function categoriaDoTemplate(Sender $sender, WhatsappMessage $template): ?string
    {
        $categoria = strtoupper((string) (((array) ($template->payload ?? []))['categoria'] ?? ''));

        if ($categoria !== '') {
            return $categoria;
        }

        return $template->template_nome !== null ? $this->categoriaDoCache($sender, $template->template_nome) : null;
    }

    /** @param  array<string, mixed>  $c */
    private function contatos(Sender $sender, KapsoClient $cliente, array &$c): void
    {
        foreach ($this->paginas(fn (?string $after): array => $cliente->contatos([], $after, $this->porPagina()), $cliente, $c) as $item) {
            $c['contatos']['lidos']++;
            $telefone = $this->telefoneDe($item, ['wa_id', 'phone_number', 'kapso.phone_number', 'kapso.wa_id']);

            if ($telefone === null) {
                continue;
            }

            // Só completa quem já existe: a lista de contatos do número inclui o atendimento inteiro.
            $contato = Contact::query()->porTelefone($telefone)->first();

            if ($contato === null || $contato->anonimizado()) {
                continue;
            }

            $nome = $this->texto($item, ['profile_name', 'profile.name', 'display_name', 'kapso.profile_name', 'kapso.display_name', 'name']);
            $mudou = $this->aplicarPerfil($contato, $nome);

            if (($id = $this->texto($item, ['id', 'kapso.id', 'kapso.contact_id'])) !== null && blank($contato->kapso_contact_id)) {
                $contato->kapso_contact_id = mb_substr($id, 0, 64);
                $mudou = true;
            }

            if ($mudou) {
                $contato->save();
                $c['contatos']['atualizados']++;
            }
        }
    }

    /**
     * Broadcasts (listas mandadas pelo painel da Kapso): cada destinatário vira mensagem outbound
     * `broadcast_kapso`, casada com a mensagem já registrada (mesmo telefone e template, ±30 min) para não
     * contar em dobro no teto e na frequência; `suppressed` é a preferência de marketing da Meta; os
     * `error_details.code` (131026, 131049) valem como no webhook.
     *
     * @param  array<string, mixed>  $c
     */
    private function broadcasts(Sender $sender, KapsoClient $cliente, CarbonImmutable $desde, CarbonImmutable $ate, array &$c): void
    {
        $tolerancia = max(1, (int) config('disparo.historico.broadcast_tolerancia_minutos', 30));

        foreach ($this->paginas(fn (?string $after): array => $cliente->broadcasts($after, $this->porPagina()), $cliente, $c) as $broadcast) {
            $c['broadcasts']['lidos']++;
            $id = $this->texto($broadcast, ['id']);

            if ($id === null) {
                continue;
            }

            $numero = $this->texto($broadcast, ['phone_number_id', 'whatsapp_phone_number_id', 'phone_number.id']);

            if ($numero !== null && $numero !== (string) $sender->phone_number_id) {
                continue;
            }

            $criadoEm = $this->momento($broadcast, ['sent_at', 'started_at', 'scheduled_at', 'created_at']);

            if ($criadoEm !== null && ($criadoEm->lt($desde->subDay()) || $criadoEm->gt($ate))) {
                $c['broadcasts']['fora_da_janela']++;

                continue;
            }

            $templateNome = $this->texto($broadcast, ['template_name', 'template.name', 'whatsapp_template.name', 'whatsapp_template_name']);
            $categoria = strtoupper((string) $this->texto($broadcast, ['template_category', 'template.category', 'category'])) ?: ($templateNome !== null ? $this->categoriaDoCache($sender, $templateNome) : null);

            foreach ($this->paginas(fn (?string $after): array => $cliente->destinatariosDoBroadcast($id, $after, $this->porPagina()), $cliente, $c) as $destinatario) {
                $c['broadcasts']['destinatarios']++;
                $this->registrarDestinatario($sender, $id, $destinatario, $templateNome, $categoria, $criadoEm, $tolerancia, $c);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $destinatario
     * @param  array<string, mixed>  $c
     */
    private function registrarDestinatario(Sender $sender, string $broadcastId, array $destinatario, ?string $templateNome, ?string $categoria, ?CarbonImmutable $criadoEm, int $tolerancia, array &$c): void
    {
        $telefone = $this->telefoneDe($destinatario, ['phone_number', 'wa_id', 'to', 'phone', 'recipient', 'contact.phone_number']);

        if ($telefone === null) {
            $c['broadcasts']['sem_telefone']++;

            return;
        }

        $rid = $this->texto($destinatario, ['id', 'recipient_id']) ?? Telefone::hash($telefone);
        $chave = mb_substr('broadcast:'.$broadcastId.':'.$rid, 0, 120);

        if (WhatsappMessage::query()->where('external_key', $chave)->exists()) {
            $c['broadcasts']['ja_existiam']++;

            return;
        }

        $quando = $this->momento($destinatario, ['sent_at', 'delivered_at', 'updated_at', 'created_at']) ?? $criadoEm ?? CarbonImmutable::now();
        $statusKapso = strtolower((string) ($this->texto($destinatario, ['status']) ?? 'sent'));
        $codigoErro = $this->codigoDeErro($destinatario);
        $suprimido = $statusKapso === 'suppressed' || in_array((string) $codigoErro, WhatsAppException::CODIGOS_MARKETING_PARADO, true);
        $status = match (true) {
            $suprimido, $statusKapso === 'failed' => WhatsappMessage::STATUS_FAILED,
            $statusKapso === 'pending', $statusKapso === 'queued' => WhatsappMessage::STATUS_PENDING,
            in_array($statusKapso, [WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ], true) => $statusKapso,
            default => WhatsappMessage::STATUS_SENT,
        };
        $contato = $this->contato($sender, $telefone, $this->texto($destinatario, ['contact_name', 'name', 'profile_name']), $c);

        if ($contato === null) {
            return;
        }

        $wamid = $this->texto($destinatario, ['wamid', 'message_id', 'whatsapp_message_id']);

        $existente = $wamid !== null ? WhatsappMessage::query()->where('wamid', $wamid)->first() : null;
        $existente ??= $templateNome === null ? null : WhatsappMessage::query()
            ->where('sender_id', $sender->id)
            ->whereIn('telefone', Telefone::formas($telefone) ?: [$telefone])
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('template_nome', $templateNome)
            ->whereNull('external_key')
            ->whereBetween('enviado_em', [$quando->subMinutes($tolerancia), $quando->addMinutes($tolerancia)])
            ->orderBy('id')
            ->first();

        if ($existente !== null) {
            $existente->forceFill(array_filter([
                'external_key' => $chave,
                'contact_id' => $existente->contact_id ?? $contato->id,
                'payload' => $categoria !== null && blank(((array) ($existente->payload ?? []))['categoria'] ?? null) ? array_merge((array) ($existente->payload ?? []), ['categoria' => $categoria]) : null,
            ], fn ($v): bool => $v !== null))->save();
            $this->reconciliar($existente, $status, $quando, $contato, $c);
            $c['broadcasts']['casados']++;
        } else {
            WhatsappMessage::query()->create([
                'sender_id' => $sender->id,
                'contact_id' => $contato->id,
                'telefone' => $telefone,
                'direcao' => WhatsappMessage::OUTBOUND,
                'wamid' => $wamid !== null ? mb_substr($wamid, 0, 120) : null,
                'external_key' => $chave,
                'tipo' => WhatsappMessage::TIPO_TEMPLATE,
                'template_nome' => $templateNome !== null ? mb_substr($templateNome, 0, 512) : null,
                'status' => $status,
                'codigo_erro' => $suprimido ? 'marketing_preference_stopped' : ($codigoErro !== null ? mb_substr($codigoErro, 0, 40) : null),
                'erro' => $suprimido ? 'A Kapso não enviou: o cliente parou as mensagens de marketing na Meta.' : (($erro = $this->texto($destinatario, ['error_details.message', 'error_message', 'error'])) !== null ? mb_substr(Mask::scrub($erro), 0, 1000) : null),
                'origem' => WhatsappMessage::ORIGEM_BROADCAST_KAPSO,
                'enviado_em' => $quando,
                'entregue_em' => $this->momento($destinatario, ['delivered_at']) ?? (WhatsappMessage::rank($status) >= 2 ? $quando : null),
                'lido_em' => $this->momento($destinatario, ['read_at']) ?? ($status === WhatsappMessage::STATUS_READ ? $quando : null),
                'payload' => array_filter(['categoria' => $categoria, 'broadcast_id' => $broadcastId]),
            ]);
            $c['broadcasts']['criados']++;

            if ($status !== WhatsappMessage::STATUS_FAILED) {
                $contato->forceFill([
                    'envios_total' => $contato->envios_total + 1,
                    'ultimo_envio_em' => $contato->ultimo_envio_em === null || $quando->gt($contato->ultimo_envio_em) ? $quando : $contato->ultimo_envio_em,
                    'ultimo_template_em' => $contato->ultimo_template_em === null || $quando->gt($contato->ultimo_template_em) ? $quando : $contato->ultimo_template_em,
                ])->save();
            }
        }

        if ($suprimido) {
            $nova = Suppression::suprimir($telefone, Suppression::MOTIVO_META_MARKETING_STOPPED, 'broadcast_kapso', $sender->id, Suppression::ESCOPO_MARKETING, $wamid, 'destinatário suprimido no broadcast '.$broadcastId, null, $quando);

            if ($nova) {
                $c['supressoes'][Suppression::MOTIVO_META_MARKETING_STOPPED] = ($c['supressoes'][Suppression::MOTIVO_META_MARKETING_STOPPED] ?? 0) + 1;
            }
        }

        $this->consequenciasDoErro($contato, $codigoErro, $quando, $c);
    }

    /**
     * 131026 (sem WhatsApp) e 131049 (a Meta limitou marketing ao usuário) contados do momento do evento,
     * não de hoje - um 131049 de três meses atrás não impõe carência agora.
     *
     * @param  array<string, mixed>  $c
     */
    private function consequenciasDoErro(Contact $contato, ?string $codigo, CarbonImmutable $quando, array &$c): void
    {
        if ($codigo === null) {
            return;
        }

        if ($codigo === WhatsAppException::CODIGO_SEM_WHATSAPP && ($contato->sem_whatsapp_em === null || $quando->gt($contato->sem_whatsapp_em))) {
            $contato->marcarSemWhatsApp($quando);
            $c['sem_whatsapp']++;
        }

        if ($codigo === WhatsAppException::CODIGO_MARKETING_CARENCIA) {
            $ate = $quando->addDays((int) config('disparo.guardas.marketing_carencia_dias', 7));

            if ($ate->gt(now()) && ($contato->marketing_carencia_ate === null || $ate->gt($contato->marketing_carencia_ate))) {
                $contato->forceFill(['marketing_carencia_ate' => $ate])->save();
                $c['carencias']++;
            }
        }
    }

    /**
     * O contato do telefone (criado como `origem=kapso`, tipo `outro`, se não existir). O nome do perfil
     * do WhatsApp só preenche vazio e fica em `atributos.whatsapp_profile`.
     *
     * @param  array<string, mixed>  $c
     */
    private function contato(Sender $sender, string $telefone, ?string $nomeDoPerfil, array &$c): ?Contact
    {
        $contato = Contact::query()->porTelefone($telefone)->first();

        if ($contato === null) {
            // Direito de exclusão exercido: o histórico não recria a pessoa (nem contato, nem perfil, nem mensagem).
            if (Suppression::bloqueia($telefone, null, null) === Suppression::MOTIVO_ANONIMIZADO) {
                $c['contatos']['anonimizados'] = ($c['contatos']['anonimizados'] ?? 0) + 1;

                return null;
            }

            $contato = new Contact([
                'telefone' => $telefone,
                'origem' => 'kapso',
                'tipo' => 'outro',
                'status' => Contact::STATUS_ATIVO,
                'atributos' => [],
            ]);
            $this->aplicarPerfil($contato, $nomeDoPerfil);
            $contato->save();

            if (($motivo = Suppression::bloqueia($telefone, null, null)) !== null) {
                $contato->marcarOptout($motivo, 'supressao');
            }

            $c['contatos']['criados']++;

            return $contato;
        }

        if (! $contato->anonimizado() && $this->aplicarPerfil($contato, $nomeDoPerfil)) {
            $contato->save();
            $c['contatos']['atualizados']++;
        }

        return $contato;
    }

    /** Nome do perfil do WhatsApp: preenche nome vazio (precedência mais baixa) e guarda em atributos. True = mudou. */
    private function aplicarPerfil(Contact $contato, ?string $nomeDoPerfil): bool
    {
        $nomeDoPerfil = trim((string) $nomeDoPerfil);

        if ($nomeDoPerfil === '') {
            return false;
        }

        $mudou = $contato->atualizarNome($nomeDoPerfil, 'kapso');
        $atributos = (array) ($contato->atributos ?? []);

        if (($atributos['whatsapp_profile'] ?? null) !== $nomeDoPerfil) {
            $atributos['whatsapp_profile'] = mb_substr($nomeDoPerfil, 0, 255);
            $contato->atributos = $atributos;
            $mudou = true;
        }

        return $mudou;
    }

    /** @param  array<string, mixed>  $item */
    private function direcaoDe(Sender $sender, array $item): ?string
    {
        $direcao = strtolower((string) ($this->texto($item, ['kapso.direction', 'direction']) ?? ''));

        if (in_array($direcao, [WhatsappMessage::INBOUND, WhatsappMessage::OUTBOUND], true)) {
            return $direcao;
        }

        $proprio = preg_replace('/\D/', '', (string) $sender->display_phone_number) ?? '';
        $from = preg_replace('/\D/', '', (string) ($this->texto($item, ['from']) ?? '')) ?? '';

        if ($proprio !== '' && $from !== '') {
            return $from === $proprio ? WhatsappMessage::OUTBOUND : WhatsappMessage::INBOUND;
        }

        return null;
    }

    /** @param  array<string, mixed>  $item */
    private function templateNomeDe(array $item): ?string
    {
        return $this->texto($item, ['template.name', 'kapso.template_name', 'kapso.message_type_data.template_name', 'kapso.message_type_data.name', 'template_name']);
    }

    /** @param  array<string, mixed>  $item */
    private function categoriaDe(Sender $sender, array $item, ?string $templateNome): ?string
    {
        $categoria = strtoupper((string) ($this->texto($item, ['template.category', 'kapso.template_category', 'kapso.message_type_data.category', 'kapso.category']) ?? ''));

        if ($categoria !== '') {
            return $categoria;
        }

        return $templateNome !== null ? $this->categoriaDoCache($sender, $templateNome) : null;
    }

    private function categoriaDoCache(Sender $sender, string $templateNome): ?string
    {
        static $cache = [];
        $chave = $sender->id.':'.$templateNome;

        if (! array_key_exists($chave, $cache)) {
            $categoria = MessageTemplate::query()->where('sender_id', $sender->id)->where('nome', $templateNome)->value('categoria');
            $cache[$chave] = is_string($categoria) && $categoria !== '' ? strtoupper($categoria) : null;
        }

        return $cache[$chave];
    }

    /** @param  array<string, mixed>  $item */
    private function codigoDeErro(array $item): ?string
    {
        $codigo = $this->texto($item, ['error_details.code', 'kapso.error_code', 'kapso.error.code', 'errors.0.code', 'kapso.errors.0.code', 'error.code', 'error_code']);

        return $codigo !== null && $codigo !== '' ? $codigo : null;
    }

    /**
     * Os textos candidatos da inbound (corpo, título do botão, resposta interativa), só em memória.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function textosDe(array $item): array
    {
        $textos = [];

        foreach (['text.body', 'button.text', 'interactive.button_reply.title', 'interactive.list_reply.title', 'kapso.content', 'kapso.text', 'kapso.message_type_data.text', 'kapso.message_type_data.body', 'kapso.message_type_data.title'] as $caminho) {
            $valor = data_get($item, $caminho);

            if (is_string($valor) && trim($valor) !== '') {
                $textos[] = trim($valor);
            }
        }

        return array_values(array_unique($textos));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $caminhos
     */
    private function telefoneDe(array $item, array $caminhos): ?string
    {
        foreach ($caminhos as $caminho) {
            $valor = data_get($item, $caminho);

            if ((is_string($valor) || is_int($valor)) && trim((string) $valor) !== '') {
                $telefone = Telefone::canonico((string) $valor);

                if ($telefone !== null) {
                    return $telefone;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $caminhos
     */
    private function texto(array $item, array $caminhos): ?string
    {
        foreach ($caminhos as $caminho) {
            $valor = data_get($item, $caminho);

            if ((is_string($valor) || is_int($valor)) && trim((string) $valor) !== '') {
                return trim((string) $valor);
            }
        }

        return null;
    }

    /**
     * Momento em ISO 8601 ou em segundos Unix (a Graph manda `timestamp` como string de segundos).
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $caminhos
     */
    private function momento(array $item, array $caminhos): ?CarbonImmutable
    {
        foreach ($caminhos as $caminho) {
            $valor = data_get($item, $caminho);

            // No fuso da aplicação: o Eloquent grava a hora de parede da instância, e um Carbon em UTC
            // (timestamp Unix, ISO com "Z") entraria 3 h deslocado no banco.
            if (is_int($valor) || (is_string($valor) && preg_match('/^\d{9,11}$/', $valor) === 1)) {
                return CarbonImmutable::createFromTimestamp((int) $valor)->setTimezone(config('app.timezone'));
            }

            if (is_string($valor) && trim($valor) !== '') {
                try {
                    return CarbonImmutable::parse($valor)->setTimezone(config('app.timezone'));
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private function porPagina(): int
    {
        return max(1, min(100, (int) config('disparo.historico.pagina', 100)));
    }

    /**
     * Páginas com os itens de cada uma em ordem cronológica (a Kapso pode devolver do mais novo ao mais
     * velho; dentro da página, o "pare" vir antes do "não" muda a contagem de recusas).
     *
     * @param  Closure(?string): array{data: list<array<string, mixed>>, after: ?string}  $pagina
     * @param  array<string, mixed>  $c
     * @return Generator<int, array<string, mixed>>
     */
    private function paginasOrdenadas(Closure $pagina, KapsoClient $cliente, array &$c): Generator
    {
        $ordenada = function (?string $after) use ($pagina): array {
            $resultado = $pagina($after);
            usort($resultado['data'], fn (array $a, array $b): int => ($this->momento($a, ['timestamp', 'kapso.timestamp', 'kapso.created_at', 'created_at'])?->getTimestamp() ?? 0) <=> ($this->momento($b, ['timestamp', 'kapso.timestamp', 'kapso.created_at', 'created_at'])?->getTimestamp() ?? 0));

            return $resultado;
        };

        yield from $this->paginas($ordenada, $cliente, $c);
    }

    /**
     * Percorre as páginas com cursor respeitando o rate limit da Kapso: poucas requisições restantes =
     * espera antes da próxima página; 429 = espera o Retry-After e tenta de novo (até N vezes).
     *
     * @param  Closure(?string): array{data: list<array<string, mixed>>, after: ?string}  $pagina
     * @param  array<string, mixed>  $c
     * @return Generator<int, array<string, mixed>>
     */
    private function paginas(Closure $pagina, KapsoClient $cliente, array &$c): Generator
    {
        $after = null;
        $tentativas = 0;
        $maxTentativas = max(1, (int) config('disparo.historico.rate_limit_tentativas', 3));
        $minimo = (int) config('disparo.historico.rate_limit_minimo', 20);
        $espera = max(1, (int) config('disparo.historico.rate_limit_espera_segundos', 15));

        while (true) {
            try {
                $resultado = $pagina($after);
            } catch (WhatsAppException $e) {
                if ($e->httpStatus === 429 && ++$tentativas < $maxTentativas) {
                    $c['esperas']++;
                    ($this->dormir)($cliente->retryAfter() ?? $espera);

                    continue;
                }

                throw $e;
            }

            $tentativas = 0;
            $c['paginas']++;

            foreach ($resultado['data'] as $item) {
                yield $item;
            }

            $anterior = $after;
            $after = $resultado['after'];

            if ($after === null) {
                return;
            }

            // Cursor que não anda ou páginas demais: o formato da Kapso ainda é [inferido]; melhor parar com aviso.
            if ($after === $anterior || $c['paginas'] >= (int) config('disparo.historico.max_paginas', 5000)) {
                Log::warning('Importação da Kapso interrompida: cursor repetido ou teto de páginas', ['paginas' => $c['paginas']]);
                $c['paginacao_interrompida'] = ($c['paginacao_interrompida'] ?? 0) + 1;

                return;
            }

            $restante = $cliente->rateLimitRestante();

            if ($restante !== null && $restante < $minimo) {
                $c['esperas']++;
                Log::info('Importação da Kapso perto do rate limit: esperando', ['restante' => $restante, 'segundos' => $espera]);
                ($this->dormir)($espera);
            }
        }
    }
}
