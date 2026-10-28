<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Batimentos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Despachado pelo agendador a cada 5 min; só o worker da fila executa. Se o batimento do worker parar
 * enquanto o do agendador segue, o `queue:work` morreu: webhooks e importações ficam "na fila" para sempre.
 */
final class WorkerHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Batimentos::registrarWorker();
    }
}
