<?php

namespace App\Filament\Resources\ContactLists\Pages;

use App\Filament\Resources\ContactLists\ContactListResource;
use App\Models\ContactList;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContactList extends EditRecord
{
    protected static string $resource = ContactListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver_contatos')
                ->label('Ver contatos')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(fn (ContactList $record): string => ContactListResource::urlDosContatos($record)),
            DeleteAction::make(),
        ];
    }
}
