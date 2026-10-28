<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Mask;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Throwable;

/**
 * Roda um comando `disparo:*` a partir de uma ação do painel. Os comandos de sincronização e de webhook
 * são de outra frente: se ainda não existirem (ou não aceitarem o argumento do remetente), a ação avisa
 * o operador em vez de estourar a tela.
 */
final class ComandoDoPainel
{
    /**
     * @param  array<string, mixed>  $parametros
     * @return array{ok: bool, saida: string, erro: ?string}
     */
    public static function rodar(string $comando, array $parametros = []): array
    {
        try {
            $codigo = Artisan::call($comando, $parametros);
        } catch (CommandNotFoundException) {
            return ['ok' => false, 'saida' => '', 'erro' => sprintf('O comando "%s" ainda não está disponível nesta versão do sistema.', $comando)];
        } catch (\InvalidArgumentException $e) {
            // O comando existe mas não aceita o parâmetro (ex.: o remetente): roda para todos.
            if ($parametros !== []) {
                return self::rodar($comando, []);
            }

            return ['ok' => false, 'saida' => '', 'erro' => Mask::scrub($e->getMessage())];
        } catch (Throwable $e) {
            return ['ok' => false, 'saida' => self::saida(), 'erro' => Mask::scrub($e->getMessage())];
        }

        return [
            'ok' => $codigo === 0,
            'saida' => self::saida(),
            'erro' => $codigo === 0 ? null : 'O comando terminou com código '.$codigo.'.',
        ];
    }

    private static function saida(): string
    {
        try {
            return mb_substr(trim((string) Artisan::output()), 0, 1500);
        } catch (Throwable) {
            return '';
        }
    }
}
