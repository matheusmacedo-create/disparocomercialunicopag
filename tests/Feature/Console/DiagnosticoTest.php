<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\WorkerHeartbeatJob;
use App\Models\Sender;
use App\Support\Batimentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `disparo:diagnostico`: passa com cron, worker, pepper e webhook da Kapso em dia; acusa o que falta. */
class DiagnosticoTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://disparo.unicopag.com.br', 'app.debug' => false, 'queue.default' => 'database', 'disparo.api_key' => 'chave']);
        $this->usuario();
        $this->sender = Sender::factory()->create(['webhook_secret' => 'segredo-de-teste']);
    }

    /** @param  list<array<string, mixed>>|null  $webhooks  null = o webhook desta instalação, ativo e completo */
    private function fakeWebhooksNaKapso(?array $webhooks = null): void
    {
        Http::fake([
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*/webhooks' => Http::response(['data' => $webhooks ?? [[
                'id' => 'wh-1',
                'url' => route('webhooks.kapso', ['webhookKey' => $this->sender->webhook_key]),
                'active' => true,
                'events' => array_merge(config('disparo.provedores.kapso.webhook_events'), ['whatsapp.contact.marketing_preference_changed']),
            ]]]),
        ]);
    }

    public function test_ambiente_saudavel_passa(): void
    {
        $this->fakeWebhooksNaKapso();
        Batimentos::registrarAgendador();
        // Com a fila em banco o job ficaria esperando um worker: aqui ele roda na hora, como o worker faria.
        WorkerHeartbeatJob::dispatchSync();

        $this->artisan('disparo:diagnostico')
            ->expectsOutputToContain('Ambiente ok.')
            ->assertSuccessful();
    }

    public function test_sem_batimentos_falha(): void
    {
        $this->fakeWebhooksNaKapso();
        $this->artisan('disparo:diagnostico')
            ->expectsOutputToContain('nunca rodou')
            ->expectsOutputToContain('nunca bateu')
            ->assertFailed();
    }

    public function test_remetente_kapso_sem_segredo_ou_sem_webhook_na_url_certa_falha(): void
    {
        Batimentos::registrarAgendador();
        Batimentos::registrarWorker();

        $this->fakeWebhooksNaKapso([['id' => 'x', 'url' => 'https://outro.exemplo/webhook', 'active' => true]]);

        $this->sender->forceFill(['webhook_secret' => null])->save();
        $this->artisan('disparo:diagnostico')->expectsOutputToContain('SEM segredo do webhook')->assertFailed();

        $this->sender->forceFill(['webhook_secret' => 'segredo-de-teste'])->save();
        $this->artisan('disparo:diagnostico')->expectsOutputToContain('não cadastrado com a URL desta instalação')->assertFailed();
    }

    public function test_pepper_ausente_falha(): void
    {
        $this->fakeWebhooksNaKapso();
        Batimentos::registrarAgendador();
        Batimentos::registrarWorker();
        config(['disparo.hash_pepper' => '']);

        $this->artisan('disparo:diagnostico')->expectsOutputToContain('vazio: nenhum hash')->assertFailed();
    }
}
