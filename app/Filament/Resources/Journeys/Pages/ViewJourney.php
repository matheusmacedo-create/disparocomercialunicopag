<?php

namespace App\Filament\Resources\Journeys\Pages;

use App\Filament\Resources\Journeys\JourneyResource;
use App\Filament\Resources\Journeys\Support\JourneyActions;
use App\Models\Journey;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewJourney extends ViewRecord
{
    protected static string $resource = JourneyResource::class;

    public function getTitle(): string
    {
        /** @var Journey $journey */
        $journey = $this->getRecord();

        return $journey->nome.' · '.(Journey::statusLabels()[$journey->status] ?? $journey->status);
    }

    protected function getHeaderActions(): array
    {
        return [
            JourneyActions::enviarParaAprovacao(),
            JourneyActions::aprovar(),
            JourneyActions::ativar(),
            JourneyActions::pausar(),
            JourneyActions::inscrever(),
            ActionGroup::make([
                EditAction::make()->label('Editar'),
                JourneyActions::devolver(),
            ])->label('Mais')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
