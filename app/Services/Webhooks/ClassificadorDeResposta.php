<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use Illuminate\Support\Str;

/**
 * O que uma mensagem do cliente diz à plataforma. Portado do KapsoWebhookController do KYC (regras
 * conferidas em produção; KB 6). A leitura é ESTRUTURAL, não uma regex única: o mesmo número também
 * atende, e "cancelar" ou "não" têm outro sentido numa conversa de atendimento.
 */
final class ClassificadorDeResposta
{
    /** Pediu para parar: bloqueia o telefone para sempre. */
    public const PARAR = 'parar';

    /** "Não, agora não" a um template nosso: não manda mais nesta campanha, mas o telefone segue na base. */
    public const NAO_AGORA = 'nao_agora';

    /** Pedido do titular (LGPD, Art. 18): apagar/acessar os dados. Bloqueia e abre item com prazo em Conversas. */
    public const PEDIDO_LGPD = 'pedido_lgpd';

    private const PEDIDO_LGPD_REGEX = '/\b(?:apag|exclu|delet|remov)\w* (?:todos )?(?:os )?meus dados\b'
        .'|\b(?:apag|exclu|delet)\w* (?:o )?meu cadastro\b'
        .'|\bacesso aos meus dados\b'
        .'|\bcopia d(?:e|os) (?:todos os )?meus dados\b'
        .'|\bquais (?:sao )?(?:os )?meus dados\b'
        .'|\blgpd\b'
        .'|\bdireito (?:de|ao) esquecimento\b/';

    /**
     * Resposta INTEIRA (normalizada) que é pedido para parar, mas só quando responde a um template nosso:
     * na conversa do atendimento "cancelar", "sair" ou "chega" são respostas comuns.
     */
    private const PARAR_EM_RESPOSTA = [
        'para', 'chega', 'bloquear', 'denunciar', 'sair',
        'cancelar', 'cancelar inscricao', 'descadastrar', 'nao quero', 'nao quero mais',
        'nao quero isso', 'nao quero mais isso', 'nao tenho interesse', 'nao tenho mais interesse',
        'nao quero receber', 'nao quero mais receber', 'quero parar', 'parar de receber',
        'quero parar de receber', 'quero sair', 'sair da lista', 'me exclui',
        'nao quero ofertas', 'nao quero promocoes', 'remover', 'me remove', 'me remova',
    ];

    /**
     * Resposta inteira que é pedido para parar em qualquer conversa: sozinhas, estas palavras não têm
     * outro sentido. "Minha conta vai parar?" não é a mensagem inteira, então não conta. Inclui o botão
     * "Parar promoções" que a própria Meta oferece em template de marketing.
     */
    private const PARAR_SEMPRE = [
        'pare', 'parar', 'parem', 'stop', 'descadastrar', 'me descadastra', 'me descadastre',
        'sair da lista', 'me exclui da lista', 'me tira da lista', 'parar promocoes', 'parar de receber promocoes',
        'nao quero receber promocoes', 'cancelar inscricao', 'unsubscribe', 'denunciar spam',
    ];

    /**
     * Resposta inteira que é "não, agora não" a um template nosso (inclusive os botões negativos dos
     * templates): não é pedido para parar.
     */
    private const NAO_AGORA_LISTA = [
        'nao', 'agora nao', 'prefiro nao', 'nao preciso', 'nao preciso mais', 'nao faz mais',
        'nao faz mais sentido', 'pode arquivar', 'so pausa mesmo', 'nao posso', 'depois', 'mais tarde',
        'nao obrigado', 'nao obrigada', 'hoje nao', 'agora nao posso',
    ];

