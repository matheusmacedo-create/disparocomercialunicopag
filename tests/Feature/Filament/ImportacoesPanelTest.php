<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContactImports\Pages\CreateContactImport;
use App\Filament\Resources\ContactImports\Pages\ListContactImports;
use App\Filament\Resources\ContactImports\Pages\ViewContactImport;
use App\Jobs\ImportarHistoricoKapsoJob;
use App\Jobs\ProcessarImportacaoJob;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\Sender;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\UsaStubsDasOutrasFrentes;
use Tests\TestCase;

class ImportacoesPanelTest extends TestCase
{
    use RefreshDatabase;
    use UsaStubsDasOutrasFrentes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usarStubsDasOutrasFrentes();
        Storage::fake('local');
    }

    public function test_importacao_csv_cria_o_registro_e_despacha_o_job(): void
    {
        Bus::fake();
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $this->actingAs($operador);
        $lista = ContactList::factory()->create();

        Livewire::test(CreateContactImport::class)
            ->fillForm([
                'tipo' => ContactImport::TIPO_CSV,
                'arquivo' => UploadedFile::fake()->createWithContent('leads-outubro.csv', "phone;name;oferta\n21998765432;Ana;taxa zero\n"),
                'contact_list_id' => $lista->id,
                'origem' => 'unicopag',
                'tipo_contato' => 'seller_inativo',
                'mapeamento' => [
                    ['coluna' => 'phone', 'campo' => 'telefone'],
                    ['coluna' => 'name', 'campo' => 'nome'],
                    ['coluna' => 'oferta', 'campo' => CreateContactImport::CAMPO_ATRIBUTO],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $import = ContactImport::query()->sole();
        $this->assertSame(ContactImport::TIPO_CSV, $import->tipo);
        $this->assertSame(ContactImport::STATUS_PENDENTE, $import->status);
        $this->assertSame($lista->id, $import->contact_list_id);
        $this->assertSame($operador->id, $import->user_id);
        $this->assertSame('leads-outubro.csv', $import->nome_original);
        $this->assertSame(['phone' => 'telefone', 'name' => 'nome', 'oferta' => 'atributos.oferta'], $import->mapeamento);
        $this->assertStringStartsWith('importacoes/', (string) $import->arquivo);
        Storage::disk('local')->assertExists($import->arquivo);

        Bus::assertDispatched(ProcessarImportacaoJob::class, fn (ProcessarImportacaoJob $job): bool => $job->import->is($import));
    }

    public function test_base_externa_exige_consentimento_e_o_telefone_tem_de_ser_mapeado(): void
    {
        Bus::fake();
        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $lista = ContactList::factory()->unicodrop()->create();

        Livewire::test(CreateContactImport::class)
            ->fillForm([
                'tipo' => ContactImport::TIPO_CSV,
                'arquivo' => UploadedFile::fake()->createWithContent('drop.csv', "phone,name\n21998765432,Ana\n"),
                'contact_list_id' => $lista->id,
                'origem' => 'unicodrop',
                'tipo_contato' => 'lead',
                'mapeamento' => [['coluna' => 'phone', 'campo' => 'nome'], ['coluna' => 'name', 'campo' => 'nome']],
            ])
            ->call('create')
            ->assertHasFormErrors(['consentimento_origem', 'consentimento_canal', 'mapeamento']);

        $this->assertSame(0, ContactImport::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_cabecalho_com_bom_e_ponto_e_virgula(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($caminho, "\xEF\xBB\xBF\"Telefone\";Nome;E-mail\n1;2;3\n");

        $this->assertSame(['Telefone', 'Nome', 'E-mail'], CreateContactImport::lerCabecalho($caminho));
        $this->assertSame(['phone' => 'telefone', 'CPF' => 'atributos.cpf'], CreateContactImport::mapeamentoAssociativo([
            ['coluna' => 'phone', 'campo' => 'telefone'],
            ['coluna' => 'x', 'campo' => CreateContactImport::CAMPO_IGNORAR],
            ['coluna' => 'CPF', 'campo' => CreateContactImport::CAMPO_ATRIBUTO],
        ]));

        @unlink($caminho);
    }

    public function test_visualizador_nao_entra_e_operador_nao_importa_historico_da_kapso(): void
    {
        $this->actingAs($this->usuario(Permissions::ROLE_VIEWER));
        $this->get('/admin/importacoes')->assertForbidden();

        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        $this->get('/admin/importacoes')->assertOk();
        Livewire::test(ListContactImports::class)->assertActionHidden('importarHistoricoKapso');
    }

    public function test_admin_enfileira_a_importacao_do_historico_da_kapso(): void
    {
        Bus::fake();
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $this->actingAs($admin);
        $sender = Sender::factory()->create();

        Livewire::test(ListContactImports::class)
            ->assertActionVisible('importarHistoricoKapso')
            ->callAction('importarHistoricoKapso', data: ['sender_id' => $sender->id, 'desde' => '2026-10-01', 'contatos' => true, 'broadcasts' => false])
            ->assertHasNoActionErrors();

        Bus::assertDispatched(ImportarHistoricoKapsoJob::class, function (ImportarHistoricoKapsoJob $job) use ($sender, $admin): bool {
            return $job->senderId === $sender->id
                && $job->userId === $admin->id
                && $job->parametros() === ['sender' => $sender->id, '--desde' => '2026-10-01', '--contatos' => true];
        });
    }

    public function test_job_do_historico_avisa_no_sininho_mesmo_quando_o_comando_falha(): void
    {
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $sender = Sender::factory()->create();

        // O comando é de outra frente: sem ele no worktree, o job tem de avisar o erro, não explodir.
        (new ImportarHistoricoKapsoJob($sender->id, userId: $admin->id))->handle();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertStringContainsString('não importado', (string) data_get($admin->notifications()->first()->data, 'title'));
    }

    public function test_baixar_rejeitadas_so_com_view_pii_e_quando_o_arquivo_existe(): void
    {
        $import = ContactImport::query()->create(['tipo' => ContactImport::TIPO_CSV, 'status' => ContactImport::STATUS_CONCLUIDA, 'nome_original' => 'x.csv', 'erros' => [['linha' => 2, 'motivo' => 'telefone inválido']]]);

        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));
        Livewire::test(ViewContactImport::class, ['record' => $import->getRouteKey()])
            ->assertSee('telefone inválido')
            ->assertActionHidden('baixarRejeitadas');

        Storage::disk('local')->put("importacoes/{$import->id}/rejeitadas.csv", "linha;motivo\n2;telefone inválido\n");

        Livewire::test(ViewContactImport::class, ['record' => $import->getRouteKey()])
            ->assertActionVisible('baixarRejeitadas')
            ->callAction('baixarRejeitadas')
            ->assertFileDownloaded("rejeitadas-importacao-{$import->id}.csv");

        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));
        Livewire::test(ViewContactImport::class, ['record' => $import->getRouteKey()])->assertActionHidden('baixarRejeitadas');
    }
}
