<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * Um evento de WhatsApp já no formato interno, seja ele da Kapso ou da Meta (KB 10). Serializável
 * (array) para viajar num job.
 */
final class EventoDeMensagem
{
    public const STATUS = 'status';

    public const RECEBIDA = 'recebida';

    public const PREFERENCIA = 'preferencia';

    /**
     * @param  list<string>  $textos  textos candidatos (corpo, título do botão, resposta interativa)
     * @param  array<string, mixed>  $bruto  payload enxuto para a trilha
     */
    public function __construct(
        public readonly string $tipo,
        public readonly ?string $wamid = null,
        public readonly ?string $status = null,
        public readonly ?int $timestamp = null,
        public readonly ?string $telefone = null,
        public readonly ?string $tipoMensagem = null,
        public readonly array $textos = [],
        public readonly ?string $contextWamid = null,
        public readonly ?string $codigoErro = null,
        public readonly ?string $erro = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $templateNome = null,
        public readonly ?string $categoria = null,
        public readonly ?string $nomeDoPerfil = null,
        public readonly bool $foiBotao = false,
        public readonly ?string $preferencia = null,
        public readonly array $bruto = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $dados */
    public static function fromArray(array $dados): self
    {
        return new self(
            tipo: (string) ($dados['tipo'] ?? self::STATUS),
            wamid: $dados['wamid'] ?? null,
            status: $dados['status'] ?? null,
            timestamp: isset($dados['timestamp']) ? (int) $dados['timestamp'] : null,
            telefone: $dados['telefone'] ?? null,
            tipoMensagem: $dados['tipoMensagem'] ?? null,
            textos: array_values(array_filter((array) ($dados['textos'] ?? []), 'is_string')),
            contextWamid: $dados['contextWamid'] ?? null,
            codigoErro: $dados['codigoErro'] ?? null,
            erro: $dados['erro'] ?? null,
            conversationId: $dados['conversationId'] ?? null,
            templateNome: $dados['templateNome'] ?? null,
            categoria: $dados['categoria'] ?? null,
            nomeDoPerfil: $dados['nomeDoPerfil'] ?? null,
            foiBotao: (bool) ($dados['foiBotao'] ?? false),
            preferencia: $dados['preferencia'] ?? null,
            bruto: (array) ($dados['bruto'] ?? []),
        );
    }
}
