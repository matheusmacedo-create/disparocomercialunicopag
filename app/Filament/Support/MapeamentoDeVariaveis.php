<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Services\Envio\MontadorDeComponentes;
use App\Support\Variaveis;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;

/**
 * A ponte entre o formulário (um Select de variável + um padrão por marcador do corpo do template) e o
 * formato que o envio entende: `variaveis.corpo` com strings "{{variavel|padrão}}", lista posicional para
 * {{1}}..{{n}} ou mapa nomeado para {{nome}} (KB 4.6, {@see MontadorDeComponentes}).
 * Ninguém do comercial digita "{{atributos.oferta|taxa zero}}" na mão (achado da revisão de 28/10).
 */
final class MapeamentoDeVariaveis
{
    /** Opção "texto fixo": o padrão vale para todo mundo, sem variável. */
    public const FIXO = '__fixo';

    /** @var array<string, ?MessageTemplate> memo por requisição: o formulário pergunta pelo template dezenas de vezes */
    private static array $templates = [];

    /** O template escolhido no formulário (remetente + "nome|idioma"), ou null. */
    public static function templateDe(mixed $senderId, mixed $chave): ?MessageTemplate
    {
        [$nome, $idioma] = self::nomeEIdioma($chave);

        if (! $senderId || $nome === null) {
            return null;
        }

        $memo = (int) $senderId.'|'.$nome.'|'.$idioma;

        return self::$templates[$memo] ??= MessageTemplate::query()
            ->where('sender_id', (int) $senderId)
            ->where('nome', $nome)
            ->where('idioma', $idioma)
            ->first();
    }

    public static function esquecer(): void
    {
        self::$templates = [];
    }

    /** @return array{0: ?string, 1: ?string} nome e idioma de uma chave "nome|idioma" */
    public static function nomeEIdioma(mixed $chave): array
    {
        $chave = trim((string) $chave);

        if ($chave === '') {
            return [null, null];
        }

        $partes = explode('|', $chave, 2);

        return [$partes[0], ($partes[1] ?? '') !== '' ? $partes[1] : 'pt_BR'];
    }

    public static function chave(?string $nome, ?string $idioma): ?string
    {
        return blank($nome) ? null : $nome.'|'.($idioma ?: 'pt_BR');
    }

    /**
     * Templates que a campanha/régua pode usar neste remetente: aprovados na Meta e na categoria que o
     * remetente permite (KB 2.5).
     *
     * @return array<string, string> "nome|idioma" => rótulo
     */
    public static function opcoesDeTemplate(mixed $senderId): array
    {
        $sender = $senderId ? Sender::query()->find((int) $senderId) : null;

        if ($sender === null) {
            return [];
        }

        return MessageTemplate::query()
            ->where('sender_id', $sender->id)
            ->where('status', MessageTemplate::STATUS_APPROVED)
            ->orderBy('nome')
            ->get()
            ->filter(fn (MessageTemplate $template): bool => $sender->permiteCategoria($template->categoria))
            ->mapWithKeys(fn (MessageTemplate $template): array => [
                (string) self::chave($template->nome, $template->idioma) => sprintf('%s · %s · %s', $template->nome, $template->categoria, $template->idioma),
            ])
            ->all();
    }

    /** O que o operador vê embaixo do select: categoria, cabeçalho, botões e o corpo. */
    public static function descricaoDoTemplate(?MessageTemplate $template): ?string
    {
        if ($template === null) {
            return null;
        }

        $partes = [sprintf('Categoria %s · status %s', $template->categoria, $template->status)];

        if (($cabecalho = $template->formatoDoCabecalho()) !== null) {
            $partes[] = 'cabeçalho de '.strtolower($cabecalho);
        }

        if ($template->temBotaoDeUrlDinamica()) {
            $partes[] = 'botão de URL dinâmica';
        }

        return implode(' · ', $partes)."\n\n".$template->corpo();
    }

