<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\WhatsappMessage;
use App\Support\Mask;

/**
 * Do payload v2 da Kapso ao evento interno (KB 10). Formato conferido no KapsoWebhookController do KYC:
 * `message` com `id`, `to`/`from`, `type`, `timestamp`, `template.name`, `context.id`, `text.body`,
 * `button.text`, `interactive.button_reply.title` e a extensão `kapso` (`direction`, `status`, `statuses[]`,
 * `content`); `conversation` com `id`, `phone_number` e `contact_name`; `phone_number_id` no topo.
 *
 * Só o essencial sai daqui: nada de texto do cliente em `bruto` (ele vai para `conteudo`, atrás de
 * `view conversations`), nada de nome de terceiro, nada de mídia.
 */
final class NormalizadorKapso
{
    public const EVENTO_RECEBIDA = 'whatsapp.message.received';

    public const EVENTO_PREFERENCIA = 'whatsapp.contact.marketing_preference_changed';

    /** Evento de status da Kapso => status interno da mensagem. */
    public const EVENTOS_DE_STATUS = [
        'whatsapp.message.sent' => WhatsappMessage::STATUS_SENT,
        'whatsapp.message.delivered' => WhatsappMessage::STATUS_DELIVERED,
        'whatsapp.message.read' => WhatsappMessage::STATUS_READ,
        'whatsapp.message.failed' => WhatsappMessage::STATUS_FAILED,
    ];

    private const CATEGORIAS = ['MARKETING', 'UTILITY', 'AUTHENTICATION', 'SERVICE'];

    public static function conhecido(?string $evento): bool
    {
        return $evento !== null && ($evento === self::EVENTO_RECEBIDA || $evento === self::EVENTO_PREFERENCIA || isset(self::EVENTOS_DE_STATUS[$evento]));
    }

    /**
     * O phone_number_id que o payload diz ser dele (null = não veio). Serve para cruzar com o remetente
     * da URL: um corpo assinado para o remetente A reposto na URL de B não pode passar.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function phoneNumberId(array $payload): ?string
    {
        foreach (['phone_number_id', 'metadata.phone_number_id', 'phone_number.id', 'message.phone_number_id', 'contact.phone_number_id', 'whatsapp_phone_number_id'] as $caminho) {
            $valor = self::texto(data_get($payload, $caminho));

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Null = evento que a plataforma não trata, ou payload sem o essencial (wamid, telefone).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function normalizar(?string $evento, array $payload): ?EventoDeMensagem
    {
        if ($evento !== null && isset(self::EVENTOS_DE_STATUS[$evento])) {
            return self::status(self::EVENTOS_DE_STATUS[$evento], $payload);
        }

        return match ($evento) {
            self::EVENTO_RECEBIDA => self::recebida($payload),
            self::EVENTO_PREFERENCIA => self::preferencia($payload),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $payload */
    private static function status(string $status, array $payload): ?EventoDeMensagem
    {
        $mensagem = self::mapa($payload['message'] ?? null);
        $wamid = self::texto($mensagem['id'] ?? null);

        if ($wamid === '') {
            return null;
        }

        $item = self::statusDaMeta($mensagem, $wamid, $status);
        $erro = self::mapa(self::mapa($item['errors'] ?? null)[0] ?? null);
        $codigo = is_int($erro['code'] ?? null) || is_string($erro['code'] ?? null) ? (string) $erro['code'] : null;
        $textoDoErro = implode(' - ', array_filter([
            self::texto($erro['title'] ?? null),
            self::texto($erro['message'] ?? null) ?: self::texto(data_get($erro, 'error_data.details')),
        ]));

        $telefone = self::texto($mensagem['to'] ?? null)
            ?: self::texto($item['recipient_id'] ?? null)
            ?: self::texto(data_get($payload, 'conversation.phone_number'));

        $categoria = strtoupper(self::texto(data_get($item, 'pricing.category')) ?: self::texto(data_get($mensagem, 'kapso.pricing.category')));
        $templateNome = self::texto(data_get($mensagem, 'template.name')) ?: self::texto(data_get($mensagem, 'kapso.template_name'));
        $tipo = self::texto($mensagem['type'] ?? null) ?: ($templateNome !== '' ? WhatsappMessage::TIPO_TEMPLATE : '');

        return new EventoDeMensagem(
            tipo: EventoDeMensagem::STATUS,
            wamid: $wamid,
            status: $status,
            timestamp: self::momento($item['timestamp'] ?? null) ?? self::momento($mensagem['timestamp'] ?? null),
            telefone: $telefone !== '' ? $telefone : null,
            tipoMensagem: $tipo !== '' ? $tipo : null,
            codigoErro: $codigo,
            // A Meta ecoa valores de parâmetro recusados nos detalhes do erro: nada de PII na coluna de motivo.
            erro: $textoDoErro !== '' ? Mask::scrub($textoDoErro) : null,
            conversationId: self::texto(data_get($payload, 'conversation.id')) ?: null,
            templateNome: $templateNome !== '' ? $templateNome : null,
            categoria: in_array($categoria, self::CATEGORIAS, true) ? $categoria : null,
        );
    }

