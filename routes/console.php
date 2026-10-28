<?php

use App\Jobs\WorkerHeartbeatJob;
use App\Models\Campaign;
use App\Support\Batimentos;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ATENÇÃO produção: o cron `php artisan schedule:run` a cada minuto precisa existir no Forge - sem ele
// estes agendamentos "existem e nunca rodam" (foi o que aconteceu no KYC, KB 8.4 de lá). O batimento do
// agendador no fim deste arquivo é o que o `disparo:diagnostico` lê para acusar isso.
//
// `timezone()` vem ANTES de qualquer horário (dailyAt/between leem o fuso na hora em que são chamados), e
// `withoutOverlapping(N)` sempre com minutos: o mutex padrão dura 24 h, e uma rodada que morre no meio
// calaria o dia inteiro (lição do KYC).

$fuso = 'America/Sao_Paulo';
$log = storage_path('logs/disparo-agendado.log');

// Campanhas: uma rodada por remetente por minuto, com orçamento de 50 s dentro do comando; o mutex de 5
// min só segura processo que morreu.
Schedule::command('disparo:executar-campanhas')
    ->timezone($fuso)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->appendOutputTo($log);

// Réguas: passos vencidos a cada 10 min; gatilhos por segmento reavaliados uma vez por dia.
Schedule::command('disparo:processar-reguas')
    ->timezone($fuso)
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo($log);

Schedule::command('disparo:processar-reguas --segmentos')
    ->timezone($fuso)
    ->dailyAt('09:30')
    ->withoutOverlapping(30)
    ->appendOutputTo($log);

// Qualidade, tier e saúde dos remetentes: de hora em hora todos; a cada 10 min só quem tem campanha em
// andamento (a nota da Meta atrasa, e é durante a campanha que ela importa).
Schedule::command('disparo:sincronizar-remetentes')
    ->timezone($fuso)
    ->hourly()
    ->withoutOverlapping(30)
    ->appendOutputTo($log);

Schedule::command('disparo:sincronizar-remetentes --rapido')
    ->timezone($fuso)
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->appendOutputTo($log);

Schedule::command('disparo:sincronizar-templates')
    ->timezone($fuso)
    ->everySixHours()
    ->withoutOverlapping(30)
    ->appendOutputTo($log);

// Histórico da Kapso (KB 7): completo de madrugada; incremental a cada 15 min enquanto houver campanha em
// andamento, para o teto e a conversa aberta enxergarem o que sai pelo painel da Kapso ou pelo KYC no
// mesmo número. O comando é da frente de importação.
Schedule::command('disparo:importar-historico-kapso --todos')
    ->name('disparo:importar-historico-kapso')
    ->timezone($fuso)
    ->dailyAt('03:00')
    ->withoutOverlapping(120)
    ->appendOutputTo($log);

// Mesmo nome da completa: as duas dividem o mutex e nunca correm juntas sobre o mesmo remetente (03:00 é
// múltiplo de 15).
Schedule::command('disparo:importar-historico-kapso --todos --incremental')
    ->name('disparo:importar-historico-kapso')
    ->timezone($fuso)
    ->everyFifteenMinutes()
    ->when(fn (): bool => Campaign::query()->where('status', Campaign::STATUS_EM_ANDAMENTO)->exists())
    ->withoutOverlapping(14)
    ->appendOutputTo($log);

// Retenção (LGPD): webhook_calls e, quando a frente de importação entrar, contatos e mensagens.
Schedule::command('disparo:podar')
    ->timezone($fuso)
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->appendOutputTo($log);

// Batimento do agendador: prova que o cron `schedule:run` existe e roda.
Schedule::call(fn () => Batimentos::registrarAgendador())
    ->timezone($fuso)
    ->everyMinute()
    ->name('disparo:scheduler-heartbeat');

// Batimento do worker: o agendador despacha, só o `queue:work` executa. Os dois batimentos juntos dizem
// ao `disparo:diagnostico` se cron e worker estão vivos.
Schedule::job(new WorkerHeartbeatJob)
    ->timezone($fuso)
    ->everyFiveMinutes()
    ->name('disparo:worker-heartbeat');
