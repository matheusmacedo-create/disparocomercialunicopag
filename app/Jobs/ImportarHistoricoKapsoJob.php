<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Sender;
use App\Models\User;
use App\Support\Mask;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Importar histórico da Kapso" pelo painel: roda o comando `disparo:importar-historico-kapso` (KB 7)
 * fora da requisição - a importação lê páginas e páginas da Kapso - e avisa quem pediu no sininho.
 * O comando é de outra frente; aqui só a chamada: {sender} [--desde=] [--ate=] [--contatos] [--broadcasts].
 */
class ImportarHistoricoKapsoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const COMANDO = 'disparo:importar-historico-kapso';

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $senderId,
        public readonly ?string $desde = null,
        public readonly ?string $ate = null,
        public readonly bool $contatos = true,
        public readonly bool $broadcasts = true,
        public readonly ?int $userId = null,
    ) {}

    /** @return array<string, mixed> os argumentos como o Artisan::call recebe */
    public function parametros(): array
    {
        return array_filter([
            'sender' => $this->senderId,
            '--desde' => $this->desde,
            '--ate' => $this->ate,
            '--contatos' => $this->contatos,
            '--broadcasts' => $this->broadcasts,
        ], fn (mixed $valor): bool => $valor !== null && $valor !== false);
    }

    public function handle(): void
    {
        $sender = Sender::query()->find($this->senderId);
        $user = $this->userId !== null ? User::query()->find($this->userId) : null;
        $rotulo = $sender?->rotulo() ?? ('remetente #'.$this->senderId);

        try {
            $codigo = Artisan::call(self::COMANDO, $this->parametros());
            $saida = trim(Artisan::output());
        } catch (Throwable $e) {
            Log::warning('Importação do histórico da Kapso não rodou', ['sender_id' => $this->senderId, 'erro' => Mask::scrub($e->getMessage())]);

            $user?->notify(Notification::make()
                ->danger()
                ->title('Histórico da Kapso não importado ('.$rotulo.')')
                ->body(mb_substr(Mask::scrub($e->getMessage()), 0, 500))
                ->toDatabase());

            return;
        }

        $user?->notify(Notification::make()
            ->status($codigo === 0 ? 'success' : 'warning')
            ->title(($codigo === 0 ? 'Histórico da Kapso importado' : 'Importação do histórico terminou com aviso').' ('.$rotulo.')')
            // Só o fim da saída (contagens); o scrubber tira qualquer telefone que escape.
            ->body(mb_substr(Mask::scrub($saida), -800))
            ->toDatabase());
    }
}
