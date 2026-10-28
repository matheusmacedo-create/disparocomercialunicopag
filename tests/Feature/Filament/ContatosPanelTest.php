<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\ContactList;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\UsaStubsDasOutrasFrentes;
use Tests\TestCase;

class ContatosPanelTest extends TestCase
{
    use RefreshDatabase;
    use UsaStubsDasOutrasFrentes;

    private Contact $contato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usarStubsDasOutrasFrentes();
        $this->contato = Contact::factory()->create(['telefone' => '+5521998765432', 'nome' => 'Ana Souza', 'email' => 'ana@exemplo.com']);
    }

    public function test_visualizador_ve_telefone_mascarado_e_nenhuma_acao(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_VIEWER));

        $this->get('/admin/contatos')->assertOk();
        $this->get('/admin/contatos/create')->assertForbidden();
        $this->get("/admin/contatos/{$this->contato->id}")->assertOk();
        $this->get("/admin/contatos/{$this->contato->id}/edit")->assertForbidden();

        Livewire::test(ListContacts::class)
            ->assertCanSeeTableRecords([$this->contato])
            ->assertSee('+55 21 9****-5432')
            ->assertDontSee('+55 (21) 99876-5432')
            ->assertDontSee('ana@exemplo.com')
            ->assertTableBulkActionHidden('bloquear')
            ->assertTableBulkActionHidden('adicionar_base')
            ->assertTableBulkActionHidden('inscrever_regua');

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->assertSee('+55 21 9****-5432')
            ->assertDontSee('+55 (21) 99876-5432')
            ->assertSee('an***@exemplo.com')
            ->assertActionHidden('bloquear')
            ->assertActionHidden('anonimizar')
            ->assertActionHidden('registrarConsentimento')
            ->assertActionHidden('exportarDados')
            ->assertActionHidden('responder');
    }

    public function test_admin_ve_o_telefone_completo_e_o_email(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));

        Livewire::test(ListContacts::class)
            ->assertSee('+55 (21) 99876-5432')
            ->searchTable('ana@exemplo')
            ->assertCanSeeTableRecords([$this->contato]);

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->assertSee('ana@exemplo.com')
            ->assertActionVisible('exportarDados')
            ->assertActionVisible('anonimizar');
    }

    public function test_busca_por_telefone_sem_o_nono_digito_e_por_documento(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $this->contato->forceFill(['documento' => '12345678909'])->save();
        $outro = Contact::factory()->create(['nome' => 'Bruno']);

        Livewire::test(ListContacts::class)
            ->searchTable('552198765432')
            ->assertCanSeeTableRecords([$this->contato])
            ->assertCanNotSeeTableRecords([$outro])
            ->searchTable('123.456.789-09')
            ->assertCanSeeTableRecords([$this->contato])
            ->assertCanNotSeeTableRecords([$outro])
            // Sem `view pii` a busca não olha o e-mail.
            ->searchTable('ana@exemplo')
            ->assertCanNotSeeTableRecords([$this->contato]);
    }

    public function test_operador_bloqueia_mas_nao_anonimiza_nem_forcando_a_acao(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->assertActionVisible('bloquear')
            ->callAction('bloquear', data: ['motivo' => 'Pediu por telefone ao atendimento'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('anonimizar')
            // Forjando a requisição, como um cliente malicioso faria.
            ->call('mountAction', 'anonimizar')
            ->set('mountedActions.0.data.motivo', 'tentativa')
            ->set('mountedActions.0.data.confirmacao', 'ANONIMIZAR')
            ->call('callMountedAction');

        $this->contato->refresh();
        $this->assertSame(Contact::STATUS_OPTOUT, $this->contato->status);
        $this->assertSame(Suppression::MOTIVO_MANUAL, Suppression::bloqueia($this->contato->telefone, null, null));

        $supressao = Suppression::query()->vigentes()->where('telefone_hash', $this->contato->telefone_hash)->sole();
        $this->assertSame(Suppression::ESCOPO_TUDO, $supressao->escopo);
        $this->assertNull($supressao->sender_id);
        $this->assertSame('painel', $supressao->origem);
        $this->assertSame($operador->id, $supressao->user_id);
        $this->assertDatabaseHas('contact_events', ['telefone_hash' => $this->contato->telefone_hash, 'evento' => 'bloqueado', 'user_id' => $operador->id]);

        $this->assertSame([], $this->anonimizador->chamadas);
    }

    public function test_admin_anonimiza_so_com_a_confirmacao_digitada(): void
    {
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $this->actingAs($admin);

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->callAction('anonimizar', data: ['motivo' => 'Pedido do titular', 'confirmacao' => 'anonimizar'])
            ->assertHasActionErrors(['confirmacao']);

        $this->assertSame([], $this->anonimizador->chamadas);

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->callAction('anonimizar', data: ['motivo' => 'Pedido do titular, protocolo 42', 'confirmacao' => 'ANONIMIZAR'])
            ->assertHasNoActionErrors();

        $this->assertSame([['contact_id' => $this->contato->id, 'user_id' => $admin->id, 'motivo' => 'Pedido do titular, protocolo 42']], $this->anonimizador->chamadas);
        $this->assertSame(Contact::STATUS_ANONIMIZADO, $this->contato->fresh()->status);
    }

    public function test_exportar_dados_do_titular_entra_na_trilha(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->callAction('exportarDados')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('titular-'.$this->contato->id.'.json');

        $this->assertDatabaseHas('contact_events', ['telefone_hash' => $this->contato->telefone_hash, 'evento' => 'dados_exportados']);
    }

    public function test_registrar_consentimento_grava_no_contato_e_na_trilha(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $this->contato->forceFill(['consentimento_origem' => null, 'consentimento_em' => null])->save();

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->callAction('registrarConsentimento', data: ['origem' => 'formulario', 'canal' => 'site', 'quando' => '2026-10-20 10:00:00', 'detalhes' => 'Landing page da maquininha'])
            ->assertHasNoActionErrors();

        $this->contato->refresh();
        $this->assertSame('formulario', $this->contato->consentimento_origem);
        $this->assertSame('site', $this->contato->consentimento_canal);
        $this->assertSame('2026-10-20 10:00', $this->contato->consentimento_em->format('Y-m-d H:i'));
        $this->assertSame(1, ContactEvent::query()->where('evento', 'consentimento')->count());
    }

    public function test_responder_so_com_a_janela_de_24h_aberta_e_grava_o_autor(): void
    {
        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => Http::response(['messages' => [['id' => 'wamid.texto.1']]])]);
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);
        $sender = Sender::factory()->create();

        // Janela fechada: a ação nem aparece.
        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])->assertActionHidden('responder');

        $this->contato->forceFill(['ultima_resposta_em' => now()->subHours(2)])->save();

        Livewire::test(ViewContact::class, ['record' => $this->contato->getRouteKey()])
            ->assertActionVisible('responder')
            ->callAction('responder', data: ['sender_id' => $sender->id, 'texto' => 'Oi Ana, a taxa é 1,99%.'])
            ->assertHasNoActionErrors();

        $mensagem = WhatsappMessage::query()->where('direcao', WhatsappMessage::OUTBOUND)->sole();
        $this->assertSame(WhatsappMessage::STATUS_SENT, $mensagem->status);
        $this->assertSame(WhatsappMessage::ORIGEM_MANUAL, $mensagem->origem);
        $this->assertSame($operador->id, $mensagem->user_id);
        $this->assertSame('wamid.texto.1', $mensagem->wamid);
        $this->assertDatabaseHas('contact_events', ['evento' => 'texto_enviado', 'user_id' => $operador->id]);
    }

    public function test_acoes_em_massa_adicionam_a_base_e_a_tag(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $outro = Contact::factory()->create();
        $lista = ContactList::factory()->create();
        $tag = Tag::query()->create(['nome' => 'voltou_a_vender']);

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('adicionar_base', [$this->contato, $outro], data: ['contact_list_id' => $lista->id])
            ->assertHasNoActionErrors()
            ->callTableBulkAction('adicionar_tag', [$this->contato], data: ['tag_id' => $tag->id])
            ->assertHasNoActionErrors();

        $this->assertSame(2, $lista->contacts()->count());
        $this->assertNotNull($lista->contacts()->find($this->contato->id)->pivot->adicionado_em);
        $this->assertTrue($this->contato->tags()->where('tags.id', $tag->id)->exists());
        $this->assertFalse($outro->tags()->exists());
    }

    public function test_formulario_normaliza_o_telefone_e_recusa_repetido(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));

        Livewire::test(CreateContact::class)
            ->fillForm(['telefone' => '(21) 99876-5432', 'nome' => 'Repetida', 'origem' => 'manual', 'tipo' => 'lead'])
            ->call('create')
            ->assertHasFormErrors(['telefone']);

        Livewire::test(CreateContact::class)
            ->fillForm(['telefone' => '(11) 98888-7777', 'nome' => 'Novo Contato', 'origem' => 'manual', 'tipo' => 'lead'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('contacts', ['telefone' => '+5511988887777', 'primeiro_nome' => 'Novo']);
    }
}
