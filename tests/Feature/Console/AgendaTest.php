<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Campaign;
use App\Support\Batimentos;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A agenda de routes/console.php: cada rotina com a expressão, o fuso, o mutex com prazo e o log certos. */
class AgendaTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<int, Event> */
    private function eventos(): Collection
    {
        return collect(app(Schedule::class)->events());
    }

    private function evento(string $trecho): Event
    {
        $evento = $this->eventos()->first(fn (Event $e): bool => str_contains((string) $e->command, $trecho) || $e->description === $trecho);
        $this->assertNotNull($evento, 'agendado: '.$trecho);

        return $evento;
    }

    /** @return array<string, array{string, string, ?int}> */
    public static function rotinas(): array
    {
        return [
            'executar-campanhas' => ['disparo:executar-campanhas', '* * * * *', 5],
            'processar-reguas' => ['disparo:processar-reguas', '*/10 * * * *', 30],
            'processar-reguas --segmentos' => ['disparo:processar-reguas --segmentos', '30 9 * * *', 30],
            'sincronizar-remetentes' => ['disparo:sincronizar-remetentes', '0 * * * *', 30],
            'sincronizar-remetentes --rapido' => ['disparo:sincronizar-remetentes --rapido', '*/10 * * * *', 10],
            'sincronizar-templates' => ['disparo:sincronizar-templates', '0 */6 * * *', 30],
            'importar-historico-kapso --todos' => ['disparo:importar-historico-kapso --todos', '0 3 * * *', 120],
            'importar-historico-kapso --incremental' => ['disparo:importar-historico-kapso --todos --incremental', '*/15 * * * *', 14],
            'podar' => ['disparo:podar', '0 4 * * *', 60],
        ];
    }

    #[DataProvider('rotinas')]
    public function test_cada_rotina_tem_expressao_fuso_mutex_e_log(string $trecho, string $expressao, ?int $mutexMinutos): void
    {
        $evento = $this->evento($trecho);

        $this->assertSame($expressao, $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertSame($mutexMinutos, $evento->expiresAt);
        $this->assertStringEndsWith('disparo-agendado.log', (string) $evento->output);
        $this->assertTrue($evento->shouldAppendOutput);
    }

    public function test_batimentos_do_agendador_e_do_worker(): void
    {
        $agendador = $this->evento('disparo:scheduler-heartbeat');
        $this->assertSame('* * * * *', $agendador->expression);

        $agendador->run(app());
        $this->assertNotNull(Batimentos::agendador());

        $worker = $this->evento('disparo:worker-heartbeat');
        $this->assertSame('*/5 * * * *', $worker->expression);

        // Fila `sync` nos testes: o despacho executa o job na hora.
        $worker->run(app());
        $this->assertNotNull(Batimentos::worker());
    }

    public function test_importacao_incremental_so_roda_com_campanha_em_andamento(): void
    {
        $evento = $this->evento('disparo:importar-historico-kapso --todos --incremental');

        $this->assertFalse($evento->filtersPass(app()));

        Campaign::factory()->emAndamento()->create();
        $this->assertTrue($evento->filtersPass(app()));
    }
}
