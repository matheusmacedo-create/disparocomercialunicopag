<?php

namespace App\Filament\Resources\Journeys\Pages;

use App\Filament\Resources\Journeys\JourneyResource;
use App\Filament\Resources\Journeys\Schemas\JourneyForm;
use App\Models\Journey;
use App\Services\Reguas\FluxoDeRegua;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditJourney extends EditRecord
{
    protected static string $resource = JourneyResource::class;

    /** @var array<int|string, mixed> */
    private array $passos = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Journey $journey */
        $journey = $this->getRecord();
        $data['passos'] = JourneyForm::passosParaFormulario($journey);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->passos = (array) ($data['passos'] ?? []);
        unset($data['passos']);

        return $data;
    }

    /** Regrava os passos e deixa o fluxo decidir: régua editada volta a rascunho e para. */
    protected function afterSave(): void
    {
        /** @var Journey $journey */
        $journey = $this->getRecord()->refresh();
        JourneyForm::gravarPassos($journey, $this->passos);
        app(FluxoDeRegua::class)->aoEditar($journey, auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
