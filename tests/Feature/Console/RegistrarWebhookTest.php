<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sender;
use App\Models\SenderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `disparo:registrar-webhook`: segredo novo, URL com a chave do remetente, eventos com a preferência de marketing, segredo fora da trilha. */
class RegistrarWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_na_kapso_e_mostra_o_segredo_uma_vez(): void
    {
        $sender = Sender::factory()->create(['webhook_secret' => 'segredo-antigo']);

        Http::fake([
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*/webhooks*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['data' => ['id' => 'wh-1', 'url' => $request->data()['whatsapp_webhook']['url'], 'active' => true, 'secret_key' => $request->data()['whatsapp_webhook']['secret_key']]]),
        ]);

        $this->assertSame(0, Artisan::call('disparo:registrar-webhook', ['sender' => (string) $sender->id]));
        $saida = Artisan::output();

        $sender->refresh();
        $novo = (string) $sender->webhook_secret;
        $this->assertNotSame('segredo-antigo', $novo);
        $this->assertSame(48, strlen($novo));
        $this->assertStringNotContainsString($novo, $saida, 'o segredo não aparece na saída (ficaria no histórico do Forge)');
        $this->assertStringContainsString(route('webhooks.kapso', ['webhookKey' => $sender->webhook_key]), $saida);

        Http::assertSent(function (Request $request) use ($sender, $novo): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $corpo = $request->data()['whatsapp_webhook'];

            return $corpo['url'] === route('webhooks.kapso', ['webhookKey' => $sender->webhook_key])
                && $corpo['secret_key'] === $novo
                && in_array('whatsapp.contact.marketing_preference_changed', $corpo['events'], true)
                && in_array('whatsapp.message.received', $corpo['events'], true);
        });

        $evento = SenderEvent::query()->where('sender_id', $sender->id)->where('evento', 'webhook_registrado')->sole();
        $this->assertSame('wh-1', $evento->detalhes['webhook_id']);
        $this->assertStringNotContainsString($novo, json_encode($evento->detalhes, JSON_THROW_ON_ERROR));
    }

    public function test_recusa_da_kapso_mantem_o_segredo_antigo(): void
    {
        $sender = Sender::factory()->create(['webhook_secret' => 'segredo-antigo']);

        Http::fake([
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*/webhooks*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => []])
                : Http::response(['error' => 'url inválida'], 422),
        ]);

        $this->artisan('disparo:registrar-webhook', ['sender' => $sender->phone_number_id])->assertFailed();

        $this->assertSame('segredo-antigo', $sender->fresh()->webhook_secret);
        $this->assertFalse(SenderEvent::query()->where('evento', 'webhook_registrado')->exists());
    }

    public function test_remetente_meta_ou_desconhecido_falha(): void
    {
        $meta = Sender::factory()->meta()->create();

        $this->artisan('disparo:registrar-webhook', ['sender' => (string) $meta->id])->assertFailed();
        $this->artisan('disparo:registrar-webhook', ['sender' => 'nao-existe'])->assertFailed();
    }
}
