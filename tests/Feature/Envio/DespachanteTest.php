<?php

declare(strict_types=1);

namespace Tests\Feature\Envio;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use App\Services\Envio\Despachante;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Um envio com registro (KB 4.7): a linha existe como `pending` antes do POST, o webhook que chegou antes
 * é mesclado, e o `payload` nunca leva os valores das variáveis (só `conteudo`, atrás de `view pii`).
 */
class DespachanteTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create()->refresh();
        $this->template = MessageTemplate::factory()->for($this->sender)->create();
    }

    public function test_grava_a_mensagem_como_pending_antes_do_post_e_confirma_depois(): void
    {
        $vistoNoPost = null;

        Http::fake([
            'api.kapso.test/meta/whatsapp/*/messages' => function (Request $request) use (&$vistoNoPost) {
                $vistoNoPost = WhatsappMessage::query()->where('status', WhatsappMessage::STATUS_PENDING)->get(['id', 'campaign_recipient_id']);

                return Http::response(['messages' => [['id' => 'wamid.novo']]]);
            },
        ]);

        $contato = Contact::factory()->create(['nome' => 'Ana Souza']);

        [$mensagem, $excecao] = app(Despachante::class)->enviarTemplate($this->sender, $contato, $this->template, ['corpo' => ['{{primeiro_nome|tudo bem}}']], WhatsappMessage::ORIGEM_CAMPANHA, ['campaign_recipient_id' => 7]);

        $this->assertNull($excecao);
        $this->assertCount(1, $vistoNoPost, 'a linha pending tem de existir quando o POST sai');
        $this->assertSame(7, $vistoNoPost->first()->campaign_recipient_id);
        $this->assertSame($vistoNoPost->first()->id, $mensagem->id);

        $mensagem->refresh();
        $this->assertSame(WhatsappMessage::STATUS_SENT, $mensagem->status);
        $this->assertSame('wamid.novo', $mensagem->wamid);
        $this->assertSame(WhatsappMessage::ORIGEM_CAMPANHA, $mensagem->origem);
        $this->assertNotNull($mensagem->enviado_em);

        $contato->refresh();
        $this->assertSame(1, $contato->envios_total);
        $this->assertNotNull($contato->ultimo_template_em);

        Http::assertSent(function (Request $request): bool {
            $corpo = $request->data();

            return $request->method() === 'POST'
                && ($corpo['type'] ?? null) === 'template'
                && ($corpo['template']['name'] ?? null) === 'oferta_maquininha_v1'
                && ($corpo['template']['components'][0]['parameters'][0]['text'] ?? null) === 'Ana';
        });
    }

    public function test_webhook_que_criou_a_linha_antes_do_registro_e_mesclado(): void
    {
        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => Http::response(['messages' => [['id' => 'wamid.corrida']]])]);
        $contato = Contact::factory()->create();

        // O webhook `delivered` chegou (e foi gravado como `origem=webhook`) antes de o processo gravar o wamid.
        $doWebhook = WhatsappMessage::query()->create([
            'sender_id' => $this->sender->id,
            'telefone' => $contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'wamid' => 'wamid.corrida',
            'status' => WhatsappMessage::STATUS_DELIVERED,
            'origem' => WhatsappMessage::ORIGEM_WEBHOOK,
            'enviado_em' => now()->subSecond(),
            'entregue_em' => now(),
            'payload' => ['conversation_id' => 'conv-1'],
        ]);

        [$mensagem, $excecao] = app(Despachante::class)->enviarTemplate($this->sender, $contato, $this->template, [], WhatsappMessage::ORIGEM_CAMPANHA, ['campaign_recipient_id' => 9]);

        $this->assertNull($excecao);
        $this->assertSame($doWebhook->id, $mensagem->id);
        $this->assertSame(1, WhatsappMessage::query()->count(), 'a linha pending some; fica a do webhook');

        $mensagem->refresh();
        // A entrega que o webhook já tinha avançado fica; o vínculo e a origem passam a ser os nossos.
        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, $mensagem->status);
        $this->assertSame($contato->id, $mensagem->contact_id);
        $this->assertSame(9, $mensagem->campaign_recipient_id);
        $this->assertSame(WhatsappMessage::ORIGEM_CAMPANHA, $mensagem->origem);
        $this->assertSame(WhatsappMessage::TIPO_TEMPLATE, $mensagem->tipo);
        $this->assertSame('oferta_maquininha_v1', $mensagem->template_nome);
        $this->assertSame('conv-1', $mensagem->payload['conversation_id']);
        $this->assertSame(Sender::CATEGORIA_MARKETING, $mensagem->payload['categoria']);
    }

    public function test_payload_nao_leva_os_valores_das_variaveis(): void
    {
        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => Http::response(['messages' => [['id' => 'wamid.x']]])]);
        $contato = Contact::factory()->create(['nome' => 'Zuleide Pereira', 'email' => 'zuleide@exemplo.com']);

        [$mensagem] = app(Despachante::class)->enviarTemplate($this->sender, $contato, $this->template, ['corpo' => ['{{primeiro_nome}}']], WhatsappMessage::ORIGEM_CAMPANHA);

        $mensagem->refresh();
        $this->assertSame(['categoria' => Sender::CATEGORIA_MARKETING], $mensagem->payload);
        $this->assertStringNotContainsString('Zuleide', json_encode($mensagem->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('exemplo.com', json_encode($mensagem->payload, JSON_THROW_ON_ERROR));
        // Os valores ficam em `conteudo` (só com `view pii`).
        $this->assertStringContainsString('Zuleide', (string) $mensagem->conteudo);
    }

    public function test_recusa_do_provedor_fica_na_mensagem_e_nunca_lanca(): void
    {
        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => Http::response(['error' => ['message' => 'Message undeliverable', 'code' => 131026]], 400)]);
        $contato = Contact::factory()->create();

        [$mensagem, $excecao] = app(Despachante::class)->enviarTemplate($this->sender, $contato, $this->template, [], WhatsappMessage::ORIGEM_CAMPANHA);

        $this->assertNotNull($excecao);
        $this->assertSame('131026', (string) $excecao->codigo);
        $mensagem->refresh();
        $this->assertSame(WhatsappMessage::STATUS_FAILED, $mensagem->status);
        $this->assertSame('131026', $mensagem->codigo_erro);
        $this->assertSame('contato', $mensagem->payload['classe']);
        $this->assertNotNull($contato->fresh()->sem_whatsapp_em);
        $this->assertSame(1, $contato->fresh()->falhas_total);
        $this->assertSame(0, $this->sender->templatesNasUltimas24h(), 'falha confirmada não conta no teto');
    }

    public function test_texto_livre_com_autor_deixa_trilha(): void
    {
        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => Http::response(['messages' => [['id' => 'wamid.t']]])]);
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $contato = Contact::factory()->create();

        [$mensagem, $excecao] = app(Despachante::class)->enviarTexto($this->sender, $contato, 'Oi, segue o link', WhatsappMessage::ORIGEM_MANUAL, [], $admin->id);

        $this->assertNull($excecao);
        $mensagem->refresh();
        $this->assertSame(WhatsappMessage::STATUS_SENT, $mensagem->status);
        $this->assertSame(WhatsappMessage::TIPO_TEXTO, $mensagem->tipo);
        $this->assertSame($admin->id, $mensagem->user_id);
        $this->assertSame(1, ContactEvent::query()->where('evento', 'texto_enviado')->where('user_id', $admin->id)->count());
        Http::assertSent(fn (Request $request): bool => ($request->data()['type'] ?? null) === 'text' && ($request->data()['text']['body'] ?? null) === 'Oi, segue o link');
    }
}
