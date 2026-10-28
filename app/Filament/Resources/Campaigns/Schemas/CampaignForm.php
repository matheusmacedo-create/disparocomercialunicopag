<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Filament\Support\MapeamentoDeVariaveis;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ContactList;
use App\Models\MessageTemplate;
use App\Models\Segment;
use App\Models\Sender;
use App\Models\Tag;
use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Formulário da campanha. O template é escolhido entre os do remetente (aprovados, categoria permitida) e
 * o mapeamento de variáveis é gerado a partir do que o corpo pede; `template_chave` e `mapeamento` são
 * campos só do formulário - {@see self::antesDeGravar()} os traduz para `template_nome`/`template_idioma`
 * e `variaveis.corpo`, e {@see self::aoPreencher()} faz o caminho de volta.
 */
class CampaignForm
{
    public const DIAS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campanha')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')->label('Nome')->required()->maxLength(150),
                        Select::make('objetivo')
                            ->label('Objetivo')
                            ->options(Campaign::objetivoLabels())
                            ->required()
                            ->default(Campaign::OBJETIVO_RECUPERAR)
                            ->helperText('Recuperar e ofertar nunca saem como utilidade para quem parou marketing (KB 2.4).'),
                        Select::make('sender_id')
                            ->label('Remetente')
                            ->options(fn (?Campaign $record): array => Sender::query()
                                ->where(fn ($q) => $q->where('ativo', true)->when($record?->sender_id, fn ($q, $id) => $q->orWhere('id', $id)))
                                ->orderBy('nome')
                                ->pluck('nome', 'id')
                                ->all())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('template_chave', null);
                                $set('mapeamento', []);
                            }),
                        Select::make('template_chave')
                            ->label('Template')
                            ->options(fn (Get $get): array => MapeamentoDeVariaveis::opcoesDeTemplate($get('sender_id')))
                            ->required()
                            ->searchable()
                            ->live()
                            ->helperText('Só templates aprovados na Meta e na categoria que o remetente permite. Não achou? Sincronize em Configuração > Templates.')
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $template = MapeamentoDeVariaveis::templateDe($get('sender_id'), $state);
                                $set('mapeamento', MapeamentoDeVariaveis::mapeamento($template, []));

                                if (strtoupper((string) $template?->categoria) === Sender::CATEGORIA_MARKETING && blank($get('regras.dias_da_semana'))) {
                                    $set('regras.dias_da_semana', (array) config('disparo.guardas.dias_marketing', [1, 2, 3, 4, 5]));
                                }
                            }),
                        Placeholder::make('template_corpo')
                            ->label('Como está na Meta')
                            ->content(fn (Get $get): ?string => MapeamentoDeVariaveis::descricaoDoTemplate(self::template($get)))
                            ->visible(fn (Get $get): bool => self::template($get) !== null)
                            ->extraAttributes(['class' => 'whitespace-pre-line'])
                            ->columnSpanFull(),
                    ]),

                Section::make('Variáveis do template')
                    ->description('Para cada marcador do corpo, de onde vem o valor e o que vai no lugar quando o contato não tem o dado. Links em variável só com permissão de quem aprova.')
                    ->visible(fn (Get $get): bool => self::template($get) !== null)
                    ->schema(fn (Get $get): array => self::camposDoTemplate(self::template($get))),

                Section::make('Público')
                    ->description('Bases e/ou segmento e/ou destinatários de uma campanha anterior, menos as exclusões. Sem público não existe campanha: seria a base inteira.')
                    ->columns(2)
                    ->schema([
                        Select::make('publico.listas')
                            ->label('Bases')
                            ->multiple()
                            ->options(fn (): array => ContactList::query()->orderBy('nome')->pluck('nome', 'id')->all())
                            ->searchable(),
                        Select::make('publico.segment_id')
                            ->label('Segmento')
                            ->options(fn (): array => Segment::query()->orderBy('nome')->pluck('nome', 'id')->all())
                            ->searchable()
                            ->nullable(),
                        Select::make('publico.campanha_anterior.campaign_id')
                            ->label('Quem recebeu a campanha')
                            ->options(fn (?Campaign $record): array => Campaign::query()
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->latest('id')
                                ->limit(200)
                                ->pluck('nome', 'id')
                                ->all())
                            ->searchable()
                            ->nullable()
                            ->live(),
                        Select::make('publico.campanha_anterior.status')
                            ->label('...com status')
                            ->multiple()
                            ->options(CampaignRecipient::statusLabels())
                            ->visible(fn (Get $get): bool => filled($get('publico.campanha_anterior.campaign_id')))
                            ->helperText('Ex.: "Falhou" para reenviar falhas; "Entregue" + "Lido" para quem não respondeu à oferta anterior.'),
                        Select::make('publico.excluir_listas')
                            ->label('Excluir quem está nas bases')
                            ->multiple()
                            ->options(fn (): array => ContactList::query()->orderBy('nome')->pluck('nome', 'id')->all())
                            ->searchable(),
                        Select::make('publico.excluir_tags')
                            ->label('Excluir quem tem as tags')
                            ->multiple()
                            ->options(fn (): array => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all())
                            ->searchable(),
                    ]),

                Section::make('Regras e agenda')
                    ->columns(2)
                    ->schema([
                        Toggle::make('regras.ignorar_conversa_aberta')
                            ->label('Ignorar conversa aberta (só utilidade)')
                            ->helperText('Aviso de conta pode sair mesmo com template recente ao mesmo telefone. Só vale para template UTILITY.')
                            ->visible(fn (Get $get): bool => strtoupper((string) self::template($get)?->categoria) === Sender::CATEGORIA_UTILITY),
                        TextInput::make('regras.max_por_dia')
                            ->label('Máximo por dia nesta campanha')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText('Em branco: o teto do remetente manda.'),
                        CheckboxList::make('regras.dias_da_semana')
                            ->label('Dias da semana')
                            ->options(self::DIAS)
                            ->columns(4)
                            ->helperText('Nada marcado = todos os dias. Campanha de marketing nasce de segunda a sexta; domingo nunca.')
                            ->columnSpanFull(),
                        DateTimePicker::make('agendada_para')
                            ->label('Agendada para')
                            ->timezone('America/Sao_Paulo')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d/m/Y H:i')
                            ->helperText('Horário de Brasília. Em branco: começa quando alguém iniciar, respeitando a janela do remetente.'),
                        TextInput::make('prioridade')
                            ->label('Prioridade')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(9)
                            ->default(5)
                            ->required()
                            ->helperText('1 sai primeiro, 9 por último. Réguas sempre antes das campanhas.'),
                        Textarea::make('observacoes')->label('Observações')->rows(3)->maxLength(2000)->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return list<Component> */
    private static function camposDoTemplate(?MessageTemplate $template): array
    {
        if ($template === null) {
            return [];
        }

        $cabecalho = $template->formatoDoCabecalho();
        $midia = in_array($cabecalho, ['IMAGE', 'DOCUMENT', 'VIDEO'], true);

        return [
            ...MapeamentoDeVariaveis::campos($template, 'mapeamento'),
            Group::make()
                ->columns(2)
                ->schema([
                    TextInput::make('variaveis.cabecalho_texto')
                        ->label('Texto do cabeçalho')
                        ->helperText('O cabeçalho do template tem variável: pode usar {{primeiro_nome|tudo bem}} e as outras variáveis.')
                        ->maxLength(60)
                        ->required()
                        ->visible($cabecalho === 'TEXT' && MapeamentoDeVariaveis::cabecalhoTemVariavel($template)),
                    TextInput::make('variaveis.cabecalho_midia_url')
                        ->label('URL pública da '.match ($cabecalho) {
                            'IMAGE' => 'imagem',
                            'VIDEO' => 'vídeo',
                            default => 'mídia (documento)',
                        }.' do cabeçalho')
                        ->url()
                        ->required()
                        ->maxLength(2000)
                        ->visible($midia),
                    TextInput::make('variaveis.cabecalho_documento_nome')
                        ->label('Nome do arquivo (documento)')
                        ->maxLength(120)
                        ->visible($cabecalho === 'DOCUMENT'),
                    TextInput::make('variaveis.botao_url_sufixo')
                        ->label('Sufixo do link do botão')
                        ->helperText('O que completa a URL do botão aprovado na Meta (pode usar variáveis).')
                        ->required()
                        ->maxLength(500)
                        ->visible($template->temBotaoDeUrlDinamica()),
                    Toggle::make('variaveis.permitir_links')
                        ->label('Permitir links em variáveis')
                        ->helperText('Por padrão uma célula de CSV com link não vira mensagem oficial. Só quem aprova libera.')
                        ->visible(fn (): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)),
                ]),
        ];
    }

    public static function template(Get $get): ?MessageTemplate
    {
        return MapeamentoDeVariaveis::templateDe($get('sender_id'), $get('template_chave'));
    }

    /**
     * Do formulário para as colunas: template_nome/idioma, `variaveis` (corpo + cabeçalho + botão),
     * `publico` e `regras` limpos (chave a mais mudaria o hash da prévia sem mudar o envio).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function antesDeGravar(array $data): array
    {
        [$nome, $idioma] = MapeamentoDeVariaveis::nomeEIdioma($data['template_chave'] ?? null);
        $template = MapeamentoDeVariaveis::templateDe($data['sender_id'] ?? null, $data['template_chave'] ?? null);

        $data['template_nome'] = (string) $nome;
        $data['template_idioma'] = $idioma ?? 'pt_BR';
        $data['variaveis'] = MapeamentoDeVariaveis::variaveis($template, (array) ($data['mapeamento'] ?? []), (array) ($data['variaveis'] ?? []));
        $data['publico'] = self::limparPublico((array) ($data['publico'] ?? []));
        $data['regras'] = self::limparRegras((array) ($data['regras'] ?? []), $template);
        $data['prioridade'] = max(1, min(9, (int) ($data['prioridade'] ?? 5)));

        unset($data['template_chave'], $data['mapeamento']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function aoPreencher(array $data): array
    {
        $data['template_chave'] = MapeamentoDeVariaveis::chave($data['template_nome'] ?? null, $data['template_idioma'] ?? null);
        $template = MapeamentoDeVariaveis::templateDe($data['sender_id'] ?? null, $data['template_chave']);
        $data['mapeamento'] = MapeamentoDeVariaveis::mapeamento($template, ((array) ($data['variaveis'] ?? []))['corpo'] ?? null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $publico
     * @return array<string, mixed>
     */
    private static function limparPublico(array $publico): array
    {
        $ids = fn (mixed $valores): array => array_values(array_filter(array_map('intval', (array) ($valores ?? []))));
        $limpo = [];

        foreach (['listas', 'excluir_listas', 'excluir_tags'] as $chave) {
            if (($lista = $ids($publico[$chave] ?? null)) !== []) {
                $limpo[$chave] = $lista;
            }
        }

        if ((int) ($publico['segment_id'] ?? 0) > 0) {
            $limpo['segment_id'] = (int) $publico['segment_id'];
        }

        $anterior = (array) ($publico['campanha_anterior'] ?? []);

        if ((int) ($anterior['campaign_id'] ?? 0) > 0) {
            $limpo['campanha_anterior'] = [
                'campaign_id' => (int) $anterior['campaign_id'],
                'status' => array_values(array_filter(array_map('strval', (array) ($anterior['status'] ?? [])))),
            ];
        }

        return $limpo;
    }

    /**
     * @param  array<string, mixed>  $regras
     * @return array<string, mixed>
     */
    private static function limparRegras(array $regras, ?MessageTemplate $template): array
    {
        $limpo = [];
        $categoria = strtoupper((string) $template?->categoria);

        if ($categoria === Sender::CATEGORIA_UTILITY && ! empty($regras['ignorar_conversa_aberta'])) {
            $limpo['ignorar_conversa_aberta'] = true;
        }

        if ((int) ($regras['max_por_dia'] ?? 0) > 0) {
            $limpo['max_por_dia'] = (int) $regras['max_por_dia'];
        }

        $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($regras['dias_da_semana'] ?? [])), fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($dias);

        // Marketing sem dia marcado herda o padrão da casa (seg-sex): oferta no domingo é denúncia.
        if ($dias === [] && $categoria === Sender::CATEGORIA_MARKETING) {
            $dias = array_values(array_map('intval', (array) config('disparo.guardas.dias_marketing', [1, 2, 3, 4, 5])));
        }

        if ($dias !== [] && count($dias) < 7) {
            $limpo['dias_da_semana'] = $dias;
        }

        return $limpo;
    }
}
