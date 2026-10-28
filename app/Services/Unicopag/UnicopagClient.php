<?php

declare(strict_types=1);

namespace App\Services\Unicopag;

use App\Models\Contact;
use App\Support\Telefone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Leitura no unico-api (plataforma ÚnicoPag), no mesmo desenho do cliente do MED: X-API-Key + Bearer com
 * a chave própria do Disparo (`UNICOPAG_API_KEY`), caminho relativo à base ou URL absoluta. KB 9.
 *
 *  - Cadastro do seller: `GET /api/kyc-panel/accounts/{account_id}/register` (a rota que existe). Daqui
 *    só saem telefone e nome, já normalizados, e só isso vai para o cache (24 h) - o cadastro inteiro
 *    (endereço, nascimento, faturamento) não é da finalidade desta ferramenta.
 *  - Sellers inativos: `UNICOPAG_SELLERS_ENDPOINT` (contrato JSON: account_id, telefone?, nome?, email?,
 *    ultima_venda_em?, dias_sem_venda?, etapa?, aprovado_em?). Sem o endpoint, a fonte é o arquivo.
 */
class UnicopagClient
{
    /** Chaves onde o telefone pode estar no JSON do cadastro (varredura recursiva, como o KYC faz). */
    private const CHAVES_DE_TELEFONE = ['phone_number', 'whatsapp_phone', 'phone', 'whatsapp', 'celular', 'telefone', 'mobile_phone', 'cellphone', 'mobile'];

    /** Nome de quem se cadastrou, do mais específico ao menos (no topo, `name` é a razão social da PJ). */
    private const CHAVES_DE_NOME = ['register.name', 'register.nome', 'register.responsible_name', 'register.contact_name', 'additional_info.name', 'responsible_name', 'contact_name', 'owner.name', 'user.name', 'name', 'nome'];

    private const CHAVES_DE_EMPRESA = ['register.trade_name', 'register.nome_fantasia', 'register.store_name', 'trade_name', 'nome_fantasia', 'store_name', 'legal_name', 'razao_social', 'company_name'];

    public function cadastroConfigurado(): bool
    {
        return filled(config('disparo.unicoapi.key')) && filled(config('disparo.unicoapi.base_url')) && filled(config('disparo.unicoapi.register_endpoint'));
    }

    public function sellersConfigurado(): bool
    {
        return filled(config('disparo.unicoapi.key')) && filled(config('disparo.unicoapi.sellers_endpoint'))
            && (filled(config('disparo.unicoapi.base_url')) || preg_match('#^https?://#i', (string) config('disparo.unicoapi.sellers_endpoint')) === 1);
    }

    /**
     * Telefone (E.164) e nome do cadastro da conta, normalizados; `existe=false` quando o unico-api não tem
     * a conta (404). Cache de 24 h do normalizado, por conta.
     *
     * @return array{existe: bool, telefone: ?string, nome: ?string, empresa: ?string}
     */
    public function cadastro(string $accountId): array
    {
        $accountId = trim($accountId);
        $chave = 'disparo:unicoapi:cadastro:'.sha1($accountId);
        $horas = max(1, (int) config('disparo.unicoapi.cache_cadastro_horas', 24));

        return Cache::remember($chave, now()->addHours($horas), function () use ($accountId): array {
            $json = $this->get((string) config('disparo.unicoapi.register_endpoint'), ['{account_id}' => $accountId, '{id}' => $accountId]);

            if ($json === null) {
                return ['existe' => false, 'telefone' => null, 'nome' => null, 'empresa' => null];
            }

            $cadastro = self::desembrulhar($json);
            $bruto = self::telefoneBruto($cadastro);

            return [
                'existe' => true,
                'telefone' => $bruto !== null ? Telefone::normalizar($bruto) : null,
                'nome' => Contact::nomeHigienizado(self::primeiro($cadastro, self::CHAVES_DE_NOME)),
                'empresa' => Contact::nomeHigienizado(self::primeiro($cadastro, self::CHAVES_DE_EMPRESA)),
            ];
        });
    }