    /**
     * Pedido para parar inequívoco em qualquer conversa: só expressão que tem a mensagem, o contato ou a
     * lista como objeto. "Não envie mais o boleto", "quero descadastrar o cartão antigo" ou "não quero
     * receber pelo Pix" são atendimento, não pedido para parar.
     */
    private const PARAR_EM_QUALQUER_CONVERSA = '/\b(?:sair|me (?:exclu|remov|tir|retir)\w*) da lista\b'
        .'|\bparar promocoes\b'
        .'|\bme descadastr\w*'
        .'|\bdescadastr\w* (?:meu|este|esse|o meu|do) (?:numero|telefone|contato|whatsapp)\b'
        .'|\bpar(?:a|e|em|ar) de (?:me )?(?:mandar|enviar) (?:mensage\w*|isso|nada|oferta\w*|promoc\w*)\b'
        .'|\bpar(?:a|e|em|ar) de (?:me )?(?:mandar|enviar)$'
        .'|\bnao (?:me )?(?:manda|mande|mandem|envia|envie|enviem) mais (?:nada|mensage\w*|isso|oferta\w*|promoc\w*)\b'
        .'|\bnao (?:me )?(?:manda|mande|mandem|envia|envie|enviem) mais$'
        .'|\bnao quero (?:mais )?receber (?:mais )?(?:nada|mensage\w*|essas mensage\w*|isso|contato|oferta\w*|promoc\w*)\b/';

    /** Enfeite em volta da resposta que não muda o sentido ("Pode parar, por favor"). */
    private const ENFEITES = '/^(?:(?:pode|por favor|favor|ok|ah|olha|oi|ola) )+|(?: (?:por favor|obrigad[oa]|please|valeu|ok|viu))+$/';

    /** Botões explícitos de saída em templates (quick reply): valem como pedido para parar em qualquer data. */
    private const BOTOES_DE_SAIDA = ['denunciar', 'sair da lista', 'parar promocoes', 'nao quero receber', 'parar', 'descadastrar', 'remover da lista'];

    /**
     * Classifica o texto (ou o botão) da mensagem. `$respondeATemplate` = a mensagem responde a um template
     * nosso (cita o wamid dele, ou há template recente para o telefone): só aí palavra solta ("não", "para")
     * tem o sentido da campanha.
     *
     * @param  list<string>  $textos  os textos candidatos: corpo, título do botão, resposta interativa
     */
    public static function classificar(array $textos, bool $respondeATemplate): ?string
    {
        $naoAgora = false;

        foreach ($textos as $texto) {
            $normal = self::normalizar($texto);

            if ($normal === '') {
                continue;
            }

            $nucleo = trim((string) preg_replace(self::ENFEITES, '', $normal));
            $em = fn (array $lista): bool => in_array($normal, $lista, true) || in_array($nucleo, $lista, true);

            if (preg_match(self::PEDIDO_LGPD_REGEX, $normal) === 1) {
                return self::PEDIDO_LGPD;
            }

            if (preg_match(self::PARAR_EM_QUALQUER_CONVERSA, $normal) === 1 || $em(self::PARAR_SEMPRE)) {
                return self::PARAR;
            }

            if (! $respondeATemplate) {
                continue;
            }

            if ($em(self::PARAR_EM_RESPOSTA)) {
                return self::PARAR;
            }

            $naoAgora = $naoAgora || $em(self::NAO_AGORA_LISTA);
        }

        return $naoAgora ? self::NAO_AGORA : null;
    }

    /**
     * Modo histórico (importação da Kapso): só o inequívoco - "parar sempre", a frase com a lista como
     * objeto e os botões explícitos de saída. Sem contexto do template não se lê "não" como recusa.
     *
     * @param  list<string>  $textos
     */
    public static function pedidoParaPararNoHistorico(array $textos, bool $foiBotao): bool
    {
        foreach ($textos as $texto) {
            $normal = self::normalizar($texto);

            if ($normal === '') {
                continue;
            }

            $nucleo = trim((string) preg_replace(self::ENFEITES, '', $normal));

            if (preg_match(self::PARAR_EM_QUALQUER_CONVERSA, $normal) === 1
                || in_array($normal, self::PARAR_SEMPRE, true)
                || in_array($nucleo, self::PARAR_SEMPRE, true)
                || ($foiBotao && (in_array($normal, self::BOTOES_DE_SAIDA, true) || in_array($nucleo, self::BOTOES_DE_SAIDA, true)))) {
                return true;
            }
        }

        return false;
    }

    /** O texto que pediu para parar, normalizado, para guardar como motivo (sem PII: é o botão ou a palavra). */
    public static function motivo(array $textos): string
    {
        foreach ($textos as $texto) {
            $normal = self::normalizar($texto);

            if ($normal !== '') {
                return mb_substr($normal, 0, 50);
            }
        }

        return 'pediu_para_parar';
    }

    /** Minúsculas, sem acento, sem pontuação, espaços colapsados. */
    public static function normalizar(?string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $texto))));
    }
}
