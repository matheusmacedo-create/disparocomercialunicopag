<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Telefone brasileiro para WhatsApp. Mesma anatomia que o KYC aceita (ManyChatService::normalizeBrPhone):
 * DDD 11-99 seguido de fixo com 8 dígitos (começa em 2-5) ou celular com 9 dígitos (começa em 9). O que
 * não se reconhece é recusado - melhor pular o contato do que mandar mensagem a um número quebrado.
 *
 * Formato canônico no banco: E.164 com "+" (+5521998765432). Para a Cloud API, só dígitos.
 */
final class Telefone
{
    /** E.164 com "+", ou null quando não parece um número brasileiro. */
    public static function normalizar(?string $bruto): ?string
    {
        $bruto = trim((string) $bruto);
        $temDdi = str_starts_with($bruto, '+');
        $digitos = preg_replace('/\D/', '', $bruto) ?? '';

        if (! $temDdi) {
            // "00" internacional e zero de operadora/DDD ("0 22 ...").
            $digitos = str_starts_with($digitos, '00') ? substr($digitos, 2) : ltrim($digitos, '0');
        }

        if ($digitos === '') {
            return null;
        }

        // Sem "+", um número nacional ganha o 55. Com "+" o país já veio: ou é 55, ou não é BR
        // (um +1 americano de 11 dígitos se parece com DDD + celular).
        if (! $temDdi && self::pareceNacional($digitos)) {
            $digitos = '55'.$digitos;
        }

        if ((strlen($digitos) === 12 || strlen($digitos) === 13)
            && str_starts_with($digitos, '55')
            && self::pareceNacional(substr($digitos, 2))) {
            return '+'.$digitos;
        }

        return null;
    }

    /**
     * Forma canônica para gravar e comparar: E.164 com o nono dígito quando é celular. O `from` da Meta
     * às vezes vem sem o 9 (552198765432); sem canonizar, o "pare" do webhook não casaria com o envio
     * (+5521998765432) e o contato receberia a próxima campanha.
     */
    public static function canonico(?string $bruto): ?string
    {
        $normal = self::normalizar($bruto);

        if ($normal !== null) {
            return $normal;
        }

        $formas = self::formas((string) $bruto);

        // formas() devolve [cru, com o 9] para celular antigo sem o nono dígito.
        return count($formas) === 2 ? self::normalizar($formas[1]) : null;
    }

    /**
     * Fuso do contato pelo DDD (a janela de horário vale onde ele está: 08:00 de Brasília é 06:00 no
     * Acre). Fora do Brasil ou desconhecido: null.
     */
    public static function fusoDoDdd(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone) ?? '';

        if (strlen($digitos) < 12 || ! str_starts_with($digitos, '55')) {
            return null;
        }

        $ddd = (int) substr($digitos, 2, 2);

        return match (true) {
            $ddd === 68 => 'America/Rio_Branco',
            in_array($ddd, [92, 97], true) => 'America/Manaus',
            in_array($ddd, [65, 66], true) => 'America/Cuiaba',
            $ddd === 67 => 'America/Campo_Grande',
            $ddd === 69 => 'America/Porto_Velho',
            $ddd === 95 => 'America/Boa_Vista',
            default => 'America/Sao_Paulo',
        };
    }

    /** O `to` da Cloud API: só dígitos, com 55. Null quando o telefone não é válido. */
    public static function paraEnvio(?string $telefone): ?string
    {
        $e164 = self::normalizar($telefone);

        return $e164 === null ? null : ltrim($e164, '+');
    }

    /**
     * Chave estável para supressão, dedupe e trilha, inclusive depois da anonimização. HMAC com segredo
     * próprio (DISPARO_HASH_PEPPER): sha256 puro de um celular brasileiro se inverte por enumeração
     * (~10^11 valores) - seria pseudonimização fraca.
     */
    public static function hash(string $telefoneE164): string
    {
        return hash_hmac('sha256', ltrim($telefoneE164, '+'), self::pepper());
    }

    /** Mesmo HMAC para CPF/CNPJ (só dígitos). */
    public static function hashDocumento(string $digitos): string
    {
        return hash_hmac('sha256', $digitos, self::pepper());
    }

    /** Telefone anonimizado: identificador estável que não é dado pessoal ("anon:" + 12 hex do hash). */
    public static function anonimo(string $telefoneE164): string
    {
        return 'anon:'.substr(self::hash($telefoneE164), 0, 12);
    }

    private static function pepper(): string
    {
        $pepper = (string) config('disparo.hash_pepper');

        if ($pepper === '') {
            throw new \RuntimeException('DISPARO_HASH_PEPPER não configurado: sem ele nenhum hash de telefone ou documento é gerado (KB 2.13).');
        }

        return $pepper;
    }

    /** Para log, Discord e saída de comando: +55 21 9****-5432 (DDD e final bastam para conferir). */
    public static function mascarar(?string $telefone): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone) ?? '';

        if ($digitos === '') {
            return '(sem telefone)';
        }

        if (strlen($digitos) < 12 || ! str_starts_with($digitos, '55')) {
            return str_repeat('*', max(0, strlen($digitos) - 4)).substr($digitos, -4);
        }

        $numero = substr($digitos, 4);

        return sprintf('+55 %s %s%s-%s', substr($digitos, 2, 2), $numero[0], str_repeat('*', strlen($numero) - 5), substr($numero, -4));
    }

    /** Exibição completa no painel: +55 (21) 99876-5432. Só para quem pode ver o contato. */
    public static function formatar(?string $telefone): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone) ?? '';

        if (strlen($digitos) < 12 || ! str_starts_with($digitos, '55')) {
            return $telefone === null || $telefone === '' ? '' : '+'.$digitos;
        }

        $numero = substr($digitos, 4);

        return sprintf('+55 (%s) %s-%s', substr($digitos, 2, 2), substr($numero, 0, -4), substr($numero, -4));
    }

    /**
     * As formas em que a Meta pode mandar o mesmo celular no webhook: o wa_id de celular brasileiro antigo
     * às vezes vem SEM o nono dígito (552198765432) enquanto o banco guarda com ele (+5521998765432). O
     * contrário não existe: tirar o 9 de um celular casaria o FIXO de outra pessoa.
     *
     * @return list<string> E.164 com "+"
     */
    public static function formas(string $bruto): array
    {
        $digitos = preg_replace('/\D/', '', $bruto) ?? '';

        if ($digitos === '') {
            return [];
        }

        $formas = ['+'.$digitos];
        $nacional = str_starts_with($digitos, '55') ? substr($digitos, 2) : '';

        if (strlen($nacional) === 10 && preg_match('/^[1-9][1-9][6-9]/', $nacional) === 1) {
            $formas[] = '+55'.substr($nacional, 0, 2).'9'.substr($nacional, 2);
        }

        return $formas;
    }

    private static function pareceNacional(string $nacional): bool
    {
        return preg_match('/^[1-9][1-9](?:[2-5]\d{7}|9\d{8})$/', $nacional) === 1;
    }
}
