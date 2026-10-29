<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use Generator;
use RuntimeException;

/**
 * Lê o CSV como a CLI original lia (mcp/src/dispatch.ts): BOM descartado, delimitador `,` ou `;` decidido
 * pela primeira linha (o que aparecer mais vezes), cabeçalho limpo, linha vazia pulada, linha com menos
 * colunas completada. Planilha salva pelo Excel em Windows-1252 é convertida para UTF-8 célula a célula -
 * a UnicoDrop manda das duas formas.
 */
final class LeitorDeCsv
{
    public const BOM = "\xEF\xBB\xBF";

    /** @return array{delimitador: string, cabecalho: list<string>} */
    public static function cabecalho(string $caminho): array
    {
        $arquivo = self::abrir($caminho);

        try {
            $delimitador = self::detectarDelimitador($arquivo);
            $primeira = fgetcsv($arquivo, 0, $delimitador, '"', '');

            return ['delimitador' => $delimitador, 'cabecalho' => self::limparCabecalho(is_array($primeira) ? $primeira : [])];
        } finally {
            fclose($arquivo);
        }
    }

    /**
     * As linhas de dados, uma a uma: a chave é o número da linha no arquivo (o cabeçalho é a 1, a primeira
     * linha de dados a 2 - é o número que o operador procura no Excel), o valor é célula por coluna.
     *
     * @return Generator<int, array<string, string>>
     */
    public static function linhas(string $caminho): Generator
    {
        $arquivo = self::abrir($caminho);

        try {
            $delimitador = self::detectarDelimitador($arquivo);
            $primeira = fgetcsv($arquivo, 0, $delimitador, '"', '');
            $cabecalho = self::limparCabecalho(is_array($primeira) ? $primeira : []);

            if ($cabecalho === []) {
                return;
            }

            $numero = 1;

            while (($linha = fgetcsv($arquivo, 0, $delimitador, '"', '')) !== false) {
                $numero++;

                // Linha em branco vem como [null].
                if ($linha === [null] || trim(implode('', array_map(fn ($c): string => (string) $c, $linha))) === '') {
                    continue;
                }

                $registro = [];

                foreach ($cabecalho as $indice => $coluna) {
                    $registro[$coluna] = trim(self::utf8((string) ($linha[$indice] ?? '')));
                }

                yield $numero => $registro;
            }
        } finally {
            fclose($arquivo);
        }
    }

    /** @return resource */
    private static function abrir(string $caminho)
    {
        $arquivo = @fopen($caminho, 'rb');

        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo da importação.');
        }

        return $arquivo;
    }

    /** @param  resource  $arquivo */
    private static function detectarDelimitador($arquivo): string
    {
        $primeira = (string) fgets($arquivo);
        rewind($arquivo);

        return substr_count($primeira, ';') > substr_count($primeira, ',') ? ';' : ',';
    }

    /**
     * Cabeçalho sem BOM, sem espaços sobrando, sem nome vazio ("coluna_3") e sem nome repetido ("nome_2").
     *
     * @param  list<mixed>  $bruto
     * @return list<string>
     */
    private static function limparCabecalho(array $bruto): array
    {
        $cabecalho = [];
        $vistos = [];

        foreach (array_values($bruto) as $indice => $celula) {
            $nome = trim(self::utf8((string) $celula));

            if ($indice === 0 && str_starts_with($nome, self::BOM)) {
                $nome = trim(substr($nome, strlen(self::BOM)));
            }

            if ($nome === '') {
                $nome = 'coluna_'.($indice + 1);
            }

            $base = $nome;
            $n = 1;

            while (isset($vistos[mb_strtolower($nome)])) {
                $nome = $base.'_'.(++$n);
            }

            $vistos[mb_strtolower($nome)] = true;
            $cabecalho[] = $nome;
        }

        // Cabeçalho inteiro vazio (arquivo sem linhas) não serve.
        return array_filter($cabecalho, fn (string $c): bool => ! str_starts_with($c, 'coluna_')) === [] ? [] : $cabecalho;
    }

    /** Célula que não é UTF-8 válido veio do Excel em Windows-1252. */
    private static function utf8(string $texto): string
    {
        if ($texto === '' || mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return (string) mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }
}
