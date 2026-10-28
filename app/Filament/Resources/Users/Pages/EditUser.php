<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::desativarAction(),
            UserResource::reativarAction(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Mesmo com o campo desabilitado na tela, o servidor não deixa ninguém se desativar.
        if ($this->getRecord()->is(auth()->user())) {
            unset($data['ativo']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
