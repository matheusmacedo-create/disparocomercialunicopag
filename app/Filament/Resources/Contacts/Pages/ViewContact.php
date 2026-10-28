<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Support\ContactActions;
use App\Filament\Support\Pii;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * A ficha do contato. O título nunca mostra o telefone completo a quem não tem `view pii`.
 */
class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    public function getTitle(): string
    {
        $contato = $this->getRecord();

        if ($contato->anonimizado()) {
            return 'Contato anonimizado';
        }

        return trim((string) ($contato->nome ?: $contato->empresa)) ?: Pii::telefone($contato->telefone);
    }

    protected function getHeaderActions(): array
    {
        return [
            ContactActions::responder(),
            ContactActions::registrarConsentimento(),
            ContactActions::bloquear(),
            ActionGroup::make([
                EditAction::make(),
                ContactActions::exportarDados(),
                ContactActions::anonimizar(),
            ])->label('Mais')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
