<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Models\WhatsappMessage;
use App\Support\Mask;

/**
 * Do `entry[].changes[].value` do webhook da Meta Cloud API ao evento interno (KB 10): `statuses[]`
 * (id, status, timestamp, recipient_id, pricing.category, conversation.id, errors[]), `messages[]`
 * (from, id, timestamp, type, text/button/interactive, context.id), `contacts[]` (wa_id => profile.name)
 * e `user_preferences[]` (category marketing_messages, value stop|resume).
 */
final class NormalizadorMeta
{
    private const STATUS = [WhatsappMessage::STATUS_SENT, WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ, WhatsappMessage::STATUS_FAILED];

    private const CATEGORIAS = ['MARKETING', 'UTILITY', 'AUTHENTICATION', 'SERVICE'];

    /**
     * @param  array<string, mixed>  $value
     * @return list<EventoDeMensagem>
     */
    public static function eventos(array $value): array
    {
        $eventos = [];

        foreach (NormalizadorKapso::mapa($value['statuses'] ?? null) as $item) {
            if (is_array($item) && ($evento = self::status($item)) !== null) {
                $eventos[] = $evento;
            }
        }

        $perfis = [];

        foreach (NormalizadorKapso::mapa($value['contacts'] ?? null) as $contato) {
            if (is_array($contato) && ($waId = NormalizadorKapso::texto($contato['wa_id'] ?? null)) !== '') {
                $perfis[$waId] = NormalizadorKapso::texto(data_get($contato, 'profile.name')) ?: null;
            }
        }

        foreach (NormalizadorKapso::mapa($value['messages'] ?? null) as $mensagem) {
            if (is_array($mensagem) && ($evento = self::recebida($mensagem, $perfis)) !== null) {
                $eventos[] = $evento;
            }
        }

        foreach (NormalizadorKapso::mapa($value['user_preferences'] ?? null) as $preferencia) {
            if (is_array($preferencia) && ($evento = self::preferencia($preferencia)) !== null) {
                $eventos[] = $evento;
            }
        }

        return $eventos;
    }

    /** @param  array<string, mixed>  $item */
    private static function status(array $item): ?EventoDeMensagem
    {
        $wamid = NormalizadorKapso::texto($item['id'] ?? null);
        $status = strtolower(NormalizadorKapso::texto($item['status'] ?? null));

        if ($wamid === '' || ! in_array($status, self::STATUS, true)) {
            return null;
        }

        $erro = NormalizadorKapso::mapa(NormalizadorKapso::mapa($item['errors'] ?? null)[0] ?? null);
        $codigo = is_int($erro['code'] ?? null) || is_string($erro['code'] ?? null) ? (string) $erro['code'] : null;
        $textoDoErro = implode(' - ', array_filter([
            NormalizadorKapso::texto($erro['title'] ?? null),
            NormalizadorKapso::texto($erro['message'] ?? null) ?: NormalizadorKapso::texto(data_get($erro, 'error_data.details')),
        ]));
        $categoria = strtoupper(NormalizadorKapso::texto(data_get($item, 'pricing.category')));

        return new EventoDeMensagem(
            tipo: EventoDeMensagem::STATUS,
            wamid: $wamid,
            status: $status,
            timestamp: NormalizadorKapso::momento($item['timestamp'] ?? null),
            telefone: NormalizadorKapso::texto($item['recipient_id'] ?? null) ?: null,
            codigoErro: $codigo,
            erro: $textoDoErro !== '' ? Mask::scrub($textoDoErro) : null,
            conversationId: NormalizadorKapso::texto(data_get($item, 'conversation.id')) ?: null,
            categoria: in_array($categoria, self::CATEGORIAS, true) ? $categoria : null,
        );
    }

    /**
     * @param  array<string, mixed>  $mensagem
     * @param  array<string, ?string>  $perfis
     */
    private static function recebida(array $mensagem, array $perfis): ?EventoDeMensagem
    {
        $de = NormalizadorKapso::texto($mensagem['from'] ?? null);

        if ($de === '') {
            return null;
        }

        $tipo = NormalizadorKapso::texto($mensagem['type'] ?? null);
        $foiBotao = in_array($tipo, ['button', 'interactive'], true);
        $textos = array_values(array_unique(array_filter([
            NormalizadorKapso::texto(data_get($mensagem, 'button.text')),
            NormalizadorKapso::texto(data_get($mensagem, 'interactive.button_reply.title')),
            NormalizadorKapso::texto(data_get($mensagem, 'interactive.list_reply.title')),
            NormalizadorKapso::texto(data_get($mensagem, 'text.body')),
        ], fn (string $t): bool => $t !== '')));
        $contextWamid = NormalizadorKapso::texto(data_get($mensagem, 'context.id')) ?: null;

        return new EventoDeMensagem(
            tipo: EventoDeMensagem::RECEBIDA,
            wamid: NormalizadorKapso::texto($mensagem['id'] ?? null) ?: null,
            timestamp: NormalizadorKapso::momento($mensagem['timestamp'] ?? null),
            telefone: $de,
            tipoMensagem: $tipo !== '' ? $tipo : null,
            textos: $textos,
            contextWamid: $contextWamid,
            nomeDoPerfil: $perfis[$de] ?? null,
            foiBotao: $foiBotao,
            bruto: array_filter([
                'tipo' => $tipo !== '' ? $tipo : null,
                'context_wamid' => $contextWamid,
                'botao' => $foiBotao ? ($textos[0] ?? null) : null,
            ]),
        );
    }

    /** @param  array<string, mixed>  $item */
    private static function preferencia(array $item): ?EventoDeMensagem
    {
        $telefone = NormalizadorKapso::texto($item['wa_id'] ?? null);
        $valor = strtolower(NormalizadorKapso::texto($item['value'] ?? null));

        if ($telefone === '' || NormalizadorKapso::texto($item['category'] ?? null) !== 'marketing_messages' || ! in_array($valor, ['stop', 'resume'], true)) {
            return null;
        }

        return new EventoDeMensagem(
            tipo: EventoDeMensagem::PREFERENCIA,
            timestamp: NormalizadorKapso::momento($item['timestamp'] ?? null),
            telefone: $telefone,
            erro: ($detalhe = NormalizadorKapso::texto($item['detail'] ?? null)) !== '' ? Mask::scrub(mb_substr($detalhe, 0, 200)) : null,
            preferencia: $valor,
        );
    }
}
