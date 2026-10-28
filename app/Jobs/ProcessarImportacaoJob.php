<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContactImport;
use App\Services\Importacao\ImportadorCsv;
use App\Support\Mask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa uma importação de CSV (ou de lista de opt-out) na fila. Carrega só o id: o arquivo e o
 * mapeamento ficam na `contact_imports`, nunca no payload do job (que iria parar em `failed_jobs`).
 * Em teste a fila é síncrona.
 */
class ProcessarImportacaoJob implements ShouldQueue
{
    use Queueable;

    /** Planilha de milhares de linhas: uma hora de folga; sem retentativa (o que entrou, entrou). */
    public int $timeout = 3600;

    public int $tries = 1;

    public readonly int $importacaoId;

    public function __construct(int|ContactImport $importacao)
    {
        $this->importacaoId = $importacao instanceof ContactImport ? $importacao->id : $importacao;
    }

    public function handle(ImportadorCsv $importador): void
    {
        $importacao = ContactImport::query()->find($this->importacaoId);

        if ($importacao === null) {
            return;
        }

        if (! in_array($importacao->tipo, [ContactImport::TIPO_CSV, ContactImport::TIPO_OPTOUT], true)) {
            Log::warning('ProcessarImportacaoJob só processa CSV e opt-out', ['importacao' => $importacao->id, 'tipo' => $importacao->tipo]);

            return;
        }

        $importador->processar($importacao);
    }

    public function failed(?Throwable $exception): void
    {
        $importacao = ContactImport::query()->find($this->importacaoId);

        if ($importacao !== null && $importacao->emAndamento()) {
            $importacao->forceFill([
                'status' => ContactImport::STATUS_FALHOU,
                'concluido_em' => now(),
                'mensagem' => mb_substr('O processamento parou: '.Mask::scrub($exception?->getMessage() ?? 'erro desconhecido'), 0, 1000),
            ])->save();
        }
    }
}
