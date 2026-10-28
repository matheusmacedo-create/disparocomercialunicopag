<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Models\CampaignRecipient;
use App\Support\Permissions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** As campanhas em que o contato entrou e o que aconteceu em cada uma. */
class CampaignRecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'campaignRecipients';

    protected static ?string $title = 'Campanhas';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public function table(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('campaign:id,nome,template_nome,status'))
            ->columns([
                TextColumn::make('campaign.nome')->label('Campanha')->weight('medium')->description(fn (CampaignRecipient $record): ?string => $record->campaign?->template_nome),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CampaignRecipient::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => CampaignRecipient::statusColor($state)),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->formatStateUsing(fn (?string $state): string => CampaignRecipient::motivoLabels()[$state] ?? (string) $state)
                    ->placeholder('—'),
                TextColumn::make('enviado_em')->label('Enviado')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                TextColumn::make('entregue_em')->label('Entregue')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                TextColumn::make('lido_em')->label('Lido')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                TextColumn::make('respondido_em')->label('Respondeu')->dateTime('d/m/Y H:i', $fuso)->placeholder('—'),
                TextColumn::make('codigo_erro')->label('Erro')->placeholder('—')->color('danger')->tooltip(fn (CampaignRecipient $record): ?string => $record->erro),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25]);
    }
}
