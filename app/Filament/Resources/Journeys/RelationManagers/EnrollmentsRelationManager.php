<?php

namespace App\Filament\Resources\Journeys\RelationManagers;

use App\Models\JourneyEnrollment;
use App\Support\Permissions;
use App\Support\Telefone;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Inscrições da régua: quem está em que passo, quando sai o próximo e por que saiu. */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = 'Inscrições';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
    }

    public function table(Table $table): Table
    {
        $pii = Permissions::allows(Permissions::VIEW_PII);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('contact'))
            ->columns([
                TextColumn::make('contact.telefone')
                    ->label('Telefone')
                    ->formatStateUsing(fn (?string $state): string => $pii ? Telefone::formatar($state) : Telefone::mascarar($state)),
                TextColumn::make('contact.nome')->label('Contato')->placeholder('—')->limit(30),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => JourneyEnrollment::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        JourneyEnrollment::STATUS_ATIVA => 'info',
                        JourneyEnrollment::STATUS_CONCLUIDA => 'success',
                        JourneyEnrollment::STATUS_FALHOU => 'danger',
                        JourneyEnrollment::STATUS_PAUSADA => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('passo_atual')->label('Passo'),
                TextColumn::make('proximo_em')->label('Próximo em')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->sortable(),
                TextColumn::make('motivo_saida')->label('Motivo')->placeholder('—')->wrap(),
                TextColumn::make('iniciada_em')->label('Inscrita em')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->toggleable(),
                TextColumn::make('encerrada_em')->label('Encerrada em')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->multiple()->options(JourneyEnrollment::statusLabels()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
