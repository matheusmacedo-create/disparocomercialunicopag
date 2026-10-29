<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Campaigns\RelationManagers\RecipientsRelationManager;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\User;
use App\Services\Campanhas\FluxoDeCampanha;
use App\Support\Permissions;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O painel de campanhas de ponta a ponta: o operador cria e gera a prévia, não aprova (nem forçando a
 * ação escondida); o admin aprova digitando o número; o formulário não altera `status`.
 */
class CampanhasPainelTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $template;

    private ContactList $lista;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->sender = Sender::factory()->create();
        $this->template = MessageTemplate::factory()->for($this->sender)->create();
        $this->lista = ContactList::factory()->create();
        $this->lista->contacts()->attach(Contact::factory()->count(5)->create()->pluck('id')->all(), ['adicionado_em' => now()]);
    }

    /** @return array<string, mixed> */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Oferta de outubro',
            'objetivo' => Campaign::OBJETIVO_OFERTAR,
            'sender_id' => $this->sender->id,
            'template_chave' => $this->template->nome.'|'.$this->template->idioma,
            'mapeamento' => [['variavel' => 'primeiro_nome', 'padrao' => 'tudo bem']],
            'publico' => ['listas' => [$this->lista->id]],
            'prioridade' => 5,
        ], $extra);
    }

    public function test_operador_cria_a_campanha_gera_a_previa_e_nao_consegue_aprovar(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);

        Livewire::test(CreateCampaign::class)
            ->fillForm($this->formulario())
            ->call('create')
            ->assertHasNoFormErrors();

        $campanha = Campaign::query()->sole();
        $this->assertSame($operador->id, $campanha->criado_por);
        $this->assertSame(Campaign::STATUS_RASCUNHO, $campanha->status);
        $this->assertSame($this->template->nome, $campanha->template_nome);
        $this->assertSame(['{{primeiro_nome|tudo bem}}'], $campanha->variaveis['corpo']);
        $this->assertSame([$this->lista->id], $campanha->publico['listas']);
        // Campanha MARKETING sem dia marcado herda seg-sex.
        $this->assertSame([1, 2, 3, 4, 5], $campanha->regras['dias_da_semana']);
        $this->assertDatabaseHas('campaign_events', ['campaign_id' => $campanha->id, 'evento' => 'criada', 'user_id' => $operador->id]);

        $ficha = Livewire::test(ViewCampaign::class, ['record' => $campanha->getRouteKey()])
            ->assertOk()
            ->callAction('gerar_previa')
            ->assertHasNoActionErrors();

        $campanha->refresh();
        $this->assertSame(5, $campanha->simulacao['elegiveis']);
        $this->assertTrue($campanha->previaAtual());

        $ficha->callAction('enviar_para_aprovacao')->assertHasNoActionErrors();
        $this->assertSame(Campaign::STATUS_AGUARDANDO_APROVACAO, $campanha->refresh()->status);

        // O operador não vê "Aprovar"; forçar a ação escondida não aprova nada.
        Livewire::test(ViewCampaign::class, ['record' => $campanha->getRouteKey()])
            ->assertActionHidden('aprovar')
            ->call('mountAction', 'aprovar')
            ->set('mountedActions.0.data.destinatarios', 5)
            ->call('callMountedAction');

        $campanha->refresh();
        $this->assertSame(Campaign::STATUS_AGUARDANDO_APROVACAO, $campanha->status);
        $this->assertNull($campanha->aprovado_por);
        $this->assertSame(0, $campanha->recipients()->count());
    }

    public function test_admin_aprova_digitando_o_numero_de_destinatarios(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $campanha = $this->campanhaAguardando($operador);

        $this->actingAs($admin);

        // Número errado: o fluxo recusa e nada muda.
        Livewire::test(ViewCampaign::class, ['record' => $campanha->getRouteKey()])
            ->assertActionVisible('aprovar')
            ->callAction('aprovar', data: ['destinatarios' => 4]);

        $this->assertSame(Campaign::STATUS_AGUARDANDO_APROVACAO, $campanha->refresh()->status);

        Livewire::test(ViewCampaign::class, ['record' => $campanha->getRouteKey()])
            ->callAction('aprovar', data: ['destinatarios' => 5, 'observacao' => 'Conferi a base com o comercial.'])
            ->assertHasNoActionErrors();

        $campanha->refresh();
        $this->assertSame(Campaign::STATUS_APROVADA, $campanha->status);
        $this->assertSame($admin->id, $campanha->aprovado_por);
        $this->assertSame(5, $campanha->recipients()->count());
        $this->assertDatabaseHas('campaign_events', ['campaign_id' => $campanha->id, 'evento' => 'aprovada', 'user_id' => $admin->id]);

        // Depois de aprovada a campanha não se edita mais pelo painel; a ficha mostra a prévia e os totais.
        $this->get("/admin/campaigns/{$campanha->id}/edit")->assertForbidden();
        $this->get("/admin/campaigns/{$campanha->id}")->assertOk()->assertSee('Prévia atual')->assertSee('Aprovada');

        // Exportação dos destinatários: CSV em storage, telefone completo só com `view pii` (o admin tem).
        Livewire::test(RecipientsRelationManager::class, ['ownerRecord' => $campanha, 'pageClass' => ViewCampaign::class])
            ->assertOk()
            ->assertCanSeeTableRecords($campanha->recipients()->get())
            ->callAction(TestAction::make('exportar')->table())
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();

        $arquivo = Storage::disk('local')->files('exportacoes');
        $this->assertCount(1, $arquivo);
        $this->assertStringContainsString('+55 (21) 9', Storage::disk('local')->get($arquivo[0]));
        $this->assertDatabaseHas('campaign_events', ['campaign_id' => $campanha->id, 'evento' => 'exportada']);
    }

    public function test_o_campo_status_nao_e_alteravel_pelo_formulario(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);
        $campanha = $this->campanhaRascunho($operador);

        Livewire::test(EditCampaign::class, ['record' => $campanha->getRouteKey()])
            ->assertOk()
            ->set('data.status', Campaign::STATUS_APROVADA)
            ->set('data.aprovado_por', $operador->id)
            ->fillForm(['nome' => 'Nome editado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $campanha->refresh();
        $this->assertSame('Nome editado', $campanha->nome);
        $this->assertSame(Campaign::STATUS_RASCUNHO, $campanha->status);
        $this->assertNull($campanha->aprovado_por);
    }

    public function test_editar_campanha_aguardando_aprovacao_volta_a_rascunho(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $campanha = $this->campanhaAguardando($operador);
        $this->actingAs($operador);

        Livewire::test(EditCampaign::class, ['record' => $campanha->getRouteKey()])
            ->fillForm(['mapeamento' => [['variavel' => 'nome', 'padrao' => 'cliente']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $campanha->refresh();
        $this->assertSame(['{{nome|cliente}}'], $campanha->variaveis['corpo']);
        $this->assertSame(Campaign::STATUS_RASCUNHO, $campanha->status);
        $this->assertDatabaseHas('campaign_events', ['campaign_id' => $campanha->id, 'evento' => 'aprovacao_invalidada']);
    }

    public function test_visualizador_le_a_lista_e_a_ficha_mas_nao_cria(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $campanha = $this->campanhaRascunho($operador);
        $this->actingAs($this->usuario(Permissions::ROLE_VIEWER));

        $this->get('/admin/campaigns')->assertOk();
        $this->get("/admin/campaigns/{$campanha->id}")->assertOk()->assertSee('Oferta de outubro');
        $this->get('/admin/campaigns/create')->assertForbidden();

        Livewire::test(ListCampaigns::class)->assertOk()->assertCanSeeTableRecords([$campanha]);

        Livewire::test(ViewCampaign::class, ['record' => $campanha->getRouteKey()])
            ->assertActionHidden('gerar_previa')
            ->assertActionHidden('enviar_para_aprovacao')
            ->assertActionHidden('cancelar');
    }

    private function campanhaRascunho(User $criador): Campaign
    {
        $campanha = Campaign::factory()->for($this->sender)->create([
            'nome' => 'Oferta de outubro',
            'objetivo' => Campaign::OBJETIVO_OFERTAR,
            'template_nome' => $this->template->nome,
            'publico' => ['listas' => [$this->lista->id]],
        ]);
        $campanha->forceFill(['criado_por' => $criador->id])->save();

        return $campanha->refresh();
    }

    private function campanhaAguardando(User $criador): Campaign
    {
        $campanha = $this->campanhaRascunho($criador);
        $fluxo = app(FluxoDeCampanha::class);
        $fluxo->simular($campanha, $criador);
        $fluxo->enviarParaAprovacao($campanha->refresh(), $criador);

        return $campanha->refresh();
    }
}
