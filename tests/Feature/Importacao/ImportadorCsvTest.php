<?php

declare(strict_types=1);

namespace Tests\Feature\Importacao;

use App\Jobs\ProcessarImportacaoJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\Suppression;
use App\Services\Contatos\AnonimizarContato;
use App\Services\Importacao\ImportadorCsv;
use App\Services\Importacao\LeitorDeCsv;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportadorCsvTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = base_path('tests/Fixtures/contatos.csv');
    }

    public function test_leitor_descarta_bom_e_detecta_ponto_e_virgula(): void
    {
        $cabecalho = LeitorDeCsv::cabecalho($this->fixture);

        $this->assertSame(';', $cabecalho['delimitador']);
        $this->assertSame(['Telefone', 'NOME', 'E-mail', 'CPF', 'Loja', 'Oferta', 'Cidade'], $cabecalho['cabecalho']);
        $this->assertSame([2, 3, 4, 5, 6, 7], array_keys(iterator_to_array(LeitorDeCsv::linhas($this->fixture))));
    }

    public function test_sugere_mapeamento_e_recusa_coluna_sensivel_em_atributos(): void
    {
        $sugestao = ImportadorCsv::sugerirMapeamento(LeitorDeCsv::cabecalho($this->fixture)['cabecalho']);

        $this->assertSame([
            'Telefone' => 'telefone', 'NOME' => 'nome', 'E-mail' => 'email', 'CPF' => 'documento',
            'Loja' => 'empresa', 'Oferta' => 'atributos.oferta', 'Cidade' => 'atributos.cidade',
        ], $sugestao);

        // Coluna de telefone com mais palavras e coluna sensível que não é documento.
        $this->assertSame(['WhatsApp (com DDD)' => 'telefone', 'Endereço' => 'ignorar', 'Renda mensal' => 'ignorar'], ImportadorCsv::sugerirMapeamento(['WhatsApp (com DDD)', 'Endereço', 'Renda mensal']));

        $problemas = ImportadorCsv::problemasDoMapeamento(['Telefone' => 'telefone', 'CPF' => 'atributos.cpf', 'Nascimento' => 'atributos.nasc']);
        $this->assertCount(2, $problemas);
        $this->assertStringContainsString('dado sensível', $problemas[0]);

        $this->assertStringContainsString('Nenhuma coluna foi mapeada como telefone', ImportadorCsv::problemasDoMapeamento(['NOME' => 'nome'])[0]);

        $this->expectException(ValidationException::class);
        app(ImportadorCsv::class)->previa($this->fixture, ['Telefone' => 'telefone', 'CPF' => 'atributos.cpf']);
    }

    public function test_previa_nao_grava_nada(): void
    {
        Contact::factory()->create(['telefone' => '+5521977776666', 'nome' => 'Ana Existente']);
        $mapeamento = ImportadorCsv::sugerirMapeamento(LeitorDeCsv::cabecalho($this->fixture)['cabecalho']);

        $previa = app(ImportadorCsv::class)->previa($this->fixture, $mapeamento, ['origem' => 'unicodrop']);

        $this->assertSame(['total' => 6, 'validos' => 3, 'criados' => 2, 'atualizados' => 1, 'invalidos' => 1, 'duplicados' => 1, 'ignorados' => 1, 'optouts' => 0, 'bloqueados' => 0, 'sem_consentimento' => 2], $previa['previsao']);
        $this->assertCount(6, $previa['linhas']);
        $this->assertSame('+5521998765432', $previa['linhas'][0]['telefone']);
        $this->assertSame('Maria', $previa['linhas'][0]['primeiro_nome']);
        $this->assertSame('***.456.789-**', $previa['linhas'][0]['documento']);
        $this->assertSame('telefone inválido', $previa['linhas'][3]['motivo']);
        $this->assertStringContainsString('repetido', $previa['linhas'][4]['motivo']);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(0, ContactImport::query()->count());
    }

    public function test_importa_csv_com_bom_telefones_em_tres_formatos_e_devolve_todas_as_rejeitadas(): void
    {
        Storage::fake('local');
        $user = $this->usuario(Permissions::ROLE_OPERATOR);
        $lista = ContactList::factory()->create();
        $mapeamento = ImportadorCsv::sugerirMapeamento(LeitorDeCsv::cabecalho($this->fixture)['cabecalho']);

        $importacao = ImportadorCsv::criar($this->fixture, 'contatos.csv', $mapeamento, [
            'contact_list_id' => $lista->id, 'origem' => 'csv', 'tipo_contato' => 'lead', 'tags' => ['out-2026'],
        ], $user);

        // QUEUE_CONNECTION=sync: o job já rodou.
        $importacao->refresh();
        $this->assertSame(ContactImport::STATUS_CONCLUIDA, $importacao->status);
        $this->assertSame([6, 3, 0, 1, 1, 1], [$importacao->total_linhas, $importacao->criados, $importacao->atualizados, $importacao->invalidos, $importacao->duplicados, $importacao->ignorados]);
        $this->assertSame(3, $importacao->sem_consentimento, 'origem csv é externa: sem consentimento informado conta');
        $this->assertNull($importacao->arquivo, 'o original é apagado depois de processar');
        Storage::disk('local')->assertMissing('importacoes/'.$importacao->id.'/original.csv');

        $maria = Contact::query()->where('telefone', '+5521998765432')->firstOrFail();
        $this->assertSame('Maria das Dores Silva', $maria->nome);
        $this->assertSame('Maria', $maria->primeiro_nome);
        $this->assertSame('csv', $maria->nome_origem);
        $this->assertSame('maria@exemplo.com', $maria->email);
        $this->assertSame('12345678909', $maria->documento);
        $this->assertSame('Loja da Maria', $maria->empresa);
        $this->assertSame(['oferta' => 'taxa zero', 'cidade' => 'Niterói'], $maria->atributos);
        $this->assertSame('lead', $maria->tipo);
        $this->assertTrue($maria->lists()->whereKey($lista->id)->exists());
        $this->assertSame(['out-2026'], $maria->tags()->pluck('nome')->all());

        $joao = Contact::query()->where('telefone', '+5521988887777')->firstOrFail();
        $this->assertSame('Joao', $joao->primeiro_nome, 'razão social de MEI: o dígito não vira primeiro nome');
        $this->assertNotNull(Contact::query()->where('telefone', '+5521977776666')->first());

        // Todas as rejeitadas (inválida, repetida, sem telefone), com o telefone mascarado.
        $this->assertTrue($importacao->temRejeitadas());
        $rejeitadas = Storage::disk('local')->get($importacao->caminhoDasRejeitadas());
        $this->assertStringContainsString('6;"+55 21 9****-5432";;"telefone repetido no arquivo (linha 2)"', str_replace("\r", '', $rejeitadas));
        $this->assertStringContainsString('5;123;;"telefone inválido"', str_replace("\r", '', $rejeitadas));
        $this->assertStringContainsString('7;;;"sem telefone"', str_replace("\r", '', $rejeitadas));
        $this->assertStringNotContainsString('21998765432', $rejeitadas);
        $this->assertCount(3, $importacao->erros);
        $this->assertNotNull($importacao->baixarRejeitadas());

        // Aviso no sininho de quem importou, sem PII.
        $this->assertSame(1, $user->notifications()->count());
        $this->assertStringContainsString('3 criados', json_encode($user->notifications()->first()->data));
        $this->assertStringNotContainsString('maria@', json_encode($user->notifications()->first()->data));
    }

    public function test_criar_enfileira_o_job(): void
    {
        Queue::fake();
        $importacao = ImportadorCsv::criar($this->fixture, 'contatos.csv', ['Telefone' => 'telefone']);

        Queue::assertPushed(ProcessarImportacaoJob::class, fn (ProcessarImportacaoJob $job): bool => $job->importacaoId === $importacao->id);
        $this->assertSame(ContactImport::STATUS_PENDENTE, $importacao->status);
        $this->assertTrue(Storage::disk('local')->exists('importacoes/'.$importacao->id.'/original.csv'));
    }

    public function test_telefone_existente_atualiza_com_precedencia_de_nome_e_merge_de_atributos(): void
    {
        $existente = Contact::factory()->create(['telefone' => '+5521998765432', 'nome' => 'Maria do Cadastro', 'nome_origem' => 'unicoapi', 'origem' => 'unicoapi', 'tipo' => 'seller_inativo', 'atributos' => ['plano' => 'pro', 'oferta' => 'antiga'], 'email' => 'cadastro@exemplo.com']);
        $semNome = Contact::factory()->create(['telefone' => '+5521988887777', 'nome' => null, 'primeiro_nome' => null, 'origem' => 'kapso']);
        $bloqueado = Contact::factory()->optout()->create(['telefone' => '+5521977776666']);
        $lista = ContactList::factory()->create();

        $importacao = ImportadorCsv::criar($this->fixture, 'contatos.csv', ['Telefone' => 'telefone', 'NOME' => 'nome', 'Oferta' => 'atributos.oferta'], ['contact_list_id' => $lista->id, 'origem' => 'unicopag', 'tipo_contato' => 'lead'], null);
        $importacao->refresh();

        $this->assertSame([0, 3], [$importacao->criados, $importacao->atualizados]);
        $this->assertSame(0, $importacao->sem_consentimento, 'origem unicopag em base sem exigência não conta');

        $existente->refresh();
        $this->assertSame('Maria do Cadastro', $existente->nome, 'unicoapi vale mais que csv');
        $this->assertSame('unicoapi', $existente->origem, 'a origem é a da primeira vez');
        $this->assertSame('lead', $existente->tipo, 'o tipo é o que a importação diz');
        $this->assertSame(['plano' => 'pro', 'oferta' => 'taxa zero'], $existente->atributos);
        $this->assertSame('cadastro@exemplo.com', $existente->email, 'vazio não apaga');

        $this->assertSame('45.678.901 Joao da Silva', $semNome->refresh()->nome, 'nome vazio é preenchido, já higienizado');
        $this->assertSame('Joao', $semNome->primeiro_nome);

        $bloqueado->refresh();
        $this->assertSame(Contact::STATUS_OPTOUT, $bloqueado->status, 'optout entra na base e segue bloqueado');
        $this->assertTrue($bloqueado->lists()->whereKey($lista->id)->exists());
        $this->assertSame(1, $importacao->resultado['bloqueados']);
    }

    public function test_base_que_exige_consentimento_conta_sem_consentimento_e_o_consentimento_so_preenche_vazio(): void
    {
        $lista = ContactList::factory()->unicodrop()->create();
        $comConsentimento = Contact::factory()->comConsentimento()->create(['telefone' => '+5521998765432']);

        $importacao = ImportadorCsv::criar($this->fixture, 'contatos.csv', ['Telefone' => 'telefone'], ['contact_list_id' => $lista->id, 'origem' => 'unicodrop', 'consentimento_origem' => 'formulario', 'consentimento_detalhes' => 'LP drop out/2026'], null);
        $importacao->refresh();

        $this->assertSame(0, $importacao->sem_consentimento);
        $this->assertSame('whatsapp_optin', $comConsentimento->refresh()->consentimento_origem, 'consentimento existente não é sobrescrito');
        $novo = Contact::query()->where('telefone', '+5521988887777')->firstOrFail();
        $this->assertSame('formulario', $novo->consentimento_origem);
        $this->assertSame('LP drop out/2026', $novo->consentimento_detalhes);
        $this->assertNotNull($novo->consentimento_em);

        // Sem consentimento informado numa base que exige: importa, mas conta.
        $outra = ImportadorCsv::criar($this->fixture, 'contatos.csv', ['Telefone' => 'telefone'], ['contact_list_id' => $lista->id, 'origem' => 'unicodrop'], null)->refresh();
        $this->assertSame(0, $outra->sem_consentimento, 'todos já têm consentimento da importação anterior');
    }

    public function test_telefone_ja_suprimido_entra_como_optout(): void
    {
        Suppression::suprimir('+5521998765432', Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta');

        ImportadorCsv::criar($this->fixture, 'contatos.csv', ['Telefone' => 'telefone'], [], null);

        $this->assertSame(Contact::STATUS_OPTOUT, Contact::query()->where('telefone', '+5521998765432')->value('status'));
        $this->assertSame(Contact::STATUS_ATIVO, Contact::query()->where('telefone', '+5521988887777')->value('status'));
    }

    public function test_importacao_de_optout_suprime_cada_telefone_sem_criar_contato(): void
    {
        $ana = Contact::factory()->create(['telefone' => '+5521977776666']);

        $importacao = ImportadorCsv::criar(base_path('tests/Fixtures/optout.csv'), 'optout.csv', ['telefone' => 'telefone', 'motivo' => 'ignorar'], ['tipo' => ContactImport::TIPO_OPTOUT], null)->refresh();

        $this->assertSame(ContactImport::TIPO_OPTOUT, $importacao->tipo);
        $this->assertSame(2, $importacao->optouts);
        $this->assertSame(0, $importacao->criados);
        $this->assertSame(1, Contact::query()->count(), 'lista de opt-out não cria contato');
        $this->assertSame(Suppression::MOTIVO_IMPORTACAO, Suppression::bloqueia('+5521998765432', null, null));
        $this->assertSame('csv', Suppression::query()->first()->origem);
        $this->assertSame(Contact::STATUS_OPTOUT, $ana->refresh()->status);
    }

    public function test_comando_importa_csv(): void
    {
        $lista = ContactList::factory()->create(['slug' => 'leads-drop']);

        $this->artisan('disparo:importar-csv', ['arquivo' => $this->fixture, '--lista' => 'leads-drop', '--origem' => 'unicodrop', '--tags' => 'drop'])
            ->expectsOutputToContain('Importação #')
            ->assertSuccessful();

        $this->assertSame(3, $lista->contacts()->count());
        $this->assertSame('unicodrop', Contact::query()->first()->origem);

        $this->artisan('disparo:importar-csv', ['arquivo' => $this->fixture, '--mapeamento' => json_encode(['Telefone' => 'telefone', 'CPF' => 'atributos.cpf'])])
            ->expectsOutputToContain('dado sensível')
            ->assertFailed();
    }

    public function test_mapeamento_invalido_marca_a_importacao_como_falha_sem_estourar_o_job(): void
    {
        $importacao = ContactImport::query()->create(['tipo' => 'csv', 'mapeamento' => ['Telefone' => 'telefone', 'CPF' => 'atributos.cpf'], 'status' => 'pendente', 'arquivo' => 'importacoes/x/original.csv']);

        app(ImportadorCsv::class)->processar($importacao);

        $this->assertSame(ContactImport::STATUS_FALHOU, $importacao->refresh()->status);
        $this->assertStringContainsString('dado sensível', (string) $importacao->mensagem);
    }

    public function test_telefone_anonimizado_nao_renasce_e_coluna_sensivel_so_vai_para_documento(): void
    {
        Storage::fake('local');
        $user = $this->usuario(Permissions::ROLE_OPERATOR);
        $anonimo = Contact::factory()->create(['telefone' => '+5521998765432', 'nome' => 'Ana']);
        app(AnonimizarContato::class)->pelaRotina($anonimo, 'pedido do titular');

        $arquivo = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($arquivo, "telefone;nome;cargo\n21998765432;Ana de Novo;Gerente\n21 8765-4321;Bia Sem Nove;Dona\n");
        $mapeamento = ImportadorCsv::sugerirMapeamento(['telefone', 'nome', 'cargo']);
        $this->assertSame('atributos.cargo', $mapeamento['cargo'], '"cargo" não é "rg"');

        $importacao = ImportadorCsv::criar($arquivo, 'novo.csv', $mapeamento, ['origem' => 'csv', 'tipo_contato' => 'lead'], $user)->refresh();

        $this->assertSame(0, Contact::query()->where('telefone', '+5521998765432')->count(), 'o anonimizado não volta');
        $this->assertSame(1, $importacao->resultado['bloqueados']);
        $this->assertNotNull(Contact::query()->where('telefone', '+5521987654321')->first(), 'celular antigo sem o 9 entra com o 9, como no webhook');

        try {
            ImportadorCsv::exigirMapeamentoValido(['cpf' => 'empresa']);
            $this->fail('cpf em empresa viraria variável de template');
        } catch (ValidationException) {
        }
    }
}
