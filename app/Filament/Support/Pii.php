<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Mask;
use App\Support\Permissions;
use App\Support\Telefone;

/**
 * O que o painel mostra de dado pessoal depende de `view pii` (KB 2.13, 2.14): telefone, CPF/CNPJ e
 * e-mail saem mascarados para quem não tem a permissão. Porta única: coluna, infolist e título passam
 * por aqui, para o gate não ser esquecido numa tela nova.
 */
final class Pii
{
    public static function podeVer(): bool
    {
        return Permissions::allows(Permissions::VIEW_PII);
    }

    public static function telefone(?string $telefone): string
    {
        $telefone = trim((string) $telefone);

        if ($telefone === '') {
            return '—';
        }

        // Contato anonimizado: o "telefone" é um identificador que não é dado pessoal (Telefone::anonimo).
        if (str_starts_with($telefone, 'anon:')) {
            return 'Anonimizado';
        }

        return self::podeVer() ? Telefone::formatar($telefone) : Telefone::mascarar($telefone);
    }

    public static function documento(?string $documento): ?string
    {
        if (blank($documento)) {
            return null;
        }

        return self::podeVer() ? Mask::documentoFormatado($documento) : Mask::documento($documento);
    }

    public static function email(?string $email): ?string
    {
        if (blank($email)) {
            return null;
        }

        return self::podeVer() ? $email : Mask::email($email);
    }
}
