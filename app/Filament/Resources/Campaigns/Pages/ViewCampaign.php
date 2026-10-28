<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Support\CampaignActions;
use App\Models\Campaign;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * A ficha da campanha: cada ação de cabeçalho chama o FluxoDeCampanha com o usuário logado; a visibilidade
 * segue o status e a permissão, e o fluxo confere tudo de novo no servidor.
 */
class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string
    {
        /** @var Campaign $campanha */
        $campanha = $this->getRecord();

        return $campanha->nome.' · '.(Campaign::statusLabels()[$campanha->status] ?? $campanha->status);
    }

    protected function getHeaderActions(): array
    {
        return [
            CampaignActions::gerarPrevia(),
            CampaignActions::enviarParaAprovacao(),
            CampaignActions::aprovar(),
            CampaignActions::iniciar(),
            CampaignActions::retomar(),
            CampaignActions::pausar(),
            ActionGroup::make([
                EditAction::make()->label('Editar'),
                CampaignActions::enviarTeste(),
                CampaignActions::devolver(),
                CampaignActions::duplicar(),
                CampaignActions::reenviarFalhas(),
                CampaignActions::cancelar(),
            ])->label('Mais')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
