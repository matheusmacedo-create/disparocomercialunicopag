<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactImport;
use App\Models\ContactList;
use App\Services\Importacao\ImportadorCsv;
use App\Services\Importacao\LeitorDeCsv;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * O mesmo que a tela de importação, pela linha de comando (KB 8): mapeamento sugerido a partir do
 * cabeçalho (ou o JSON passado em --mapeamento), prévia e processamento síncrono. Saída sem PII.
 */
class ImportarCsvCommand extends Command
{
    protected $signature = 'disparo:importar-csv
        {arquivo : Caminho do CSV (delimitador , ou ; detectado; BOM aceito)}
        {--lista= : Base de destino (id ou slug)}
        {--origem=csv : Origem dos contatos (unicodrop | unicopag | csv | manual)}
        {--tipo=lead : Tipo dos contatos (seller_inativo | seller_ativo | lead | cliente | outro) - ou "optout" para importar uma lista de bloqueio}
        {--tags= : Tags separadas por vírgula}
        {--mapeamento= : JSON coluna => campo (telefone | nome | primeiro_nome | email | documento | empresa | account_id | atributos.<chave> | ignorar); sem ele, sugerido pelo cabeçalho}
        {--consentimento= : consentimento_origem a registrar em quem não tem (whatsapp_optin | formulario | contrato | cliente_ativo | cadastro_kyc | outro)}
        {--previa : Só mostra o mapeamento e a prévia, não importa}';

    protected $description = 'Importa um CSV de contatos (ou uma lista de opt-out) para uma base, como a tela de importação.';

    public function handle(ImportadorCsv $importador): int
    {
        $arquivo = (string) $this->argument('arquivo');

        if (! is_file($arquivo)) {
            $this->error('Arquivo não encontrado.');

            return self::FAILURE;
        }

        try {
            $cabecalho = LeitorDeCsv::cabecalho($arquivo);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $mapeamento = $this->mapeamento($cabecalho['cabecalho']);

        if ($mapeamento === null) {
            return self::FAILURE;
        }

        $this->line('Delimitador: "'.$cabecalho['delimitador'].'"');
        $this->table(['Coluna', 'Campo'], array_map(fn (string $c, string $f): array => [$c, $f], array_keys($mapeamento), array_values($mapeamento)));

        $optout = $this->option('tipo') === 'optout';
        $lista = $this->lista();

        if ($this->option('lista') && $lista === null) {
            $this->error('Base não encontrada: '.$this->option('lista'));

            return self::FAILURE;
        }

        $opcoes = [
            'tipo' => $optout ? ContactImport::TIPO_OPTOUT : ContactImport::TIPO_CSV,
            'contact_list_id' => $lista?->id,
            'origem' => (string) $this->option('origem'),
            'tipo_contato' => $optout ? null : (string) $this->option('tipo'),
            'tags' => array_filter(array_map('trim', explode(',', (string) $this->option('tags')))),
            'consentimento_origem' => $this->option('consentimento') ?: null,
            'consentimento_canal' => $this->option('consentimento') ? 'importacao' : null,
        ];

        try {
            $previa = $importador->previa($arquivo, $mapeamento, $opcoes);
        } catch (ValidationException $e) {
            foreach (collect($e->errors())->flatten() as $problema) {
                $this->error((string) $problema);
            }

            return self::FAILURE;
        }

        $this->info('Prévia: '.collect($previa['previsao'])->map(fn (int $n, string $k): string => "$k=$n")->implode(', '));

        if ($this->option('previa')) {
            return self::SUCCESS;
        }

        try {
            $importacao = ImportadorCsv::criar($arquivo, basename($arquivo), $mapeamento, $opcoes, null, enfileirar: false);
        } catch (ValidationException $e) {
            foreach (collect($e->errors())->flatten() as $problema) {
                $this->error((string) $problema);
            }

            return self::FAILURE;
        }

        $importador->processar($importacao);
        $importacao->refresh();

        if ($importacao->status !== ContactImport::STATUS_CONCLUIDA) {
            $this->error('Importação #'.$importacao->id.' falhou: '.$importacao->mensagem);

            return self::FAILURE;
        }

        $this->info(sprintf('Importação #%d concluída: %s.', $importacao->id, $importacao->resumo()));

        if ($importacao->temRejeitadas()) {
            $this->warn('Linhas rejeitadas em '.$importacao->caminhoDasRejeitadas().' (disco local): baixe pelo painel em Importações.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $cabecalho
     * @return array<string, string>|null
     */
    private function mapeamento(array $cabecalho): ?array
    {
        $json = (string) $this->option('mapeamento');

        if ($json === '') {
            return ImportadorCsv::sugerirMapeamento($cabecalho);
        }

        $mapeamento = json_decode($json, true);

        if (! is_array($mapeamento)) {
            $this->error('--mapeamento precisa ser um JSON de coluna => campo.');

            return null;
        }

        $desconhecidas = array_diff(array_keys($mapeamento), $cabecalho);

        if ($desconhecidas !== []) {
            $this->error('Colunas que não existem no arquivo: '.implode(', ', $desconhecidas));

            return null;
        }

        return ImportadorCsv::normalizarMapeamento($mapeamento);
    }

    private function lista(): ?ContactList
    {
        $ref = (string) $this->option('lista');

        if ($ref === '') {
            return null;
        }

        return ctype_digit($ref) ? ContactList::query()->find((int) $ref) : ContactList::query()->where('slug', $ref)->first();
    }
}
