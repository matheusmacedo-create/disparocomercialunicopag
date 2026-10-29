<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Máscaras de PII para painel, log e Discord. Nada de CPF/CNPJ completo fora da tela de quem tem
 * `view pii`.
 */
final class Mask
{
    /** CPF: ***.456.789-**; CNPJ: **.345.678/0001-**. Outro tamanho: só os 3 últimos dígitos. */
    public static function documento(?string $documento): string
    {
        $digitos = preg_replace('/\D/', '', (string) $documento) ?? '';

        if ($digitos === '') {
            return '';
        }

        if (strlen($digitos) === 11) {
            return sprintf('***.%s.%s-**', substr($digitos, 3, 3), substr($digitos, 6, 3));
        }

        if (strlen($digitos) === 14) {
            return sprintf('**.%s.%s/%s-**', substr($digitos, 2, 3), substr($digitos, 5, 3), substr($digitos, 8, 4));
        }

        return str_repeat('*', max(0, strlen($digitos) - 3)).substr($digitos, -3);
    }

    /** CPF/CNPJ formatado, para quem pode ver. */
    public static function documentoFormatado(?string $documento): string
    {
        $digitos = preg_replace('/\D/', '', (string) $documento) ?? '';

        return match (strlen($digitos)) {
            11 => sprintf('%s.%s.%s-%s', substr($digitos, 0, 3), substr($digitos, 3, 3), substr($digitos, 6, 3), substr($digitos, 9, 2)),
            14 => sprintf('%s.%s.%s/%s-%s', substr($digitos, 0, 2), substr($digitos, 2, 3), substr($digitos, 5, 3), substr($digitos, 8, 4), substr($digitos, 12, 2)),
            default => $digitos,
        };
    }

    /**
     * Tira PII de um texto que vai para log, Discord ou coluna de motivo (a Meta ecoa valores de parâmetro
     * recusados nos erros 100/132012/131030): telefones brasileiros, CPF/CNPJ, e-mails e query strings.
     */
    public static function scrub(?string $texto): string
    {
        $texto = (string) $texto;

        if ($texto === '') {
            return '';
        }

        $texto = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[e-mail]', $texto);
        $texto = (string) preg_replace('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/', '[cnpj]', $texto);
        $texto = (string) preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[cpf]', $texto);
        $texto = (string) preg_replace('/\+?55\s?\(?\d{2}\)?\s?9?\d{4}[-\s]?\d{4}\b/', '[telefone]', $texto);
        $texto = (string) preg_replace('/\b\d{12,13}\b/', '[telefone]', $texto);
        $texto = (string) preg_replace('/\(?\b\d{2}\)?\s?9\d{4}[-\s]?\d{4}\b/', '[telefone]', $texto);

        return (string) preg_replace('/(https?:\/\/[^\s?]+)\?[^\s]*/i', '$1?[...]', $texto);
    }

    /** E-mail: ma***@dominio.com */
    public static function email(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '' || ! str_contains($email, '@')) {
            return $email === '' ? '' : '***';
        }

        [$usuario, $dominio] = explode('@', $email, 2);

        return mb_substr($usuario, 0, 2).'***@'.$dominio;
    }
}
