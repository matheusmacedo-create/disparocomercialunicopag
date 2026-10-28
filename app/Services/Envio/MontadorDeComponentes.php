<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Services\WhatsApp\WhatsAppException;
use App\Support\Variaveis;

/**
 * Da definição do template na Meta + variáveis da campanha + dados do contato aos componentes de envio da
 * Cloud API. Junta a CLI original (`{{coluna|padrão}}`, cabeçalho de mídia, botão de URL) com a conferência
 * estrutural do KYC (KapsoMessageSender::montarTemplate): o que não bate com o template aprovado é
 * recusado aqui, antes de gastar o envio - 132000 descoberto um contato por vez é o que a régua do KYC
 * sofria.
 *
 * `variaveis` da campanha (KB 4.6):
 *  - `corpo`: lista posicional (["{{primeiro_nome|tudo bem}}", "{{atributos.oferta}}"]) ou mapa nomeado
 *    ({"nome": "{{primeiro_nome}}"}) para template NAMED;
 *  - `cabecalho_texto`: valor do {{1}} do cabeçalho de texto;
 *  - `cabecalho_midia_url` + `cabecalho_midia_tipo` (image|document|video);
 *  - `botao_url_sufixo`: o resto do link do botão de URL dinâmica (índice do botão detectado no template).
 */
final class MontadorDeComponentes
{
    /**
     * @param  array<string, mixed>  $definicao  template como a Meta devolve (components, parameter_format)
     * @param  array<string, mixed>  $variaveis  da campanha/passo (ver acima)
     * @param  array<string, string>  $dados  variáveis disponíveis do contato ({@see Variaveis::doContato()})
     * @return list<array<string, mixed>>
     */
    public static function montar(array $definicao, array $variaveis, array $dados): array
    {
        $nome = (string) ($definicao['name'] ?? 'template');
        $componentes = [];
        $corpo = $variaveis['corpo'] ?? [];
        $nomeado = is_array($corpo) && $corpo !== [] && ! array_is_list($corpo);
        self::$permitirLinks = (bool) ($variaveis['permitir_links'] ?? false);

        foreach ((array) ($definicao['components'] ?? []) as $componente) {
            $tipo = strtoupper((string) ($componente['type'] ?? ''));

            if ($tipo === 'HEADER') {
                $formato = strtoupper((string) ($componente['format'] ?? 'TEXT'));

                if ($formato === 'TEXT') {
                    $marcadores = self::marcadores((string) ($componente['text'] ?? ''));

                    if ($marcadores !== []) {
                        $valor = Variaveis::render((string) ($variaveis['cabecalho_texto'] ?? ''), $dados);
                        $componentes[] = ['type' => 'header', 'parameters' => [self::parametroTexto($valor, 'cabeçalho', $nome, $nomeado ? $marcadores[0] : null)]];
                    }
                } elseif (in_array($formato, ['IMAGE', 'DOCUMENT', 'VIDEO'], true)) {
                    $url = trim((string) ($variaveis['cabecalho_midia_url'] ?? ''));

                    if ($url === '') {
                        throw new WhatsAppException(sprintf('Template "%s" tem cabeçalho de %s: informe a URL da mídia na campanha.', $nome, strtolower($formato)), 0, 'template_nao_suportado');
                    }

                    $chave = strtolower($formato);
                    $midia = ['link' => $url];

                    if ($chave === 'document' && filled($variaveis['cabecalho_documento_nome'] ?? null)) {
                        $midia['filename'] = (string) $variaveis['cabecalho_documento_nome'];
                    }

                    $componentes[] = ['type' => 'header', 'parameters' => [['type' => $chave, $chave => $midia]]];
                } else {
                    throw new WhatsAppException(sprintf('Template "%s" tem cabeçalho de %s, que a plataforma não envia.', $nome, strtolower($formato)), 0, 'template_nao_suportado');
                }
            }

            if ($tipo === 'BODY') {
                $marcadores = self::marcadores((string) ($componente['text'] ?? ''));

                if ($marcadores === []) {
                    continue;
                }

                $parametros = self::parametrosDoCorpo($marcadores, $corpo, $dados, $nome);

                if ($parametros !== []) {
                    $componentes[] = ['type' => 'body', 'parameters' => $parametros];
                }
            }

            if ($tipo === 'BUTTONS') {
                foreach (array_values((array) ($componente['buttons'] ?? [])) as $indice => $botao) {
                    $url = (string) ($botao['url'] ?? '');

                    if (strtoupper((string) ($botao['type'] ?? '')) !== 'URL' || ! str_contains($url, '{{')) {
                        continue;
                    }

                    $sufixo = Variaveis::render((string) ($variaveis['botao_url_sufixo'] ?? ''), $dados);

                    if (trim($sufixo) === '') {
                        throw new WhatsAppException(sprintf('Template "%s" tem botão de URL dinâmica (%s...): informe o sufixo do link na campanha.', $nome, (string) strstr($url, '{{', true)), 0, 'template_nao_suportado');
                    }

                    $componentes[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => (string) $indice,
                        // O sufixo compõe uma URL por natureza; o domínio é o do template aprovado.
                        'parameters' => [['type' => 'text', 'text' => self::limpar($sufixo, 'botão URL', true)]],
                    ];
                }
            }
        }

        return $componentes;
    }

