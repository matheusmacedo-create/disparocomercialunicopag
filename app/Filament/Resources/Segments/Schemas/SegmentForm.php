<?php

namespace App\Filament\Resources\Segments\Schemas;

use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Tag;
use App\Services\Segmentos\ConsultaDeSegmento;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Um campo por regra que a {@see ConsultaDeSegmento} entende; a contagem ao vivo roda a mesma query
 * que a campanha vai usar.
 */
class SegmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $bases = fn (): array => ContactList::query()->orderBy('nome')->pluck('nome', 'id')->all();
        $tags = fn (): array => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all();

        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Segmento')
                            ->schema([
                                TextInput::make('nome')->label('Nome')->required()->maxLength(255),
                                Textarea::make('descricao')->label('Descrição')->rows(2),
                            ]),

                        Section::make('Quem entra')
                            ->columns(2)
                            ->schema([
                                Select::make('regras.listas')->label('Nas bases')->multiple()->options($bases)->searchable()->live(),
                                Select::make('regras.excluir_listas')->label('Fora das bases')->multiple()->options($bases)->searchable()->live(),
                                Select::make('regras.tags')->label('Com as tags')->multiple()->options($tags)->searchable()->live(),
                                Select::make('regras.excluir_tags')->label('Sem as tags')->multiple()->options($tags)->searchable()->live(),
                                Select::make('regras.origens')->label('Origens')->multiple()->options(Contact::origemLabels())->live(),
                                Select::make('regras.tipos')->label('Tipos')->multiple()->options(Contact::tipoLabels())->live(),
                                Select::make('regras.status')
                                    ->label('Status do contato')
                                    ->multiple()
                                    ->options(Contact::statusLabels())
                                    ->default([Contact::STATUS_ATIVO])
                                    ->helperText('Vazio = só ativos.')
                                    ->live(),
                                Toggle::make('regras.sem_whatsapp')
                                    ->label('Incluir quem está sem WhatsApp (quarentena 131026)')
                                    ->default(true)
                                    ->live(),
                            ]),

                        Section::make('Atividade')
                            ->columns(3)
                            ->schema([
                                TextInput::make('regras.sem_envio_ha_dias')->label('Sem envio há (dias)')->numeric()->minValue(0)->live(onBlur: true),
                                TextInput::make('regras.sem_resposta_ha_dias')->label('Sem resposta há (dias)')->numeric()->minValue(0)->live(onBlur: true),
                                TextInput::make('regras.respondeu_ha_dias')->label('Respondeu nos últimos (dias)')->numeric()->minValue(0)->live(onBlur: true),
                                DatePicker::make('regras.criado_apos')->label('Cadastrado a partir de')->native(false)->displayFormat('d/m/Y')->live(),
                                DatePicker::make('regras.criado_antes')->label('Cadastrado até')->native(false)->displayFormat('d/m/Y')->live(),
                            ]),

                        Section::make('Templates e campanhas')
                            ->description('Quem recebeu (ou não) um template, por qualquer remetente, inclusive no histórico da Kapso; e quem esteve numa campanha com certo resultado.')
                            ->columns(2)
                            ->schema([
                                TextInput::make('regras.recebeu_template.nome')->label('Recebeu o template')->placeholder('oferta_maquininha_v1')->maxLength(512)->live(onBlur: true),
                                TextInput::make('regras.recebeu_template.dias')->label('... nos últimos (dias)')->numeric()->minValue(0)->placeholder('sempre')->live(onBlur: true),
                                TextInput::make('regras.nao_recebeu_template.nome')->label('NÃO recebeu o template')->placeholder('oferta_maquininha_v2')->maxLength(512)->live(onBlur: true),
                                TextInput::make('regras.nao_recebeu_template.dias')->label('... nos últimos (dias)')->numeric()->minValue(0)->placeholder('sempre')->live(onBlur: true),
                                Select::make('regras.campanha.campaign_id')
                                    ->label('Esteve na campanha')
                                    ->options(fn (): array => Campaign::query()->orderByDesc('id')->limit(200)->pluck('nome', 'id')->all())
                                    ->searchable()
                                    ->live(),
                                Select::make('regras.campanha.status')
                                    ->label('... com o resultado')
                                    ->multiple()
                                    ->options(CampaignRecipient::statusLabels())
                                    ->helperText('Vazio = qualquer resultado.')
                                    ->live(),
                            ]),

                        Section::make('Atributos do CSV')
                            ->description('Colunas extras importadas ({{atributos.coluna}}).')
                            ->schema([
                                Repeater::make('regras.atributos')
                                    ->hiddenLabel()
                                    ->schema([
                                        TextInput::make('campo')->label('Coluna')->required()->maxLength(60),
                                        Select::make('operador')
                                            ->label('Operador')
                                            ->options(['=' => 'igual a', '!=' => 'diferente de', 'contem' => 'contém', 'vazio' => 'está vazio', 'nao_vazio' => 'não está vazio'])
                                            ->default('=')
                                            ->required()
                                            ->native(false),
                                        TextInput::make('valor')->label('Valor')->maxLength(255),
                                    ])
                                    ->columns(3)
                                    ->defaultItems(0)
                                    ->addActionLabel('Adicionar condição')
                                    ->reorderable(false)
                                    ->live(),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Contagem ao vivo')
                            ->description('Roda a mesma consulta que a campanha usa. As guardas de envio (opt-out, frequência, janela) só entram na prévia da campanha.')
                            ->schema([
                                Placeholder::make('contagem')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): string => self::contagem((array) ($get('regras') ?? []))),
                            ]),
                    ]),
            ]);
    }

    /** @param  array<string, mixed>  $regras */
    public static function contagem(array $regras): string
    {
        try {
            $total = ConsultaDeSegmento::query(SegmentResource::limparRegras($regras))->count();
        } catch (Throwable) {
            return 'Regras incompletas: ajuste os campos para contar.';
        }

        return number_format($total, 0, ',', '.').' contato(s)';
    }
}
