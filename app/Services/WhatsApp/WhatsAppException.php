<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Recusa da Kapso ou da Meta com a mensagem já traduzida para quem opera o painel. `codigo` é o código
 * da Meta (131047, 132001...) ou o da Kapso (insufficient_credits...), para quem precisa decidir em código.
 * Portado do KapsoException do KYC (KB do KYC 8.23).
 */
class WhatsAppException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string|int|null $codigo = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Códigos que valem para qualquer destinatário até alguém mexer na conta: pagamento na Meta (131042,
     * 141006), conta bloqueada/restrita (131031, 368), número fora da lista permitida (131030), limite por
     * denúncias de spam (131048: a Meta mede a qualidade do número, não passa sozinho em minutos),
     * acesso/token recusado (131005, 190, 10, 200), saldo da Kapso, provedor não configurado e Sandbox.
     */
    private const CODIGOS_DA_CONTA = [
        '131042', '141006', '131031', '131030', '368',
        '131048', '131005', '190', '10', '200',
        'insufficient_credits', 'nao_configurado', 'sandbox', 'sem_waba',
    ];

    /**
     * Instabilidade que passa sozinha: limite de envio da Meta (130429), limite de chamadas da API (4,
     * 80007), Meta fora (131016, 2), provedor sem resposta antes (`timeout`) ou depois (`timeout_envio`)
     * do envio.
     */
    private const CODIGOS_TRANSITORIOS = ['130429', '4', '80007', '131016', '2', 'timeout', 'timeout_envio'];

    /** Recusas sobre o template: valem para toda a campanha, mas não dizem nada da conta. */
    public const CODIGOS_DO_TEMPLATE = ['132001', '132000', '132012', '132015', '132016', 'template_nao_aprovado', 'template_nao_permitido', 'template_nao_suportado'];

    /** O contato não tem WhatsApp (ou é inalcançável): não insistir por 90 dias. */
    public const CODIGO_SEM_WHATSAPP = '131026';

    /** O contato parou as mensagens de marketing deste número na própria Meta (supressão de marketing). */
    public const CODIGOS_MARKETING_PARADO = ['131050', 'marketing_preference_stopped'];

    /** A Meta limitou marketing a este usuário por ora (131049): carência de dias, nunca supressão. */
    public const CODIGO_MARKETING_CARENCIA = '131049';

    /**
     * Bloqueio por qualidade: a Meta sinalizou o número (denúncias/spam) ou bloqueou a conta. Sem sonda
     * automática - insistir é o que derruba o número; só humano religa (KB 2.12).
     */
    public const CODIGOS_DE_QUALIDADE = ['131048', '368', '131031'];

    /** Texto livre fora da janela de 24 h: só template abre a conversa. */
    public const CODIGO_JANELA_FECHADA = '131047';

    /**
     * Recusa que vale para qualquer destinatário até alguém resolver: quem dispara em lote para no
     * primeiro e manda 1 só, como sonda, na rodada seguinte. Erro transitório não entra: ele não diz
     * nada sobre a conta amanhã.
     */
    public function afetaTodos(): bool
    {
        if ($this->transitoria()) {
            return false;
        }

        return in_array($this->httpStatus, [401, 402, 403, 404], true)
            || in_array((string) $this->codigo, self::CODIGOS_DA_CONTA, true)
            || ($this->codigo === null && $this->httpStatus === 0);
    }

    /** Instabilidade do provedor ou da Meta: espera e tenta de novo; não vira sonda. */
    public function transitoria(): bool
    {
        return $this->httpStatus === 429
            || $this->httpStatus >= 500
            || in_array((string) $this->codigo, self::CODIGOS_TRANSITORIOS, true);
    }

    public function doTemplate(): bool
    {
        return in_array((string) $this->codigo, self::CODIGOS_DO_TEMPLATE, true);
    }

    /** Bloqueio por qualidade (131048, 368, 131031): pausa sem sonda. */
    public function bloqueioPorQualidade(): bool
    {
        return in_array((string) $this->codigo, self::CODIGOS_DE_QUALIDADE, true);
    }

    /** Conta impedida (pagamento, token, saldo, Sandbox, 401-404): pausa com sonda a cada N minutos. */
    public function contaImpedida(): bool
    {
        return $this->afetaTodos() && ! $this->bloqueioPorQualidade();
    }

    public function carenciaDeMarketing(): bool
    {
        return (string) $this->codigo === self::CODIGO_MARKETING_CARENCIA;
    }

    /** A classe da recusa, para o disjuntor e o painel. */
    public function classe(): string
    {
        return match (true) {
            $this->transitoria() => 'transitoria',
            $this->bloqueioPorQualidade() => 'qualidade',
            $this->afetaTodos() => 'conta',
            $this->doTemplate() => 'template',
            default => 'contato',
        };
    }

    public function semWhatsApp(): bool
    {
        return (string) $this->codigo === self::CODIGO_SEM_WHATSAPP;
    }

    public function marketingParado(): bool
    {
        return in_array((string) $this->codigo, self::CODIGOS_MARKETING_PARADO, true);
    }

    public function janelaFechada(): bool
    {
        return (string) $this->codigo === self::CODIGO_JANELA_FECHADA;
    }

    /** A partir da resposta HTTP recusada pela Kapso ou pela Meta (formato de erro da Graph API). */
    public static function daResposta(Response $response, string $operacao): self
    {
        $erro = $response->json('error');
        $codigo = null;

        if (is_array($erro)) {
            $original = (string) ($erro['message'] ?? '');
            $detalhe = $erro['error_data']['details'] ?? null;
            if (is_string($detalhe) && $detalhe !== '' && ! str_contains($original, $detalhe)) {
                $original .= ' - '.$detalhe;
            }
            // O código principal identifica a família; o subcode só detalha. 131xxx vem em `code`.
            $codigo = $erro['code'] ?? $erro['error_subcode'] ?? null;
        } else {
            $original = is_string($erro) ? $erro : mb_substr($response->body(), 0, 300);
            $codigo = $response->json('code');
        }

        $codigo = is_string($codigo) || is_int($codigo) ? $codigo : null;

        if ($codigo === null && stripos($original, 'sandbox') !== false) {
            $codigo = 'sandbox';
        } elseif ($codigo === null && (stripos($original, 'payment') !== false || stripos($original, 'pagamento') !== false)) {
            $codigo = '131042';
        } elseif ($codigo === null && stripos($original, 'marketing_preference_stopped') !== false) {
            $codigo = 'marketing_preference_stopped';
        }

        // A Meta ecoa valores de parâmetro recusados (100, 132012, 131030): nada de PII na mensagem, que vai
        // para coluna de motivo, log e Discord.
        $original = \App\Support\Mask::scrub($original);

        return new self(
            trim(self::traduzir($response->status(), $codigo, $original).sprintf(' [%s: HTTP %d%s]', $operacao, $response->status(), $original !== '' ? ' - '.$original : '')),
            $response->status(),
            $codigo,
        );
    }

    /** O que o operador precisa entender (e fazer) para cada recusa conhecida. */
    public static function traduzir(int $status, string|int|null $codigo, string $original): string
    {
        $codigo = (string) $codigo;

        return match (true) {
            in_array($codigo, ['131042', '141006'], true) || stripos($original, 'payment') !== false => 'A Meta bloqueou: a forma de pagamento da conta WhatsApp está com problema (Meta Business > Billing Hub, ou o cartão "Meta message fees" do número na Kapso). Conversas iniciadas pela empresa ficam travadas até resolver.',
            str_contains($original, 'Active sandbox session required') => 'Número Sandbox da Kapso: só entrega para o celular de teste cadastrado.',
            str_contains($original, 'Sandbox WhatsApp configurations only support messaging') => 'Número Sandbox da Kapso não tem templates.',
            $codigo === '131047' => 'Fora da janela de 24h: o cliente não falou com a ÚnicoPag nas últimas 24h, então só um template aprovado pela Meta inicia a conversa.',
            $codigo === '131026' => 'Número sem WhatsApp ou inalcançável.',
            $codigo === '132001' => 'Template não existe na Meta (ou não neste idioma).',
            $codigo === '132000' => 'Quantidade de variáveis não bate com o template aprovado.',
            $codigo === '132012' => 'Formato de variável recusado pela Meta (vazio, quebra de linha, tab ou texto longo demais).',
            in_array($codigo, ['132015', '132016'], true) => 'Template pausado ou desativado pela Meta por qualidade: use outro template.',
            in_array($codigo, ['131050', '131049'], true) || str_contains($original, 'marketing_preference_stopped') || str_contains($original, 'stopped marketing') => 'O cliente parou de receber mensagens de marketing deste número (ou a Meta segurou para não saturar). Só template de utilidade chega.',
            in_array($codigo, ['130429', '131048'], true) => 'A Meta limitou o envio deste número (volume ou denúncias de spam): espere antes de mandar mais.',
            $codigo === '368' || $codigo === '131031' => 'A Meta bloqueou a conta WhatsApp (violação de política): confira o aviso no Meta Business.',
            in_array($codigo, ['190', '131005', '10', '200'], true) => 'A Meta recusou o acesso ao número: token inválido/expirado ou sem permissão (reconecte o número na Kapso ou gere outro token).',
            $codigo === '131016' => 'Serviço da Meta indisponível no momento: tente de novo mais tarde.',
            $codigo === '131030' => 'Destinatário fora da lista de números permitidos (conta em modo de teste na Meta).',
            $status === 401 => 'Credencial recusada: KAPSO_API_KEY/token da Meta inválido ou revogado.',
            $status === 402 || $codigo === 'insufficient_credits' => 'Saldo da Kapso não cobre a taxa da Meta para esta mensagem: adicione créditos no painel da Kapso (Billing).',
            $status === 409 => 'Já há uma mensagem saindo para este número: tente de novo em instantes.',
            $status === 404 => 'Número não encontrado no provedor: confira o phone_number_id do remetente.',
            $status === 429 => 'Limite de requisições do provedor atingido: tente de novo em instantes.',
            default => 'O provedor recusou.',
        };
    }
}
