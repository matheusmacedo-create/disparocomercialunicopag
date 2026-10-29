<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AchaRemetente;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Services\DiscordNotifier;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Console\Command;

/**
 * Cache dos templates da WABA de cada remetente (KB 4.5): o que a Meta devolve vira
 * `message_templates`; template que sumiu da WABA vira DISABLED; categoria que mudou (a Meta reclassifica
 * depois de aprovado - lição do KYC) alerta no Discord, porque muda custo, fila e preferência do usuário.
 * O envio reconfere na Meta; este cache é do painel, da prévia e da API.
 */
final class SincronizarTemplatesCommand extends Command
{
    use AchaRemetente;

    protected $signature = 'disparo:sincronizar-templates {sender? : Só este remetente (id, phone_number_id ou nome)}';

    protected $description = 'Sincroniza o cache dos templates da Meta de cada remetente ativo';

    public function handle(WhatsAppClientFactory $clientes, DiscordNotifier $discord): int
    {
        $referencia = $this->argument('sender');

        if ($referencia !== null) {
            $sender = $this->acharRemetente((string) $referencia);

            if ($sender === null) {
                $this->error('Remetente não encontrado: '.$referencia);

                return self::FAILURE;
            }

            $remetentes = collect([$sender]);
        } else {
            $remetentes = Sender::query()->where('ativo', true)->orderBy('id')->get();
        }

        $linhas = [];
        $falhas = 0;

        foreach ($remetentes as $sender) {
            $cliente = $clientes->para($sender);

            if (! $cliente->configurado()) {
                $linhas[] = [$sender->nome, '-', '-', '-', '-', 'sem credencial ou phone_number_id'];
                $falhas++;

                continue;
            }

            try {
                $definicoes = $cliente->templates();
            } catch (WhatsAppException $e) {
                $linhas[] = [$sender->nome, '-', '-', '-', '-', mb_substr($e->getMessage(), 0, 80)];
                $falhas++;

                continue;
            }

            $contagem = ['novos' => 0, 'atualizados' => 0, 'desativados' => 0, 'reclassificados' => 0];
            $vistos = [];

            if ($definicoes === [] && $sender->templates()->exists()) {
                // Lista vazia com cache cheio é mais provável um problema da leitura do que uma WABA zerada.
                $linhas[] = [$sender->nome, 0, 0, 0, 0, 'a Meta devolveu lista vazia: nada desativado por precaução'];

                continue;
            }

            foreach ($definicoes as $definicao) {
                if (blank($definicao['name'] ?? null)) {
                    continue;
                }

                $anterior = MessageTemplate::query()
                    ->where('sender_id', $sender->id)
                    ->where('nome', (string) $definicao['name'])
                    ->where('idioma', (string) ($definicao['language'] ?? 'pt_BR'))
                    ->first();

                $template = MessageTemplate::sincronizar($sender, $definicao);
                $vistos[] = $template->id;

                if ($anterior === null) {
                    $contagem['novos']++;

                    continue;
                }

                $contagem['atualizados']++;

                if ($anterior->categoria !== '' && $anterior->categoria !== $template->categoria) {
                    $contagem['reclassificados']++;
                    $discord->enviar(
                        'Template reclassificado pela Meta',
                        sprintf('No remetente "%s", o template "%s" (%s) passou de %s para %s. Campanha aprovada com ele volta para aprovação na próxima rodada; confira custo e público.', $sender->nome, $template->nome, $template->idioma, $anterior->categoria, $template->categoria),
                        DiscordNotifier::COR_ALERTA,
                        [],
                        true,
                    );
                }
            }

            $contagem['desativados'] = MessageTemplate::query()
                ->where('sender_id', $sender->id)
                ->whereNotIn('id', $vistos)
                ->where('status', '!=', 'DISABLED')
                ->update(['status' => 'DISABLED', 'sincronizado_em' => now()]);

            $sender->forceFill(['templates_sincronizados_em' => now()])->save();

            $linhas[] = [$sender->nome, $contagem['novos'], $contagem['atualizados'], $contagem['desativados'], $contagem['reclassificados'], 'ok'];
        }

        $this->table(['Remetente', 'Novos', 'Atualizados', 'Desativados', 'Reclassificados', 'Situação'], $linhas);

        return $falhas > 0 && $falhas === $remetentes->count() ? self::FAILURE : self::SUCCESS;
    }
}
