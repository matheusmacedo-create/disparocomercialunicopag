<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\Sender;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/senders/{id}/templates - o cache dos templates do remetente (o que `disparo:sincronizar-templates`
 * trouxe da Meta), para o MCP listar e montar campanha. Quem decide o envio reconfere na Meta; aqui é
 * leitura.
 */
final class SendersController extends Controller
{
    public function templates(int $id): JsonResponse
    {
        $sender = Sender::query()->find($id);

        if ($sender === null) {
            return response()->json(['error' => 'Remetente não encontrado.'], 404);
        }

        return response()->json([
            'remetente' => [
                'id' => $sender->id,
                'nome' => $sender->nome,
                'provider' => $sender->provider,
                'categorias_permitidas' => $sender->categoriasPermitidas(),
                'templates_sincronizados_em' => $sender->templates_sincronizados_em?->toIso8601String(),
            ],
            'data' => $sender->templates()->orderBy('nome')->orderBy('idioma')->get()->map(fn (MessageTemplate $t): array => [
                'nome' => $t->nome,
                'idioma' => $t->idioma,
                'categoria' => $t->categoria,
                'status' => $t->status,
                'aprovado' => $t->aprovado(),
                'enviavel' => $t->motivoNaoEnviavel($sender) === null,
                'parameter_format' => $t->parameter_format,
                'corpo' => $t->corpo(),
                'variaveis_do_corpo' => $t->variaveisDoCorpo(),
                'cabecalho' => $t->formatoDoCabecalho(),
                'botao_url_dinamica' => $t->temBotaoDeUrlDinamica(),
                'qualidade' => ($t->qualidade ?? [])['score'] ?? null,
                'sincronizado_em' => $t->sincronizado_em?->toIso8601String(),
            ])->values(),
        ]);
    }
}
