<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\MessageTemplate;
use App\Support\Variaveis;

/**
 * O template como o cliente veria no celular, com os valores de exemplo ({@see Variaveis::exemplo()}):
 * {{1}} vira o primeiro nome, {{nome}} vira o exemplo da variável de mesmo nome, o resto fica marcado.
 */
final class PreviaDeTemplate
{
    /**
     * @return array{cabecalho: ?string, cabecalho_formato: ?string, corpo: string, rodape: ?string, botoes: list<string>}
     */
    public static function montar(MessageTemplate $template): array
    {
        $previa = ['cabecalho' => null, 'cabecalho_formato' => null, 'corpo' => '', 'rodape' => null, 'botoes' => []];

        foreach ((array) $template->componentes as $componente) {
            $tipo = strtoupper((string) ($componente['type'] ?? ''));

            if ($tipo === 'HEADER') {
                $formato = strtoupper((string) ($componente['format'] ?? 'TEXT'));
                $previa['cabecalho_formato'] = $formato;
                $previa['cabecalho'] = $formato === 'TEXT' ? self::exemplificar((string) ($componente['text'] ?? '')) : '['.strtolower($formato).']';
            } elseif ($tipo === 'BODY') {
                $previa['corpo'] = self::exemplificar((string) ($componente['text'] ?? ''));
            } elseif ($tipo === 'FOOTER') {
                $previa['rodape'] = (string) ($componente['text'] ?? '');
            } elseif ($tipo === 'BUTTONS') {
                foreach ((array) ($componente['buttons'] ?? []) as $botao) {
                    $previa['botoes'][] = (string) ($botao['text'] ?? strtolower((string) ($botao['type'] ?? 'botão')));
                }
            }
        }

        return $previa;
    }

    public static function exemplificar(string $texto): string
    {
        $exemplo = Variaveis::exemplo();

        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function (array $m) use ($exemplo): string {
            $marcador = strtolower($m[1]);

            if ($marcador === '1') {
                return $exemplo['primeiro_nome'];
            }

            return $exemplo[$marcador] ?? '[{{'.$m[1].'}}]';
        }, $texto);
    }
}
