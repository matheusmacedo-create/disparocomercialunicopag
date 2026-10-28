<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Models\JourneyEnrollment;
use App\Support\Permissions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Inscrições do contato nas réguas: em que passo está, por que saiu. */
class JourneyEnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'journeyEnrollments';

    protected static ?string $title = 'Réguas';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public function table(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('journey:id,nome'))
            ->columns([
                TextColumn::make('journey.nome')->label('Régua')->weight('medium'),
                TextColumn::make('passo_atual')->label('Passo'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => JourneyEnrollment::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        JourneyEnrollment::STATUS_ATIVA => 'primary',
                        JourneyEnrollment::STATUS_CONCLUIDA => 'success',
                        JourneyEnrollment::STATUS_FALHOU => 'danger',
                        JourneyEnrollment::STATUS_PAUSADA => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('proximo_em')->label('Próximo passo')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                TextColumn::make('motivo_saida')->label('Saída / origem')->placeholder('—')->wrap(),
                TextColumn::make('iniciada_em')->label('Início')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                TextColumn::make('encerrada_em')->label('Fim')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25]);
    }
}
