<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContactLists\Pages\CreateContactList;
use App\Filament\Resources\ContactLists\Pages\EditContactList;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Segments\Pages\CreateSegment;
use App\Filament\Resources\Segments\Pages\EditSegment;
use App\Filament\Resources\Segments\Schemas\SegmentForm;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\Segment;
use App\Models\Tag;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Toda tela do grupo Contatos renderiza para o Super Admin, e os formulários de base/tag/segmento gravam. */
class PainelContatosSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->usuario(Permissions::ROLE_SUPER_ADMIN));
    }

    public function test_todas_as_listas_renderizam(): void
    {
        $contato = Contact::factory()->create(['atributos' => ['oferta' => 'taxa zero'], 'documento' => '12345678909']);
        $lista = ContactList::factory()->create();
        $lista->contacts()->attach($contato->id, ['adicionado_em' => now()]);
        $tag = Tag::query()->create(['nome' => 'vip', 'cor' => '#ff0000']);
        $contato->tags()->attach($tag->id, ['created_at' => now()]);
        $segmento = Segment::query()->create(['nome' => 'Com tag vip', 'regras' => ['tags' => [$tag->id]]]);
        $campanha = Campaign::factory()->create();
        CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $contato->id, 'telefone' => $contato->telefone, 'status' => CampaignRecipient::STATUS_PULADO, 'motivo' => 'frequencia']);
        Journey::factory()->ativa()->create()->enrollments()->create(['contact_id' => $contato->id, 'passo_atual' => 1, 'status' => 'ativa', 'chave_ativa' => '1:'.$contato->id, 'proximo_em' => now()]);
        ContactEvent::registrar($contato->telefone_hash, 'consentimento', ['origem' => 'contrato'], null, $contato->id);

        foreach (['/admin', '/admin/contatos', "/admin/contatos/{$contato->id}", "/admin/contatos/{$contato->id}/edit", '/admin/contatos/create', '/admin/bases', "/admin/bases/{$lista->id}/edit", '/admin/tags', '/admin/segmentos', "/admin/segmentos/{$segmento->id}/edit", '/admin/segmentos/create', '/admin/importacoes', '/admin/importacoes/create', '/admin/bloqueios', '/admin/bloqueios/create', '/admin/conversas', '/admin/usuarios'] as $url) {
            $this->get($url)->assertOk();
        }

        Livewire::test(ViewContact::class, ['record' => $contato->getRouteKey()])
            ->assertSee('taxa zero')
            ->assertSee('123.456.789-09')
            ->assertSee($lista->nome)
            ->assertSee('vip');

        Livewire::test(EditContact::class, ['record' => $contato->getRouteKey()])
            ->fillForm(['nome' => 'Nome Editado', 'documento' => '123.456.789-09'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nome Editado', $contato->fresh()->nome);
        $this->assertSame('12345678909', $contato->fresh()->documento);
    }

    public function test_base_externa_fica_com_consentimento_obrigatorio_mesmo_desmarcado(): void
    {
        Livewire::test(CreateContactList::class)
            ->fillForm(['nome' => 'Leads UnicoDrop out/2026', 'dono' => ContactList::DONO_UNICODROP, 'exige_consentimento' => false, 'ativa' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $lista = ContactList::query()->sole();
        $this->assertTrue($lista->exige_consentimento);
        $this->assertNotEmpty($lista->slug);

        Livewire::test(EditContactList::class, ['record' => $lista->getRouteKey()])
            ->assertActionVisible('ver_contatos')
            ->fillForm(['dono' => ContactList::DONO_UNICOPAG, 'exige_consentimento' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($lista->fresh()->exige_consentimento);
    }

    public function test_tag_e_segmento_gravam_e_o_segmento_conta_ao_vivo(): void
    {
        Livewire::test(CreateTag::class)
            ->fillForm(['nome' => 'voltou_a_vender', 'cor' => '#00aa00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $tag = Tag::query()->sole();
        Contact::factory()->count(2)->create()->each(fn (Contact $c) => $c->tags()->attach($tag->id));
        Contact::factory()->create();

        Livewire::test(CreateSegment::class)
            ->fillForm(['nome' => 'Quem voltou', 'regras' => ['tags' => [$tag->id], 'status' => [Contact::STATUS_ATIVO], 'sem_envio_ha_dias' => '', 'recebeu_template' => ['nome' => '', 'dias' => ''], 'atributos' => []]])
            ->call('create')
            ->assertHasNoFormErrors();

        $segmento = Segment::query()->sole();
        $this->assertSame(['tags' => [$tag->id], 'status' => [Contact::STATUS_ATIVO]], $segmento->regras);
        $this->assertSame('2 contato(s)', SegmentForm::contagem($segmento->regras));

        Livewire::test(EditSegment::class, ['record' => $segmento->getRouteKey()])
            ->assertSee('2 contato(s)')
            ->assertActionVisible('ver_contatos');
    }
}
