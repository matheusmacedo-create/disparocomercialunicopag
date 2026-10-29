<?php

namespace App\Filament\Resources\Senders\Tables;

use App\Models\Sender;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SendersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')->label('Remetente')->description(fn (Sender $record): ?string => $record->display_phone_number)->searchable()->sortable(),
                TextColumn::make('provider')->label('Provedor')->badge()->color('gray')->formatStateUsing(fn (?string $state): string => $state === Sender::PROVIDER_META ? 'Meta' : 'Kapso'),
                TextColumn::make('finalidade')->label('Finalidade')->formatStateUsing(fn (?string $state): string => Sender::finalidades()[$state] ?? (string) $state)->toggleable(),
                TextColumn::make('qualidade')
                    ->label('Qualidade')
                    ->badge()
                    ->color(fn (?string $state): string => self::corDaQualidade($state))
                    ->description(fn (Sender $record): ?string => $record->limite_tier),
                TextColumn::make('categorias')->label('Categorias')->state(fn (Sender $record): string => implode(' / ', $record->categoriasPermitidas()))->toggleable(),
                TextColumn::make('teto_efetivo')
                    ->label('Teto efetivo')
                    ->state(fn (Sender $record): string => $record->tetoEfetivo().' / '.$record->teto_diario)
                    ->helperText('efetivo / configurado'),
                TextColumn::make('enviados_hoje')
                    ->label('Enviados 24 h')
                    ->state(fn (Sender $record): int => $record->templatesNasUltimas24h())
                    ->badge()
                    ->color(fn (int $state, Sender $record): string => $state >= $record->tetoEfetivo() && $state > 0 ? 'danger' : 'gray'),
                IconColumn::make('pausado')
                    ->label('Pausado')
                    ->boolean()
                    ->state(fn (Sender $record): bool => $record->pausado())
                    ->trueIcon('heroicon-o-pause-circle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-o-play-circle')
                    ->falseColor('success'),
                IconColumn::make('webhook_configurado')
                    ->label('Webhook')
                    ->boolean()
                    ->state(fn (Sender $record): bool => filled($record->webhook_secret)),
                IconColumn::make('ativo')->label('Ativo')->boolean(),
                TextColumn::make('sincronizado_em')->label('Lido')->since()->placeholder('Nunca')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('provider')->label('Provedor')->options(Sender::provedores()),
                SelectFilter::make('qualidade')->label('Qualidade')->options(['GREEN' => 'GREEN', 'YELLOW' => 'YELLOW', 'RED' => 'RED', 'UNKNOWN' => 'UNKNOWN']),
            ])
            ->defaultSort('nome')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function corDaQualidade(?string $qualidade): string
    {
        return match ($qualidade) {
            Sender::QUALIDADE_GREEN => 'success',
            Sender::QUALIDADE_YELLOW => 'warning',
            Sender::QUALIDADE_RED => 'danger',
            default => 'gray',
        };
    }
}
