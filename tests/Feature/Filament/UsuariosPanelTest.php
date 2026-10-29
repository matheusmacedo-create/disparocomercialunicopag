<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UsuariosPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_sem_papel_ou_inativo_nao_entra_no_painel(): void
    {
        $this->usuario(); // garante os papéis e o primeiro Super Admin
        $semPapel = User::factory()->create();
        $this->actingAs($semPapel)->get('/admin')->assertForbidden();

        $inativo = $this->usuario(Permissions::ROLE_ADMIN, ['ativo' => false]);
        $this->actingAs($inativo)->get('/admin')->assertForbidden();

        $ativo = $this->usuario(Permissions::ROLE_VIEWER);
        $this->actingAs($ativo)->get('/admin')->assertOk();
    }

    public function test_so_quem_gerencia_usuarios_entra_na_tela(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));
        $this->get('/admin/usuarios')->assertForbidden();

        $this->actingAs($this->usuario(Permissions::ROLE_SUPER_ADMIN));
        $this->get('/admin/usuarios')->assertOk();
    }

    public function test_cria_usuario_com_papel_e_telefone_de_teste_normalizado(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_SUPER_ADMIN));
        $operador = Role::findByName(Permissions::ROLE_OPERATOR);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Novo Operador', 'email' => 'novo@unicopag.com', 'password' => 'senha-forte-123', 'telefone_teste' => '(21) 99876-5432', 'ativo' => true, 'roles' => [$operador->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $novo = User::query()->where('email', 'novo@unicopag.com')->sole();
        $this->assertSame('+5521998765432', $novo->telefone_teste);
        $this->assertTrue($novo->ativo);
        $this->assertTrue($novo->hasRole(Permissions::ROLE_OPERATOR));

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'X', 'email' => 'x@unicopag.com', 'password' => 'senha-forte-123', 'telefone_teste' => '12345'])
            ->call('create')
            ->assertHasFormErrors(['telefone_teste']);
    }

    public function test_ninguem_edita_o_proprio_papel_nem_se_desativa(): void
    {
        $superAdmin = $this->usuario(Permissions::ROLE_SUPER_ADMIN);
        $this->actingAs($superAdmin);
        $viewer = Role::findByName(Permissions::ROLE_VIEWER);

        Livewire::test(EditUser::class, ['record' => $superAdmin->getRouteKey()])
            ->assertActionHidden('desativar')
            ->fillForm(['roles' => [$viewer->id], 'ativo' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $superAdmin->refresh();
        $this->assertTrue($superAdmin->hasRole(Permissions::ROLE_SUPER_ADMIN));
        $this->assertFalse($superAdmin->hasRole(Permissions::ROLE_VIEWER));
        $this->assertTrue($superAdmin->ativo);
    }

    public function test_desativa_e_reativa_outro_usuario_em_vez_de_apagar(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_SUPER_ADMIN));
        $outro = $this->usuario(Permissions::ROLE_OPERATOR);

        $this->assertFalse(UserResource::canDelete($outro));

        Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('delete')
            ->callTableAction('desativar', $outro)
            ->assertHasNoActionErrors();

        $this->assertFalse($outro->fresh()->ativo);
        $this->assertDatabaseHas('users', ['id' => $outro->id]);

        Livewire::test(ListUsers::class)
            ->callTableAction('reativar', $outro)
            ->assertHasNoActionErrors();

        $this->assertTrue($outro->fresh()->ativo);
    }
}
