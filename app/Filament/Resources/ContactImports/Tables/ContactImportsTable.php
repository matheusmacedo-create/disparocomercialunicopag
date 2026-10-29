<?php

namespace App\Filament\Resources\ContactImports\Tables;

use App\Models\ContactImport;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContactImportsTable
{
    public static function configure(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => self::tipoLabels()[$state] ?? (string) $state),
                TextColumn::make('nome_original')
                    ->label('Arquivo / fonte')
                    ->state(fn (ContactImport $record): string => $record->nome_original ?: ($record->sender?->nome ?: '—'))
                    ->description(fn (ContactImport $record): ?string => $record->list?->nome ? 'base: '.$record->list->nome : null)
                    ->wrap()
                    ->searchable(['nome_original']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ContactImport::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => self::statusColor($state)),
                TextColumn::make('total_linhas')->label('Linhas')->numeric()->alignEnd(),
                TextColumn::make('criados')->label('Criados')->numeric()->alignEnd()->color('success'),
                TextColumn::make('atualizados')->label('Atualizados')->numeric()->alignEnd(),
                TextColumn::make('invalidos')->label('Inválidos')->numeric()->alignEnd()->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('duplicados')->label('Duplicados')->numeric()->alignEnd()->toggleable(),
                TextColumn::make('optouts')->label('Opt-outs')->numeric()->alignEnd()->toggleable(),
                TextColumn::make('erros')
                    ->label('Erros')
                    ->state(fn (ContactImport $record): int => count((array) ($record->erros ?? [])))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('user.name')->label('Por')->placeholder('sistema')->toggleable(),
                TextColumn::make('created_at')->label('Enviada em')->dateTime('d/m/Y H:i', $fuso)->sortable(),
                TextColumn::make('concluido_em')->label('Concluída em')->dateTime('d/m/Y H:i', $fuso)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(ContactImport::statusLabels()),
                SelectFilter::make('tipo')->label('Tipo')->options(self::tipoLabels()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /** @return array<string, string> */
    public static function tipoLabels(): array
    {
        return [
            ContactImport::TIPO_CSV => 'CSV de contatos',
            ContactImport::TIPO_OPTOUT => 'Lista de opt-out',
            ContactImport::TIPO_KAPSO => 'Histórico da Kapso',
            ContactImport::TIPO_UNICOAPI => 'Sellers (unico-api)',
        ];
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            ContactImport::STATUS_PENDENTE => 'gray',
            ContactImport::STATUS_PROCESSANDO => 'info',
            ContactImport::STATUS_CONCLUIDA => 'success',
            ContactImport::STATUS_FALHOU => 'danger',
            default => 'gray',
        };
    }
}
