<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Models\SenderQualitySnapshot;
use App\Services\DiscordNotifier;
use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lê na Meta (pela Kapso ou direto) o estado de cada remetente ativo - qualidade, tier, status, número
 * exibido, nome verificado, WABA, saúde - grava o snapshot da hora e reage à transição (KB 2.11, 8):
 *  - qualquer coisa → RED: bloqueio por qualidade (sem sonda; só humano religa) + Discord;
 *  - qualquer coisa → YELLOW: pausa as campanhas MARKETING em andamento do remetente + Discord;
 *  - RED/YELLOW → GREEN: o aquecimento recomeça hoje (teto volta ao inicial) + Discord.
 * `--rapido` lê só os remetentes com campanha em andamento (a cada 10 min): a qualidade que a Meta
 * publica atrasa, e é durante a campanha que ela importa.
 */
final class SincronizarRemetentesCommand extends Command
{
    protected $signature = 'disparo:sincronizar-remetentes {--rapido : Só remetentes com campanha em andamento}';

    protected $description = 'Sincroniza qualidade, tier, status e saúde dos remetentes com a Meta e reage à mudança de qualidade';

    public function handle(WhatsAppClientFactory $clientes, DiscordNotifier $discord): int
    {
        $remetentes = Sender::query()
            ->where('ativo', true)
            ->when($this->option('rapido'), fn ($q) => $q->whereHas('campaigns', fn ($q) => $q->where('status', Campaign::STATUS_EM_ANDAMENTO)))
            ->orderBy('id')
            ->get();

        if ($remetentes->isEmpty()) {
            $this->info($this->option('rapido') ? 'Nenhum remetente com campanha em andamento.' : 'Nenhum remetente ativo.');

            return self::SUCCESS;
        }

        $linhas = [];
        $falhas = 0;

        foreach ($remetentes as $sender) {
            $cliente = $clientes->para($sender);

            if (! $cliente->configurado()) {
                $linhas[] = [$sender->nome, $sender->qualidade, '-', 'sem credencial ou phone_number_id'];
                $falhas++;

                continue;
            }

            $numero = $cliente->numero(fresco: true);

            if ($numero === null) {
                $linhas[] = [$sender->nome, $sender->qualidade, '-', 'provedor não respondeu'];
                $falhas++;

                continue;
            }

            $antes = (string) $sender->qualidade;
            $nova = (string) ($numero['quality_rating'] ?? Sender::QUALIDADE_UNKNOWN);

            $sender->forceFill([
                'qualidade' => $nova,
                'limite_tier' => isset($numero['messaging_limit_tier']) ? mb_substr((string) $numero['messaging_limit_tier'], 0, 30) : $sender->limite_tier,
                'status_meta' => isset($numero['status']) ? mb_substr((string) $numero['status'], 0, 30) : $sender->status_meta,
                'display_phone_number' => isset($numero['display_phone_number']) ? mb_substr((string) $numero['display_phone_number'], 0, 30) : $sender->display_phone_number,
                'verified_name' => isset($numero['verified_name']) ? mb_substr((string) $numero['verified_name'], 0, 255) : $sender->verified_name,
                'waba_id' => filled($sender->waba_id) ? $sender->waba_id : (filled($numero['waba_id'] ?? null) ? mb_substr((string) $numero['waba_id'], 0, 40) : null),
                'saude' => $this->saude($sender, $cliente) ?? $sender->saude,
                'sincronizado_em' => now(),
            ])->save();

            SenderQualitySnapshot::query()->create([
                'sender_id' => $sender->id,
                'qualidade' => $nova,
                'limite_tier' => $sender->limite_tier,
                'status_meta' => $sender->status_meta,
                'medido_em' => now(),
                'created_at' => now(),
            ]);

            $acao = $nova === $antes ? 'sem mudança' : $this->transicao($sender, $antes, $nova, $discord);
            $linhas[] = [$sender->nome, $antes.' → '.$nova, (string) ($sender->limite_tier ?? '-'), $acao];
        }

        $this->table(['Remetente', 'Qualidade', 'Tier', 'Ação'], $linhas);

        return $falhas > 0 && $falhas === $remetentes->count() ? self::FAILURE : self::SUCCESS;
    }

