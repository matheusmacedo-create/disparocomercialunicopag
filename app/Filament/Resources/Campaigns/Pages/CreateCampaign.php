<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Schemas\CampaignForm;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CampaignForm::antesDeGravar($data);
    }

    /** `criado_por` fica fora do $fillable: quem criou é quem está logado, nunca um valor do formulário. */
    protected function afterCreate(): void
    {
        $campanha = $this->getRecord();
        $campanha->forceFill(['criado_por' => auth()->id()])->save();
        $campanha->registrarAcao('criada', ['nome' => $campanha->nome]);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
