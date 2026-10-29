<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\Pii;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** Sem `view pii`, nada sensível entra no estado do formulário (ele sai inteiro no snapshot do Livewire). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! Pii::podeVer()) {
            unset($data['email'], $data['documento'], $data['atributos'], $data['observacoes']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
