<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ContactListContact;
use App\Models\Journey;
use App\Services\Reguas\ProcessadorDeReguas;

/** Contato entrou numa base: inscreve nas réguas ativas com gatilho "lista" apontando para ela. */
final class ContactListContactObserver
{
    public function created(ContactListContact $pivot): void
    {
        app(ProcessadorDeReguas::class)->inscreverPorGatilho(Journey::GATILHO_LISTA, (int) $pivot->contact_list_id, (int) $pivot->contact_id);
    }
}
