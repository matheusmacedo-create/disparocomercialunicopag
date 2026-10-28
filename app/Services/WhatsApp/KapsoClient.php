<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Kapso: duas APIs, uma chave de projeto (header X-API-Key). KB 3 e 7.
 *  - meta/whatsapp/{versão}: espelho da Graph API (enviar, templates, dados do número) mais as extensões
 *    de histórico (conversas, mensagens e contatos do número, com `fields=kapso()`);
 *  - platform/v1: o que é da Kapso (números, saúde, webhooks, broadcasts, preferências de marketing).
 */
class KapsoClient extends GraphClient
{
    protected function baseGraph(): string
    {
        return rtrim((string) config('disparo.provedores.kapso.base_url', 'https://api.kapso.ai'), '/').'/meta/whatsapp/'.config('disparo.provedores.kapso.graph_version', 'v24.0');
    }

    protected function basePlatform(): string
    {
        return rtrim((string) config('disparo.provedores.kapso.base_url', 'https://api.kapso.ai'), '/').'/platform/v1';
    }

    protected function cabecalhos(): array
    {
        return ['X-API-Key' => $this->sender->credencial()];
    }

    protected function nomeDoProvedor(): string
    {
        return 'Kapso';
    }

    /** O número pela API da Kapso: traz `kind` (sandbox|production) e a WABA, que a Graph não dá. */
    protected function consultarNumero(): ?array
    {
        try {
            $response = $this->platform()->get('/whatsapp/phone_numbers/'.$this->sender->phone_number_id);
        } catch (ConnectionException) {
            return null;
        }

        $dados = $response->successful() ? $response->json('data') : null;

        if (! is_array($dados)) {
            return null;
        }

        return $this->normalizarNumero($dados);
    }

    /** Todos os números do projeto (para achar o phone_number_id). @return list<array<string, mixed>> */
    public function numeros(): array
    {
        $response = $this->consultar('listar números', fn (): Response => $this->platform()->get('/whatsapp/phone_numbers', ['per_page' => 50]));

        return $this->lista($response->json('data'));
    }

    public function isSandbox(): bool
    {
        return ($this->numero()['kind'] ?? null) === 'sandbox';
    }

    public function saude(): array
    {
        $response = $this->consultar('checar saúde do número', fn (): Response => $this->platform()->get('/whatsapp/phone_numbers/'.$this->sender->phone_number_id.'/health'));

        return (array) $response->json();
    }

    /** @return list<array<string, mixed>> */
    public function webhooks(): array
    {
        $response = $this->consultar('listar webhooks', fn (): Response => $this->platform()->get('/whatsapp/phone_numbers/'.$this->sender->phone_number_id.'/webhooks'));

        return $this->lista($response->json('data'));
    }

    /**
     * Cria (ou atualiza, se já houver um para a mesma URL) o webhook do número.
     *
     * @param  list<string>  $eventos
     * @return array<string, mixed>
     */
    public function registrarWebhook(string $url, string $segredo, array $eventos): array
    {
        $corpo = ['whatsapp_webhook' => [
            'kind' => 'kapso',
            'url' => $url,
            'events' => $eventos,
            'secret_key' => $segredo,
            'active' => true,
        ]];

        $existente = collect($this->webhooks())->first(fn (array $webhook): bool => ($webhook['url'] ?? null) === $url);
        $base = '/whatsapp/phone_numbers/'.$this->sender->phone_number_id.'/webhooks';

        $response = $this->consultar('registrar webhook', fn (): Response => $existente !== null && isset($existente['id'])
            ? $this->platform()->patch($base.'/'.$existente['id'], $corpo)
            : $this->platform()->post($base, $corpo));

        return (array) ($response->json('data') ?? []);
    }

