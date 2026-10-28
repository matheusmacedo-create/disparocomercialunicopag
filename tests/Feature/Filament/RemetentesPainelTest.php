<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Senders\Pages\CreateSender;
use App\Filament\Resources\Senders\Pages\EditSender;
use App\Filament\Resources\Senders\Pages\ViewSender;
use App\Models\Sender;
use App\Models\SenderQualitySnapshot;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RemetentesPainelTest extends TestCase
{
    use RefreshDatabase;

    public function test_chave_da_api_em_branco_mantem_a_atual(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));
        $sender = Sender::factory()->create(['api_key' => 'chave-original']);

        Livewire::test(EditSender::class, ['record' => $sender->getRouteKey()])
            ->assertOk()
            ->assertFormSet(['api_key' => null])
            ->fillForm(['nome' => 'Comercial novo', 'teto_diario' => 150])
            ->call('save')
            ->assertHasNoFormErrors();

        $sender->refresh();
        $this->assertSame('Comercial novo', $sender->nome);
        $this->assertSame(150, $sender->teto_diario);
        $this->assertSame('chave-original', $sender->api_key);
        $this->assertDatabaseHas('sender_events', ['sender_id' => $sender->id, 'evento' => 'politica_alterada']);

        Livewire::test(EditSender::class, ['record' => $sender->getRouteKey()])
            ->fillForm(['api_key' => 'chave-nova'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('chave-nova', $sender->refresh()->api_key);
    }

    public function test_teto_diario_nao_passa_do_maximo_absoluto(): void
    {
        config(['disparo.teto_maximo_absoluto' => 500]);
        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));

        Livewire::test(CreateSender::class)
            ->fillForm(['nome' => 'Novo', 'provider' => Sender::PROVIDER_KAPSO, 'finalidade' => Sender::FINALIDADE_COMERCIAL, 'phone_number_id' => '123456', 'teto_diario' => 900, 'msgs_por_minuto' => 20, 'janela_inicio' => '08:00', 'janela_fim' => '20:00', 'fuso' => 'America/Sao_Paulo', 'sonda_intervalo_minutos' => 120])
            ->call('create')
            ->assertHasFormErrors(['teto_diario']);

        $this->assertSame(0, Sender::query()->count());
    }

    public function test_religar_so_com_manage_senders(): void
    {
        $sender = Sender::factory()->create();
        $sender->pausar('teste');
        SenderQualitySnapshot::query()->create(['sender_id' => $sender->id, 'qualidade' => 'GREEN', 'medido_em' => now()->subHour(), 'created_at' => now()]);

        // Operador nem entra na tela do remetente.
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $this->get("/admin/senders/{$sender->id}")->assertForbidden();
        $this->assertNotNull($sender->refresh()->pausado_em);

        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));
        $this->get("/admin/senders/{$sender->id}")->assertOk()->assertSee('Qualidade do número');

        Livewire::test(ViewSender::class, ['record' => $sender->getRouteKey()])
            ->assertActionVisible('religar')
            ->assertActionHidden('pausar')
            ->callAction('religar')
            ->assertHasNoActionErrors();

        $sender->refresh();
        $this->assertNull($sender->pausado_em);
        $this->assertNotNull($sender->aquecimento_inicio);
        $this->assertDatabaseHas('sender_events', ['sender_id' => $sender->id, 'evento' => 'religado']);

        // Comando de outra frente ainda inexistente: a ação avisa, não estoura.
        Livewire::test(ViewSender::class, ['record' => $sender->getRouteKey()])
            ->callAction('sincronizar_numero')
            ->assertHasNoActionErrors()
            ->callAction('pausar', data: ['motivo' => 'manutenção'])
            ->assertHasNoActionErrors();

        $this->assertNotNull($sender->refresh()->pausado_em);
    }
}
