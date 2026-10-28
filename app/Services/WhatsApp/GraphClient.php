<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Sender;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * O que Kapso e Meta têm em comum: os dois falam a Graph API do WhatsApp (enviar mensagem, templates,
 * dados do número). Só mudam a base e a autenticação. Portado do KapsoService do KYC.
 */
abstract class GraphClient implements WhatsAppClient
{
    private const CACHE_NUMERO_SEGUNDOS = 600;

    public function __construct(protected readonly Sender $sender) {}

    public function sender(): Sender
    {
        return $this->sender;
    }

    /** Base da Graph API (sem barra no fim). */
    abstract protected function baseGraph(): string;

    /** @return array<string, string> */
    abstract protected function cabecalhos(): array;

    abstract protected function nomeDoProvedor(): string;

    public function configurado(): bool
    {
        return $this->sender->credencial() !== '' && $this->sender->phone_number_id !== '';
    }

    public function numero(bool $fresco = false): ?array
    {
        if (! $this->configurado()) {
            return null;
        }

        $chave = 'disparo:numero:'.$this->sender->provider.':'.$this->sender->phone_number_id;

        if (! $fresco && is_array($emCache = Cache::get($chave))) {
            return $emCache;
        }

        $dados = $this->consultarNumero();

        if ($dados === null) {
            return null;
        }

        Cache::put($chave, $dados, self::CACHE_NUMERO_SEGUNDOS);

        return $dados;
    }

