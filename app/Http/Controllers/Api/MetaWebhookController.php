<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sender;
use App\Services\Webhooks\EventoDeMensagem;
use App\Services\Webhooks\NormalizadorKapso;
use App\Services\Webhooks\NormalizadorMeta;
use App\Services\Webhooks\ProcessadorDeEventos;
use App\Services\Webhooks\TrilhaDeWebhooks;
use App\Services\WhatsApp\MetaCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook do App da Meta (remetentes `provider=meta`, KB 10). Um só para o App: cada
 * `entry[].changes[].value` diz o número em `metadata.phone_number_id`, e é por ele que se acha o
 * remetente. Número que não é remetente cadastrado responde 200 e não deixa nada gravado (seria guardar
 * dado de número alheio).
 *
 *  - GET: a verificação da Meta (`hub.mode=subscribe`, `hub.verify_token` comparado em tempo constante
 *    com META_WEBHOOK_VERIFY_TOKEN; devolve `hub.challenge`);
 *  - POST: `X-Hub-Signature-256` = sha256 HMAC do corpo cru com o App Secret (META_APP_SECRET).
 *
 * Mesmo processador do webhook da Kapso: statuses, mensagens recebidas e `user_preferences` (o botão
 * "Parar promoções" da Meta) viram o mesmo evento interno, inline, antes do 200.
 */
final class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly ProcessadorDeEventos $processador,
        private readonly TrilhaDeWebhooks $trilha,
    ) {}

    /** A Meta chama com hub.mode, hub.verify_token e hub.challenge (o PHP troca o ponto por sublinhado). */
    public function verificar(Request $request): Response
    {
        $esperado = (string) config('disparo.provedores.meta.webhook_verify_token');
        $modo = (string) ($request->query('hub_mode') ?? $request->query('hub.mode') ?? '');
        $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? '');
        $desafio = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge') ?? '');

        if ($modo !== 'subscribe' || $esperado === '' || $token === '' || ! hash_equals($esperado, $token)) {
            Log::warning('Verificação do webhook da Meta recusada', ['modo' => $modo, 'token_configurado' => $esperado !== '']);

            return response('token de verificação inválido', 403)->header('Content-Type', 'text/plain');
        }

        return response($desafio, 200)->header('Content-Type', 'text/plain');
    }

    public function receber(Request $request): JsonResponse
    {
        $corpoCru = (string) $request->getContent();

        if (! MetaCloudClient::assinaturaValida($corpoCru, $request->header('X-Hub-Signature-256'), (string) config('disparo.provedores.meta.app_secret'))) {
            Log::warning('Webhook da Meta recusado: assinatura inválida ou META_APP_SECRET vazio');

            return response()->json(['error' => 'assinatura inválida'], 401);
        }

        $corpo = NormalizadorKapso::mapa(json_decode($corpoCru, true));
        $resumo = ['payloads' => 0, 'processados' => 0, 'ignorados' => 0, 'desconhecidos' => 0, 'erros' => 0, 'resultados' => []];

        /** @var list<array{0: Sender, 1: list<EventoDeMensagem>}> $lotes */
        $lotes = [];
        $remetentes = [];

        foreach (NormalizadorKapso::mapa($corpo['entry'] ?? null) as $entry) {
            foreach (NormalizadorKapso::mapa(NormalizadorKapso::mapa($entry)['changes'] ?? null) as $change) {
                $change = NormalizadorKapso::mapa($change);
                $value = NormalizadorKapso::mapa($change['value'] ?? null);
                $resumo['payloads']++;

                // Só o campo `messages` (statuses, mensagens, preferências); template/qualidade do número vêm por outros campos.
                if (NormalizadorKapso::texto($change['field'] ?? 'messages') !== 'messages') {
                    $resumo['ignorados']++;

                    continue;
                }

                $phoneNumberId = NormalizadorKapso::texto(data_get($value, 'metadata.phone_number_id'));
                $sender = $phoneNumberId === '' ? null : ($remetentes[$phoneNumberId] ??= Sender::query()->where('provider', Sender::PROVIDER_META)->where('phone_number_id', $phoneNumberId)->first());

                if ($sender === null) {
                    $resumo['desconhecidos']++;

                    continue;
                }

                $lotes[] = [$sender, NormalizadorMeta::eventos($value)];
            }
        }

        if ($lotes === []) {
            Log::debug('Webhook da Meta sem remetente conhecido', ['payloads' => $resumo['payloads'], 'desconhecidos' => $resumo['desconhecidos']]);

            return response()->json(['ok' => true, 'processados' => 0]);
        }

        [$registro] = $this->trilha->abrir(Sender::PROVIDER_META, $lotes[0][0], 'messages', null);

        foreach ($lotes as [$sender, $eventos]) {
            foreach ($eventos as $evento) {
                try {
                    $resultado = $this->processador->processar($sender, $evento);
                } catch (Throwable $e) {
                    report($e);
                    $resumo['erros']++;

                    continue;
                }

                isset($resultado['ignorado']) ? $resumo['ignorados']++ : $resumo['processados']++;

                if (count($resumo['resultados']) < 50) {
                    $resumo['resultados'][] = $resultado;
                }
            }
        }

        $this->trilha->concluir($registro, 'messages', $resumo, $resumo['erros'] > 0 ? 'erro: '.TrilhaDeWebhooks::resultadoDe($resumo) : null);

        Log::log($resumo['processados'] === 0 ? 'debug' : 'info', 'Webhook da Meta', [
            'payloads' => $resumo['payloads'],
            'processados' => $resumo['processados'],
            'ignorados' => $resumo['ignorados'],
            'desconhecidos' => $resumo['desconhecidos'],
            'erros' => $resumo['erros'],
        ]);

        if ($resumo['erros'] > 0) {
            return response()->json(['ok' => false, 'error' => 'falha ao processar parte do lote'], 500);
        }

        return response()->json(['ok' => true, 'processados' => $resumo['processados'], 'ignorados' => $resumo['ignorados'] + $resumo['desconhecidos']]);
    }
}
