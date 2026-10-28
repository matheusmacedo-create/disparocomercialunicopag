<?php

namespace App\Filament\Resources\Campaigns\Tables;

use App\Models\Campaign;
use App\Models\Sender;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sender', 'creator']))
            ->columns([
                TextColumn::make('nome')
                    ->label('Campanha')
                    ->description(fn (Campaign $record): string => $record->template_nome.' · '.($record->template_categoria ?: 'categoria na aprovação'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('sender.nome')->label('Remetente')->sortable(),
                TextColumn::make('objetivo')
                    ->label('Objetivo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => Campaign::objetivoLabels()[$state] ?? (string) $state),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Campaign::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => Campaign::statusColor($state))
                    ->sortable(),
                TextColumn::make('totais')
                    ->label('Total / enviados / respondidos')
                    ->state(fn (Campaign $record): string => sprintf('%d / %d / %d', $record->total_destinatarios, $record->enviados, $record->respondidos))
                    ->description(fn (Campaign $record): ?string => $record->falhas > 0 ? $record->falhas.' falha(s)' : null),
                TextColumn::make('agendada_para')
                    ->label('Agendada')
                    ->dateTime('d/m/Y H:i', 'America/Sao_Paulo')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('creator.name')->label('Criada por')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->multiple()->options(Campaign::statusLabels()),
                SelectFilter::make('sender_id')->label('Remetente')->options(fn (): array => Sender::query()->orderBy('nome')->pluck('nome', 'id')->all()),
                SelectFilter::make('objetivo')->label('Objetivo')->options(Campaign::objetivoLabels()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
