<?php

namespace App\Filament\Resources\Journeys\Tables;

use App\Filament\Resources\Journeys\Support\JourneyActions;
use App\Models\Campaign;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\Sender;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JourneysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sender')->withCount(['steps', 'enrollments as inscricoes_ativas_count' => fn ($q) => $q->where('status', JourneyEnrollment::STATUS_ATIVA)]))
            ->columns([
                TextColumn::make('nome')->label('Régua')->searchable()->sortable()->wrap(),
                TextColumn::make('sender.nome')->label('Remetente')->sortable(),
                TextColumn::make('objetivo')->label('Objetivo')->badge()->color('gray')->formatStateUsing(fn (?string $state): string => Campaign::objetivoLabels()[$state] ?? (string) $state),
                TextColumn::make('gatilho')->label('Gatilho')->formatStateUsing(fn (?string $state): string => Journey::gatilhoLabels()[$state] ?? (string) $state),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Journey::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => JourneyActions::statusColor($state))
                    ->sortable(),
                TextColumn::make('steps_count')->label('Passos')->badge()->color('gray'),
                TextColumn::make('inscricoes_ativas_count')->label('Inscritos ativos')->badge()->color(fn (int $state): string => $state > 0 ? 'info' : 'gray'),
                TextColumn::make('max_por_dia')->label('Máx./dia'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->multiple()->options(Journey::statusLabels()),
                SelectFilter::make('sender_id')->label('Remetente')->options(fn (): array => Sender::query()->orderBy('nome')->pluck('nome', 'id')->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
