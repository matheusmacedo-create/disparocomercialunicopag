<?php

namespace App\Filament\Resources\MessageTemplates\Schemas;

use App\Filament\Resources\MessageTemplates\Tables\MessageTemplatesTable;
use App\Filament\Support\PreviaDeTemplate;
use App\Models\MessageTemplate;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MessageTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Callout::make('Categoria não permitida neste remetente')
                    ->danger()
                    ->icon(Heroicon::OutlinedHandRaised)
                    ->description(fn (MessageTemplate $record): string => sprintf('Este template é %s e o remetente "%s" só envia %s. Campanha com ele é recusada na validação; para ofertar, use um remetente que permita MARKETING.', $record->categoria, (string) $record->sender?->nome, implode('/', $record->sender?->categoriasPermitidas() ?? [])))
                    ->visible(fn (MessageTemplate $record): bool => MessageTemplatesTable::alertaDeCategoria($record) !== null)
                    ->columnSpanFull(),

                Callout::make('Template não aprovado')
                    ->warning()
                    ->description(fn (MessageTemplate $record): string => 'Status "'.$record->status.'" na Meta: só template APPROVED é enviado.')
                    ->visible(fn (MessageTemplate $record): bool => ! $record->aprovado())
                    ->columnSpanFull(),

                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Template')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('nome')->label('Nome')->copyable(),
                                TextEntry::make('sender.nome')->label('Remetente'),
                                TextEntry::make('idioma')->label('Idioma'),
                                TextEntry::make('categoria')->label('Categoria')->badge()->color(fn (?string $state): string => MessageTemplatesTable::corDaCategoria($state)),
                                TextEntry::make('status')->label('Status')->badge()->color(fn (?string $state): string => MessageTemplatesTable::corDoStatus($state)),
                                TextEntry::make('qualidade_score')
                                    ->label('Qualidade')
                                    ->state(fn (MessageTemplate $record): string => strtoupper((string) (($record->qualidade ?? [])['score'] ?? 'UNKNOWN')))
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'GREEN' => 'success',
                                        'YELLOW' => 'warning',
                                        'RED' => 'danger',
                                        default => 'gray',
                                    })
                                    ->helperText('YELLOW/RED em MARKETING não sai: insistir pausa o template (132015) e depois o número.'),
                                TextEntry::make('formato')->label('Formato')->state(fn (MessageTemplate $record): string => MessageTemplatesTable::formato($record)),
                                TextEntry::make('variaveis')
                                    ->label('Variáveis do corpo')
                                    ->state(fn (MessageTemplate $record): string => implode(', ', array_map(fn (string $v): string => '{{'.$v.'}}', $record->variaveisDoCorpo())) ?: 'nenhuma'),
                                TextEntry::make('meta_id')->label('ID na Meta')->placeholder('—')->copyable(),
                                TextEntry::make('sincronizado_em')->label('Sincronizado em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('Nunca'),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Prévia')
                            ->description('Como o cliente vê, com valores de exemplo.')
                            ->schema([
                                ViewEntry::make('previa')
                                    ->hiddenLabel()
                                    ->view('filament.templates.balao')
                                    ->viewData(fn (MessageTemplate $record): array => ['balao' => PreviaDeTemplate::montar($record)]),
                            ]),
                    ]),
            ]);
    }
}
