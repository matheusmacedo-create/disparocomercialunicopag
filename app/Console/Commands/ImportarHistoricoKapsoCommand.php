<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactImport;
use App\Models\Sender;
use App\Services\Importacao\ImportadorKapso;
use App\Support\Mask;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Importa o histórico de um número da Kapso (KB 7): conversas, mensagens (metadados; o texto só passa
 * pelo classificador), contatos e broadcasts. Relatório sem PII.
 */
class ImportarHistoricoKapsoCommand extends Command
{
    protected $signature = 'disparo:importar-historico-kapso
        {sender? : Remetente (id, phone_number_id ou nome)}
        {--todos : Todos os remetentes Kapso ativos}
        {--desde= : Início da janela (ISO 8601 ou data); sem ele, a janela inicial configurada}
        {--ate= : Fim da janela (padrão: agora)}
        {--incremental : Continua de historico_importado_ate (menos a folga)}
        {--contatos : Também completa nomes pela lista de contatos do número}
        {--broadcasts : Também importa os broadcasts do painel da Kapso}';

    protected $description = 'Importa o histórico (conversas, mensagens, contatos, broadcasts) de um número da Kapso.';

    public function handle(ImportadorKapso $importador): int
    {
        $senders = $this->remetentes();

        if ($senders === null) {
            return self::FAILURE;
        }

        if ($senders->isEmpty()) {
            $this->warn('Nenhum remetente Kapso para importar.');

            return self::SUCCESS;
        }

        $falhas = 0;

        foreach ($senders as $sender) {
            $this->line(sprintf('Remetente "%s" (%s): importando...', $sender->nome, $sender->display_phone_number ?: $sender->phone_number_id));

            try {
                $importacao = $importador->importar($sender, [
                    'desde' => $this->option('desde') ?: null,
                    'ate' => $this->option('ate') ?: null,
                    'incremental' => (bool) $this->option('incremental'),
                    'contatos' => (bool) $this->option('contatos'),
                    'broadcasts' => (bool) $this->option('broadcasts'),
                ]);
            } catch (Throwable $e) {
                $falhas++;
                $this->error(sprintf('Remetente "%s": falhou - %s', $sender->nome, Mask::scrub($e->getMessage())));

                continue;
            }

            $this->relatorio($importacao);
        }

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function relatorio(ContactImport $importacao): void
    {
        $r = (array) ($importacao->resultado ?? []);
        $p = (array) ($importacao->parametros ?? []);

        $this->info(sprintf('Importação #%d concluída (janela %s → %s%s).', $importacao->id, $p['desde'] ?? '?', $p['ate'] ?? '?', ! empty($p['incremental']) ? ', incremental' : ''));

        $linhas = [
            ['Páginas lidas / esperas de rate limit', ($r['paginas'] ?? 0).' / '.($r['esperas'] ?? 0)],
            ['Conversas lidas', (string) ($r['conversas']['lidas'] ?? 0)],
            ['Contatos criados / atualizados', ($r['contatos']['criados'] ?? 0).' / '.($r['contatos']['atualizados'] ?? 0)],
            ['Mensagens lidas / novas / já existiam', ($r['mensagens']['lidas'] ?? 0).' / '.($r['mensagens']['novas'] ?? 0).' / '.($r['mensagens']['ja_existiam'] ?? 0)],
            ['  inbound / outbound / templates', ($r['mensagens']['inbound'] ?? 0).' / '.($r['mensagens']['outbound'] ?? 0).' / '.($r['mensagens']['templates'] ?? 0)],
            ['Respostas a template nosso', (string) ($r['classificadas']['em_resposta_a_template'] ?? 0)],
            ['Pediram para parar / pedidos LGPD / "agora não"', ($r['classificadas']['parar'] ?? 0).' / '.($r['classificadas']['pedido_lgpd'] ?? 0).' / '.($r['classificadas']['nao_agora'] ?? 0)],
            ['Recusas a MARKETING registradas', (string) ($r['recusas'] ?? 0)],
            ['Sem WhatsApp (131026) / carências (131049)', ($r['sem_whatsapp'] ?? 0).' / '.($r['carencias'] ?? 0)],
        ];

        foreach ((array) ($r['supressoes'] ?? []) as $motivo => $total) {
            $linhas[] = ['Supressões novas: '.$motivo, (string) $total];
        }

        if (! empty($p['broadcasts'])) {
            $b = (array) ($r['broadcasts'] ?? []);
            $linhas[] = ['Broadcasts lidos / fora da janela', ($b['lidos'] ?? 0).' / '.($b['fora_da_janela'] ?? 0)];
            $linhas[] = ['Destinatários: casados / criados / já existiam', ($b['casados'] ?? 0).' / '.($b['criados'] ?? 0).' / '.($b['ja_existiam'] ?? 0)];
        }

        $this->table(['Item', 'Total'], $linhas);
    }

    /** @return Collection<int, Sender>|null */
    private function remetentes(): ?Collection
    {
        if ($this->option('todos')) {
            return Sender::query()->where('provider', Sender::PROVIDER_KAPSO)->where('ativo', true)->orderBy('id')->get();
        }

        $ref = trim((string) $this->argument('sender'));

        if ($ref === '') {
            $this->error('Informe o remetente (id, phone_number_id ou nome) ou use --todos.');

            return null;
        }

        $sender = Sender::query()
            ->where(fn ($q) => $q->where('phone_number_id', $ref)->orWhere('nome', $ref)->when(ctype_digit($ref), fn ($q) => $q->orWhere('id', (int) $ref)))
            ->first();

        if ($sender === null) {
            $this->error('Remetente não encontrado: '.$ref);

            return null;
        }

        if ($sender->provider !== Sender::PROVIDER_KAPSO) {
            $this->error('O remetente "'.$sender->nome.'" não é da Kapso: não há histórico para importar.');

            return null;
        }

        return new Collection([$sender]);
    }
}
