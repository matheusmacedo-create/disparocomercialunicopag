<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/** Quem fez: nome e e-mail do usuário logado no momento, ou `sistema` nas rotinas. Para as trilhas. */
final class Ator
{
    public static function atual(?int $userId = null): string
    {
        $userId ??= auth()->id();

        if ($userId === null) {
            return 'sistema';
        }

        $user = User::query()->find($userId);

        return $user === null ? 'usuário #'.$userId : mb_substr($user->name.' <'.$user->email.'>', 0, 160);
    }
}
