<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Cloud API da Meta direto (graph.facebook.com) com token permanente de usuário do sistema, como a CLI
 * original em mcp/. Sem histórico nem preferências de marketing: só envio, templates e dados do número.
 */
class MetaCloudClient extends GraphClient
{
    protected function baseGraph(): string
    {
        return 'https://graph.facebook.com/'.config('disparo.provedores.meta.graph_version', 'v23.0');
    }

    protected function cabecalhos(): array
    {
        return ['Authorization' => 'Bearer '.$this->sender->credencial()];
    }

    protected function nomeDoProvedor(): string
    {
        return 'Meta';
    }

    /** Assinatura do webhook da Meta: X-Hub-Signature-256 = "sha256=" + HMAC do corpo com o App Secret. */
    public static function assinaturaValida(string $corpoCru, ?string $assinatura, ?string $appSecret): bool
    {
        if (blank($appSecret) || blank($assinatura)) {
            return false;
        }

        $esperada = 'sha256='.hash_hmac('sha256', $corpoCru, (string) $appSecret);

        return hash_equals($esperada, strtolower(trim((string) $assinatura)));
    }
}
