<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Sender;
use InvalidArgumentException;

/**
 * O cliente certo para cada remetente. Os testes trocam a fábrica por uma que devolve um cliente falso
 * (`app()->instance(WhatsAppClientFactory::class, ...)`), então ninguém constrói cliente à mão.
 */
class WhatsAppClientFactory
{
    public function para(Sender $sender): WhatsAppClient
    {
        return match ($sender->provider) {
            Sender::PROVIDER_KAPSO => new KapsoClient($sender),
            Sender::PROVIDER_META => new MetaCloudClient($sender),
            default => throw new InvalidArgumentException(sprintf('Provedor desconhecido: %s', $sender->provider)),
        };
    }

    /** Só para o que é da Kapso (histórico, webhooks, preferências). */
    public function kapso(Sender $sender): KapsoClient
    {
        if ($sender->provider !== Sender::PROVIDER_KAPSO) {
            throw new WhatsAppException(sprintf('O remetente "%s" não é da Kapso: histórico, webhook e preferências de marketing só existem lá.', $sender->nome), 0, 'nao_configurado');
        }

        $cliente = $this->para($sender);

        assert($cliente instanceof KapsoClient);

        return $cliente;
    }
}
