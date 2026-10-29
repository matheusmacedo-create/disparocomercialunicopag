<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Sender;
use App\Models\WebhookCall;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Support\Batimentos;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Confere se o ambiente está de pé de verdade - não só se o código existe (mesmo desenho do
 * `med:diagnostico`). Feito para rodar no Forge depois de cada mudança de ambiente. Sai com erro quando
 * algo obrigatório falha: cron do agendador, worker da fila, pepper dos hashes, remetente Kapso sem
 * segredo ou sem webhook ativo na URL certa.
 */
final class DiagnosticoCommand extends Command
{
    protected $signature = 'disparo:diagnostico';

    protected $description = 'Confere agendador, worker, interruptor de envio, pepper, remetentes, webhooks, campanhas e falhas recentes';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $linhas = [];

    private bool $falhou = false;

    public function handle(WhatsAppClientFactory $clientes): int
    {
        $producao = app()->environment('production');

        $this->conferir('Ambiente', $producao ? 'ok' : 'warn', (string) app()->environment());
        $this->conferir('APP_DEBUG desligado', config('app.debug') ? ($producao ? 'fail' : 'warn') : 'ok', config('app.debug') ? 'ligado' : 'desligado');
        $this->conferir('APP_URL público com https', str_starts_with((string) config('app.url'), 'https://') ? 'ok' : ($producao ? 'fail' : 'warn'), (string) config('app.url'));

        $this->conferirBanco();
        $this->conferirAgendador();
        $this->conferirFila($producao);

        $envio = (bool) config('disparo.envio_habilitado');
        $this->conferir('Interruptor de envio', $envio ? 'ok' : 'warn', $envio ? 'LIGADO: campanhas aprovadas saem de verdade' : 'SIMULAÇÃO (DISPARO_ENVIO_HABILITADO=false): nada sai, campanhas rodam como prévia executada');
        $this->conferir('Pepper dos hashes (DISPARO_HASH_PEPPER)', filled(config('disparo.hash_pepper')) ? 'ok' : 'fail', filled(config('disparo.hash_pepper')) ? 'definido (nunca troque depois do primeiro import)' : 'vazio: nenhum hash de telefone é gerado, nada importa nem bloqueia');
        $this->conferir('API v1 (API_KEY)', filled(config('disparo.api_key')) ? 'ok' : 'warn', filled(config('disparo.api_key')) ? 'definida' : 'vazia: /api/v1 responde 503 (MCP e KYC sem acesso)');
        $this->conferir('Discord (alertas)', filled(config('disparo.discord.webhook_url')) ? 'ok' : 'warn', filled(config('disparo.discord.webhook_url')) ? 'configurado' : 'vazio: alertas só no log');

        $this->conferirRemetentes($clientes);
        $this->conferirCampanhas();
        $this->conferirWebhooks();
        $this->conferirFalhasRecentes();
        $this->conferirPessoas();

        $this->table(['', 'Item', 'Situação'], $this->linhas);

        if ($this->falhou) {
            $this->error('Há itens obrigatórios falhando (✗).');

            return self::FAILURE;
        }

        $this->info('Ambiente ok.');

        return self::SUCCESS;
    }

    private function conferirBanco(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->conferir('Banco', 'fail', $e->getMessage());

            return;
        }

        $this->conferir('Banco', 'ok', DB::connection()->getDriverName().' / '.DB::connection()->getDatabaseName());