    private function transicao(Sender $sender, string $antes, string $nova, DiscordNotifier $discord): string
    {
        SenderEvent::registrar($sender, 'qualidade', ['de' => $antes, 'para' => $nova, 'limite_tier' => $sender->limite_tier]);

        if ($nova === Sender::QUALIDADE_RED) {
            $sender->bloquearPorQualidade('qualidade RED na Meta');
            $discord->enviar(
                'Qualidade RED: remetente travado',
                sprintf('O remetente "%s" caiu de %s para RED na Meta. Bloqueado sem sonda: nenhuma campanha nem régua sai por ele até alguém com "manage senders" religar, depois de conferir a qualidade no WhatsApp Manager.', $sender->nome, $antes),
                DiscordNotifier::COR_PERIGO,
                [],
                true,
            );

            return 'bloqueado por qualidade (RED)';
        }

        if ($nova === Sender::QUALIDADE_YELLOW) {
            $pausadas = $this->pausarMarketing($sender);
            $discord->enviar(
                'Qualidade YELLOW: campanhas MARKETING pausadas',
                sprintf('O remetente "%s" caiu de %s para YELLOW na Meta. O teto caiu pela metade e %d campanha(s) MARKETING em andamento foram pausadas%s. Retomar é decisão de quem aprova.', $sender->nome, $antes, count($pausadas), $pausadas !== [] ? ': '.implode(', ', $pausadas) : ''),
                DiscordNotifier::COR_ALERTA,
                [],
                true,
            );

            return sprintf('YELLOW: %d campanha(s) MARKETING pausada(s)', count($pausadas));
        }

        if ($nova === Sender::QUALIDADE_GREEN && in_array($antes, [Sender::QUALIDADE_RED, Sender::QUALIDADE_YELLOW], true)) {
            $sender->forceFill(['aquecimento_inicio' => $sender->agora()->toDateString()])->save();
            $discord->enviar(
                'Qualidade voltou a GREEN',
                sprintf('O remetente "%s" voltou de %s para GREEN na Meta. O aquecimento recomeça hoje (teto inicial %d/dia).%s', $sender->nome, $antes, $sender->aquecimento_teto_inicial ?? (int) config('disparo.remetente.aquecimento_teto_inicial', 50), $sender->bloqueadoPorQualidade() ? ' O bloqueio por qualidade continua até alguém religar em Configuração > Remetentes.' : ''),
                DiscordNotifier::COR_SUCESSO,
            );

            return 'GREEN: aquecimento reiniciado';
        }

        return 'transição registrada';
    }

    /** @return list<string> nomes das campanhas pausadas */
    private function pausarMarketing(Sender $sender): array
    {
        $pausadas = [];
        $motivo = 'Qualidade do número caiu para YELLOW na Meta: campanha MARKETING pausada até alguém retomar.';

        foreach ($sender->campaigns()->where('status', Campaign::STATUS_EM_ANDAMENTO)->get() as $campanha) {
            $categoria = strtoupper((string) ($campanha->template_categoria ?: MessageTemplate::query()
                ->where('sender_id', $sender->id)
                ->where('nome', $campanha->template_nome)
                ->where('idioma', $campanha->template_idioma)
                ->value('categoria')));

            if ($categoria !== Sender::CATEGORIA_MARKETING) {
                continue;
            }

            $campanha->forceFill(['status' => Campaign::STATUS_PAUSADA, 'pausada_motivo' => $motivo])->save();
            $campanha->registrarEvento('pausada', ['motivo' => $motivo, 'automatico' => true, 'qualidade' => Sender::QUALIDADE_YELLOW], null);
            $pausadas[] = $campanha->nome;
        }

        return $pausadas;
    }

    /**
     * Saúde do número na Kapso, sem nada que pareça segredo (a resposta é guardada em `senders.saude`).
     *
     * @return array<string, mixed>|null
     */
    private function saude(Sender $sender, WhatsAppClient $cliente): ?array
    {
        if ($sender->provider !== Sender::PROVIDER_KAPSO) {
            return null;
        }

        try {
            $saude = $cliente->saude();
        } catch (WhatsAppException|Throwable $e) {
            $this->line(sprintf('  %s: saúde indisponível (%s)', $sender->nome, mb_substr($e->getMessage(), 0, 120)));

            return null;
        }

        return $saude === [] ? null : self::semSegredos($saude);
    }

    /**
     * @param  array<array-key, mixed>  $dados
     * @return array<array-key, mixed>
     */
    private static function semSegredos(array $dados): array
    {
        $limpo = [];

        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && preg_match('/secret|token|key|password|senha/i', $chave) === 1) {
                continue;
            }

            $limpo[$chave] = is_array($valor) ? self::semSegredos($valor) : $valor;
        }

        return $limpo;
    }
}
