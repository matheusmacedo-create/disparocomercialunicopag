<?php

use App\Http\Controllers\Api\KapsoWebhookController;
use App\Http\Controllers\Api\MetaWebhookController;
use App\Http\Controllers\Api\V1\CampaignsController;
use App\Http\Controllers\Api\V1\ContactsController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SendersController;
use App\Http\Controllers\Api\V1\SuppressionsController;
use App\Http\Middleware\ApiKeyMiddleware;
use Illuminate\Support\Facades\Route;

// Webhook da Kapso, um por remetente: a chave aleatória na URL diz de qual número é o evento (KB 10).
// Fora da X-API-Key de propósito: quem chama é a Kapso, que autentica assinando o corpo (HMAC em
// X-Webhook-Signature com o segredo do remetente, conferido no controller). Throttle folgado porque uma
// rodada devolve uma rajada de sent/delivered/read (mesmo valor do KYC e do MED).
Route::post('webhooks/kapso/{webhookKey}', KapsoWebhookController::class)
    ->where('webhookKey', '[A-Za-z0-9]{16,64}')
    ->middleware('throttle:600,1')
    ->name('webhooks.kapso');

// Webhook do App da Meta (remetentes provider=meta): GET é a verificação da Meta, POST os eventos,
// assinados com o App Secret (X-Hub-Signature-256).
Route::get('webhooks/meta', [MetaWebhookController::class, 'verificar'])
    ->middleware('throttle:60,1')
    ->name('webhooks.meta.verificar');
Route::post('webhooks/meta', [MetaWebhookController::class, 'receber'])
    ->middleware('throttle:600,1')
    ->name('webhooks.meta');

// API v1 para integrações (MCP, KYC): X-API-Key = API_KEY do .env (vazio = 503). Só leitura, supressão e
// rascunho de campanha: aprovar, iniciar, pausar e cancelar são do painel (quatro olhos, KB 2.2).
Route::prefix('v1')->middleware([ApiKeyMiddleware::class, 'throttle:300,1'])->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::get('suppressions', [SuppressionsController::class, 'index'])->name('api.v1.suppressions.index');
    Route::post('suppressions', [SuppressionsController::class, 'store'])->name('api.v1.suppressions.store');
    Route::get('senders/{id}/templates', [SendersController::class, 'templates'])->whereNumber('id')->name('api.v1.senders.templates');
    Route::get('contacts/{telefone}', [ContactsController::class, 'show'])->name('api.v1.contacts.show');
    Route::post('campaigns', [CampaignsController::class, 'store'])->name('api.v1.campaigns.store');
    Route::get('campaigns/{id}', [CampaignsController::class, 'show'])->whereNumber('id')->name('api.v1.campaigns.show');
});
