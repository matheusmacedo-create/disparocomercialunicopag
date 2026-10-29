<?php

namespace App\Filament\Resources\Senders\Pages;

use App\Filament\Resources\Senders\SenderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSenders extends ListRecords
{
    protected static string $resource = SenderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo remetente'),
        ];
    }
}
