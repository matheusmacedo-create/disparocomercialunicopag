<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Senders\Tables\SendersTable;
use App\Models\Sender;
use App\Support\Permissions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Qualidade e teto de cada número: o painel de instrumentos do incidente de 26/10. */
class RemetentesWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Remetentes';

    public static function canView(): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Sender::query()->where('ativo', true)->orderBy('nome'))
            ->columns([
                TextColumn::make('nome')->label('Remetente')->description(fn (Sender $record): ?string => $record->display_phone_number),
                TextColumn::make('qualidade')->label('Qualidade')->badge()->color(fn (?string $state): string => SendersTable::corDaQualidade($state)),
                TextColumn::make('teto_efetivo')->label('Teto efetivo')->state(fn (Sender $record): int => $record->tetoEfetivo()),
                TextColumn::make('usado_24h')
                    ->label('Usado 24 h')
                    ->state(fn (Sender $record): int => $record->templatesNasUltimas24h())
                    ->badge()
                    ->color(fn (int $state, Sender $record): string => $state > 0 && $state >= $record->tetoEfetivo() ? 'danger' : 'gray'),
                TextColumn::make('situacao')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Sender $record): string => match (true) {
                        $record->bloqueadoPorQualidade() => 'Bloqueado por qualidade',
                        $record->pausado_em !== null => 'Pausado',
                        default => 'Enviando',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Enviando' => 'success',
                        'Pausado' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('sincronizado_em')->label('Última leitura')->since()->placeholder('Nunca'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nenhum remetente ativo');
    }
}
