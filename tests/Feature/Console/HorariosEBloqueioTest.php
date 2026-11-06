<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Contact;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** disparo:corrigir-horarios-webhook (reparo do UTC até 06/11/2026) e disparo:marcar-provavel-bloqueio (KB 2.9). */
class HorariosEBloqueioTest extends TestCase
{
    use RefreshDatabase;

    private function mensagem(Contact $contato, array $atributos): WhatsappMessage
    {
        $sender = Sender::query()->first() ?? Sender::factory()->create();

        return WhatsappMessage::query()->forceCreate(array_merge([
            'sender_id' => $sender->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'status' => WhatsappMessage::STATUS_SENT,
            'origem' => WhatsappMessage::ORIGEM_CAMPANHA,
            'wamid' => 'wamid.'.uniqid(),
        ], $atributos));
    }

    public function test_corrige_so_o_valor_igual_a_versao_utc_de_um_webhook(): void
    {
        $contato = Contact::factory()->create();
        Sender::factory()->create();
        $ts = CarbonImmutable::parse('2026-11-05 10:22:25', 'America/Sao_Paulo')->timestamp;
        // A Kapso devolve o horário real da mensagem e de cada status.
        Http::fake(['api.kapso.test/*' => Http::response(['data' => [
            ['id' => 'wamid.E', 'timestamp' => (string) ($ts - 5), 'kapso' => ['statuses' => [['status' => 'delivered', 'timestamp' => (string) $ts]]]],
        ]])]);

        $errada = $this->mensagem($contato, ['origem' => WhatsappMessage::ORIGEM_WEBHOOK, 'wamid' => 'wamid.E']);
        DB::table('whatsapp_messages')->where('id', $errada->id)->update(['enviado_em' => '2026-11-05 13:22:25', 'entregue_em' => '2026-11-05 13:22:25']);
        $certa = $this->mensagem($contato, ['origem' => WhatsappMessage::ORIGEM_HISTORICO_KAPSO, 'wamid' => 'wamid.C']);
        DB::table('whatsapp_messages')->where('id', $certa->id)->update(['enviado_em' => '2026-11-05 10:22:25']);

        $this->artisan('disparo:corrigir-horarios-webhook')->expectsOutputToContain('PRÉVIA')->assertSuccessful();
        $this->assertSame('2026-11-05 13:22:25', (string) DB::table('whatsapp_messages')->where('id', $errada->id)->value('enviado_em'));

        $this->artisan('disparo:corrigir-horarios-webhook', ['--aplicar' => true])->expectsOutputToContain('corrigidos: 2')->assertSuccessful();
        $linha = DB::table('whatsapp_messages')->where('id', $errada->id)->first();
        $this->assertStringStartsWith('2026-11-05 10:22:25', (string) $linha->enviado_em);
        $this->assertStringStartsWith('2026-11-05 10:22:25', (string) $linha->entregue_em);
        $this->assertStringStartsWith('2026-11-05 10:22:25', (string) DB::table('whatsapp_messages')->where('id', $certa->id)->value('enviado_em'));

        // Rodar de novo não muda nada.
        $this->artisan('disparo:corrigir-horarios-webhook', ['--aplicar' => true])->expectsOutputToContain('corrigidos: 0')->assertSuccessful();
    }

    public function test_template_sem_entrega_ha_48h_marca_provavel_bloqueio(): void
    {
        $semEntrega = Contact::factory()->create();
        $this->mensagem($semEntrega, ['enviado_em' => now()->subHours(50)]);

        // Entregue depois: recebe, não marca.
        $entregou = Contact::factory()->create();
        $this->mensagem($entregou, ['enviado_em' => now()->subHours(60)]);
        $this->mensagem($entregou, ['enviado_em' => now()->subHours(55), 'status' => WhatsappMessage::STATUS_DELIVERED, 'entregue_em' => now()->subHours(55)]);

        // Ainda no prazo.
        $recente = Contact::factory()->create();
        $this->mensagem($recente, ['enviado_em' => now()->subHours(10)]);

        $this->artisan('disparo:marcar-provavel-bloqueio')->expectsOutputToContain('marcados como provável bloqueio: 1')->assertSuccessful();
        $this->assertNull($semEntrega->fresh()->provavel_bloqueio_em);

        $this->artisan('disparo:marcar-provavel-bloqueio', ['--send' => true])->assertSuccessful();

        $this->assertTrue($semEntrega->fresh()->provavelBloqueio());
        $this->assertNull($entregou->fresh()->provavel_bloqueio_em);
        $this->assertNull($recente->fresh()->provavel_bloqueio_em);

        // Idempotente.
        $this->artisan('disparo:marcar-provavel-bloqueio', ['--send' => true])->expectsOutputToContain('marcados como provável bloqueio: 0')->assertSuccessful();
    }
}
