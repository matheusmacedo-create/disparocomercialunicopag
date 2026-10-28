<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;

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

    /**
     * Passo 1 do registro de um número DISCONNECTED (mcp/src/whatsapp.ts): a Meta manda um código de 6
     * dígitos por SMS ou ligação para o número.
     */
    public function solicitarCodigo(string $metodo = 'SMS', string $idioma = 'pt_BR'): bool
    {
        $metodo = strtoupper($metodo) === 'VOICE' ? 'VOICE' : 'SMS';

        $response = $this->consultar('solicitar código de registro', fn (): Response => $this->graph()->post('/'.$this->sender->phone_number_id.'/request_code', [
            'code_method' => $metodo,
            'language' => $idioma,
        ]));

        return (bool) $response->json('success', true);
    }

    /** Passo 2: confirma o código recebido. */
    public function verificarCodigo(string $codigo): bool
    {
        $response = $this->consultar('verificar código de registro', fn (): Response => $this->graph()->post('/'.$this->sender->phone_number_id.'/verify_code', [
            'code' => $codigo,
        ]));

        return (bool) $response->json('success', true);
    }

    /** Passo 3: registra o número na Cloud API. O PIN vira a verificação em duas etapas do número. */
    public function registrarNumero(string $pin): bool
    {
        $response = $this->consultar('registrar número', fn (): Response => $this->graph()->post('/'.$this->sender->phone_number_id.'/register', [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]));

        return (bool) $response->json('success', true);
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
