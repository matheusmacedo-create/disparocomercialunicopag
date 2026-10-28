<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Filament\Resources\Suppressions\Support\SuppressionActions;
use App\Models\Suppression;
use App\Support\Permissions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Bloqueios do telefone: vigentes e já liberados (com quem liberou e por quê). */
class SuppressionsRelationManager extends RelationManager
{
    protected static string $relationship = 'suppressions';

    protected static ?string $title = 'Bloqueios';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public function table(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['sender:id,nome', 'liberadaPor:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Bloqueado em')->dateTime('d/m/Y H:i', $fuso),
                TextColumn::make('escopo')
                    ->label('Escopo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === Suppression::ESCOPO_MARKETING ? 'Só marketing' : 'Tudo')
                    ->color(fn (?string $state): string => $state === Suppression::ESCOPO_MARKETING ? 'warning' : 'danger'),
                TextColumn::make('motivo')->label('Motivo')->formatStateUsing(fn (?string $state): string => Suppression::motivoLabels()[$state] ?? (string) $state),
                TextColumn::make('origem')->label('Origem')->placeholder('—'),
                TextColumn::make('sender.nome')->label('Remetente')->placeholder('Todos'),
                TextColumn::make('detalhes')
                    ->label('Detalhes')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(120)
                    ->visible(fn (): bool => Permissions::allows(Permissions::VIEW_CONVERSATIONS)),
                TextColumn::make('liberada_em')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Suppression $record): string => $record->vigente() ? 'Vigente' : 'Liberada')
                    ->color(fn (Suppression $record): string => $record->vigente() ? 'danger' : 'gray')
                    ->description(fn (Suppression $record): ?string => $record->vigente() ? null : collect([
                        $record->liberada_em?->timezone($fuso)->format('d/m/Y H:i'),
                        $record->liberadaPor?->name,
                        $record->liberacao_motivo,
                    ])->filter()->implode(' · ')),
            ])
            ->recordActions([
                SuppressionActions::liberar(),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25]);
    }
}
