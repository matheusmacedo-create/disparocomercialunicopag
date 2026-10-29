<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Contact;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WebhookCall;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Webhook do App da Meta (remetente provider=meta): verificação, assinatura, statuses, mensagens e preferências. */
class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '5521998765432';

    private Sender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->sender = Sender::factory()->meta()->create();
    }

    public function test_verificacao_da_meta_devolve_o_challenge_so_com_o_token_certo(): void
    {
        $this->get('/api/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-de-teste&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345', false);

        $this->get('/api/webhooks/meta?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=12345')->assertStatus(403);
        $this->get('/api/webhooks/meta?hub.mode=unsubscribe&hub.verify_token=verify-de-teste&hub.challenge=12345')->assertStatus(403);
    }

    public function test_assinatura_invalida_responde_401(): void
    {
        $this->webhook($this->corpo(['statuses' => [['id' => 'wamid.X', 'status' => 'delivered']]]), segredo: 'outro')->assertStatus(401);
        $this->webhook($this->corpo(['statuses' => [['id' => 'wamid.X', 'status' => 'delivered']]]), segredo: null)->assertStatus(401);
    }

    public function test_status_e_mensagem_recebida_viram_eventos(): void
    {
        $contato = Contact::factory()->create(['telefone' => '+'.self::TELEFONE]);
        WhatsappMessage::query()->create([
            'sender_id' => $this->sender->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'wamid' => 'wamid.META1',
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'template_nome' => 'oferta_maquininha_v1',
            'status' => WhatsappMessage::STATUS_SENT,
            'origem' => WhatsappMessage::ORIGEM_CAMPANHA,
            'enviado_em' => now()->subMinutes(5),
            'payload' => ['categoria' => 'MARKETING'],
        ]);

        $this->webhook($this->corpo([
            'statuses' => [['id' => 'wamid.META1', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'recipient_id' => self::TELEFONE, 'conversation' => ['id' => 'c1'], 'pricing' => ['category' => 'marketing']]],
        ]))->assertOk()->assertJson(['ok' => true, 'processados' => 1]);

        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::query()->where('wamid', 'wamid.META1')->sole()->status);

        $this->webhook($this->corpo([
            'contacts' => [['profile' => ['name' => 'Cliente'], 'wa_id' => self::TELEFONE]],
            'messages' => [['from' => self::TELEFONE, 'id' => 'wamid.IN1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'pare de me mandar mensagem'], 'context' => ['from' => '5521900000000', 'id' => 'wamid.META1']]],
        ]))->assertOk()->assertJson(['processados' => 1]);

        $this->assertSame(Contact::STATUS_OPTOUT, $contato->fresh()->status);
        $this->assertSame(Suppression::ESCOPO_TUDO, Suppression::query()->where('telefone_hash', $contato->telefone_hash)->sole()->escopo);
        $this->assertSame(2, WebhookCall::query()->where('provider', 'meta')->count());
    }

    public function test_user_preferences_stop_vira_supressao_de_marketing(): void
    {
        $contato = Contact::factory()->create(['telefone' => '+'.self::TELEFONE]);

        $this->webhook($this->corpo([
            'user_preferences' => [['wa_id' => self::TELEFONE, 'detail' => 'User requested to stop marketing messages', 'category' => 'marketing_messages', 'value' => 'stop', 'timestamp' => (string) now()->timestamp]],
        ]))->assertOk()->assertJson(['processados' => 1]);

        $supressao = Suppression::query()->where('telefone_hash', $contato->telefone_hash)->sole();
        $this->assertSame(Suppression::ESCOPO_MARKETING, $supressao->escopo);
        $this->assertSame($this->sender->id, $supressao->sender_id);
    }

    public function test_phone_number_id_desconhecido_responde_200_sem_persistir(): void
    {
        $this->webhook($this->corpo(['messages' => [['from' => self::TELEFONE, 'id' => 'wamid.IN9', 'type' => 'text', 'text' => ['body' => 'pare']]]], phoneNumberId: '123123123'))
            ->assertOk()->assertJson(['processados' => 0]);

        $this->assertSame(0, WebhookCall::query()->count());
        $this->assertSame(0, WhatsappMessage::query()->count());
        $this->assertSame(0, Contact::query()->count());
    }

    /** @return array<string, mixed> */
    private function corpo(array $value, ?string $phoneNumberId = null): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => (string) $this->sender->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '5521900000000', 'phone_number_id' => $phoneNumberId ?? $this->sender->phone_number_id]] + $value,
                ]],
            ]],
        ];
    }

    private function webhook(array $corpo, ?string $segredo = 'segredo-meta-de-teste'): TestResponse
    {
        $cru = json_encode($corpo, JSON_THROW_ON_ERROR);
        $server = array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $segredo !== null ? 'sha256='.hash_hmac('sha256', $cru, $segredo) : null,
        ]);

        return $this->call('POST', route('webhooks.meta'), [], [], [], $server, $cru);
    }
}
