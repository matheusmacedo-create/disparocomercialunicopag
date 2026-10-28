<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use App\Models\ContactImport;
use App\Support\Telefone;
use Illuminate\Support\Facades\Storage;

/**
 * O `rejeitadas.csv` de uma importação: TODAS as linhas que não entraram (não só as 100 primeiras que
 * ficam em `contact_imports.erros`), com o número da linha, o telefone mascarado e o motivo, para o
 * operador corrigir e reimportar (achado da revisão de 28/10). Fica no disco `local`
 * (storage/app/private/importacoes/{id}/rejeitadas.csv) e o painel baixa por
 * {@see ContactImport::baixarRejeitadas()}.
 *
 * Separador `;` e BOM: é o que o Excel em português abre certo. Célula que começa com `= + - @` ganha
 * apóstrofo (fórmula de planilha não executa em exportação).
 */
final class Rejeitadas
{
    /** @var resource|null */
    private $arquivo = null;

    private int $total = 0;

    public function __construct(private readonly ContactImport $importacao) {}

    public function registrar(int|string $linha, ?string $telefone, string $motivo, ?string $referencia = null): void
    {
        $mascarado = filled($telefone) ? Telefone::mascarar($telefone) : '';

        $this->importacao->registrarErro($linha, $motivo.($referencia !== null && $referencia !== '' ? ' ('.$referencia.')' : ''));

        fputcsv($this->abrir(), array_map(self::celula(...), [(string) $linha, $mascarado, $referencia ?? '', $motivo]), ';', '"', '');
        $this->total++;
    }

    /** Fecha o arquivo; devolve quantas linhas foram rejeitadas (0 = nenhum arquivo foi criado). */
    public function fechar(): int
    {
        if ($this->arquivo !== null) {
            fclose($this->arquivo);
            $this->arquivo = null;
        }

        return $this->total;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return resource */
    private function abrir()
    {
        if ($this->arquivo !== null) {
            return $this->arquivo;
        }

        $disco = Storage::disk('local');
        $caminho = $this->importacao->caminhoDasRejeitadas();
        $disco->makeDirectory(dirname($caminho));

        $arquivo = fopen($disco->path($caminho), 'wb');

        if ($arquivo === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo de linhas rejeitadas.');
        }

        fwrite($arquivo, LeitorDeCsv::BOM);
        fputcsv($arquivo, ['linha', 'telefone', 'referencia', 'motivo'], ';', '"', '');

        return $this->arquivo = $arquivo;
    }

    private static function celula(string $valor): string
    {
        // O telefone mascarado começa com "+55 " e não é fórmula.
        return preg_match('/^[=+\-@]/', $valor) === 1 && preg_match('/^\+55 /', $valor) !== 1 ? "'".$valor : $valor;
    }
}
