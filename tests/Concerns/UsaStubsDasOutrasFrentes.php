<?php

namespace Tests\Concerns;

use App\Services\Contatos\AnonimizarContato;
use App\Services\Contatos\ExportarDadosDoTitular;
use App\Services\Importacao\ImportadorCsv;
use Tests\Stubs\AnonimizadorEspiao;
use Tests\Stubs\ExportadorFalso;
use Tests\Stubs\ImportadorCsvFalso;

/**
 * Liga os stubs das outras frentes: o job pelo nome real (declarado se não existir) e os serviços pelo
 * container (o painel os resolve com app(), aceitando método estático ou de instância).
 */
trait UsaStubsDasOutrasFrentes
{
    protected AnonimizadorEspiao $anonimizador;

    protected ImportadorCsvFalso $importadorCsv;

    protected function usarStubsDasOutrasFrentes(): void
    {
        require_once __DIR__.'/../Stubs/outras-frentes.php';

        $this->anonimizador = new AnonimizadorEspiao;
        $this->importadorCsv = new ImportadorCsvFalso;

        app()->instance(AnonimizarContato::class, $this->anonimizador);
        app()->instance(ExportarDadosDoTitular::class, new ExportadorFalso);
        app()->instance(ImportadorCsv::class, $this->importadorCsv);
    }
}
