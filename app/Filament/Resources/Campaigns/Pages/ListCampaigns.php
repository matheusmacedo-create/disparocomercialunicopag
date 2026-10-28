<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova campanha'),
        ];
    }

    public function getTabs(): array
    {
        $abertas = [Campaign::STATUS_RASCUNHO, Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA, Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_PAUSADA];

        return [
            'abertas' => Tab::make('Em aberto')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $abertas)),
            'aguardando' => Tab::make('Aguardando aprovação')
                ->badge(fn (): ?int => Campaign::query()->where('status', Campaign::STATUS_AGUARDANDO_APROVACAO)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', Campaign::STATUS_AGUARDANDO_APROVACAO)),
            'em_andamento' => Tab::make('Em andamento')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_PAUSADA])),
            'encerradas' => Tab::make('Encerradas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [Campaign::STATUS_CONCLUIDA, Campaign::STATUS_CANCELADA])),
            'todas' => Tab::make('Todas'),
        ];
    }
}
