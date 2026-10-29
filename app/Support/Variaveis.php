<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;
use Illuminate\Support\Str;

/**
 * As variáveis que uma campanha ou régua pode usar no template ("{{coluna}}" e "{{coluna|padrão}}", como
 * na CLI original) e a renderização. Única fonte: o painel lista `describe()`, a prévia usa `exemplo()`,
 * o envio usa `doContato()`.
 */
final class Variaveis
{
    /** @return array<string, string> variável => descrição */
    public static function describe(): array
    {
        return [
            'primeiro_nome' => 'Primeiro nome do contato (ou vazio: use {{primeiro_nome|tudo bem}})',
            'nome' => 'Nome completo',
            'email' => 'E-mail',
            'empresa' => 'Empresa / loja',
            'telefone' => 'Telefone formatado',
            'tipo' => 'Tipo do contato (seller_inativo, lead...)',
            'origem' => 'Origem do contato (unicodrop, csv...)',
            'data_hoje' => 'Data de hoje (dd/mm/aaaa)',
            'atributos.<coluna>' => 'Qualquer coluna extra importada do CSV (ex.: {{atributos.oferta}})',
        ];
    }

    /** @return array<string, string> */
    public static function exemplo(): array
    {
        return [
            'primeiro_nome' => 'Ana',
            'nome' => 'Ana Souza',
            'email' => 'ana@exemplo.com',
            'empresa' => 'Loja da Ana',
            'telefone' => '+55 (21) 99876-5432',
            'tipo' => 'seller_inativo',
            'origem' => 'unicopag',
            'data_hoje' => now(config('disparo.fuso'))->format('d/m/Y'),
            'atributos.oferta' => 'taxa zero por 30 dias',
        ];
    }

    /** @return array<string, string> */
    public static function doContato(Contact $contato): array
    {
        $dados = [
            'primeiro_nome' => (string) ($contato->primeiro_nome ?: Str::of((string) $contato->nome)->before(' ')->trim()),
            'nome' => (string) $contato->nome,
            'email' => (string) $contato->email,
            'empresa' => (string) $contato->empresa,
            'telefone' => Telefone::formatar($contato->telefone),
            'tipo' => (string) $contato->tipo,
            'origem' => (string) $contato->origem,
            'data_hoje' => now(config('disparo.fuso'))->format('d/m/Y'),
        ];

        foreach ((array) ($contato->atributos ?? []) as $chave => $valor) {
            if (is_scalar($valor) || $valor === null) {
                $dados['atributos.'.strtolower(trim((string) $chave))] = trim((string) $valor);
            }
        }

        // Nome com dígito (razão social de MEI "45.678.901 JOAO...") não serve de primeiro nome (KYC 8.23).
        if (preg_match('/\d/', $dados['primeiro_nome']) === 1) {
            $dados['primeiro_nome'] = '';
        }

        return $dados;
    }

    /**
     * Substitui "{{variavel}}" / "{{variavel|padrão}}" pelos dados. Variável desconhecida fica vazia (e cai
     * no padrão); texto sem marcador volta como está.
     *
     * @param  array<string, string>  $dados
     */
    public static function render(string $modelo, array $dados): string
    {
        return (string) preg_replace_callback('/\{\{\s*([^}|]+?)\s*(?:\|([^}]*))?\}\}/', function (array $m) use ($dados): string {
            $chave = strtolower(trim($m[1]));
            $valor = trim((string) ($dados[$chave] ?? ''));

            return $valor !== '' ? $valor : trim((string) ($m[2] ?? ''));
        }, $modelo);
    }

    /**
     * Variáveis citadas num modelo que não existem em `describe()` nem nos dados: o painel acusa.
     *
     * @param  array<string, string>  $dados
     * @return list<string>
     */
    public static function desconhecidas(string $modelo, array $dados): array
    {
        preg_match_all('/\{\{\s*([^}|]+?)\s*(?:\|[^}]*)?\}\}/', $modelo, $achados);

        return array_values(array_unique(array_filter(
            array_map(fn (string $v): string => strtolower(trim($v)), $achados[1]),
            fn (string $v): bool => ! array_key_exists($v, $dados) && ! str_starts_with($v, 'atributos.'),
        )));
    }
}
