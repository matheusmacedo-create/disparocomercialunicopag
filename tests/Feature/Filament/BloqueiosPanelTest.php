<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Suppressions\Pages\CreateSuppression;
use App\Filament\Resources\Suppressions\Pages\ListSuppressions;
use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Models\Contact;
use App\Models\Suppression;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BloqueiosPanelTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contato = Contact::factory()->create(['telefone' => '+5521998765432']);
    }

    private function bloqueio(string $motivo): Suppression
    {
        Suppression::suprimir($this->contato->telefone, $motivo, 'resposta', null, Suppression::ESCOPO_TUDO, 'wamid.x', 'nao quero mais');

        return Suppression::query()->vigentes()->where('telefone_hash', $this->contato->telefone_hash)->sole();
    }

    public function test_admin_libera_bloqueio_manual_com_justificativa(): void
    {
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $this->actingAs($admin);
        $bloqueio = $this->bloqueio(Suppression::MOTIVO_PEDIU_PARA_PARAR);
        $this->assertSame(Contact::STATUS_OPTOUT, $this->contato->fresh()->status);

        Livewire::test(ListSuppressions::class)
            ->assertCanSeeTableRecords([$bloqueio])
            ->assertSee('+55 (21) 99876-5432')
            ->callTableAction('liberar', $bloqueio, data: ['justificativa' => 'curta'])
            ->assertHasActionErrors(['justificativa']);

        $this->assertNull($bloqueio->fresh()->liberada_em);

        Livewire::test(ListSuppressions::class)
            ->callTableAction('liberar', $bloqueio, data: ['justificativa' => '"não quero mais o boleto" era pedido ao atendimento, não opt-out.'])
            ->assertHasNoActionErrors();

        $bloqueio->refresh();
        $this->assertNotNull($bloqueio->liberada_em);
        $this->assertSame($admin->id, $bloqueio->liberada_por);
        $this->assertNull(Suppression::bloqueia($this->contato->telefone, null, null));
        $this->assertSame(Contact::STATUS_ATIVO, $this->contato->fresh()->status);
        $this->assertDatabaseHas('contact_events', ['telefone_hash' => $this->contato->telefone_hash, 'evento' => 'bloqueio_liberado', 'user_id' => $admin->id]);
    }

    public function test_denuncia_nunca_e_liberada_nem_forcando(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));
        $bloqueio = $this->bloqueio(Suppression::MOTIVO_DENUNCIOU);

        Livewire::test(ListSuppressions::class)
            ->assertTableActionHidden('liberar', $bloqueio)
            ->call('mountAction', 'liberar', [], ['table' => true, 'recordKey' => $bloqueio->getKey()])
            ->set('mountedActions.0.data.justificativa', 'tentativa de liberar denúncia pela força')
            ->call('callMountedAction');

        $this->assertNull($bloqueio->fresh()->liberada_em);
        $this->assertSame(Suppression::MOTIVO_DENUNCIOU, Suppression::bloqueia($this->contato->telefone, null, null));
    }

    public function test_operador_ve_a_lista_mascarada_e_nao_libera(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $bloqueio = $this->bloqueio(Suppression::MOTIVO_PEDIU_PARA_PARAR);

        $this->get('/admin/bloqueios')->assertOk();

        Livewire::test(ListSuppressions::class)
            ->assertSee('+55 21 9****-5432')
            ->assertDontSee('+55 (21) 99876-5432')
            ->assertTableActionHidden('liberar', $bloqueio)
            ->call('mountAction', 'liberar', [], ['table' => true, 'recordKey' => $bloqueio->getKey()])
            ->set('mountedActions.0.data.justificativa', 'tentativa sem permissão de liberar')
            ->call('callMountedAction');

        $this->assertNull($bloqueio->fresh()->liberada_em);
    }

    public function test_bloqueio_manual_pelo_painel_passa_pelo_suprimir(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);

        Livewire::test(CreateSuppression::class)
            ->fillForm(['telefone' => '21 99876-5432', 'escopo' => Suppression::ESCOPO_TUDO, 'motivo' => Suppression::MOTIVO_MANUAL, 'detalhes' => 'pediu no balcão'])
            ->call('create')
            ->assertHasNoFormErrors();

        $bloqueio = Suppression::query()->vigentes()->where('telefone_hash', $this->contato->telefone_hash)->sole();
        $this->assertSame('painel', $bloqueio->origem);
        $this->assertSame($operador->id, $bloqueio->user_id);
        $this->assertSame(Contact::STATUS_OPTOUT, $this->contato->fresh()->status);
        $this->assertSame('1', SuppressionResource::getNavigationBadge());

        // Visualizador não cria bloqueio.
        $this->actingAs($this->usuario(Permissions::ROLE_VIEWER));
        $this->get('/admin/bloqueios/create')->assertForbidden();
    }
}
