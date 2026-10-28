<?php

namespace App\Filament\Resources\Senders\Pages;

use App\Filament\Resources\Senders\SenderResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSender extends EditRecord
{
    protected static string $resource = SenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** Chave em branco na edição = manter a atual (o campo nunca mostra a chave). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
