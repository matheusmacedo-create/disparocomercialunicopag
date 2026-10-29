<?php

declare(strict_types=1);

namespace Tests\Feature\Envio;

use App\Models\Sender;
use App\Models\SenderQualitySnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Teto efetivo do remetente (KB 2.11): aquecimento por dia GREEN, tier da Meta, YELLOW, RED e o teto absoluto. */
class SenderTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(Sender $sender, string $qualidade, CarbonInterface $em): void
    {
        SenderQualitySnapshot::query()->create(['sender_id' => $sender->id, 'qualidade' => $qualidade, 'medido_em' => $em, 'created_at' => $em]);
    }

    public function test_aquecimento_so_sobe_nos_dias_green_medidos_nos_snapshots(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'America/Sao_Paulo'));

        $sender = Sender::factory()->create([
            'teto_diario' => 200,
            'aquecimento_inicio' => now()->subDays(3)->toDateString(),
            'aquecimento_teto_inicial' => 50,
            'aquecimento_fator' => 1.5,
        ])->refresh();

        // Sem leitura nenhuma: primeiro degrau.
        $this->assertSame(50, $sender->tetoEfetivo());

        // Dois dias GREEN, um YELLOW: só os GREEN sobem o degrau (50 × 1,5²).
        $this->snapshot($sender, Sender::QUALIDADE_GREEN, now()->subDays(3)->setTime(9, 0));
        $this->snapshot($sender, Sender::QUALIDADE_GREEN, now()->subDays(3)->setTime(15, 0)); // mesmo dia, conta uma vez
        $this->snapshot($sender, Sender::QUALIDADE_GREEN, now()->subDays(2)->setTime(9, 0));
        $this->snapshot($sender, Sender::QUALIDADE_YELLOW, now()->subDay()->setTime(9, 0));
        $this->snapshot($sender, Sender::QUALIDADE_GREEN, now()->setTime(9, 0)); // hoje não conta

        $this->assertSame(2, $sender->diasDeAquecimentoConcluidos());
        $this->assertSame(112, $sender->tetoEfetivo());

        // Nunca passa do teto configurado.
        $sender->forceFill(['aquecimento_inicio' => now()->subDays(30)->toDateString()])->save();
        foreach (range(1, 12) as $dia) {
            $this->snapshot($sender, Sender::QUALIDADE_GREEN, now()->subDays($dia)->setTime(9, 0));
        }
        $this->assertSame(200, $sender->fresh()->tetoEfetivo());
    }

    public function test_tier_da_meta_limita_a_fracao_configurada(): void
    {
        $sender = Sender::factory()->create(['teto_diario' => 1000, 'limite_tier' => 'TIER_250'])->refresh();
        $this->assertSame(250, $sender->limiteDoTier());
        $this->assertSame(200, $sender->tetoEfetivo());

        $sender->forceFill(['limite_tier' => 'TIER_1K'])->save();
        $this->assertSame(1000, $sender->limiteDoTier());
        $this->assertSame(800, $sender->tetoEfetivo());

        $sender->forceFill(['limite_tier' => 'TIER_UNLIMITED'])->save();
        $this->assertNull($sender->limiteDoTier());
        $this->assertSame(1000, $sender->tetoEfetivo());

        // Tier maior que o teto configurado não sobe nada.
        $this->assertSame(200, Sender::factory()->create(['teto_diario' => 200, 'limite_tier' => 'TIER_10K'])->refresh()->tetoEfetivo());
    }

    public function test_yellow_corta_pela_metade_red_e_bloqueio_zeram(): void
    {
        $yellow = Sender::factory()->create(['teto_diario' => 200, 'qualidade' => Sender::QUALIDADE_YELLOW])->refresh();
        $this->assertSame(100, $yellow->tetoEfetivo());
        $this->assertSame(30, $yellow->tetoDeMarketing());

        $red = Sender::factory()->create(['teto_diario' => 200, 'qualidade' => Sender::QUALIDADE_RED])->refresh();
        $this->assertSame(0, $red->tetoEfetivo());

        $bloqueado = Sender::factory()->create(['teto_diario' => 200])->refresh();
        $bloqueado->bloquearPorQualidade('131048');
        $this->assertSame(0, $bloqueado->tetoEfetivo());
        $this->assertTrue($bloqueado->pausado());
        $this->assertFalse($bloqueado->sondaDevida());

        $green = Sender::factory()->create(['teto_diario' => 200])->refresh();
        $this->assertSame(200, $green->tetoEfetivo());
        $this->assertSame(60, $green->tetoDeMarketing());
    }

    public function test_teto_absoluto_vale_mesmo_editado_no_painel(): void
    {
        config(['disparo.teto_maximo_absoluto' => 1000]);

        $sender = Sender::factory()->create(['teto_diario' => 5000])->refresh();
        $this->assertSame(1000, $sender->teto_diario);
        $this->assertSame(1000, $sender->tetoEfetivo());

        $sender->update(['teto_diario' => 3000]);
        $this->assertSame(1000, $sender->fresh()->teto_diario);
    }

    public function test_conta_impedida_agenda_sonda_e_religar_reinicia_o_aquecimento(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'America/Sao_Paulo'));
        $sender = Sender::factory()->create()->refresh();

        $sender->impedir('pagamento');
        $this->assertTrue($sender->pausado());
        $this->assertFalse($sender->sondaDevida());
        $this->assertSame(now()->addMinutes(120)->toDateTimeString(), $sender->sonda_em->toDateTimeString());

        $this->travelTo(now()->addMinutes(121));
        $this->assertTrue($sender->fresh()->sondaDevida());

        $sender->despausar();
        $this->assertFalse($sender->pausado());
        $this->assertNull($sender->sonda_em);

        $sender->bloquearPorQualidade('131048');
        $sender->religar();
        $this->assertFalse($sender->pausado());
        $this->assertSame(now()->toDateString(), $sender->aquecimento_inicio->toDateString());
        $this->assertSame(50, $sender->tetoEfetivo());
    }
}
