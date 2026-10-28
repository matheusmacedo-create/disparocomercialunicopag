<?php

namespace App\Filament\Resources\ContactLists\Pages;

use App\Filament\Resources\ContactLists\ContactListResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContactList extends CreateRecord
{
    protected static string $resource = ContactListResource::class;
}
