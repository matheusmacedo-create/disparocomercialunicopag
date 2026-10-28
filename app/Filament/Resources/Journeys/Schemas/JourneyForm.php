<?php

namespace App\Filament\Resources\Journeys\Schemas;

use App\Filament\Support\MapeamentoDeVariaveis;
use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\Segment;
use App\Models\Sender;
use App\Models\Tag;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Formulário da régua: a régua em si e o Repeater de passos (`passos`, campo só do formulário). A ordem
 * é a do Repeater; {@see self::gravarPassos()} apaga e regrava os passos (ordem única por régua, e a
 * inscrição guarda o número do passo, não o id).
 */
class JourneyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Régua')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')->label('Nome')->required()->maxLength(150),
                        Select::make('sender_id')
                            ->label('Remetente')
                            ->options(fn (?Journey $record): array => Sender::query()
                                ->where(fn ($q) => $q->where('ativo', true)->when($record?->sender_id, fn ($q, $id) => $q->orWhere('id', $id)))
                                ->orderBy('nome')
                                ->pluck('nome', 'id')
                                ->all())
                            ->required()
                            ->live(),
                        Textarea::make('descricao')->label('Descrição')->rows(2)->maxLength(2000)->columnSpanFull(),
                        Select::make('objetivo')
                            ->label('Objetivo')
                            ->options(Campaign::objetivoLabels())
                            ->required()
                            ->default(Campaign::OBJETIVO_RECUPERAR),
                        Select::make('gatilho')
                            ->label('Gatilho')
                            ->options(Journey::gatilhoLabels())
                            ->required()
                            ->default(Journey::GATILHO_MANUAL)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('gatilho_ref', null)),
                        Select::make('gatilho_ref')
                            ->label(fn (Get $get): string => match ($get('gatilho')) {
                                Journey::GATILHO_LISTA => 'Base',
                                Journey::GATILHO_TAG => 'Tag',
                                Journey::GATILHO_SEGMENTO => 'Segmento',
                                default => 'Referência',
                            })
                            ->options(fn (Get $get): array => match ($get('gatilho')) {
                                Journey::GATILHO_LISTA => ContactList::query()->orderBy('nome')->pluck('nome', 'id')->all(),
                                Journey::GATILHO_TAG => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all(),
                                Journey::GATILHO_SEGMENTO => Segment::query()->orderBy('nome')->pluck('nome', 'id')->all(),
                                default => [],
                            })
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('gatilho') !== null && $get('gatilho') !== Journey::GATILHO_MANUAL)
                            ->required(fn (Get $get): bool => $get('gatilho') !== null && $get('gatilho') !== Journey::GATILHO_MANUAL),
                    ]),

                Section::make('Regras')
                    ->description('A régua é a mensagem esperada: não espera a conversa aberta de 7 dias, só o intervalo mínimo; conta na frequência e tem teto próprio por dia.')
                    ->columns(3)
                    ->schema([
                        Toggle::make('sair_ao_responder')->label('Sair ao responder')->default(true)->helperText('Respondeu, virou conversa humana.'),
                        Toggle::make('sair_ao_converter')->label('Sair ao converter')->default(true)->helperText('Vendeu / abriu cadastro: sai com motivo "converteu".'),
                        Toggle::make('ignora_conversa_aberta')->label('Ignora conversa aberta')->default(true),
                        TextInput::make('intervalo_minimo_horas')->label('Intervalo mínimo entre templates (h)')->numeric()->integer()->minValue(1)->default(48)->required(),
                        TextInput::make('max_por_dia')->label('Máximo de envios por dia')->numeric()->integer()->minValue(1)->default(50)->required()->helperText('Obrigatório: a régua roda sozinha, todo dia.'),
                        TextInput::make('reinscrever_apos_dias')->label('Reinscrever depois de (dias)')->numeric()->integer()->minValue(1)->nullable()->helperText('Em branco: quem concluiu nunca volta.'),
                    ]),

                Section::make('Passos')
                    ->description('Na ordem em que acontecem. Template e texto só saem dentro das guardas do remetente.')
                    ->schema([
                        Repeater::make('passos')
                            ->hiddenLabel()
                            ->addActionLabel('Adicionar passo')
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): string => (JourneyStep::tipoLabels()[$state['tipo'] ?? ''] ?? 'Passo')
                                .(filled($state['template_chave'] ?? null) ? ' · '.MapeamentoDeVariaveis::nomeEIdioma($state['template_chave'])[0] : '')
                                .(filled($state['aguardar_horas'] ?? null) ? ' · '.$state['aguardar_horas'].' h' : ''))
                            ->schema([
                                Select::make('tipo')
                                    ->label('Tipo')
                                    ->options(JourneyStep::tipoLabels())
                                    ->required()
                                    ->live(),
                                Select::make('template_chave')
                                    ->label('Template')
                                    ->options(fn (Get $get): array => MapeamentoDeVariaveis::opcoesDeTemplate($get('../../sender_id')))
                                    ->searchable()
                                    ->live()
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TEMPLATE)
                                    ->required(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TEMPLATE)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                        $set('mapeamento', MapeamentoDeVariaveis::mapeamento(MapeamentoDeVariaveis::templateDe($get('../../sender_id'), $state), []));
                                    }),
                                Placeholder::make('template_corpo')
                                    ->label('Como está na Meta')
                                    ->content(fn (Get $get): ?string => MapeamentoDeVariaveis::descricaoDoTemplate(self::templateDoPasso($get)))
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TEMPLATE && self::templateDoPasso($get) !== null)
                                    ->extraAttributes(['class' => 'whitespace-pre-line']),
                                Group::make()
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TEMPLATE && self::templateDoPasso($get) !== null)
                                    ->schema(fn (Get $get): array => [
                                        ...MapeamentoDeVariaveis::campos(self::templateDoPasso($get), 'mapeamento'),
                                        TextInput::make('variaveis.cabecalho_midia_url')
                                            ->label('URL pública da mídia do cabeçalho')
                                            ->url()
                                            ->required()
                                            ->visible(in_array(self::templateDoPasso($get)?->formatoDoCabecalho(), ['IMAGE', 'DOCUMENT', 'VIDEO'], true)),
                                        TextInput::make('variaveis.cabecalho_texto')
                                            ->label('Texto do cabeçalho')
                                            ->required()
                                            ->visible(self::templateDoPasso($get)?->formatoDoCabecalho() === 'TEXT' && MapeamentoDeVariaveis::cabecalhoTemVariavel(self::templateDoPasso($get))),
                                        TextInput::make('variaveis.botao_url_sufixo')
                                            ->label('Sufixo do link do botão')
                                            ->required()
                                            ->visible((bool) self::templateDoPasso($get)?->temBotaoDeUrlDinamica()),
                                    ]),
                                TextInput::make('aguardar_horas')
                                    ->label('Aguardar (horas)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_AGUARDAR)
                                    ->required(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_AGUARDAR),
                                Select::make('condicao')
                                    ->label('Continuar só se')
                                    ->options(JourneyStep::condicaoLabels())
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_CONDICAO)
                                    ->required(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_CONDICAO),
                                Select::make('tag_id')
                                    ->label('Tag')
                                    ->options(fn (): array => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all())
                                    ->searchable()
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TAG)
                                    ->required(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TAG),
                                Textarea::make('texto')
                                    ->label('Texto (sai na resposta do contato, dentro das 24 h)')
                                    ->rows(3)
                                    ->maxLength(4000)
                                    ->helperText('Pode usar {{primeiro_nome|tudo bem}} e as outras variáveis; é a única hora em que um link pode ir.')
                                    ->visible(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TEXTO)
                                    ->required(fn (Get $get): bool => $get('tipo') === JourneyStep::TIPO_TEXTO),
                            ]),
                    ]),
            ]);
    }

    private static function templateDoPasso(Get $get): ?MessageTemplate
    {
        return MapeamentoDeVariaveis::templateDe($get('../../sender_id'), $get('template_chave'));
    }

    /**
     * Do Repeater para as linhas de `journey_steps` (ordem = posição).
     *
     * @param  array<int|string, mixed>  $passos
     * @return list<array<string, mixed>>
     */
    public static function passosParaGravar(array $passos, int $senderId): array
    {
        $linhas = [];

        foreach (array_values($passos) as $i => $passo) {
            $passo = (array) $passo;
            $tipo = (string) ($passo['tipo'] ?? '');
            [$nome, $idioma] = MapeamentoDeVariaveis::nomeEIdioma($passo['template_chave'] ?? null);
            $template = $tipo === JourneyStep::TIPO_TEMPLATE ? MapeamentoDeVariaveis::templateDe($senderId, $passo['template_chave'] ?? null) : null;

            $variaveis = match ($tipo) {
                JourneyStep::TIPO_TEMPLATE => MapeamentoDeVariaveis::variaveis($template, (array) ($passo['mapeamento'] ?? []), (array) ($passo['variaveis'] ?? [])),
                JourneyStep::TIPO_TEXTO => ['texto' => trim((string) ($passo['texto'] ?? ''))],
                default => null,
            };

            $linhas[] = [
                'ordem' => $i + 1,
                'tipo' => $tipo,
                'template_nome' => $tipo === JourneyStep::TIPO_TEMPLATE ? $nome : null,
                'template_idioma' => $tipo === JourneyStep::TIPO_TEMPLATE ? ($idioma ?? 'pt_BR') : null,
                'variaveis' => $variaveis,
                'aguardar_horas' => $tipo === JourneyStep::TIPO_AGUARDAR ? max(1, (int) ($passo['aguardar_horas'] ?? 0)) : null,
                'condicao' => $tipo === JourneyStep::TIPO_CONDICAO ? ($passo['condicao'] ?? null) : null,
                'tag_id' => $tipo === JourneyStep::TIPO_TAG ? ((int) ($passo['tag_id'] ?? 0) ?: null) : null,
            ];
        }

        return $linhas;
    }

    /** Apaga e regrava os passos na ordem do formulário, numa transação. @param  array<int|string, mixed>  $passos */
    public static function gravarPassos(Journey $journey, array $passos): void
    {
        $linhas = self::passosParaGravar($passos, (int) $journey->sender_id);

        DB::transaction(function () use ($journey, $linhas): void {
            $journey->steps()->delete();

            foreach ($linhas as $linha) {
                $journey->steps()->create($linha);
            }
        });
    }

    /** Dos passos gravados para o Repeater. @return list<array<string, mixed>> */
    public static function passosParaFormulario(Journey $journey): array
    {
        return $journey->steps()->get()->map(function (JourneyStep $passo) use ($journey): array {
            $chave = MapeamentoDeVariaveis::chave($passo->template_nome, $passo->template_idioma);
            $template = $passo->tipo === JourneyStep::TIPO_TEMPLATE ? MapeamentoDeVariaveis::templateDe($journey->sender_id, $chave) : null;
            $variaveis = (array) ($passo->variaveis ?? []);

            return [
                'tipo' => $passo->tipo,
                'template_chave' => $passo->tipo === JourneyStep::TIPO_TEMPLATE ? $chave : null,
                'mapeamento' => MapeamentoDeVariaveis::mapeamento($template, $variaveis['corpo'] ?? null),
                'variaveis' => array_intersect_key($variaveis, array_flip(['cabecalho_texto', 'cabecalho_midia_url', 'botao_url_sufixo'])),
                'aguardar_horas' => $passo->aguardar_horas,
                'condicao' => $passo->condicao,
                'tag_id' => $passo->tag_id,
                'texto' => $variaveis['texto'] ?? null,
            ];
        })->values()->all();
    }
}