    /** @return array<string, string> opções do select de variável */
    public static function opcoes(): array
    {
        $opcoes = [];

        foreach (Variaveis::describe() as $chave => $descricao) {
            if ($chave === 'atributos.<coluna>') {
                continue;
            }

            $opcoes[$chave] = sprintf('{{%s}} - %s', $chave, $descricao);
        }

        foreach (self::atributosConhecidos() as $atributo) {
            $opcoes['atributos.'.$atributo] = sprintf('{{atributos.%s}} - coluna importada do CSV', $atributo);
        }

        $opcoes[self::FIXO] = 'Texto fixo (o mesmo para todos: só o padrão)';

        return $opcoes;
    }

    /**
     * Colunas extras presentes nos contatos (amostra das últimas importações): viram {{atributos.coluna}}.
     *
     * @return list<string>
     */
    public static function atributosConhecidos(): array
    {
        $chaves = [];

        Contact::query()
            ->whereNotNull('atributos')
            ->latest('id')
            ->limit(300)
            ->pluck('atributos')
            ->each(function ($atributos) use (&$chaves): void {
                foreach (array_keys((array) $atributos) as $chave) {
                    $chave = strtolower(trim((string) $chave));

                    if ($chave !== '') {
                        $chaves[$chave] = true;
                    }
                }
            });

        ksort($chaves);

        return array_keys($chaves);
    }

    /**
     * Um Fieldset por marcador do corpo ({{1}}, {{2}} ou {{nome}}), gravado em `$caminho.{i}.variavel` e
     * `$caminho.{i}.padrao`.
     *
     * @return list<Fieldset>
     */
    public static function campos(?MessageTemplate $template, string $caminho = 'mapeamento'): array
    {
        if ($template === null) {
            return [];
        }

        $opcoes = self::opcoes();
        $campos = [];

        foreach (array_values($template->variaveisDoCorpo()) as $i => $marcador) {
            $campos[] = Fieldset::make('{{'.$marcador.'}} do corpo')
                ->statePath($caminho.'.'.$i)
                ->columns(2)
                ->schema([
                    Select::make('variavel')
                        ->label('Variável')
                        ->options($opcoes)
                        ->searchable()
                        ->required()
                        ->live()
                        ->default(self::variavelSugerida($marcador)),
                    TextInput::make('padrao')
                        ->label('Padrão (quando vazio para o contato)')
                        ->maxLength(120)
                        ->default($marcador === '1' ? 'tudo bem' : null)
                        ->required(fn (Get $get): bool => $get('variavel') === self::FIXO)
                        ->helperText('A Meta recusa variável vazia: sem padrão, o contato sem o dado fica de fora (motivo "variável vazia").'),
                ]);
        }

        return $campos;
    }

    /**
     * De `variaveis.corpo` (gravado) para o formulário.
     *
     * @return array<int, array{variavel: ?string, padrao: ?string}>
     */
    public static function mapeamento(?MessageTemplate $template, mixed $corpo): array
    {
        if ($template === null) {
            return [];
        }

        $corpo = is_array($corpo) ? $corpo : [];
        $mapeamento = [];

        foreach (array_values($template->variaveisDoCorpo()) as $i => $marcador) {
            $modelo = ctype_digit($marcador) ? ($corpo[(int) $marcador - 1] ?? null) : ($corpo[$marcador] ?? null);

            $mapeamento[$i] = $modelo === null || trim((string) $modelo) === ''
                ? ['variavel' => self::variavelSugerida($marcador), 'padrao' => $marcador === '1' ? 'tudo bem' : null]
                : self::decompor((string) $modelo);
        }

        return $mapeamento;
    }

    /** @return array{variavel: string, padrao: ?string} */
    public static function decompor(string $modelo): array
    {
        if (preg_match('/^\{\{\s*([^}|]+?)\s*(?:\|([^}]*))?\}\}$/', trim($modelo), $m) === 1) {
            return ['variavel' => strtolower(trim($m[1])), 'padrao' => isset($m[2]) && trim($m[2]) !== '' ? trim($m[2]) : null];
        }

        return ['variavel' => self::FIXO, 'padrao' => $modelo];
    }

