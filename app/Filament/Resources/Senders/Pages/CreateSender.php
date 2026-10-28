<?php

namespace App\Filament\Resources\Senders\Pages;

use App\Filament\Resources\Senders\SenderResource;
use App\Models\Sender;
use App\Models\SenderEvent;
use Filament\Resources\Pages\CreateRecord;

class CreateSender extends CreateRecord
{
    protected static string $resource = SenderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Sender $sender */
        $sender = $this->getRecord();
        SenderEvent::registrar($sender, 'criado', ['provider' => $sender->provider, 'finalidade' => $sender->finalidade, 'teto_diario' => $sender->teto_diario], auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
