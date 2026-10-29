<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Support\Batimentos;
use App\Support\Telefone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** API v1 (X-API-Key): saúde, supressões compartilhadas, templates, contato mascarado e campanha em rascunho. */
class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'chave-da-api';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        config(['disparo.api_key' => self::CHAVE]);
    }

    public function test_sem_chave_configurada_503_e_chave_errada_401(): void
    {
        config(['disparo.api_key' => '']);
        $this->getJson('/api/v1/health', ['X-API-Key' => self::CHAVE])->assertStatus(503);

        config(['disparo.api_key' => self::CHAVE]);
        $this->getJson('/api/v1/health')->assertStatus(401);
        $this->getJson('/api/v1/health', ['X-API-Key' => 'errada'])->assertStatus(401);
    }

    public function test_health_mostra_interruptor_remetentes_campanhas_e_batimentos(): void
    {
        $sender = Sender::factory()->create();
        Campaign::factory()->for($sender)->emAndamento()->create(['nome' => 'Oferta de outubro']);
        Batimentos::registrarAgendador();

        $this->getJson('/api/v1/health', ['X-API-Key' => self::CHAVE])
            ->assertOk()
            ->assertJsonPath('envio_habilitado', true)
            ->assertJsonPath('remetentes_ativos.0.id', $sender->id)
            ->assertJsonPath('remetentes_ativos.0.teto_efetivo', 200)
            ->assertJsonPath('campanhas_em_andamento.0.nome', 'Oferta de outubro')
            ->assertJsonPath('batimentos.agendador_ok', true)
            ->assertJsonPath('batimentos.worker_ok', false);
    }

    public function test_lista_supressoes_desde_uma_data_por_hash(): void
    {
        $antiga = Contact::factory()->create();
        Suppression::suprimir($antiga->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta');
        Suppression::query()->update(['updated_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);

        $nova = Contact::factory()->create();
        Suppression::suprimir($nova->telefone, Suppression::MOTIVO_DENUNCIOU, 'resposta');

        $resposta = $this->getJson('/api/v1/suppressions?since='.urlencode(now()->subDay()->toIso8601String()), ['X-API-Key' => self::CHAVE])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.hash', $nova->telefone_hash)
            ->assertJsonPath('data.0.escopo', 'tudo')
            ->assertJsonPath('data.0.motivo', 'denunciou')
            ->assertJsonPath('proximo_since', null);

        $this->assertStringNotContainsString(ltrim($nova->telefone, '+'), $resposta->getContent());

        $this->getJson('/api/v1/suppressions', ['X-API-Key' => self::CHAVE])->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_grava_supressao_por_telefone_e_por_hash(): void
    {
        $contato = Contact::factory()->create();

        $this->postJson('/api/v1/suppressions', ['telefone' => $contato->telefone, 'motivo' => 'historico', 'origem' => 'kyc'], ['X-API-Key' => self::CHAVE])
            ->assertStatus(201)
            ->assertJsonPath('criada', true)
            ->assertJsonPath('hash', $contato->telefone_hash);

        $this->assertSame(Contact::STATUS_OPTOUT, $contato->fresh()->status);
        $this->assertSame('kyc', Suppression::query()->sole()->origem);

        // Repetir não cria de novo.
        $this->postJson('/api/v1/suppressions', ['telefone' => $contato->telefone], ['X-API-Key' => self::CHAVE])->assertOk()->assertJsonPath('criada', false);

        $outro = Contact::factory()->create();
        $hash = Telefone::hash($outro->telefone);
        $this->postJson('/api/v1/suppressions', ['telefone_hash' => $hash, 'escopo' => 'marketing', 'sender_id' => Sender::factory()->create()->id], ['X-API-Key' => self::CHAVE])
            ->assertStatus(201)
            ->assertJsonPath('hash', $hash);

        $this->assertSame(Contact::STATUS_ATIVO, $outro->fresh()->status);
        $this->assertNotNull(Suppression::bloqueia($outro->telefone, Sender::query()->latest('id')->first(), 'MARKETING'));

        $this->postJson('/api/v1/suppressions', ['telefone' => '123'], ['X-API-Key' => self::CHAVE])->assertStatus(422);
        $this->postJson('/api/v1/suppressions', [], ['X-API-Key' => self::CHAVE])->assertStatus(422);
    }

    public function test_templates_do_remetente_e_contato_mascarado(): void
    {
        $sender = Sender::factory()->create();
        MessageTemplate::factory()->for($sender)->create();

        $this->getJson('/api/v1/senders/'.$sender->id.'/templates', ['X-API-Key' => self::CHAVE])
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'oferta_maquininha_v1')
            ->assertJsonPath('data.0.categoria', 'MARKETING')
            ->assertJsonPath('data.0.enviavel', true)
            ->assertJsonPath('data.0.variaveis_do_corpo', ['1']);
        $this->getJson('/api/v1/senders/999/templates', ['X-API-Key' => self::CHAVE])->assertStatus(404);

        $contato = Contact::factory()->create(['telefone' => '+5521998765432', 'nome' => 'Maria Segredo']);
        $resposta = $this->getJson('/api/v1/contacts/'.urlencode('+5521998765432'), ['X-API-Key' => self::CHAVE])
            ->assertOk()
            ->assertJsonPath('id', $contato->id)
            ->assertJsonPath('telefone', '+55 21 9****-5432')
            ->assertJsonPath('status', 'ativo')
            ->assertJsonPath('consentimento.registrado', true);
        $this->assertStringNotContainsString('Segredo', $resposta->getContent());
        $this->assertStringNotContainsString('998765432', $resposta->getContent());

        $this->getJson('/api/v1/contacts/5521977776666', ['X-API-Key' => self::CHAVE])->assertStatus(404);
        $this->getJson('/api/v1/contacts/12', ['X-API-Key' => self::CHAVE])->assertStatus(422);
    }

    public function test_cria_campanha_em_rascunho_sem_criador_e_consulta(): void
    {
        $sender = Sender::factory()->create();

        $resposta = $this->postJson('/api/v1/campaigns', [
            'nome' => 'Oferta via MCP',
            'sender_id' => $sender->id,
            'template_nome' => 'oferta_maquininha_v1',
            'variaveis' => ['corpo' => ['{{primeiro_nome|tudo bem}}']],
            'publico' => ['listas' => [1]],
            'status' => 'aprovada',
        ], ['X-API-Key' => self::CHAVE])
            ->assertStatus(201)
            ->assertJsonPath('status', Campaign::STATUS_RASCUNHO)
            ->assertJsonPath('objetivo', Campaign::OBJETIVO_OFERTAR)
            ->assertJsonPath('template_idioma', 'pt_BR');

        $campanha = Campaign::query()->find($resposta->json('id'));
        $this->assertNull($campanha->criado_por);
        $this->assertSame(Campaign::STATUS_RASCUNHO, $campanha->status);
        $this->assertSame('criada', $campanha->events()->sole()->evento);

        $this->getJson('/api/v1/campaigns/'.$campanha->id, ['X-API-Key' => self::CHAVE])
            ->assertOk()
            ->assertJsonPath('nome', 'Oferta via MCP')
            ->assertJsonPath('totais.destinatarios', 0)
            ->assertJsonPath('previa', null);

        $this->getJson('/api/v1/campaigns/999', ['X-API-Key' => self::CHAVE])->assertStatus(404);
        $this->postJson('/api/v1/campaigns', ['nome' => 'x'], ['X-API-Key' => self::CHAVE])->assertStatus(422);
    }
}
