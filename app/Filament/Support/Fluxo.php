<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Executa um passo de FluxoDeCampanha / FluxoDeRegua a partir do painel. Os fluxos falam com o operador
 * por ValidationException (mensagens) e recusam sem permissão por AuthorizationException; aqui as duas
 * viram Notification danger e a ação devolve `false` - nada muda de estado por fora do fluxo.
 */
final class Fluxo
{
    public static function tentar(Closure $passo): bool
    {
        try {
            $passo();
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title('Ação não concluída')
                ->body(self::corpo(Arr::flatten($e->errors())))
                ->persistent()
                ->send();

            return false;
        } catch (AuthorizationException $e) {
            Notification::make()
                ->danger()
                ->title('Sem permissão')
                ->body($e->getMessage())
                ->send();

            return false;
        }

        return true;
    }

    /** O corpo da notificação passa por sanitizeHtml: linhas escapadas, separadas por <br>. @param  list<string>  $linhas */
    public static function corpo(array $linhas): string
    {
        return implode('<br>', array_map(fn (string $linha): string => e($linha), $linhas));
    }
}
