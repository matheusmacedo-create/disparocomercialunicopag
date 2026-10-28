<?php

namespace App\Filament\Resources\Segments\Tables;

use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Segment;
use App\Services\Segmentos\ConsultaDeSegmento;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class SegmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nome')
                    ->label('Segmento')
                    ->weight('medium')
                    ->description(fn (Segment $record): ?string => $record->descricao ? mb_substr($record->descricao, 0, 120) : null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('regras')
                    ->label('Regras')
                    ->state(fn (Segment $record): string => implode(', ', array_keys(array_filter((array) $record->regras, fn ($v): bool => $v !== null && $v !== '' && $v !== []))) ?: 'todos os ativos')
                    ->wrap()
                    ->color('gray'),
                TextColumn::make('contatos')
                    ->label('Contatos agora')
                    ->badge()
                    ->color('gray')
                    ->state(function (Segment $record): string {
                        try {
                            return number_format(ConsultaDeSegmento::doSegmento($record)->count(), 0, ',', '.');
                        } catch (Throwable) {
                            return '?';
                        }
                    }),
                TextColumn::make('updated_at')->label('Atualizado em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
            ])
            ->defaultSort('nome')
            ->recordActions([
                Action::make('ver_contatos')
                    ->label('Ver contatos')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->url(fn (Segment $record): string => SegmentResource::urlDosContatos($record)),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