    /**
     * Do formulário para `variaveis.corpo`: lista posicional ({{1}}..{{n}}) ou mapa nomeado ({{nome}}).
     *
     * @param  array<int|string, mixed>  $mapeamento
     * @return array<int|string, string>
     */
    public static function corpo(?MessageTemplate $template, array $mapeamento): array
    {
        if ($template === null) {
            return [];
        }

        $marcadores = array_values($template->variaveisDoCorpo());
        $posicional = $marcadores !== [] && ctype_digit($marcadores[0]);
        $mapeamento = array_values($mapeamento);
        $corpo = [];

        foreach ($marcadores as $i => $marcador) {
            $item = (array) ($mapeamento[$i] ?? []);
            $variavel = strtolower(trim((string) ($item['variavel'] ?? '')));
            $padrao = trim((string) ($item['padrao'] ?? ''));

            $modelo = match (true) {
                $variavel === '' || $variavel === self::FIXO => $padrao,
                $padrao === '' => '{{'.$variavel.'}}',
                default => '{{'.$variavel.'|'.$padrao.'}}',
            };

            if ($posicional) {
                $corpo[(int) $marcador - 1] = $modelo;
            } else {
                $corpo[$marcador] = $modelo;
            }
        }

        if ($posicional) {
            ksort($corpo);
            $corpo = array_values($corpo);
        }

        return $corpo;
    }

    /**
     * O JSON `variaveis` completo, só com o que o template pede (cabeçalho, botão, links) - chave a mais
     * mudaria o hash da prévia sem mudar o envio.
     *
     * @param  array<int|string, mixed>  $mapeamento
     * @param  array<string, mixed>  $extras  cabecalho_texto, cabecalho_midia_url, cabecalho_documento_nome, botao_url_sufixo, permitir_links
     * @return array<string, mixed>
     */
    public static function variaveis(?MessageTemplate $template, array $mapeamento, array $extras): array
    {
        $variaveis = ['corpo' => self::corpo($template, $mapeamento)];
        $cabecalho = $template?->formatoDoCabecalho();

        if ($cabecalho === 'TEXT' && self::cabecalhoTemVariavel($template)) {
            $variaveis['cabecalho_texto'] = trim((string) ($extras['cabecalho_texto'] ?? ''));
        }

        if (in_array($cabecalho, ['IMAGE', 'DOCUMENT', 'VIDEO'], true)) {
            $variaveis['cabecalho_midia_url'] = trim((string) ($extras['cabecalho_midia_url'] ?? ''));
            $variaveis['cabecalho_midia_tipo'] = strtolower($cabecalho);

            if ($cabecalho === 'DOCUMENT' && filled($extras['cabecalho_documento_nome'] ?? null)) {
                $variaveis['cabecalho_documento_nome'] = trim((string) $extras['cabecalho_documento_nome']);
            }
        }

        if ($template?->temBotaoDeUrlDinamica()) {
            $variaveis['botao_url_sufixo'] = trim((string) ($extras['botao_url_sufixo'] ?? ''));
        }

        if (! empty($extras['permitir_links'])) {
            $variaveis['permitir_links'] = true;
        }

        return $variaveis;
    }

    public static function cabecalhoTemVariavel(?MessageTemplate $template): bool
    {
        foreach ((array) ($template?->componentes ?? []) as $componente) {
            if (strtoupper((string) ($componente['type'] ?? '')) === 'HEADER' && strtoupper((string) ($componente['format'] ?? 'TEXT')) === 'TEXT') {
                return str_contains((string) ($componente['text'] ?? ''), '{{');
            }
        }

        return false;
    }

    private static function variavelSugerida(string $marcador): ?string
    {
        if ($marcador === '1') {
            return 'primeiro_nome';
        }

        $nome = strtolower($marcador);

        return array_key_exists($nome, Variaveis::exemplo()) ? $nome : null;
    }
}