    /**
     * Resumo legível do que foi enviado (para a trilha): "nome | {{1}}=Ana | {{2}}=tudo bem".
     *
     * @param  list<array<string, mixed>>  $componentes
     */
    public static function resumo(string $template, array $componentes): string
    {
        $partes = [$template];

        foreach ($componentes as $componente) {
            foreach ((array) ($componente['parameters'] ?? []) as $i => $parametro) {
                $rotulo = $parametro['parameter_name'] ?? ($componente['type'].($componente['type'] === 'body' ? ' {{'.($i + 1).'}}' : ''));
                $valor = $parametro['text'] ?? ($parametro['image']['link'] ?? $parametro['document']['link'] ?? $parametro['video']['link'] ?? '');
                $partes[] = $rotulo.'='.$valor;
            }
        }

        return mb_substr(implode(' | ', $partes), 0, 1000);
    }

    /**
     * {{1}}, {{2}}... ou {{nome}} do corpo.
     *
     * @param  list<string>  $marcadores
     * @param  mixed  $corpo
     * @param  array<string, string>  $dados
     * @return list<array<string, string>>
     */
    private static function parametrosDoCorpo(array $marcadores, mixed $corpo, array $dados, string $template): array
    {
        $numericos = array_values(array_filter($marcadores, 'ctype_digit'));

        if ($numericos !== []) {
            sort($numericos, SORT_NUMERIC);
            $posicionais = is_array($corpo) && array_is_list($corpo) ? $corpo : [];

            // Sem variáveis na campanha e só {{1}}: é o primeiro nome (padrão dos templates da casa).
            if ($posicionais === [] && $numericos === ['1']) {
                $posicionais = ['{{primeiro_nome|tudo bem}}'];
            }

            return array_map(function (string $numero) use ($posicionais, $dados, $template): array {
                $modelo = $posicionais[(int) $numero - 1]
                    ?? throw new WhatsAppException(sprintf('Template "%s" pede {{%s}}, mas a campanha não diz o que vai nele.', $template, $numero), 0, '132000');

                return self::parametroTexto(Variaveis::render((string) $modelo, $dados), '{{'.$numero.'}}', $template, null);
            }, $numericos);
        }

        $mapa = is_array($corpo) && ! array_is_list($corpo) ? $corpo : [];

        return array_map(function (string $nome) use ($mapa, $dados, $template): array {
            $modelo = $mapa[$nome] ?? $mapa[strtolower($nome)] ?? (array_key_exists(strtolower($nome), $dados) ? '{{'.strtolower($nome).'}}' : null);

            if ($modelo === null) {
                throw new WhatsAppException(sprintf('Template "%s" usa {{%s}}, que a campanha não preenche.', $template, $nome), 0, '132000');
            }

            return self::parametroTexto(Variaveis::render((string) $modelo, $dados), '{{'.$nome.'}}', $template, $nome);
        }, $marcadores);
    }

    private static bool $permitirLinks = false;

    /** @return array<string, string> */
    private static function parametroTexto(string $valor, string $marcador, string $template, ?string $nomeado): array
    {
        $parametro = ['type' => 'text', 'text' => self::limpar($valor, $marcador, self::$permitirLinks)];

        if ($nomeado !== null && ! ctype_digit($nomeado)) {
            $parametro['parameter_name'] = $nomeado;
        }

        return $parametro;
    }

    /** Variável maior que isto não é dado de contato: é texto colado numa célula. */
    private const TAMANHO_MAXIMO = 120;

    /**
     * A Meta recusa parâmetro vazio, com quebra de linha ou tab. A plataforma recusa também link em
     * variável (uma célula de CSV de terceiro não pode virar link numa mensagem oficial) - salvo
     * `permitir_links` na campanha, decidido por quem aprova - e valor longo demais.
     */
    private static function limpar(string $valor, string $marcador, bool $permitirLinks = false): string
    {
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $valor));

        if ($limpo === '') {
            throw new WhatsAppException(sprintf('A variável %s do template ficou vazia para este contato (use {{coluna|padrão}}).', $marcador), 0, 'variavel_vazia');
        }

        if (! $permitirLinks && preg_match('/https?:\/\/|www\.|\b[a-z0-9-]+\.(?:com|net|org|br|io|app|me|ly)\b/i', $limpo) === 1) {
            throw new WhatsAppException(sprintf('A variável %s contém um link, e a campanha não permite links em variáveis.', $marcador), 0, 'variavel_com_link');
        }

        if (mb_strlen($limpo) > self::TAMANHO_MAXIMO) {
            throw new WhatsAppException(sprintf('A variável %s tem mais de %d caracteres.', $marcador, self::TAMANHO_MAXIMO), 0, 'variavel_longa');
        }

        return $limpo;
    }

    /** @return list<string> */
    private static function marcadores(string $texto): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $texto, $achados);

        return array_values(array_unique($achados[1]));
    }
}