        $pendentes = rescue(function (): int {
            $rodadas = DB::table('migrations')->pluck('migration')->all();
            $arquivos = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn (string $f): string => basename($f, '.php'));

            return $arquivos->diff($rodadas)->count();
        }, -1, report: false);

        $this->conferir('Migrações', $pendentes === 0 ? 'ok' : 'fail', $pendentes === 0 ? 'em dia' : ($pendentes < 0 ? 'tabela migrations ausente' : "{$pendentes} pendente(s): rode php artisan migrate --force"));
    }

    private function conferirAgendador(): void
    {
        $batimento = Batimentos::agendador();

        if ($batimento === null) {
            $this->conferir('Agendador (cron schedule:run)', 'fail', 'nunca rodou: crie o cron no Forge (Scheduler, a cada minuto)');

            return;
        }

        $parado = $batimento->lt(now()->subMinutes(5));
        $this->conferir('Agendador (cron schedule:run)', $parado ? 'fail' : 'ok', 'último batimento '.$batimento->timezone('America/Sao_Paulo')->format('d/m H:i').($parado ? ' - parado' : ''));
    }

    private function conferirFila(bool $producao): void
    {
        $conexao = (string) config('queue.default');

        if ($conexao === 'sync') {
            $this->conferir('Fila', $producao ? 'fail' : 'warn', 'sync: importações e jobs rodam dentro da requisição');
        } else {
            $this->conferir('Fila', 'ok', $conexao);
        }

        if ($conexao === 'database') {
            $maisAntigo = rescue(fn () => DB::table('jobs')->whereNull('reserved_at')->min('available_at'), null, report: false);

            if ($maisAntigo !== null && now()->timestamp - (int) $maisAntigo > 300) {
                $minutos = (int) floor((now()->timestamp - (int) $maisAntigo) / 60);
                $this->conferir('Jobs na fila', 'fail', "job esperando há {$minutos} min: o daemon queue:work não está rodando");
            } else {
                $this->conferir('Jobs na fila', 'ok', 'nenhum job parado há mais de 5 min');
            }

            $falhos = (int) rescue(fn () => DB::table('failed_jobs')->count(), 0, report: false);
            $this->conferir('Jobs com falha', $falhos === 0 ? 'ok' : 'warn', $falhos === 0 ? 'nenhum' : "{$falhos} em failed_jobs (php artisan queue:failed)");
        }

        $worker = Batimentos::worker();

        if ($worker === null) {
            $this->conferir('Worker da fila (batimento)', $conexao === 'sync' ? 'warn' : 'fail', 'nunca bateu: o job WorkerHeartbeatJob (a cada 5 min pelo agendador) nunca foi executado por um queue:work');

            return;
        }

        $parado = $worker->lt(now()->subMinutes(15));
        $this->conferir('Worker da fila (batimento)', $parado ? 'fail' : 'ok', 'último batimento '.$worker->timezone('America/Sao_Paulo')->format('d/m H:i').($parado ? ' - parado' : ''));
    }

    private function conferirRemetentes(WhatsAppClientFactory $clientes): void
    {
        $remetentes = Sender::query()->where('ativo', true)->orderBy('id')->get();

        if ($remetentes->isEmpty()) {
            $this->conferir('Remetentes ativos', 'warn', 'nenhum: cadastre em Configuração > Remetentes');

            return;
        }

        $this->conferir('Remetentes ativos', 'ok', (string) $remetentes->count());

        $esperaMeta = filled(config('disparo.provedores.meta.app_secret')) && filled(config('disparo.provedores.meta.webhook_verify_token'));

        foreach ($remetentes as $sender) {
            $item = sprintf('Remetente "%s" (%s)', $sender->nome, $sender->provider);
            $cliente = $clientes->para($sender);

            if (! $cliente->configurado()) {
                $this->conferir($item, 'fail', 'sem credencial ou phone_number_id');

                continue;
            }

            $estado = sprintf('qualidade %s, teto efetivo %d/dia, %d enviados em 24 h%s%s', $sender->qualidade, $sender->tetoEfetivo(), $sender->templatesNasUltimas24h(), $sender->pausado() ? ', PAUSADO' : '', $sender->bloqueadoPorQualidade() ? ' (bloqueio por qualidade)' : '');

            if ($sender->provider === Sender::PROVIDER_META) {
                $this->conferir($item, $esperaMeta ? 'ok' : 'warn', $estado.($esperaMeta ? '; webhook do App configurado' : '; falta META_APP_SECRET/META_WEBHOOK_VERIFY_TOKEN para o webhook do App'));

                continue;
            }

            if (blank($sender->webhook_secret)) {
                $this->conferir($item, 'fail', $estado.'; SEM segredo do webhook: rode disparo:registrar-webhook '.$sender->id);

                continue;
            }

            $this->conferir($item, 'ok', $estado);
            $this->conferirWebhookNaKapso($sender, $clientes);
        }
    }

    private function conferirWebhookNaKapso(Sender $sender, WhatsAppClientFactory $clientes): void
    {
        $item = sprintf('Webhook na Kapso de "%s"', $sender->nome);
        $url = route('webhooks.kapso', ['webhookKey' => $sender->webhook_key]);

        try {
            $webhooks = $clientes->kapso($sender)->webhooks();
        } catch (Throwable $e) {
            $this->conferir($item, 'warn', 'não foi possível conferir na Kapso: '.mb_substr($e->getMessage(), 0, 120));

            return;
        }

        $nosso = collect($webhooks)->first(fn (array $w): bool => ($w['url'] ?? null) === $url);

        if ($nosso === null) {
            $this->conferir($item, 'fail', 'não cadastrado com a URL desta instalação: rode disparo:registrar-webhook '.$sender->id);

            return;
        }

        $ativo = ($nosso['active'] ?? true) !== false;
        $eventos = array_values(array_filter((array) ($nosso['events'] ?? []), 'is_string'));
        $faltam = array_values(array_diff(array_merge((array) config('disparo.provedores.kapso.webhook_events', []), ['whatsapp.contact.marketing_preference_changed']), $eventos));

        $this->conferir($item, $ativo ? ($faltam === [] || $eventos === [] ? 'ok' : 'warn') : 'fail', ($ativo ? 'ativo' : 'INATIVO na Kapso').($faltam !== [] && $eventos !== [] ? '; sem os eventos '.implode(', ', $faltam) : ''));
    }

    private function conferirCampanhas(): void
    {
        $emAndamento = Campaign::query()->where('status', Campaign::STATUS_EM_ANDAMENTO)->get();
        $pausadas = (int) Campaign::query()->where('status', Campaign::STATUS_PAUSADA)->count();
        $aguardando = (int) Campaign::query()->where('status', Campaign::STATUS_AGUARDANDO_APROVACAO)->count();

        $this->conferir(
            'Campanhas',
            'ok',
            sprintf('%d em andamento%s, %d pausada(s), %d aguardando aprovação', $emAndamento->count(), $emAndamento->isNotEmpty() ? ' ('.$emAndamento->map(fn (Campaign $c): string => '#'.$c->id.' '.mb_substr($c->nome, 0, 30))->implode(', ').')' : '', $pausadas, $aguardando),
        );
    }

    private function conferirWebhooks(): void
    {
        $semProcessar = (int) WebhookCall::query()->whereNull('processado_em')->where('created_at', '<', now()->subMinutes(10))->count();
        $ultimo = WebhookCall::query()->max('created_at');

        $this->conferir(
            'Webhooks recebidos',
            $semProcessar === 0 ? 'ok' : 'warn',
            ($ultimo ? 'último em '.CarbonImmutable::parse($ultimo)->timezone('America/Sao_Paulo')->format('d/m H:i') : 'nenhum ainda').($semProcessar > 0 ? "; {$semProcessar} sem processar há mais de 10 min (lote interrompido ou erro: ver log)" : ''),
        );
    }

    private function conferirFalhasRecentes(): void
    {
        $falhas = (int) WhatsappMessage::query()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('status', WhatsappMessage::STATUS_FAILED)
            ->where('codigo_erro', '!=', 'simulado')
            ->where('enviado_em', '>=', now()->subDay())
            ->count();
        $enviadas = (int) WhatsappMessage::query()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
            ->where('enviado_em', '>=', now()->subDay())
            ->count();

        $this->conferir('Falhas de envio em 24 h', $falhas === 0 || ($enviadas > 0 && $falhas / max(1, $enviadas + $falhas) < 0.1) ? 'ok' : 'warn', sprintf('%d falha(s) para %d enviada(s)', $falhas, $enviadas));
    }

    private function conferirPessoas(): void
    {
        $superAdmins = (int) rescue(fn () => Role::findByName(Permissions::ROLE_SUPER_ADMIN)->users()->count(), 0, report: false);
        $this->conferir('Super Admin no painel', $superAdmins > 0 ? 'ok' : 'warn', $superAdmins > 0 ? (string) $superAdmins : 'nenhum: rode php artisan make:filament-user');
    }

    /** @param  'ok'|'warn'|'fail'  $status */
    private function conferir(string $item, string $status, string $detalhe): void
    {
        $this->falhou = $this->falhou || $status === 'fail';
        $this->linhas[] = [match ($status) {
            'ok' => '✓',
            'warn' => '!',
            default => '✗',
        }, $item, $detalhe];
    }
}
