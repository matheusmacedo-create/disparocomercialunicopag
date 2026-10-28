<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactImport;
use App\Services\Importacao\ImportadorSellersInativos;
use App\Services\Unicopag\UnicopagClient;
use App\Support\Mask;
use Illuminate\Console\Command;
use Throwable;

/**
 * Base "Sellers inativos" a partir do unico-api (KB 9): do endpoint configurado ou de um arquivo JSON
 * com o mesmo contrato. Quem voltou a vender sai da base e conta como conversão.
 */
class ImportarSellersInativosCommand extends Command
{
    protected $signature = 'disparo:importar-sellers-inativos
        {--arquivo= : JSON com a lista (account_id, telefone?, nome?, email?, ultima_venda_em?, dias_sem_venda?, etapa?, aprovado_em?); sem ele, o endpoint UNICOPAG_SELLERS_ENDPOINT}
        {--dias=90 : Sem venda há N dias = inativo; venda mais recente que isso = voltou a vender}';

    protected $description = 'Monta e atualiza a base "Sellers inativos" a partir do unico-api (endpoint ou arquivo JSON).';

    public function handle(ImportadorSellersInativos $importador, UnicopagClient $unicopag): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $arquivo = (string) $this->option('arquivo');

        try {
            if ($arquivo !== '') {
                if (! is_file($arquivo)) {
                    $this->error('Arquivo não encontrado.');

                    return self::FAILURE;
                }

                $importacao = $importador->deArquivo($arquivo, $dias);
            } else {
                if (! $unicopag->sellersConfigurado()) {
                    $this->error('UNICOPAG_SELLERS_ENDPOINT e UNICOPAG_API_KEY não configurados: use --arquivo=lista.json.');

                    return self::FAILURE;
                }

                $importacao = $importador->doEndpoint($dias);
            }
        } catch (Throwable $e) {
            $this->error('Importação falhou: '.Mask::scrub($e->getMessage()));

            return self::FAILURE;
        }

        $r = (array) ($importacao->resultado ?? []);

        $this->info(sprintf('Importação #%d concluída: %s.', $importacao->id, $importacao->resumo()));
        $this->table(['Item', 'Total'], [
            ['Telefone obtido no cadastro do unico-api', (string) ($r['telefone_do_cadastro'] ?? 0)],
            ['Sem telefone (rejeitados, nunca viram contato)', (string) ($r['sem_telefone'] ?? 0)],
            ['Voltaram a vender (saíram da base)', (string) ($r['convertidos'] ?? 0)],
            ['Inscrições em régua encerradas (converteu)', (string) ($r['inscricoes_encerradas'] ?? 0)],
            ['Destinatários de campanha convertidos', (string) ($r['destinatarios_convertidos'] ?? 0)],
        ]);

        if ($importacao->temRejeitadas()) {
            $this->warn('Contas rejeitadas (com o account_id) em '.$importacao->caminhoDasRejeitadas().' (disco local): baixe pelo painel em Importações.');
        }

        return $importacao->status === ContactImport::STATUS_CONCLUIDA ? self::SUCCESS : self::FAILURE;
    }
}
