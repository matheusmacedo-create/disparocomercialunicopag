<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Filament\Resources\Suppressions\SuppressionResource;
use App\Models\Suppression;
use App\Support\Telefone;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * O bloqueio manual passa por {@see Suppression::suprimir()} (chave única, trilha, opt-out no contato),
 * nunca por um create() cru.
 */
class CreateSuppression extends CreateRecord
{
    protected static string $resource = SuppressionResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $telefone = Telefone::normalizar((string) $data['telefone']);
        $escopo = (string) ($data['escopo'] ?? Suppression::ESCOPO_TUDO);
        $senderId = $escopo === Suppression::ESCOPO_MARKETING && filled($data['sender_id'] ?? null) ? (int) $data['sender_id'] : null;

        $criou = Suppression::suprimir(
            (string) $telefone,
            (string) ($data['motivo'] ?? Suppression::MOTIVO_MANUAL),
            'painel',
            $senderId,
            $escopo,
            null,
            filled($data['detalhes'] ?? null) ? (string) $data['detalhes'] : null,
            auth()->id(),
        );

        if (! $criou) {
            Notification::make()->warning()->title('Telefone já estava bloqueado')->body('O bloqueio anterior continua valendo; nada foi alterado.')->send();
        }

        return Suppression::query()->where('chave', Suppression::chaveDe((string) $telefone, $senderId))->firstOrFail();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
