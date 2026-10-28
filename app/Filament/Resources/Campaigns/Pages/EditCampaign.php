<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Schemas\CampaignForm;
use App\Services\Campanhas\FluxoDeCampanha;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return CampaignForm::aoPreencher($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CampaignForm::antesDeGravar($data);
    }

    /** Edição do que define o envio invalida prévia e aprovação: o fluxo decide (volta a rascunho). */
    protected function afterSave(): void
    {
        app(FluxoDeCampanha::class)->aoEditar($this->getRecord()->refresh());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
