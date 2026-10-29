<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Sender;

/**
 * O que a plataforma pede a um número de WhatsApp, seja ele da Kapso ou direto na Cloud API da Meta.
 * Tudo lança {@see WhatsAppException} com a recusa traduzida; "sem resposta" também vira exceção
 * (`timeout` antes do envio, `timeout_envio` depois - a mensagem pode ter saído).
 */
interface WhatsAppClient
{
    public function sender(): Sender;

    public function configurado(): bool;

    /**
     * Dados do número, normalizados: phone_number_id, display_phone_number, verified_name,
     * quality_rating (GREEN|YELLOW|RED|UNKNOWN), messaging_limit_tier, status, waba_id, kind
     * (production|sandbox), bruto. Null quando o provedor não respondeu.
     *
     * @return array<string, mixed>|null
     */
    public function numero(bool $fresco = false): ?array;

    /**
     * Templates da WABA do número, como a Meta devolve (name, language, status, category,
     * parameter_format, components).
     *
     * @return list<array<string, mixed>>
     */
    public function templates(?string $nome = null): array;

    /**
     * Definição de um template pelo nome (prefere o idioma pedido, depois pt_BR, depois o primeiro).
     *
     * @return array<string, mixed>|null
     */
    public function template(string $nome, ?string $idioma = null): ?array;

    /**
     * @param  list<array<string, mixed>>  $componentes  componentes já no formato de envio da Cloud API
     * @return string wamid
     */
    public function enviarTemplate(string $para, string $nome, string $idioma, array $componentes = []): string;

    /** Texto livre: só chega dentro da janela de 24 h. @return string wamid */
    public function enviarTexto(string $para, string $texto): string;

    /**
     * Saúde do número no provedor (formato do provedor). Vazio quando não há checagem.
     *
     * @return array<string, mixed>
     */
    public function saude(): array;
}
