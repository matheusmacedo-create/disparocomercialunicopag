<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\Sender;

/** O argumento `{sender}` dos comandos aceita id, phone_number_id ou o nome exato do remetente. */
trait AchaRemetente
{
    protected function acharRemetente(string $referencia): ?Sender
    {
        $referencia = trim($referencia);

        if ($referencia === '') {
            return null;
        }

        if (ctype_digit($referencia)) {
            $porId = Sender::query()->find((int) $referencia);

            if ($porId !== null) {
                return $porId;
            }
        }

        return Sender::query()->where('phone_number_id', $referencia)->first()
            ?? Sender::query()->where('nome', $referencia)->first();
    }
}
