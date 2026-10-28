<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel admin sempre em português, independente do APP_LOCALE do .env - vale também para as datas
 * relativas ("há 6 dias"). Mesmo middleware do KYC e do MED.
 */
class SetPanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) config('disparo.painel_locale', 'pt_BR');

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
