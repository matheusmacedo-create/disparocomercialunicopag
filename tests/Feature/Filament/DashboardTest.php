<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Campaign;
use App\Models\Sender;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_carrega_para_o_visualizador_com_banner_de_simulacao_e_historico(): void
    {
        config(['disparo.envio_habilitado' => false]);
        $sender = Sender::factory()->create(['historico_importado_ate' => now()->subDays(2)]);
        Campaign::factory()->for($sender)->emAndamento()->create(['nome' => 'Campanha rodando']);

        $this->actingAs($this->usuario(Permissions::ROLE_VIEWER));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Ambiente em SIMULAÇÃO')
            ->assertSee('Histórico da Kapso desatualizado')
            ->assertSee('Enviados hoje')
            ->assertSee('Envios por dia')
            ->assertSee('Templates com mais aderência')
            ->assertSee('Campanha rodando')
            ->assertSee($sender->nome);
    }

    public function test_sem_simulacao_e_com_historico_em_dia_os_avisos_somem(): void
    {
        config(['disparo.envio_habilitado' => true]);
        Sender::factory()->create(['historico_importado_ate' => now()->subHour()]);

        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee('Ambiente em SIMULAÇÃO')
            ->assertDontSee('Histórico da Kapso desatualizado');
    }
}
