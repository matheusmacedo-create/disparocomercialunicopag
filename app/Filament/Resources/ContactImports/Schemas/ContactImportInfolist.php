<?php

namespace App\Filament\Resources\ContactImports\Schemas;

use App\Filament\Resources\ContactImports\Tables\ContactImportsTable;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Tag;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $fuso = 'America/Sao_Paulo';

        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Importação')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('tipo')->label('Tipo')->badge()->color('gray')->formatStateUsing(fn (?string $state): string => ContactImportsTable::tipoLabels()[$state] ?? (string) $state),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => ContactImport::statusLabels()[$state] ?? (string) $state)
                                    ->color(fn (?string $state): string => ContactImportsTable::statusColor($state)),
                                TextEntry::make('nome_original')->label('Arquivo')->placeholder('—'),
                                TextEntry::make('list.nome')->label('Base de destino')->placeholder('—'),
                                TextEntry::make('sender.nome')->label('Remetente')->placeholder('—'),
                                TextEntry::make('origem')->label('Origem dos contatos')->formatStateUsing(fn (?string $state): string => Contact::origemLabels()[$state] ?? (string) $state)->placeholder('—'),
                                TextEntry::make('tipo_contato')->label('Tipo dos contatos')->formatStateUsing(fn (?string $state): string => Contact::tipoLabels()[$state] ?? (string) $state)->placeholder('—'),
                                TextEntry::make('tags')
                                    ->label('Tags')
                                    ->state(fn (ContactImport $record): array => Tag::query()->whereIn('id', array_map('intval', (array) ($record->tags ?? [])))->pluck('nome')->all())
                                    ->badge()
                                    ->color('gray')
                                    ->placeholder('—'),
                                TextEntry::make('consentimento_origem')
                                    ->label('Consentimento')
                                    ->formatStateUsing(fn (?string $state): string => Contact::consentimentoLabels()[$state] ?? (string) $state)
                                    ->helperText(fn (ContactImport $record): ?string => collect([
                                        data_get($record->parametros, 'consentimento_canal') ? 'canal: '.data_get($record->parametros, 'consentimento_canal') : null,
                                        data_get($record->parametros, 'consentimento_detalhes'),
                                    ])->filter()->implode(' · ') ?: null)
                                    ->placeholder('Não informado'),
                                TextEntry::make('user.name')->label('Enviada por')->placeholder('sistema'),
                                TextEntry::make('created_at')->label('Enviada em')->dateTime('d/m/Y H:i', $fuso),
                                TextEntry::make('iniciado_em')->label('Iniciada em')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                                TextEntry::make('concluido_em')->label('Concluída em')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                                TextEntry::make('mensagem')->label('Mensagem')->placeholder('—')->columnSpanFull()->color(fn (ContactImport $record): ?string => $record->status === ContactImport::STATUS_FALHOU ? 'danger' : null),
                            ]),

                        Section::make('Mapeamento das colunas')
                            ->visible(fn (ContactImport $record): bool => filled($record->mapeamento))
                            ->schema([
                                KeyValueEntry::make('mapeamento')->hiddenLabel()->keyLabel('Coluna do CSV')->valueLabel('Campo'),
                            ]),

                        Section::make('Primeiros erros')
                            ->description('Só os 100 primeiros ficam aqui; todas as linhas rejeitadas, com o motivo, estão no arquivo "Baixar rejeitadas".')
                            ->visible(fn (ContactImport $record): bool => filled($record->erros))
                            ->schema([
                                RepeatableEntry::make('erros')
                                    ->hiddenLabel()
                                    ->schema([
                                        TextEntry::make('linha')->label('Linha'),
                                        TextEntry::make('motivo')->label('Motivo')->columnSpan(3),
                                    ])
                                    ->columns(4),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Contagens')
                            ->schema([
                                TextEntry::make('total_linhas')->label('Linhas no arquivo'),
                                TextEntry::make('criados')->label('Contatos criados')->color('success'),
                                TextEntry::make('atualizados')->label('Contatos atualizados'),
                                TextEntry::make('ignorados')->label('Ignorados'),
                                TextEntry::make('invalidos')->label('Telefone inválido')->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                                TextEntry::make('duplicados')->label('Duplicados no arquivo'),
                                TextEntry::make('optouts')->label('Opt-outs (entram bloqueados)'),
                            ]),
                    ]),
            ]);
    }
}
