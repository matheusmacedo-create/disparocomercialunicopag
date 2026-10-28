<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\WebhookCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** processar-reguas e podar (chamadas tolerantes a classes de outras frentes), sincronizar-templates e registrar-numero. */
class OutrosComandosTest extends TestCase
{
    use RefreshDatabase;

    public function test_processar_reguas_sem_a_classe_avisa_e_com_stub_chama(): void
    {
        $this->artisan('disparo:processar-reguas')->expectsOutputToContain('ainda não existe')->assertSuccessful();

        app()->bind('App\Services\Reguas\ProcessadorDeReguas', fn () => new class
        {
            public function rodar(): array
            {
                return ['processadas' => 3, 'enviadas' => 2];
            }

            public function reavaliarSegmentos(): array
            {
                return ['inscritos' => 4];
            }
        });

        $this->artisan('disparo:processar-reguas')->expectsOutputToContain('processadas: 3')->assertSuccessful();
        $this->artisan('disparo:processar-reguas --segmentos')->expectsOutputToContain('inscritos: 4')->assertSuccessful();
    }

    public function test_podar_apaga_webhook_calls_antigos_e_chama_a_retencao_quando_existe(): void
    {
        WebhookCall::query()->create(['provider' => 'kapso', 'created_at' => now()->subDays(40)]);
        WebhookCall::query()->create(['provider' => 'kapso', 'created_at' => now()->subDays(2)]);

        $this->artisan('disparo:podar')->expectsOutputToContain('1 registro(s) com mais de 30 dias')->expectsOutputToContain('ainda não existe')->assertSuccessful();
        $this->assertSame(1, WebhookCall::query()->count());

        app()->bind('App\Services\Importacao\Retencao', fn () => new class
        {
            public function executar(): array
            {
                return ['anonimizados' => 5];
            }
        });

        $this->artisan('disparo:podar')->expectsOutputToContain('anonimizados: 5')->assertSuccessful();
    }

    public function test_sincronizar_templates_atualiza_desativa_e_reclassifica(): void
    {
        $sender = Sender::factory()->create();
        $existente = MessageTemplate::factory()->for($sender)->create(['categoria' => 'UTILITY']);
        $sumiu = MessageTemplate::factory()->for($sender)->utility()->create();

        Http::fake([
            'api.kapso.test/meta/whatsapp/*/message_templates*' => Http::response(['data' => [
                array_merge($existente->definicao(), ['id' => '10', 'category' => 'MARKETING']),
                ['id' => '11', 'name' => 'novo_template', 'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Olá']]],
            ]]),
        ]);

        $this->artisan('disparo:sincronizar-templates')->assertSuccessful();

        $this->assertSame('MARKETING', $existente->fresh()->categoria);
        $this->assertSame('DISABLED', $sumiu->fresh()->status);
        $this->assertTrue(MessageTemplate::query()->where('sender_id', $sender->id)->where('nome', 'novo_template')->exists());
        $this->assertNotNull($sender->fresh()->templates_sincronizados_em);
    }

    public function test_registrar_numero_so_para_provider_meta_com_as_etapas(): void
    {
        $kapso = Sender::factory()->create();
        $this->artisan('disparo:registrar-numero', ['sender' => (string) $kapso->id, 'etapa' => 'status'])->assertFailed();

        $meta = Sender::factory()->meta()->create();
        Http::fake([
            'graph.facebook.com/*/request_code' => Http::response(['success' => true]),
            'graph.facebook.com/*/verify_code' => Http::response(['success' => true]),
            'graph.facebook.com/*/register' => Http::response(['success' => true]),
            'graph.facebook.com/*' => Http::response(['id' => $meta->phone_number_id, 'display_phone_number' => '+55 21 9', 'verified_name' => 'Teste', 'quality_rating' => 'GREEN', 'status' => 'DISCONNECTED', 'code_verification_status' => 'NOT_VERIFIED']),
        ]);

        $this->artisan('disparo:registrar-numero', ['sender' => (string) $meta->id, 'etapa' => 'status'])->expectsOutputToContain('DISCONNECTED')->assertSuccessful();
        $this->artisan('disparo:registrar-numero', ['sender' => (string) $meta->id, 'etapa' => 'solicitar', 'valor' => 'voice'])->assertSuccessful();
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/request_code') && $r['code_method'] === 'VOICE');

        $this->artisan('disparo:registrar-numero', ['sender' => (string) $meta->id, 'etapa' => 'verificar', 'valor' => '12'])->assertFailed();
        $this->artisan('disparo:registrar-numero', ['sender' => (string) $meta->id, 'etapa' => 'verificar', 'valor' => '123456'])->assertSuccessful();
        $this->artisan('disparo:registrar-numero', ['sender' => (string) $meta->id, 'etapa' => 'registrar', 'valor' => '654321'])->expectsOutputToContain('Guarde o PIN')->assertSuccessful();
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/register') && $r['pin'] === '654321');
        $this->artisan('disparo:registrar-numero', ['sender' => (string) $meta->id, 'etapa' => 'outra'])->assertFailed();
    }
}
