<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Models\Contact;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConversasPanelTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private Contact $contato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create();
        $this->contato = Contact::factory()->create(['telefone' => '+5521998765432', 'nome' => 'Ana Souza', 'ultima_resposta_em' => now()->subHour()]);
    }

    private function resposta(array $extra = []): WhatsappMessage
    {
        return WhatsappMessage::query()->create($extra + [
            'sender_id' => $this->sender->id,
            'contact_id' => $this->contato->id,
            'telefone' => $this->contato->telefone,
            'direcao' => WhatsappMessage::INBOUND,
            'wamid' => 'wamid.in.'.fake()->unique()->numberBetween(1, 99999),
            'tipo' => 'text',
            'conteudo' => 'quero saber mais da maquininha',
            'status' => WhatsappMessage::STATUS_RECEIVED,
            'origem' => WhatsappMessage::ORIGEM_WEBHOOK,
            'enviado_em' => now()->subHour(),
            'payload' => ['classificacao' => 'resposta'],
        ]);
    }

    public function test_conversas_so_com_view_conversations(): void
    {
        $this->resposta();

        $this->actingAs($this->usuario(Permissions::ROLE_VIEWER));
        $this->get('/admin/conversas')->assertForbidden();

        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $this->get('/admin/conversas')->assertOk();
    }

    public function test_operador_ve_a_fila_marca_tratada_e_a_leitura_vai_para_a_trilha(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);
        $mensagem = $this->resposta();
        // Mensagem enviada por nós não é conversa.
        $saida = WhatsappMessage::query()->create(['sender_id' => $this->sender->id, 'contact_id' => $this->contato->id, 'telefone' => $this->contato->telefone, 'direcao' => WhatsappMessage::OUTBOUND, 'tipo' => 'template', 'status' => WhatsappMessage::STATUS_SENT, 'origem' => WhatsappMessage::ORIGEM_CAMPANHA, 'enviado_em' => now()->subDay()]);

        $this->assertSame('1', ConversationResource::getNavigationBadge());

        Livewire::test(ListConversations::class)
            ->assertCanSeeTableRecords([$mensagem])
            ->assertCanNotSeeTableRecords([$saida])
            ->assertSee('quero saber mais da maquininha')
            ->assertSee('+55 21 9****-5432')
            ->assertSee('Aberta até')
            ->assertTableActionVisible('responder', $mensagem)
            ->assertTableActionHidden('abrir_na_kapso', $mensagem)
            ->assertTableActionHidden('atender_lgpd', $mensagem)
            ->callTableAction('marcar_tratada', $mensagem)
            ->assertHasNoActionErrors();

        $mensagem->refresh();
        $this->assertNotNull($mensagem->tratada_em);
        $this->assertSame($operador->id, $mensagem->tratada_por);
        $this->assertDatabaseHas('contact_events', ['telefone_hash' => $this->contato->telefone_hash, 'evento' => 'conversa_lida', 'user_id' => $operador->id]);
        $this->assertNull(ConversationResource::getNavigationBadge());

        // Tratada sai da aba padrão e aparece em "Tratadas".
        Livewire::test(ListConversations::class)
            ->assertCanNotSeeTableRecords([$mensagem])
            ->set('activeTab', 'tratadas')
            ->assertCanSeeTableRecords([$mensagem]);
    }

    public function test_pedido_lgpd_mostra_prazo_e_abre_a_ficha(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $prazo = now()->addDays(15);
        $mensagem = $this->resposta(['conteudo' => 'apaguem meus dados', 'payload' => ['classificacao' => WhatsappMessage::CLASSIFICACAO_PEDIDO_LGPD, 'lgpd' => ['prazo' => $prazo->toIso8601String()]], 'kapso_conversation_id' => 'conv-123']);

        Livewire::test(ListConversations::class)
            ->set('activeTab', 'lgpd')
            ->assertCanSeeTableRecords([$mensagem])
            ->assertSee($prazo->format('d/m/Y'))
            ->assertSee('Pedido LGPD')
            ->assertTableActionVisible('atender_lgpd', $mensagem)
            ->assertTableActionVisible('abrir_na_kapso', $mensagem);
    }
}
