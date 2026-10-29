<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Filament\Resources\Tags\TagResource;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('contacts'))
            ->columns([
                ColorColumn::make('cor')->label('Cor')->placeholder('—'),
                TextColumn::make('nome')->label('Tag')->weight('medium')->searchable()->sortable(),
                TextColumn::make('contacts_count')->label('Contatos')->badge()->color('gray')->sortable(),
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y', 'America/Sao_Paulo')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nome')
            ->recordActions([
                Action::make('ver_contatos')
                    ->label('Ver contatos')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->url(fn (Tag $record): string => TagResource::urlDosContatos($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
