<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ContactTag;
use App\Models\Journey;
use App\Services\Reguas\ProcessadorDeReguas;

/** Contato recebeu uma tag: inscreve nas réguas ativas com gatilho "tag" apontando para ela. */
final class ContactTagObserver
{
    public function created(ContactTag $pivot): void
    {
        app(ProcessadorDeReguas::class)->inscreverPorGatilho(Journey::GATILHO_TAG, (int) $pivot->tag_id, (int) $pivot->contact_id);
    }
}
