<?php

declare(strict_types=1);

namespace Tests\Feature\Importacao;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Services\Importacao\ImportadorSellersInativos;
use App\Services\Unicopag\UnicopagClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportadorSellersInativosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['disparo.unicoapi.key' => 'chave-de-teste']);
        $this->travelTo(now()->parse('2026-10-28 12:00:00'));
    }

    private function fakeCadastro(): void
    {
        Http::fake([
            'unico-api.test/api/kyc-panel/accounts/1002/register' => Http::response(['data' => ['name' => 'EMPRESA LTDA', 'register' => ['name' => 'JOSE DO CADASTRO', 'phone' => '(21) 98888-7777', 'trade_name' => 'LOJA DO JOSE']]]),
            'unico-api.test/api/kyc-panel/accounts/1003/register' => Http::response(['account' => ['phone_number' => '21977776666', 'name' => 'Vendedora Que Voltou']]),
            'unico-api.test/api/kyc-panel/accounts/1004/register' => Http::response(['message' => 'not found'], 404),
        ]);
    }

    public function test_importa_de_arquivo_busca_telefone_no_cadastro_e_rejeita_quem_nao_tem(): void
    {
        $this->fakeCadastro();
        Storage::fake('local');

        $importacao = app(ImportadorSellersInativos::class)->deArquivo(base_path('tests/Fixtures/sellers_inativos.json'), 90);

        $this->assertSame(ContactImport::STATUS_CONCLUIDA, $importacao->status);
        $this->assertSame(ContactImport::TIPO_UNICOAPI, $importacao->tipo);
        $this->assertSame([4, 3, 0, 1], [$importacao->total_linhas, $importacao->criados, $importacao->atualizados, $importacao->invalidos]);
        $this->assertSame(2, $importacao->resultado['telefone_do_cadastro']);
        $this->assertSame(1, $importacao->resultado['sem_telefone']);

        $lista = ContactList::query()->where('slug', 'sellers-inativos')->firstOrFail();
        $this->assertSame('Sellers inativos', $lista->nome);
        $this->assertSame($lista->id, $importacao->contact_list_id);

        $carlos = Contact::query()->where('telefone', '+5521991112233')->firstOrFail();
        $this->assertSame(['unicopag', 'seller_inativo', '1001', 'Carlos Alberto', 'Carlos', 'unicoapi'], [$carlos->origem, $carlos->tipo, $carlos->account_id, $carlos->nome, $carlos->primeiro_nome, $carlos->nome_origem]);
        $this->assertSame(['ultima_venda_em' => '01/05/2026', 'dias_sem_venda' => '180', 'etapa' => 'configurado_sem_venda', 'data_cadastro' => '10/04/2026'], $carlos->atributos);
        $this->assertSame('cliente_ativo', $carlos->consentimento_origem);
        $this->assertSame('carlos@exemplo.com', $carlos->email);
        $this->assertTrue($carlos->lists()->whereKey($lista->id)->exists());

        // Sem telefone no item: veio do cadastro, já normalizado; nome do item vale mais que o do cadastro.
        $jose = Contact::query()->where('telefone', '+5521988887777')->firstOrFail();
        $this->assertSame(['Seller Sem Fone', 'Loja do Jose', '1002'], [$jose->nome, $jose->empresa, $jose->account_id]);

        // Conta 1004 não tem telefone em lugar nenhum: rejeitada, nunca contato.
        $this->assertSame(3, Contact::query()->count());
        $this->assertNull(Contact::query()->where('account_id', '1004')->first());
        $this->assertStringContainsString('1004', $importacao->erros[0]['motivo']);
        $this->assertStringContainsString('4;;1004;"sem telefone válido (nem no cadastro)"', str_replace("\r", '', Storage::disk('local')->get($importacao->caminhoDasRejeitadas())));

        // 1003 vendeu há pouco: entra como seller_ativo, fora da base, com a tag.
        $voltou = Contact::query()->where('account_id', '1003')->firstOrFail();
        $this->assertSame('seller_ativo', $voltou->tipo);
        $this->assertFalse($voltou->lists()->whereKey($lista->id)->exists());
        $this->assertSame(['voltou_a_vender'], $voltou->tags()->pluck('nome')->all());

        // O cadastro fica em cache: a segunda rodada não bate de novo no unico-api.
        app(ImportadorSellersInativos::class)->deArquivo(base_path('tests/Fixtures/sellers_inativos.json'), 90);
        Http::assertSentCount(3);
    }

    public function test_seller_que_voltou_a_vender_converte_inscricao_e_destinatarios(): void
    {
        $this->fakeCadastro();
        $seller = Contact::factory()->create(['telefone' => '+5521991112233', 'account_id' => '1001', 'tipo' => 'seller_inativo', 'origem' => 'unicopag']);
        $lista = ImportadorSellersInativos::lista();
        $lista->contacts()->attach($seller->id, ['adicionado_em' => now()]);

        $journey = Journey::factory()->ativa()->create();
        JourneyStep::query()->create(['journey_id' => $journey->id, 'ordem' => 1, 'tipo' => JourneyStep::TIPO_TEMPLATE, 'template_nome' => 'oferta_maquininha_v1']);
        $inscricao = $journey->inscrever($seller);
        $this->assertNotNull($inscricao);

        $campanha = Campaign::factory()->create();
        $recente = CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $seller->id, 'telefone' => $seller->telefone, 'status' => CampaignRecipient::STATUS_ENTREGUE, 'enviado_em' => now()->subDays(10)]);
        $antigo = CampaignRecipient::query()->create(['campaign_id' => Campaign::factory()->create()->id, 'contact_id' => $seller->id, 'telefone' => $seller->telefone, 'status' => CampaignRecipient::STATUS_ENTREGUE, 'enviado_em' => now()->subDays(60)]);

        $importacao = app(ImportadorSellersInativos::class)->importar([
            ['account_id' => '1001', 'telefone' => '21991112233', 'ultima_venda_em' => '2026-10-25', 'etapa' => 'vendeu'],
        ], 90);

        $this->assertSame(1, $importacao->resultado['convertidos']);
        $seller->refresh();
        $this->assertSame('seller_ativo', $seller->tipo);
        $this->assertFalse($seller->lists()->whereKey($lista->id)->exists());
        $this->assertSame(['voltou_a_vender'], $seller->tags()->pluck('nome')->all());

        $inscricao->refresh();
        $this->assertSame([JourneyEnrollment::STATUS_SAIU, 'converteu', 'venda', '2026-10-25'], [$inscricao->status, $inscricao->motivo_saida, $inscricao->conversao_origem, $inscricao->convertido_em->format('Y-m-d')]);
        $this->assertNull($inscricao->chave_ativa);

        $this->assertSame(['venda', '2026-10-25'], [$recente->refresh()->conversao_origem, $recente->convertido_em->format('Y-m-d')]);
        $this->assertNull($antigo->refresh()->convertido_em, 'envio de 60 dias antes da venda não conta');

        // Voltou a ficar inativo: entra na base de novo, sem a tag.
        app(ImportadorSellersInativos::class)->importar([['account_id' => '1001', 'telefone' => '21991112233', 'ultima_venda_em' => '2026-05-01']], 90);
        $seller->refresh();
        $this->assertSame('seller_inativo', $seller->tipo);
        $this->assertTrue($seller->lists()->whereKey($lista->id)->exists());
        $this->assertSame([], $seller->tags()->pluck('nome')->all());
    }

    public function test_endpoint_configurado_segue_paginacao_e_comando_resume(): void
    {
        config(['disparo.unicoapi.sellers_endpoint' => '/api/disparo/sellers-inativos?dias={dias}']);
        Http::fake([
            'unico-api.test/api/disparo/sellers-inativos?dias=90' => Http::response(['data' => [['account_id' => '1', 'telefone' => '21991110001']], 'links' => ['next' => 'https://unico-api.test/api/disparo/sellers-inativos?dias=90&page=2']]),
            'unico-api.test/api/disparo/sellers-inativos?dias=90&page=2' => Http::response(['data' => [['account_id' => '2', 'telefone' => '21991110002']]]),
        ]);

        $this->assertTrue(app(UnicopagClient::class)->sellersConfigurado());

        $this->artisan('disparo:importar-sellers-inativos', ['--dias' => 90])
            ->expectsOutputToContain('2 criados')
            ->assertSuccessful();

        Http::assertSent(fn (Request $req): bool => $req->hasHeader('X-API-Key', 'chave-de-teste'));
        $this->assertSame(2, ImportadorSellersInativos::lista()->contacts()->count());
    }

    public function test_sem_endpoint_nem_arquivo_o_comando_explica(): void
    {
        $this->artisan('disparo:importar-sellers-inativos')
            ->expectsOutputToContain('UNICOPAG_SELLERS_ENDPOINT')
            ->assertFailed();
    }
}
