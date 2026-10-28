<?php

namespace App\Filament\Resources\Suppressions\Tables;

use App\Filament\Resources\Suppressions\Support\SuppressionActions;
use App\Filament\Support\Pii;
use App\Models\Suppression;
use App\Support\Permissions;
use App\Support\Telefone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SuppressionsTable
{
    public static function configure(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->columns([
                TextColumn::make('telefone')
                    ->label('Telefone')
                    ->state(fn (Suppression $record): string => $record->telefone === null ? 'Anonimizado' : Pii::telefone($record->telefone))
                    // Busca pelo hash: casa com o telefone canônico mesmo depois da anonimização.
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::buscar($query, $search)),
                TextColumn::make('escopo')
                    ->label('Escopo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === Suppression::ESCOPO_MARKETING ? 'Só marketing' : 'Tudo')
                    ->color(fn (?string $state): string => $state === Suppression::ESCOPO_MARKETING ? 'warning' : 'danger'),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => Suppression::motivoLabels()[$state] ?? (string) $state),
                TextColumn::make('origem')->label('Origem')->placeholder('—')->toggleable(),
                TextColumn::make('sender.nome')->label('Remetente')->placeholder('Todos')->toggleable(),
                TextColumn::make('detalhes')
                    ->label('Detalhes')
                    ->placeholder('—')
                    ->wrap()
                    ->limit(100)
                    ->tooltip(fn (Suppression $record): ?string => $record->detalhes)
                    // Pode ser o texto (normalizado) da resposta do cliente: só com "ver conversas".
                    ->visible(fn (): bool => Permissions::allows(Permissions::VIEW_CONVERSATIONS)),
                TextColumn::make('user.name')->label('Bloqueado por')->placeholder('sistema')->toggleable(),
                TextColumn::make('created_at')->label('Bloqueado em')->dateTime('d/m/Y H:i', $fuso)->sortable(),
                TextColumn::make('liberada_em')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Suppression $record): string => $record->vigente() ? 'Vigente' : 'Liberada')
                    ->color(fn (Suppression $record): string => $record->vigente() ? 'danger' : 'gray')
                    ->description(fn (Suppression $record): ?string => $record->vigente() ? null : collect([
                        $record->liberada_em?->timezone($fuso)->format('d/m/Y H:i'),
                        $record->liberadaPor?->name,
                        $record->liberacao_motivo,
                    ])->filter()->implode(' · '))
                    ->wrap(),
                TextColumn::make('wamid')->label('wamid')->placeholder('—')->limit(24)->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('vigente')
                    ->label('Situação')
                    ->trueLabel('Vigentes')
                    ->falseLabel('Liberadas')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('liberada_em'),
                        false: fn (Builder $query) => $query->whereNotNull('liberada_em'),
                    ),
                SelectFilter::make('escopo')->label('Escopo')->options([Suppression::ESCOPO_TUDO => 'Tudo', Suppression::ESCOPO_MARKETING => 'Só marketing']),
                SelectFilter::make('motivo')->label('Motivo')->options(Suppression::motivoLabels()),
                SelectFilter::make('sender_id')->label('Remetente')->relationship('sender', 'nome'),
                SelectFilter::make('origem')->label('Origem')->options([
                    'resposta' => 'Resposta do cliente',
                    'webhook' => 'Webhook',
                    'painel' => 'Painel',
                    'historico_kapso' => 'Histórico da Kapso',
                    'csv' => 'CSV',
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                SuppressionActions::liberar(),
            ]);
    }

    private static function buscar(Builder $query, string $search): Builder
    {
        $canonico = Telefone::canonico($search);

        return $query->where(function (Builder $q) use ($search, $canonico): void {
            if ($canonico !== null) {
                $q->where('telefone_hash', Telefone::hash($canonico));
            }

            $digitos = (string) preg_replace('/\D/', '', $search);

            if ($digitos !== '' && Permissions::allows(Permissions::VIEW_PII)) {
                $q->orWhere('telefone', 'like', '%'.$digitos.'%');
            }

            if ($canonico === null && ! Permissions::allows(Permissions::VIEW_PII)) {
                // Sem telefone reconhecível e sem PII, a busca não casa nada (não vaza por "like").
                $q->whereRaw('1 = 0');
            }
        });
    }
}
