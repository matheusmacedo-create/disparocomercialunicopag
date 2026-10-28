<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticação server-to-server da API v1 (header `X-API-Key` = API_KEY do .env), para o MCP e o KYC
 * (supressões compartilhadas). Fail-closed como no KYC e no MED: sem chave configurada a API está
 * desligada (503), chave errada é 401 - comparação em tempo constante.
 */
final class ApiKeyMiddleware
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $esperada = (string) config('disparo.api_key');

        if ($esperada === '') {
            return response()->json(['error' => 'API desligada: API_KEY não configurada neste ambiente.'], 503);
        }

        $informada = (string) $request->header('X-API-Key');

        if ($informada === '' || ! hash_equals($esperada, $informada)) {
            return response()->json(['error' => 'Não autorizado.'], 401);
        }

        return $next($request);
    }
}
