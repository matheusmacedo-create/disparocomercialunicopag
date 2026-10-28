<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use App\Support\Permissions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** A fila de hoje: campanhas aprovadas, em andamento ou pausadas, na ordem de prioridade. */
class CampanhasEmAndamentoWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Campanhas na fila';

    public static function canView(): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Campaign::query()
                ->with('sender')
                ->whereIn('status', [Campaign::STATUS_APROVADA, Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_PAUSADA])
                ->orderBy('prioridade')
                ->orderBy('agendada_para'))
            ->columns([
                TextColumn::make('nome')->label('Campanha')->url(fn (Campaign $record): string => CampaignResource::getUrl('view', ['record' => $record]))->wrap(),
                TextColumn::make('sender.nome')->label('Remetente'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Campaign::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => Campaign::statusColor($state)),
                TextColumn::make('progresso')
                    ->label('Enviados / total')
                    ->state(fn (Campaign $record): string => $record->enviados.' / '.$record->total_destinatarios)
                    ->description(fn (Campaign $record): ?string => $record->pendentes() > 0 ? $record->pendentes().' pendente(s)' : null),
                TextColumn::make('prioridade')->label('Prioridade'),
                TextColumn::make('agendada_para')->label('Agendada')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—'),
                TextColumn::make('pausada_motivo')->label('Motivo da pausa')->limit(50)->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nenhuma campanha na fila');
    }
}
