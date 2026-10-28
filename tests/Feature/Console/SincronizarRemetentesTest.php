<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Campaign;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Models\SenderQualitySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `disparo:sincronizar-remetentes`: snapshot e as transições de qualidade (RED trava, YELLOW pausa MARKETING, GREEN reaquece). */
class SincronizarRemetentesTest extends TestCase
{
    use RefreshDatabase;

    private function fakeNumero(string $qualidade): void
    {
        Http::fake([
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*/health' => Http::response(['status' => 'healthy', 'api_key' => 'nao-deve-ficar']),
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*' => Http::response(['data' => [
                'id' => '111', 'quality_rating' => $qualidade, 'messaging_limit_tier' => 'TIER_1K', 'status' => 'CONNECTED',
                'display_phone_number' => '+55 21 92367-4860', 'verified_name' => 'ÚnicoPag', 'kind' => 'production', 'business_account_id' => 'waba1',
            ]]),
        ]);
    }

    public function test_red_bloqueia_por_qualidade_e_grava_snapshot(): void
    {
        $this->fakeNumero('RED');
        $sender = Sender::factory()->create(['qualidade' => Sender::QUALIDADE_GREEN]);

        $this->artisan('disparo:sincronizar-remetentes')->expectsOutputToContain('GREEN → RED')->assertSuccessful();

        $sender->refresh();
        $this->assertSame(Sender::QUALIDADE_RED, $sender->qualidade);
        $this->assertNotNull($sender->bloqueio_qualidade_em);
        $this->assertSame('TIER_1K', $sender->limite_tier);
        $this->assertSame('CONNECTED', $sender->status_meta);
        $this->assertSame('healthy', $sender->saude['status']);
        $this->assertArrayNotHasKey('api_key', $sender->saude);
        $this->assertNotNull($sender->sincronizado_em);
        $this->assertSame(1, SenderQualitySnapshot::query()->where('sender_id', $sender->id)->where('qualidade', 'RED')->count());
        $this->assertTrue(SenderEvent::query()->where('sender_id', $sender->id)->where('evento', 'qualidade')->exists());
    }

    public function test_yellow_pausa_so_as_campanhas_marketing_em_andamento(): void
    {
        $this->fakeNumero('YELLOW');
        $sender = Sender::factory()->create(['qualidade' => Sender::QUALIDADE_GREEN]);
        $marketing = Campaign::factory()->for($sender)->emAndamento()->create();
        $marketing->forceFill(['template_categoria' => 'MARKETING'])->save();
        $utility = Campaign::factory()->for($sender)->emAndamento()->create(['template_nome' => 'aviso_conta_v1']);
        $utility->forceFill(['template_categoria' => 'UTILITY'])->save();

        $this->artisan('disparo:sincronizar-remetentes')->assertSuccessful();

        $this->assertSame(Campaign::STATUS_PAUSADA, $marketing->fresh()->status);
        $this->assertStringContainsString('YELLOW', (string) $marketing->fresh()->pausada_motivo);
        $this->assertTrue($marketing->events()->where('evento', 'pausada')->exists());
        $this->assertSame(Campaign::STATUS_EM_ANDAMENTO, $utility->fresh()->status);
        $this->assertNull($sender->fresh()->bloqueio_qualidade_em);
    }

    public function test_voltar_a_green_reinicia_o_aquecimento(): void
    {
        $this->fakeNumero('GREEN');
        $sender = Sender::factory()->create(['qualidade' => Sender::QUALIDADE_RED, 'aquecimento_inicio' => now()->subDays(20)->toDateString()]);

        $this->artisan('disparo:sincronizar-remetentes')->assertSuccessful();

        $this->assertSame(today()->toDateString(), $sender->fresh()->aquecimento_inicio->toDateString());
        $this->assertSame(Sender::QUALIDADE_GREEN, $sender->fresh()->qualidade);
    }

    public function test_rapido_so_le_remetentes_com_campanha_em_andamento(): void
    {
        $this->fakeNumero('GREEN');
        $comCampanha = Sender::factory()->create();
        Campaign::factory()->for($comCampanha)->emAndamento()->create();
        $semCampanha = Sender::factory()->create();

        $this->artisan('disparo:sincronizar-remetentes --rapido')->assertSuccessful();

        $this->assertNotNull($comCampanha->fresh()->sincronizado_em);
        $this->assertNull($semCampanha->fresh()->sincronizado_em);
    }
}
