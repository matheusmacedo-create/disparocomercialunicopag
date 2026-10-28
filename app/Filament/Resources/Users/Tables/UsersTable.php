<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Pii;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('roles.name')->label('Papel')->badge()->placeholder('Sem papel (sem acesso)'),
                TextColumn::make('telefone_teste')->label('Telefone de teste')->state(fn (User $record): string => Pii::telefone($record->telefone_teste))->placeholder('—'),
                IconColumn::make('ativo')->label('Ativo')->boolean(),
                TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y', 'America/Sao_Paulo')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('ativo')->label('Ativo'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                UserResource::desativarAction(),
                UserResource::reativarAction(),
            ]);
    }
}