    /** @return array<string, mixed>|null */
    protected function consultarNumero(): ?array
    {
        try {
            $response = $this->graph()->get('/'.$this->sender->phone_number_id, [
                'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier,code_verification_status,status,name_status,throughput',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || ! is_array($bruto = $response->json())) {
            return null;
        }

        return $this->normalizarNumero($bruto);
    }

    /**
     * @param  array<string, mixed>  $bruto
     * @return array<string, mixed>
     */
    protected function normalizarNumero(array $bruto): array
    {
        $qualidade = strtoupper((string) ($bruto['quality_rating'] ?? $bruto['quality'] ?? ''));

        return [
            'phone_number_id' => (string) ($bruto['id'] ?? $bruto['phone_number_id'] ?? $this->sender->phone_number_id),
            'display_phone_number' => $bruto['display_phone_number'] ?? $bruto['phone_number'] ?? null,
            'verified_name' => $bruto['verified_name'] ?? $bruto['display_name'] ?? $bruto['name'] ?? null,
            'quality_rating' => in_array($qualidade, ['GREEN', 'YELLOW', 'RED'], true) ? $qualidade : 'UNKNOWN',
            'messaging_limit_tier' => $bruto['messaging_limit_tier'] ?? null,
            'status' => $bruto['status'] ?? null,
            'waba_id' => (string) ($bruto['business_account_id'] ?? $bruto['waba_id'] ?? $this->sender->waba_id ?? ''),
            'kind' => $bruto['kind'] ?? 'production',
            'bruto' => $bruto,
        ];
    }

    public function templates(?string $nome = null): array
    {
        $waba = $this->wabaId();

        if ($waba === '') {
            throw new WhatsAppException('Não foi possível descobrir a conta WhatsApp (WABA) do número: preencha o WABA ID do remetente.', 0, 'sem_waba');
        }

        $templates = [];
        $parametros = array_filter(['name' => $nome, 'fields' => 'id,name,status,language,category,parameter_format,components,quality_score', 'limit' => 100]);
        $caminho = '/'.$waba.'/message_templates';

        for ($pagina = 0; $pagina < 50 && $caminho !== null; $pagina++) {
            $response = $this->consultar('listar templates', fn (): Response => $this->graph()->get($caminho, $parametros));
            $dados = $response->json('data');

            if (is_array($dados)) {
                array_push($templates, ...array_values(array_filter($dados, 'is_array')));
            }

            $proximo = $response->json('paging.next');
            $caminho = is_string($proximo) && $proximo !== '' ? $proximo : null;
            $parametros = [];
        }

        return $templates;
    }

    public function template(string $nome, ?string $idioma = null): ?array
    {
        $candidatos = array_values(array_filter($this->templates($nome), fn (array $t): bool => ($t['name'] ?? null) === $nome));

        if ($candidatos === []) {
            return null;
        }

        $peso = fn (array $t): int => match (true) {
            $idioma !== null && ($t['language'] ?? '') === $idioma => 2,
            ($t['language'] ?? '') === 'pt_BR' => 1,
            default => 0,
        };

        usort($candidatos, fn (array $a, array $b): int => $peso($b) <=> $peso($a));

        return $candidatos[0];
    }

    public function enviarTemplate(string $para, string $nome, string $idioma, array $componentes = []): string
    {
        $template = ['name' => $nome, 'language' => ['code' => $idioma]];

        if ($componentes !== []) {
            $template['components'] = $componentes;
        }

        return $this->enviar([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    public function enviarTexto(string $para, string $texto): string
    {
        return $this->enviar([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'text',
            'text' => ['body' => $texto, 'preview_url' => true],
        ]);
    }

    public function saude(): array
    {
        return [];
    }

    protected function wabaId(): string
    {
        $waba = trim((string) $this->sender->waba_id);

        if ($waba !== '') {
            return $waba;
        }

        return (string) ($this->numero()['waba_id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function enviar(array $payload): string
    {
        if (! $this->configurado()) {
            throw new WhatsAppException(sprintf('Remetente "%s" sem credencial ou phone_number_id: configure em Remetentes.', $this->sender->nome), 0, 'nao_configurado');
        }

        try {
            $response = $this->graph()->post('/'.$this->sender->phone_number_id.'/messages', $payload);
        } catch (ConnectionException $exception) {
            if (self::conexaoNemAbriu($exception->getMessage())) {
                throw new WhatsAppException(sprintf('%s fora do ar ou inacessível (a conexão nem abriu). Nada foi enviado.', $this->nomeDoProvedor()), 0, 'timeout');
            }

            throw new WhatsAppException(sprintf('%s não confirmou o envio em %ds: a mensagem pode ter saído. Confira a conversa antes de reenviar.', $this->nomeDoProvedor(), $this->timeout()), 0, 'timeout_envio');
        }

        $this->guard($response, 'enviar mensagem');

        $wamid = $response->json('messages.0.id');

        if (! is_string($wamid) || $wamid === '') {
            throw new WhatsAppException($this->nomeDoProvedor().' aceitou o envio mas não devolveu o id da mensagem.', $response->status());
        }

        return $wamid;
    }

    /**
     * Falha de conexão ANTES de o pedido sair (mensagem do cURL): nome que não resolveu, conexão recusada,
     * handshake TLS ou tempo de conexão. O estouro do tempo de RESPOSTA vem depois do POST.
     */
    protected static function conexaoNemAbriu(string $erro): bool
    {
        return preg_match('/cURL error (?:6|7|35):|Connection timed out after|Resolving timed out after|SSL connection timeout|Failed to connect|Could not resolve|Connection refused/i', $erro) === 1;
    }

    /**
     * Consulta com a recusa já traduzida; sem resposta também vira exceção `timeout`.
     *
     * @param  Closure(): Response  $requisicao
     */
    protected function consultar(string $operacao, Closure $requisicao): Response
    {
        try {
            $response = $requisicao();
        } catch (ConnectionException) {
            throw new WhatsAppException(sprintf('%s fora do ar ou lento (sem resposta em %ds ao %s).', $this->nomeDoProvedor(), $this->timeout(), $operacao), 0, 'timeout');
        }

        $this->guard($response, $operacao);

        return $response;
    }

    protected function guard(Response $response, string $operacao): void
    {
        if ($response->successful()) {
            return;
        }

        throw WhatsAppException::daResposta($response, $this->nomeDoProvedor().' '.$operacao);
    }

    protected function graph(): PendingRequest
    {
        return $this->http($this->baseGraph());
    }

    protected function http(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withHeaders($this->cabecalhos())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->connectTimeout(5);
    }

    protected function timeout(): int
    {
        return max(3, (int) config('disparo.provedores.timeout', 15));
    }
}
