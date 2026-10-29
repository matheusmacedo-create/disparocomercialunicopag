<?php

namespace App\Filament\Resources\Journeys\Pages;

use App\Filament\Resources\Journeys\JourneyResource;
use App\Filament\Resources\Journeys\Schemas\JourneyForm;
use Filament\Resources\Pages\CreateRecord;

class CreateJourney extends CreateRecord
{
    protected static string $resource = JourneyResource::class;

    /** @var array<int|string, mixed> */
    private array $passos = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->passos = (array) ($data['passos'] ?? []);
        unset($data['passos']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $journey = $this->getRecord();
        $journey->forceFill(['criado_por' => auth()->id()])->save();
        JourneyForm::gravarPassos($journey, $this->passos);
        $journey->registrarEvento('criada', ['passos' => count($this->passos)], auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