    /**
     * A lista de sellers inativos do unico-api. `{dias}` no endpoint é substituído; sem o marcador, vai
     * como query string. Segue `links.next` / `next_page_url` quando a resposta for paginada.
     *
     * @return list<array<string, mixed>>
     */
    public function sellersInativos(int $dias): array
    {
        if (! $this->sellersConfigurado()) {
            throw new UnicopagException('UNICOPAG_SELLERS_ENDPOINT (e UNICOPAG_API_KEY) não configurados: a lista de sellers inativos só vem de arquivo.');
        }

        $endpoint = (string) config('disparo.unicoapi.sellers_endpoint');

        if (! str_contains($endpoint, '{dias}')) {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?').'dias='.$dias;
        }

        $url = $this->url($endpoint, ['{dias}' => (string) $dias]);
        $sellers = [];

        for ($pagina = 0; $pagina < 200 && $url !== null; $pagina++) {
            $json = $this->get($url, []);

            if ($json === null) {
                break;
            }

            foreach (self::listaDe($json) as $item) {
                $sellers[] = $item;
            }

            $proxima = data_get($json, 'links.next') ?? data_get($json, 'next_page_url') ?? data_get($json, 'paging.next') ?? data_get($json, 'meta.next');
            $url = is_string($proxima) && $proxima !== '' && $proxima !== $url ? $proxima : null;
        }

        return $sellers;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    public static function listaDe(array $json): array
    {
        if (array_is_list($json)) {
            return array_values(array_filter($json, 'is_array'));
        }

        foreach (['data', 'sellers', 'accounts', 'items', 'results'] as $envelope) {
            if (isset($json[$envelope]) && is_array($json[$envelope])) {
                return self::listaDe($json[$envelope]);
            }
        }

        return [];
    }

    /**
     * @param  array<string, string>  $placeholders
     * @return array<string, mixed>|null null = 404
     */
    private function get(string $endpoint, array $placeholders): ?array
    {
        $key = (string) config('disparo.unicoapi.key');
        $url = $this->url($endpoint, $placeholders);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$key,
                'X-API-Key' => $key,
            ])->timeout(max(2, (int) config('disparo.unicoapi.timeout', 8)))->get($url);
        } catch (ConnectionException $e) {
            throw new UnicopagException('unico-api não respondeu: '.$e->getMessage());
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new UnicopagException(sprintf('unico-api recusou (HTTP %d)%s', $response->status(), $this->motivo($response)));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new UnicopagException('unico-api respondeu algo que não é JSON.');
        }

        return $json;
    }

    /** @param  array<string, string>  $placeholders */
    private function url(string $endpoint, array $placeholders): string
    {
        $endpoint = strtr($endpoint, array_map('rawurlencode', $placeholders));

        if (preg_match('#^https?://#i', $endpoint) === 1) {
            return $endpoint;
        }

        return rtrim((string) config('disparo.unicoapi.base_url'), '/').'/'.ltrim($endpoint, '/');
    }

    private function motivo(Response $response): string
    {
        $mensagem = $response->json('message') ?? $response->json('error');

        return is_string($mensagem) && $mensagem !== '' ? ': '.mb_substr($mensagem, 0, 200) : '';
    }

    /**
     * Tira o envelope (`{"data":{...}}`, `{"account":{...}}`) para as chaves ficarem no topo.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function desembrulhar(array $payload): array
    {
        foreach (['data', 'account'] as $envelope) {
            if (isset($payload[$envelope]) && is_array($payload[$envelope]) && ! array_is_list($payload[$envelope])) {
                return self::desembrulhar($payload[$envelope]);
            }
        }

        return $payload;
    }

    /** @param  array<mixed>  $payload */
    private static function telefoneBruto(array $payload): ?string
    {
        foreach ($payload as $chave => $valor) {
            if (is_string($chave) && in_array(strtolower($chave), self::CHAVES_DE_TELEFONE, true) && (is_string($valor) || is_int($valor)) && trim((string) $valor) !== '') {
                return (string) $valor;
            }
        }

        foreach ($payload as $valor) {
            if (is_array($valor) && ($achado = self::telefoneBruto($valor)) !== null) {
                return $achado;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $caminhos
     */
    private static function primeiro(array $payload, array $caminhos): ?string
    {
        foreach ($caminhos as $caminho) {
            $valor = data_get($payload, $caminho);

            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }
        }

        return null;
    }
}