    /** Assinatura do webhook da Kapso: HMAC-SHA256 (hex) do corpo cru com o segredo. */
    public static function assinaturaValida(string $corpoCru, ?string $assinatura, ?string $segredo): bool
    {
        if (blank($segredo) || blank($assinatura)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $corpoCru, (string) $segredo), strtolower(trim((string) $assinatura)));
    }

    /**
     * Conversas do número (histórico), página a página. `last_active_since`/`until` em ISO 8601.
     *
     * @param  array<string, mixed>  $filtros  status, last_active_since, last_active_until, phone_number
     * @return array{data: list<array<string, mixed>>, after: ?string}
     */
    public function conversas(array $filtros = [], ?string $after = null, int $limite = 100): array
    {
        // `kapso()` vazio OMITE os campos da Kapso; `kapso(default)` traz direção, status, conteúdo...
        return $this->pagina('/'.$this->sender->phone_number_id.'/conversations', $filtros + ['fields' => 'kapso(default)'], $after, $limite, 'listar conversas');
    }

    /**
     * Mensagens do número (histórico), com as extensões `kapso` (direção, status, conteúdo). `since`/
     * `until` em ISO 8601, inclusivos.
     *
     * @param  array<string, mixed>  $filtros  conversation_id, direction, status, since, until
     * @return array{data: list<array<string, mixed>>, after: ?string}
     */
    public function mensagens(array $filtros = [], ?string $after = null, int $limite = 100): array
    {
        return $this->pagina('/'.$this->sender->phone_number_id.'/messages', $filtros + ['fields' => 'kapso(default)'], $after, $limite, 'listar mensagens');
    }

    /**
     * Contatos do número (wa_id, profile_name, display_name).
     *
     * @param  array<string, mixed>  $filtros  wa_id, customer_id, has_customer
     * @return array{data: list<array<string, mixed>>, after: ?string}
     */
    public function contatos(array $filtros = [], ?string $after = null, int $limite = 100): array
    {
        return $this->pagina('/'.$this->sender->phone_number_id.'/contacts', $filtros + ['fields' => 'kapso(default)'], $after, $limite, 'listar contatos');
    }

    /**
     * Broadcasts (listas mandadas pelo painel da Kapso) do projeto.
     *
     * @return array{data: list<array<string, mixed>>, after: ?string}
     */
    public function broadcasts(?string $after = null, int $limite = 100): array
    {
        return $this->paginaPlatform('/whatsapp/broadcasts', [], $after, $limite, 'listar broadcasts');
    }

    /** @return array{data: list<array<string, mixed>>, after: ?string} */
    public function destinatariosDoBroadcast(string $broadcastId, ?string $after = null, int $limite = 100): array
    {
        return $this->paginaPlatform('/whatsapp/broadcasts/'.$broadcastId.'/recipients', [], $after, $limite, 'listar destinatários do broadcast');
    }

    /**
     * Preferências de marketing do contato na Meta, por número: lista vazia = nunca mudou (assinado em
     * todos). Cada item tem phone_number_id, status (stopped|resumed), marketing_allowed, source, detail.
     *
     * @return list<array<string, mixed>>
     */
    public function preferenciasDeMarketing(string $telefoneDigitos): array
    {
        try {
            $response = $this->consultar('consultar preferências de marketing', fn (): Response => $this->platform()->get('/whatsapp/contacts/'.$telefoneDigitos.'/marketing_preferences'));
        } catch (WhatsAppException $exception) {
            if ($exception->httpStatus === 404) {
                return [];
            }

            throw $exception;
        }

        return $this->lista($response->json('data'));
    }

    /**
     * Uma página de uma lista com cursor (`paging.cursors.after` / `paging.next`) na Graph da Kapso.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{data: list<array<string, mixed>>, after: ?string}
     */
    private function pagina(string $caminho, array $filtros, ?string $after, int $limite, string $operacao): array
    {
        $parametros = array_filter($filtros + ['limit' => max(1, min(100, $limite)), 'after' => $after], fn ($v): bool => $v !== null && $v !== '');
        $response = $this->consultar($operacao, fn (): Response => $this->graph()->get($caminho, $parametros));

        return $this->paginada($response, $limite);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{data: list<array<string, mixed>>, after: ?string}
     */
    private function paginaPlatform(string $caminho, array $filtros, ?string $after, int $limite, string $operacao): array
    {
        $parametros = array_filter($filtros + ['limit' => max(1, min(100, $limite)), 'after' => $after], fn ($v): bool => $v !== null && $v !== '');
        $response = $this->consultar($operacao, fn (): Response => $this->platform()->get($caminho, $parametros));

        return $this->paginada($response, $limite);
    }

    /** @return array{data: list<array<string, mixed>>, after: ?string} */
    private function paginada(Response $response, int $limite): array
    {
        $dados = $this->lista($response->json('data'));
        $after = $response->json('paging.cursors.after');
        $next = $response->json('paging.next');

        // Sem `next` o after pode vir mesmo na última página: a lista menor que o limite encerra.
        $temMais = is_string($after) && $after !== '' && ($next === null ? count($dados) >= $limite : $next !== '');

        return ['data' => $dados, 'after' => $temMais ? $after : null];
    }

    /** @return list<array<string, mixed>> */
    private function lista(mixed $dados): array
    {
        return is_array($dados) ? array_values(array_filter($dados, 'is_array')) : [];
    }

    private function platform(): PendingRequest
    {
        return $this->http($this->basePlatform());
    }
}
