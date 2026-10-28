<?php

namespace App\Filament\Resources\ContactLists\Tables;

use App\Filament\Resources\ContactLists\ContactListResource;
use App\Models\ContactList;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactListsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('contacts'))
            ->columns([
                TextColumn::make('nome')
                    ->label('Base')
                    ->weight('medium')
                    ->description(fn (ContactList $record): ?string => $record->descricao ? mb_substr($record->descricao, 0, 120) : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('dono')
                    ->label('Dono')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ContactList::donoLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === ContactList::DONO_UNICOPAG ? 'primary' : 'warning'),
                IconColumn::make('exige_consentimento')->label('Exige consentimento')->boolean(),
                IconColumn::make('ativa')->label('Ativa')->boolean(),
                TextColumn::make('contacts_count')->label('Contatos')->badge()->color('gray')->sortable(),
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y', 'America/Sao_Paulo')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('dono')->label('Dono')->options(ContactList::donoLabels()),
                TernaryFilter::make('ativa')->label('Ativa'),
            ])
            ->defaultSort('nome')
            ->recordActions([
                Action::make('ver_contatos')
                    ->label('Ver contatos')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->url(fn (ContactList $record): string => ContactListResource::urlDosContatos($record)),
                EditAction::make(),
            ]);
    }
}
