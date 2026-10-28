<?php

declare(strict_types=1);

namespace Tests\Feature\Contatos;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Contatos\AnonimizarContato;
use App\Services\Contatos\ExportarDadosDoTitular;
use App\Support\Permissions;
use App\Support\Telefone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnonimizarContatoTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONE = '+5521998765432';

    private function cenario(): Contact
    {
        $sender = Sender::factory()->create();
        $contato = Contact::factory()->create(['telefone' => self::TELEFONE, 'nome' => 'Maria Silva', 'documento' => '12345678909', 'atributos' => ['cidade' => 'Rio'], 'kapso_contact_id' => 'kc-1', 'observacoes' => 'ligou']);

        WhatsappMessage::query()->create(['sender_id' => $sender->id, 'contact_id' => $contato->id, 'telefone' => self::TELEFONE, 'direcao' => 'outbound', 'wamid' => 'wamid.1', 'tipo' => 'template', 'template_nome' => 'oferta', 'conteudo' => 'Oi Maria', 'status' => 'sent', 'origem' => 'campanha', 'enviado_em' => now(), 'payload' => ['categoria' => 'MARKETING', 'componentes' => ['Maria']]]);
        // Forma sem o nono dígito (como o webhook da Meta às vezes manda), sem contact_id.
        WhatsappMessage::query()->create(['sender_id' => $sender->id, 'telefone' => '+552198765432', 'direcao' => 'inbound', 'wamid' => 'wamid.2', 'tipo' => 'text', 'conteudo' => 'quanto custa?', 'status' => 'received', 'origem' => 'webhook', 'enviado_em' => now()]);

        $campanha = Campaign::factory()->for($sender)->create();
        CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $contato->id, 'telefone' => self::TELEFONE, 'status' => 'enviado', 'variaveis' => ['primeiro_nome' => 'Maria'], 'enviado_em' => now()]);

        $journey = Journey::factory()->for($sender)->ativa()->create();
        JourneyStep::query()->create(['journey_id' => $journey->id, 'ordem' => 1, 'tipo' => JourneyStep::TIPO_TEMPLATE, 'template_nome' => 'oferta']);
        $journey->inscrever($contato);

        Suppression::suprimir(self::TELEFONE, Suppression::MOTIVO_META_MARKETING_STOPPED, 'webhook', $sender->id, Suppression::ESCOPO_MARKETING);

        return $contato;
    }

    public function test_anonimiza_sem_deixar_telefone_em_nenhuma_tabela_e_mantem_a_supressao_pelo_hash(): void
    {
        $contato = $this->cenario();
        $hash = $contato->telefone_hash;
        $admin = $this->usuario(Permissions::ROLE_ADMIN);

        app(AnonimizarContato::class)->executar($contato, $admin, 'pedido do titular por e-mail');

        $contato->refresh();
        $marcador = Telefone::anonimo(self::TELEFONE);
        $this->assertSame($marcador, $contato->telefone);
        $this->assertSame(Contact::STATUS_ANONIMIZADO, $contato->status);
        $this->assertSame($hash, $contato->telefone_hash, 'o hash do número real fica');
        $this->assertNotNull($contato->anonimizado_em);

        foreach (['nome', 'primeiro_nome', 'email', 'documento', 'documento_hash', 'empresa', 'observacoes', 'atributos', 'kapso_contact_id', 'consentimento_origem', 'consentimento_em'] as $campo) {
            $this->assertNull($contato->{$campo}, $campo);
        }

        // Nenhuma tabela guarda o número (em nenhuma forma).
        foreach (['contacts', 'whatsapp_messages', 'campaign_recipients', 'suppressions'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->where('telefone', 'like', '%98765432%')->count(), $tabela);
        }

        $this->assertSame(2, WhatsappMessage::query()->where('telefone', $marcador)->count(), 'inclusive a forma sem o nono dígito');
        $this->assertSame(0, WhatsappMessage::query()->whereNotNull('conteudo')->count());
        $this->assertSame(['categoria' => 'MARKETING'], WhatsappMessage::query()->where('wamid', 'wamid.1')->first()->payload, 'do payload sobra só a categoria');
        $this->assertNull(CampaignRecipient::query()->first()->variaveis);
        $this->assertSame($marcador, CampaignRecipient::query()->first()->telefone);

        // Supressão `anonimizado` de escopo tudo, pelo hash, com o telefone nulo - e a de marketing também sem telefone.
        $this->assertSame(Suppression::MOTIVO_ANONIMIZADO, Suppression::bloqueia(self::TELEFONE, null, null));
        $this->assertSame(2, Suppression::query()->where('telefone_hash', $hash)->whereNull('telefone')->count());

        $inscricao = JourneyEnrollment::query()->firstOrFail();
        $this->assertSame([JourneyEnrollment::STATUS_SAIU, 'anonimizado'], [$inscricao->status, $inscricao->motivo_saida]);

        $evento = ContactEvent::query()->where('telefone_hash', $hash)->where('evento', 'anonimizado')->firstOrFail();
        $this->assertSame('pedido do titular por e-mail', $evento->detalhes['motivo']);
        $this->assertStringContainsString($admin->email, $evento->ator);

        // Idempotente, e uma reimportação do número nasce bloqueada.
        app(AnonimizarContato::class)->executar($contato, $admin, 'de novo');
        $this->assertSame(1, ContactEvent::query()->where('evento', 'anonimizado')->count());
    }

    public function test_sem_permissao_nada_muda(): void
    {
        $contato = $this->cenario();
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);

        try {
            app(AnonimizarContato::class)->executar($contato, $operador, 'tentativa');
            $this->fail('operador não anonimiza');
        } catch (AuthorizationException) {
        }

        $this->assertSame(self::TELEFONE, $contato->refresh()->telefone);
        $this->assertSame('Maria Silva', $contato->nome);
        $this->assertNull(Suppression::bloqueia(self::TELEFONE, null, 'UTILITY'));
    }

    public function test_exporta_os_dados_do_titular(): void
    {
        $contato = $this->cenario();

        $dados = app(ExportarDadosDoTitular::class)->executar($contato);

        $this->assertSame('+55 (21) 99876-5432', $dados['contato']['telefone']);
        $this->assertSame('123.456.789-09', $dados['contato']['documento']);
        $this->assertSame(['cidade' => 'Rio'], $dados['contato']['atributos']);
        $this->assertCount(1, $dados['mensagens'], 'só as do contato; o texto não vai');
        $this->assertArrayNotHasKey('conteudo', $dados['mensagens'][0]);
        $this->assertSame('template', $dados['mensagens'][0]['tipo']);
        $this->assertCount(1, $dados['campanhas']);
        $this->assertCount(1, $dados['reguas']);
        $this->assertSame('meta_marketing_stopped', $dados['bloqueios'][0]['motivo']);
        $this->assertSame('bloqueado', $dados['trilha'][0]['evento']);
    }
}
