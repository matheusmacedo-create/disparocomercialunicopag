<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sender;
use App\Services\Webhooks\NormalizadorKapso;
use App\Services\Webhooks\ProcessadorDeEventos;
use App\Services\Webhooks\TrilhaDeWebhooks;
use App\Services\WhatsApp\KapsoClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/webhooks/kapso/{webhookKey} - eventos de UM número na Kapso (KB 10).
 *
 * A chave aleatória na URL diz de qual remetente é o evento (um webhook por número, como o MED faz por
 * conta); a assinatura é o HMAC-SHA256 do corpo cru com o segredo daquele remetente, gerado por
 * `disparo:registrar-webhook` - sem segredo no remetente nada passa. O `phone_number_id` do corpo é
 * cruzado com o remetente da URL: corpo assinado para A reposto em B é ignorado (202).
 *
 * Tudo roda INLINE, antes do 200: o pedido para parar tem de estar gravado quando a Kapso receber a
 * resposta, sem depender de worker (o KYC viveu campanha saindo com o "pare" parado na fila). A Kapso
 * espera 200 em até 10 s e reenvia o que não confirmamos; a `X-Idempotency-Key` deduplica a reentrega e
 * cada passo do processador é idempotente (entrega só avança, mensagem única por wamid).
 *
 * Nada de telefone nem texto no log nem na trilha: só evento, ids e contagens.
 */
final class KapsoWebhookController extends Controller
{
    public function __construct(
        private readonly ProcessadorDeEventos $processador,
        private readonly TrilhaDeWebhooks $trilha,
    ) {}

    public function __invoke(Request $request, string $webhookKey): JsonResponse
    {
        $sender = Sender::query()
            ->where('provider', Sender::PROVIDER_KAPSO)
            ->where('webhook_key', $webhookKey)
            ->first();

        if ($sender === null) {
            return response()->json(['error' => 'remetente desconhecido'], 404);
        }

        $corpoCru = (string) $request->getContent();
        $segredo = (string) $sender->webhook_secret;

        if ($segredo === '' || ! KapsoClient::assinaturaValida($corpoCru, $request->header('X-Webhook-Signature'), $segredo)) {
            Log::warning('Webhook da Kapso recusado', ['sender_id' => $sender->id, 'motivo' => $segredo === '' ? 'remetente sem segredo (rode disparo:registrar-webhook)' : 'assinatura inválida']);

            return response()->json(['error' => 'assinatura inválida'], 401);
        }

        $corpo = NormalizadorKapso::mapa(json_decode($corpoCru, true));
        // O corpo é o que está assinado: o header só vale quando o corpo não diz o evento.
        $evento = NormalizadorKapso::texto($corpo['event'] ?? null)
            ?: NormalizadorKapso::texto($corpo['type'] ?? null)
            ?: NormalizadorKapso::texto($request->header('X-Webhook-Event'));
        $evento = $evento !== '' ? $evento : null;

        // Com buffer ligado na Kapso, vários payloads do mesmo evento chegam juntos.
        $payloads = ($corpo['batch'] ?? false) === true
            ? array_values(array_filter(NormalizadorKapso::mapa($corpo['data'] ?? null), 'is_array'))
            : [$corpo];

        $chave = NormalizadorKapso::texto($request->header('X-Idempotency-Key')) ?: null;
        [$registro, $jaProcessado] = $this->trilha->abrir(Sender::PROVIDER_KAPSO, $sender, $evento, $chave);

        if ($jaProcessado) {
            Log::debug('Webhook da Kapso repetido (X-Idempotency-Key já processada)', ['sender_id' => $sender->id, 'evento' => $evento]);

            return response()->json(['ok' => true, 'duplicado' => true, 'processados' => 0]);
        }

        $inicio = microtime(true);
        $resumo = ['payloads' => count($payloads), 'processados' => 0, 'ignorados' => 0, 'outro_numero' => 0, 'erros' => 0, 'resultados' => []];

        foreach ($payloads as $payload) {
            $phoneNumberId = NormalizadorKapso::phoneNumberId($payload);

            if ($phoneNumberId !== null && $phoneNumberId !== (string) $sender->phone_number_id) {
                $resumo['outro_numero']++;

                continue;
            }

            $interno = NormalizadorKapso::normalizar($evento, $payload);

            if ($interno === null) {
                $resumo['ignorados']++;

                continue;
            }

            try {
                $resultado = $this->processador->processar($sender, $interno);
            } catch (Throwable $e) {
                // Um payload quebrado não derruba os outros; o 500 no fim faz a Kapso reentregar o lote, e a
                // linha sem `processado_em` deixa a reentrega rodar de novo (tudo idempotente).
                report($e);
                $resumo['erros']++;

                continue;
            }

            isset($resultado['ignorado']) ? $resumo['ignorados']++ : $resumo['processados']++;

            if (count($resumo['resultados']) < 50) {
                $resumo['resultados'][] = $resultado;
            }
        }

        $duracao = round(microtime(true) - $inicio, 3);

        if ($resumo['erros'] > 0) {
            $this->trilha->concluir($registro, $evento, $resumo, 'erro: '.TrilhaDeWebhooks::resultadoDe($resumo));
            $registro->forceFill(['processado_em' => null])->save();

            return response()->json(['ok' => false, 'error' => 'falha ao processar parte do lote'], 500);
        }

        $this->trilha->concluir($registro, $evento, $resumo);

        if ($resumo['outro_numero'] > 0) {
            Log::warning('Webhook da Kapso com phone_number_id de outro remetente: ignorado', ['sender_id' => $sender->id, 'evento' => $evento, 'outro_numero' => $resumo['outro_numero'], 'payloads' => count($payloads)]);
        }

        // Status de mensagem que não é nossa é a maioria do tráfego: só vira info quando mexeu em algo.
        Log::log($resumo['processados'] === 0 ? 'debug' : 'info', 'Webhook da Kapso', [
            'sender_id' => $sender->id,
            'evento' => $evento,
            'payloads' => count($payloads),
            'processados' => $resumo['processados'],
            'ignorados' => $resumo['ignorados'],
            'segundos' => $duracao,
        ]);

        if ($duracao > 8) {
            Log::warning('Webhook da Kapso lento (a Kapso espera 200 em 10 s)', ['sender_id' => $sender->id, 'evento' => $evento, 'payloads' => count($payloads), 'segundos' => $duracao]);
        }

        $somenteOutroNumero = $resumo['outro_numero'] > 0 && $resumo['processados'] === 0 && $resumo['ignorados'] === 0;

        return response()->json([
            'ok' => true,
            'processados' => $resumo['processados'],
            'ignorados' => $resumo['ignorados'] + $resumo['outro_numero'],
        ], $somenteOutroNumero ? 202 : 200);
    }
}
