<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\Suppression;
use App\Support\Mask;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cadastro único de supressões entre os sistemas que falam pelos mesmos números (KYC, MCP): o "pare"
 * dito à campanha vale para o aviso do KYC na semana seguinte, e o contrário (achado de 28/10).
 *
 *  - GET  /api/v1/suppressions?since=&limit=  tudo o que mudou desde `since` (criadas E liberadas), por
 *    hash do telefone - o KYC lê e espelha, sem receber telefone em claro;
 *  - POST /api/v1/suppressions  grava uma supressão a partir do telefone (normalizado aqui) ou do hash
 *    (quem já só tem o hash, depois da anonimização).
 *
 * Desbloquear NÃO existe pela API: é decisão humana, com justificativa, no painel (`release suppressions`).
 */
final class SuppressionsController extends Controller
{
    private const LIMITE_PADRAO = 500;

    private const LIMITE_MAXIMO = 5000;

    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIMITE_MAXIMO],
            'escopo' => ['nullable', Rule::in([Suppression::ESCOPO_TUDO, Suppression::ESCOPO_MARKETING])],
            'vigentes' => ['nullable', 'boolean'],
        ]);

        $limite = (int) ($dados['limit'] ?? self::LIMITE_PADRAO);

        $registros = Suppression::query()
            ->when(isset($dados['since']), fn ($q) => $q->where('updated_at', '>=', $dados['since']))
            ->when(isset($dados['escopo']), fn ($q) => $q->where('escopo', $dados['escopo']))
            ->when((bool) ($dados['vigentes'] ?? false), fn ($q) => $q->vigentes())
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limite)
            ->get();

        return response()->json([
            'data' => $registros->map(fn (Suppression $s): array => [
                'hash' => $s->telefone_hash,
                'sender_id' => $s->sender_id,
                'escopo' => $s->escopo,
                'motivo' => $s->motivo,
                'origem' => $s->origem,
                'created_at' => $s->created_at?->toIso8601String(),
                'liberada_em' => $s->liberada_em?->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])->values(),
            'total' => $registros->count(),
            // Página seguinte: repita a chamada com since = proximo_since (null = não há mais).
            'proximo_since' => $registros->count() === $limite ? $registros->last()?->updated_at?->toIso8601String() : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'telefone' => ['nullable', 'string', 'max:30', 'required_without:telefone_hash'],
            'telefone_hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i', 'required_without:telefone'],
            'escopo' => ['nullable', Rule::in([Suppression::ESCOPO_TUDO, Suppression::ESCOPO_MARKETING])],
            'motivo' => ['nullable', 'string', 'max:50'],
            'origem' => ['nullable', 'string', 'max:30'],
            'detalhes' => ['nullable', 'string', 'max:500'],
            'sender_id' => ['nullable', 'integer', Rule::exists('senders', 'id')],
        ]);

        $escopo = (string) ($dados['escopo'] ?? Suppression::ESCOPO_TUDO);
        $motivo = (string) ($dados['motivo'] ?? Suppression::MOTIVO_HISTORICO);
        $origem = (string) ($dados['origem'] ?? 'api');
        $detalhes = isset($dados['detalhes']) ? Mask::scrub((string) $dados['detalhes']) : null;
        $senderId = isset($dados['sender_id']) ? (int) $dados['sender_id'] : null;

        if (filled($dados['telefone'] ?? null)) {
            $telefone = Telefone::canonico((string) $dados['telefone']);

            if ($telefone === null) {
                return response()->json(['error' => 'Telefone não parece um número brasileiro válido.'], 422);
            }

            $criada = Suppression::suprimir($telefone, $motivo, $origem, $senderId, $escopo, null, $detalhes);
            $hash = Telefone::hash($telefone);
        } else {
            $hash = strtolower((string) $dados['telefone_hash']);
            $criada = $this->suprimirPorHash($hash, $motivo, $origem, $senderId, $escopo, $detalhes);
        }

        return response()->json(['criada' => $criada, 'hash' => $hash, 'escopo' => $escopo, 'motivo' => $motivo], $criada ? 201 : 200);
    }

    /**
     * Supressão a partir do hash (sem telefone em claro): a mesma chave e o mesmo espelho no contato que
     * {@see Suppression::suprimir()}, para quem só tem o hash. Escopo `tudo` é sempre global.
     */
    private function suprimirPorHash(string $hash, string $motivo, string $origem, ?int $senderId, string $escopo, ?string $detalhes): bool
    {
        if ($escopo === Suppression::ESCOPO_TUDO) {
            $senderId = null;
        }

        $registro = Suppression::query()->createOrFirst(
            ['chave' => $hash.':'.($senderId ?? 'todos')],
            [
                'telefone_hash' => $hash,
                'telefone' => null,
                'sender_id' => $senderId,
                'escopo' => $escopo,
                'motivo' => mb_substr($motivo, 0, 50),
                'origem' => mb_substr($origem, 0, 30),
                'detalhes' => $detalhes !== null ? mb_substr($detalhes, 0, 500) : null,
                'created_at' => now(),
            ],
        );

        if ($registro->wasRecentlyCreated) {
            ContactEvent::registrar($hash, 'bloqueado', ['supressao_id' => $registro->id, 'motivo' => $registro->motivo, 'escopo' => $escopo, 'sender_id' => $senderId, 'origem' => $origem], null);
        }

        if ($escopo === Suppression::ESCOPO_TUDO) {
            Contact::query()->where('telefone_hash', $hash)->get()
                ->each(fn (Contact $contato) => $contato->marcarOptout($motivo, $origem));
        }

        return $registro->wasRecentlyCreated;
    }
}