    /**
     * Mensagem do cliente. Só `kapso.direction = inbound` conta: o tipo do evento vem num header fora da
     * assinatura, e um status de envio reapresentado como "received" não pode virar resposta do cliente.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function recebida(array $payload): ?EventoDeMensagem
    {
        $mensagem = self::mapa($payload['message'] ?? null);

        if (self::texto(data_get($mensagem, 'kapso.direction')) !== 'inbound') {
            return null;
        }

        $de = self::texto($mensagem['from'] ?? null);

        if ($de === '') {
            return null;
        }

        $tipo = self::texto($mensagem['type'] ?? null);
        $foiBotao = in_array($tipo, ['button', 'interactive'], true);

        // O rótulo do botão vem primeiro: é ele que o classificador e o opt-in leem.
        $textos = array_values(array_unique(array_filter([
            self::texto(data_get($mensagem, 'button.text')),
            self::texto(data_get($mensagem, 'interactive.button_reply.title')),
            self::texto(data_get($mensagem, 'interactive.list_reply.title')),
            self::texto(data_get($mensagem, 'text.body')),
            self::texto(data_get($mensagem, 'kapso.content')),
        ], fn (string $t): bool => $t !== '')));

        $contextWamid = self::texto(data_get($mensagem, 'context.id')) ?: null;

        return new EventoDeMensagem(
            tipo: EventoDeMensagem::RECEBIDA,
            wamid: self::texto($mensagem['id'] ?? null) ?: null,
            timestamp: self::momento($mensagem['timestamp'] ?? null),
            telefone: $de,
            tipoMensagem: $tipo !== '' ? $tipo : null,
            textos: $textos,
            contextWamid: $contextWamid,
            conversationId: self::texto(data_get($payload, 'conversation.id')) ?: self::texto(data_get($mensagem, 'kapso.conversation_id')) ?: null,
            nomeDoPerfil: self::texto(data_get($payload, 'conversation.contact_name')) ?: self::texto(data_get($payload, 'contact.profile_name')) ?: null,
            foiBotao: $foiBotao,
            // Whitelist da trilha: tipo, a mensagem citada e o rótulo do botão. O texto fica em `conteudo`.
            bruto: array_filter([
                'tipo' => $tipo !== '' ? $tipo : null,
                'context_wamid' => $contextWamid,
                'botao' => $foiBotao ? ($textos[0] ?? null) : null,
            ]),
        );
    }

    /**
     * Preferência de marketing da Meta (`whatsapp.contact.marketing_preference_changed`): a Kapso emite a
     * cada transição real, com `sequence` e `source`. Aceita `status`/`value` stop|stopped|resume|resumed
     * ou `marketing_allowed` booleano.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function preferencia(array $payload): ?EventoDeMensagem
    {
        $telefone = '';

        foreach (['phone_number', 'contact.phone_number', 'contact.wa_id', 'wa_id', 'from', 'customer.phone_number', 'contact.phone'] as $caminho) {
            $telefone = self::texto(data_get($payload, $caminho));

            if ($telefone !== '') {
                break;
            }
        }

        if ($telefone === '') {
            return null;
        }

        $estado = '';

        foreach (['status', 'preference', 'marketing_preference', 'value', 'contact.marketing_preference', 'marketing_preference.status'] as $caminho) {
            $estado = strtolower(self::texto(data_get($payload, $caminho)));

            if ($estado !== '') {
                break;
            }
        }

        $preferencia = match (true) {
            in_array($estado, ['stop', 'stopped', 'opted_out', 'opt_out'], true) => 'stop',
            in_array($estado, ['resume', 'resumed', 'opted_in', 'opt_in'], true) => 'resume',
            is_bool($payload['marketing_allowed'] ?? null) => $payload['marketing_allowed'] ? 'resume' : 'stop',
            default => null,
        };

        if ($preferencia === null) {
            return null;
        }

        $detalhes = trim(implode(': ', array_filter([self::texto($payload['source'] ?? null), self::texto($payload['detail'] ?? null)])));

        return new EventoDeMensagem(
            tipo: EventoDeMensagem::PREFERENCIA,
            timestamp: self::momento($payload['timestamp'] ?? $payload['changed_at'] ?? $payload['updated_at'] ?? null),
            telefone: $telefone,
            erro: $detalhes !== '' ? Mask::scrub(mb_substr($detalhes, 0, 200)) : null,
            preferencia: $preferencia,
            bruto: array_filter(['sequence' => $payload['sequence'] ?? null, 'source' => self::texto($payload['source'] ?? null) ?: null]),
        );
    }

    /**
     * Item de message.kapso.statuses deste wamid e deste status (o mais recente), ou [].
     *
     * @param  array<string, mixed>  $mensagem
     * @return array<string, mixed>
     */
    private static function statusDaMeta(array $mensagem, string $wamid, string $status): array
    {
        $achados = array_filter(
            self::mapa(self::mapa($mensagem['kapso'] ?? null)['statuses'] ?? null),
            fn (mixed $item): bool => is_array($item)
                && ($item['status'] ?? null) === $status
                && in_array($item['id'] ?? $wamid, [$wamid, ''], true),
        );

        return $achados === [] ? [] : (array) end($achados);
    }

    /** Timestamp unix (segundos) a partir de número ou de data ISO; null quando ilegível. */
    public static function momento(mixed $valor): ?int
    {
        if (is_numeric($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        if (is_string($valor) && $valor !== '' && ($ts = strtotime($valor)) !== false && $ts > 0) {
            return $ts;
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    public static function mapa(mixed $valor): array
    {
        return is_array($valor) ? $valor : [];
    }

    public static function texto(mixed $valor): string
    {
        return is_string($valor) || is_int($valor) ? trim((string) $valor) : '';
    }
}
