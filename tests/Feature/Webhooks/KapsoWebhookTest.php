<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WebhookCall;
use App\Models\WhatsappMessage;
use App\Support\Telefone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Webhook da Kapso (KB 10): assinatura por remetente, entrega que só avança, falhas tardias com as
 * consequências no contato, respostas classificadas inline (antes do 200), preferência de marketing,
 * wamid desconhecido contando no teto, lote, idempotência e corpo de outro número.
 */
class KapsoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '5521998765432';

    private Sender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->sender = Sender::factory()->create(['webhook_secret' => 'segredo-de-teste']);
    }

    public function test_assinatura_invalida_ou_remetente_sem_segredo_responde_401(): void
    {
        $corpo = $this->statusDaMensagem('wamid.X', 'delivered');

        $this->webhook('whatsapp.message.delivered', $corpo, segredo: 'outro')->assertStatus(401);
        $this->webhook('whatsapp.message.delivered', $corpo, segredo: null)->assertStatus(401);

        $this->sender->forceFill(['webhook_secret' => null])->save();
        $this->webhook('whatsapp.message.delivered', $corpo)->assertStatus(401);

        $this->assertSame(0, WebhookCall::query()->count());
    }

    public function test_chave_desconhecida_responde_404(): void
    {
        $corpo = $this->statusDaMensagem('wamid.X', 'delivered');

        $this->webhook('whatsapp.message.delivered', $corpo, chave: 'chavequeninguemtem0000000000')->assertStatus(404);
    }

    public function test_entrega_so_avanca_e_reflete_no_destinatario_e_na_campanha(): void
    {
        [$contato, $destinatario, $campanha] = $this->destinatarioEnviado('wamid.T1');

        $this->webhook('whatsapp.message.delivered', $this->statusDaMensagem('wamid.T1', 'delivered'))
            ->assertOk()->assertJson(['ok' => true, 'processados' => 1]);

        $mensagem = WhatsappMessage::query()->where('wamid', 'wamid.T1')->sole();
        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, $mensagem->status);
        $this->assertSame(CampaignRecipient::STATUS_ENTREGUE, $destinatario->fresh()->status);
        $this->assertSame(1, $campanha->fresh()->entregues);

        $this->webhook('whatsapp.message.read', $this->statusDaMensagem('wamid.T1', 'read'))->assertOk();
        $this->assertSame(WhatsappMessage::STATUS_READ, $mensagem->fresh()->status);
        $this->assertSame(CampaignRecipient::STATUS_LIDO, $destinatario->fresh()->status);
        $this->assertSame(1, $campanha->fresh()->lidos);
        $this->assertSame(1, $contato->fresh()->lidos_total);

        // "sent" e "delivered" atrasados não rebaixam.
        $this->webhook('whatsapp.message.sent', $this->statusDaMensagem('wamid.T1', 'sent'))->assertOk();
        $this->webhook('whatsapp.message.delivered', $this->statusDaMensagem('wamid.T1', 'delivered'))->assertOk();
        $this->assertSame(WhatsappMessage::STATUS_READ, $mensagem->fresh()->status);
        $this->assertSame(CampaignRecipient::STATUS_LIDO, $destinatario->fresh()->status);
        $this->assertSame(1, $campanha->fresh()->lidos);
    }

    public function test_falha_131026_marca_sem_whatsapp_e_destinatario_falhou(): void
    {
        [$contato, $destinatario, $campanha] = $this->destinatarioEnviado('wamid.T1');

        $this->webhook('whatsapp.message.failed', $this->statusDaMensagem('wamid.T1', 'failed', errors: [['code' => 131026, 'title' => 'Message undeliverable']]))
            ->assertOk()->assertJson(['processados' => 1]);

        $this->assertNotNull($contato->fresh()->sem_whatsapp_em);
        $mensagem = WhatsappMessage::query()->where('wamid', 'wamid.T1')->sole();
        $this->assertSame(WhatsappMessage::STATUS_FAILED, $mensagem->status);
        $this->assertSame('131026', $mensagem->codigo_erro);
        $this->assertStringStartsWith('Não entregue: Número sem WhatsApp', (string) $mensagem->erro);
        $this->assertSame(CampaignRecipient::STATUS_FALHOU, $destinatario->fresh()->status);
        $this->assertSame(1, $campanha->fresh()->falhas);
        $this->assertSame(0, $campanha->fresh()->enviados);
    }

    public function test_falha_131050_vira_supressao_de_marketing_do_remetente(): void
    {
        [$contato] = $this->destinatarioEnviado('wamid.T1');

        $this->webhook('whatsapp.message.failed', $this->statusDaMensagem('wamid.T1', 'failed', errors: [['code' => 131050, 'title' => 'User has stopped marketing messages']]))->assertOk();

        $supressao = Suppression::query()->where('telefone_hash', $contato->telefone_hash)->sole();
        $this->assertSame(Suppression::ESCOPO_MARKETING, $supressao->escopo);
        $this->assertSame($this->sender->id, $supressao->sender_id);
        $this->assertSame(Suppression::MOTIVO_META_MARKETING_STOPPED, $supressao->motivo);
        $this->assertSame(Contact::STATUS_ATIVO, $contato->fresh()->status);
    }

    public function test_pare_vira_supressao_total_optout_e_mensagem_tratada(): void
    {
        [$contato] = $this->destinatarioEnviado('wamid.T1');

        $this->webhook('whatsapp.message.received', $this->recebida('Pare!'))->assertOk()->assertJson(['processados' => 1]);

        $supressao = Suppression::query()->where('telefone_hash', $contato->telefone_hash)->sole();
        $this->assertSame(Suppression::ESCOPO_TUDO, $supressao->escopo);
        $this->assertNull($supressao->sender_id);
        $this->assertSame(Suppression::MOTIVO_PEDIU_PARA_PARAR, $supressao->motivo);
        $this->assertSame(Contact::STATUS_OPTOUT, $contato->fresh()->status);

        $inbound = WhatsappMessage::query()->where('direcao', WhatsappMessage::INBOUND)->sole();
        $this->assertNotNull($inbound->tratada_em);
        $this->assertSame('Pare!', $inbound->conteudo);
        // A trilha do webhook não guarda telefone nem texto.
        $registro = WebhookCall::query()->latest('id')->first();
        $this->assertStringNotContainsString(self::TELEFONE, json_encode($registro->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Pare', json_encode($registro->payload, JSON_THROW_ON_ERROR));
    }

    public function test_nao_a_template_marketing_registra_recusa_sem_bloquear(): void
    {
        [$contato, $destinatario] = $this->destinatarioEnviado('wamid.T1');

        $this->webhook('whatsapp.message.received', $this->recebida('Não', tipo: 'button', citando: 'wamid.T1'))->assertOk();

        $contato->refresh();
        $this->assertNotNull($contato->recusou_em);
        $this->assertSame(1, $contato->recusas_total);
        $this->assertSame(Contact::STATUS_ATIVO, $contato->status);
        $this->assertSame(0, Suppression::query()->count());
        $this->assertSame(CampaignRecipient::STATUS_RESPONDIDO, $destinatario->fresh()->status);
        $this->assertNotNull(WhatsappMessage::query()->where('direcao', WhatsappMessage::INBOUND)->sole()->tratada_em);
    }

    public function test_pedido_lgpd_bloqueia_e_abre_item_com_prazo(): void
    {
        [$contato] = $this->destinatarioEnviado('wamid.T1');

        $this->webhook('whatsapp.message.received', $this->recebida('Quero que apaguem todos os meus dados'))->assertOk();

        $this->assertSame(Suppression::ESCOPO_TUDO, Suppression::query()->where('telefone_hash', $contato->telefone_hash)->sole()->escopo);
        $inbound = WhatsappMessage::query()->where('direcao', WhatsappMessage::INBOUND)->sole();
        $this->assertTrue($inbound->payload['lgpd']);
        $this->assertNotEmpty($inbound->payload['prazo']);
        $this->assertNull($inbound->tratada_em);
        $this->assertTrue(ContactEvent::query()->where('telefone_hash', $contato->telefone_hash)->where('evento', 'pedido_lgpd')->exists());
    }

    public function test_preferencia_de_marketing_stop_e_resume(): void
    {
        $contato = $this->contato();

        $this->webhook('whatsapp.contact.marketing_preference_changed', $this->preferencia('stopped'))->assertOk()->assertJson(['processados' => 1]);

        $supressao = Suppression::query()->where('telefone_hash', $contato->telefone_hash)->sole();
        $this->assertSame(Suppression::ESCOPO_MARKETING, $supressao->escopo);
        $this->assertSame($this->sender->id, $supressao->sender_id);
        $this->assertNull($supressao->liberada_em);
        $this->assertNotNull(Suppression::bloqueia($contato->telefone, $this->sender, 'MARKETING'));

        $this->webhook('whatsapp.contact.marketing_preference_changed', $this->preferencia('resumed'))->assertOk();

        $this->assertNotNull($supressao->fresh()->liberada_em);
        $this->assertNull(Suppression::bloqueia($contato->telefone, $this->sender, 'MARKETING'));
    }

    public function test_wamid_desconhecido_vira_mensagem_origem_webhook_e_conta_no_teto(): void
    {
        $contato = $this->contato();

        $this->webhook('whatsapp.message.sent', $this->statusDaMensagem('wamid.DO_PAINEL', 'sent'))->assertOk()->assertJson(['processados' => 1]);

        $mensagem = WhatsappMessage::query()->where('wamid', 'wamid.DO_PAINEL')->sole();
        $this->assertSame(WhatsappMessage::ORIGEM_WEBHOOK, $mensagem->origem);
        $this->assertSame(WhatsappMessage::TIPO_TEMPLATE, $mensagem->tipo);
        $this->assertSame('oferta_maquininha_v1', $mensagem->template_nome);
        $this->assertSame($contato->id, $mensagem->contact_id);
        $this->assertSame(1, $this->sender->templatesNasUltimas24h());

        $this->webhook('whatsapp.message.delivered', $this->statusDaMensagem('wamid.DO_PAINEL', 'delivered'))->assertOk();
        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, $mensagem->fresh()->status);
    }

    public function test_lote_processa_cada_payload_e_le_o_evento_do_corpo(): void
    {
        $this->destinatarioEnviado('wamid.A');
        $outro = Contact::factory()->create(['telefone' => '+5521988887777']);
        WhatsappMessage::query()->create($this->mensagemOutbound('wamid.B', $outro));

        $this->webhook(null, [
            'type' => 'whatsapp.message.delivered',
            'batch' => true,
            'data' => [
                $this->statusDaMensagem('wamid.A', 'delivered'),
                $this->statusDaMensagem('wamid.B', 'delivered', para: '5521988887777'),
                ['message' => ['kapso' => []]],
            ],
            'batch_info' => ['size' => 3, 'window_ms' => 5000],
        ])->assertOk()->assertJson(['ok' => true, 'processados' => 2, 'ignorados' => 1]);

        $this->assertSame(2, WhatsappMessage::query()->where('status', WhatsappMessage::STATUS_DELIVERED)->count());
    }

    public function test_idempotencia_pela_x_idempotency_key(): void
    {
        $this->contato();
        $corpo = $this->recebida('Oi, quero saber mais');

        $this->webhook('whatsapp.message.received', $corpo, idempotencyKey: 'entrega-1')->assertOk()->assertJson(['processados' => 1]);
        $this->webhook('whatsapp.message.received', $corpo, idempotencyKey: 'entrega-1')->assertOk()->assertJson(['duplicado' => true, 'processados' => 0]);

        $this->assertSame(1, WebhookCall::query()->count());
        $registro = WebhookCall::query()->sole();
        $this->assertSame('entrega-1', $registro->idempotency_key);
        $this->assertNotNull($registro->processado_em);
        $this->assertSame('whatsapp.message.received', $registro->evento);
        $this->assertSame(1, WhatsappMessage::query()->count());
    }

    public function test_corpo_de_outro_phone_number_id_e_ignorado_com_202(): void
    {
        $this->contato();
        $corpo = $this->recebida('Pare');
        $corpo['phone_number_id'] = '999999999999';

        $this->webhook('whatsapp.message.received', $corpo)->assertStatus(202)->assertJson(['processados' => 0, 'ignorados' => 1]);

        $this->assertSame(0, WhatsappMessage::query()->count());
        $this->assertSame(0, Suppression::query()->count());
        $this->assertSame(1, WebhookCall::query()->sole()->payload['outro_numero']);
    }

    public function test_received_com_direcao_outbound_e_evento_desconhecido_nao_contam(): void
    {
        $this->contato();
        $corpo = $this->recebida('Pare');
        $corpo['message']['kapso']['direction'] = 'outbound';

        $this->webhook('whatsapp.message.received', $corpo)->assertOk()->assertJson(['processados' => 0]);
        $this->webhook('whatsapp.conversation.created', $this->recebida('Pare'))->assertOk()->assertJson(['processados' => 0]);

        $this->assertSame(0, Suppression::query()->count());
    }

    private function contato(): Contact
    {
        return Contact::factory()->create(['telefone' => '+'.self::TELEFONE]);
    }

    /** @return array{0: Contact, 1: CampaignRecipient, 2: Campaign} */
    private function destinatarioEnviado(string $wamid): array
    {
        $contato = $this->contato();
        $campanha = Campaign::factory()->for($this->sender)->emAndamento()->create();
        $destinatario = CampaignRecipient::query()->create([
            'campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'status' => CampaignRecipient::STATUS_ENVIADO,
            'wamid' => $wamid,
            'enviado_em' => now()->subMinutes(10),
        ]);
        $campanha->forceFill(['total_destinatarios' => 1, 'enviados' => 1])->save();

        $mensagem = WhatsappMessage::query()->create($this->mensagemOutbound($wamid, $contato) + ['campaign_recipient_id' => $destinatario->id]);
        $destinatario->forceFill(['whatsapp_message_id' => $mensagem->id])->save();

        return [$contato, $destinatario, $campanha];
    }

    /** @return array<string, mixed> */
    private function mensagemOutbound(string $wamid, Contact $contato): array
    {
        return [
            'sender_id' => $this->sender->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'wamid' => $wamid,
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'template_nome' => 'oferta_maquininha_v1',
            'status' => WhatsappMessage::STATUS_SENT,
            'origem' => WhatsappMessage::ORIGEM_CAMPANHA,
            'enviado_em' => now()->subMinutes(10),
            'payload' => ['categoria' => 'MARKETING'],
        ];
    }

    /** @return array<string, mixed> */
    private function statusDaMensagem(string $wamid, string $status, ?int $timestamp = null, array $errors = [], string $para = self::TELEFONE): array
    {
        $timestamp ??= now()->timestamp;

        return [
            'message' => [
                'id' => $wamid,
                'timestamp' => (string) now()->subHour()->timestamp,
                'type' => 'template',
                'to' => $para,
                'template' => ['name' => 'oferta_maquininha_v1', 'language' => ['code' => 'pt_BR']],
                'kapso' => [
                    'direction' => 'outbound',
                    'status' => $status,
                    'statuses' => [array_filter([
                        'id' => $wamid,
                        'status' => $status,
                        'timestamp' => (string) $timestamp,
                        'recipient_id' => $para,
                        'pricing' => ['category' => 'marketing', 'billable' => true],
                        'errors' => $errors ?: null,
                    ])],
                ],
            ],
            'conversation' => ['id' => 'conv-1', 'phone_number' => $para],
            'is_new_conversation' => false,
            'phone_number_id' => $this->sender->phone_number_id,
        ];
    }

    /** @return array<string, mixed> */
    private function recebida(string $texto, string $tipo = 'text', ?string $citando = null, string $from = self::TELEFONE): array
    {
        return [
            'message' => array_filter([
                'id' => 'wamid.IN'.md5($texto.$from.$tipo),
                'timestamp' => (string) now()->timestamp,
                'type' => $tipo,
                'from' => $from,
                'context' => $citando !== null ? ['from' => '5521923674860', 'id' => $citando] : null,
                'text' => $tipo === 'text' ? ['body' => $texto] : null,
                'button' => $tipo === 'button' ? ['text' => $texto, 'payload' => $texto] : null,
                'kapso' => ['direction' => 'inbound', 'status' => 'received', 'content' => $texto],
            ]),
            'conversation' => ['id' => 'conv-1', 'phone_number' => $from, 'contact_name' => 'Cliente'],
            'is_new_conversation' => false,
            'phone_number_id' => $this->sender->phone_number_id,
        ];
    }

    /** @return array<string, mixed> */
    private function preferencia(string $status): array
    {
        return [
            'phone_number_id' => $this->sender->phone_number_id,
            'phone_number' => self::TELEFONE,
            'status' => $status,
            'marketing_allowed' => $status === 'resumed',
            'source' => 'meta_webhook',
            'sequence' => $status === 'stopped' ? 1 : 2,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function webhook(?string $evento, array $corpo, ?string $segredo = 'segredo-de-teste', ?string $chave = null, ?string $idempotencyKey = null): TestResponse
    {
        $cru = json_encode($corpo, JSON_THROW_ON_ERROR);
        $server = array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_EVENT' => $evento,
            'HTTP_X_WEBHOOK_SIGNATURE' => $segredo !== null ? hash_hmac('sha256', $cru, $segredo) : null,
            'HTTP_X_IDEMPOTENCY_KEY' => $idempotencyKey,
        ]);

        return $this->call('POST', route('webhooks.kapso', ['webhookKey' => $chave ?? $this->sender->webhook_key]), [], [], [], $server, $cru);
    }
}
