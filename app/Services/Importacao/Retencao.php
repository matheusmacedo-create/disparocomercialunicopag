<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\WebhookCall;
use App\Models\WhatsappMessage;
use App\Services\Contatos\AnonimizarContato;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Retenção (LGPD, Art. 15 e 16; config `disparo.retencao`): o que a rotina diária apaga.
 *
 *  - `conteudo`/`payload` das mensagens e `variaveis` dos destinatários mais velhos que
 *    `conteudo_dias` viram nulos (ficam os metadados: direção, template, status, datas);
 *  - lead de origem externa (unicodrop/csv) sem nenhuma interação (sem resposta, sem consentimento, sem
 *    conta, sem destinatário respondido) há mais de `lead_sem_interacao_meses` é anonimizado (0 = nunca);
 *  - `failed_jobs` mais velhos que `failed_jobs_dias` e `webhook_calls` mais velhos que
 *    `webhook_calls_dias` são apagados;
 *  - arquivo original de importação que sobrou no disco é apagado.
 *
 * Chamada por `disparo:podar`. Devolve as contagens para a saída do comando.
 */
final class Retencao
{
    public function __construct(private readonly AnonimizarContato $anonimizar) {}

    /** @return array<string, int> */
    public function executar(): array
    {
        $contagem = [
            'mensagens_sem_conteudo' => 0,
            'destinatarios_sem_variaveis' => 0,
            'leads_anonimizados' => 0,
            'failed_jobs_apagados' => 0,
            'webhook_calls_apagados' => 0,
            'arquivos_apagados' => 0,
            'exportacoes_apagadas' => 0,
        ];

        $dias = (int) config('disparo.retencao.conteudo_dias', 180);

        if ($dias > 0) {
            $corte = now()->subDays($dias);

            $contagem['mensagens_sem_conteudo'] = WhatsappMessage::query()
                ->where(fn ($q) => $q->whereNotNull('conteudo')->orWhereNotNull('payload'))
                ->where(fn ($q) => $q->where('enviado_em', '<', $corte)->orWhere(fn ($q) => $q->whereNull('enviado_em')->where('created_at', '<', $corte)))
                ->update(['conteudo' => null, 'payload' => null]);

            $contagem['destinatarios_sem_variaveis'] = CampaignRecipient::query()
                ->whereNotNull('variaveis')
                ->where('created_at', '<', $corte)
                ->update(['variaveis' => null]);
        }

        $meses = (int) config('disparo.retencao.lead_sem_interacao_meses', 12);

        if ($meses > 0) {
            $corte = now()->subMonths($meses);

            Contact::query()
                ->where('status', Contact::STATUS_ATIVO)
                ->whereIn('origem', Contact::ORIGENS_EXTERNAS)
                ->where('tipo', 'lead')
                ->whereNull('ultima_resposta_em')
                ->whereNull('consentimento_em')
                ->whereNull('consentimento_origem')
                ->whereNull('account_id')
                ->where('created_at', '<', $corte)
                ->whereDoesntHave('campaignRecipients', fn ($q) => $q->where('status', CampaignRecipient::STATUS_RESPONDIDO))
                ->chunkById(200, function ($leads) use (&$contagem, $meses): void {
                    foreach ($leads as $lead) {
                        try {
                            $this->anonimizar->pelaRotina($lead, sprintf('retenção: lead sem interação há mais de %d meses', $meses));
                            $contagem['leads_anonimizados']++;
                        } catch (Throwable $e) {
                            Log::warning('Retenção não conseguiu anonimizar o lead', ['contato' => $lead->id, 'erro' => $e->getMessage()]);
                        }
                    }
                });
        }

        $diasFailed = (int) config('disparo.retencao.failed_jobs_dias', 7);

        if ($diasFailed > 0 && Schema::hasTable('failed_jobs')) {
            $contagem['failed_jobs_apagados'] = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($diasFailed))->delete();
        }

        $diasWebhook = (int) config('disparo.retencao.webhook_calls_dias', 30);

        if ($diasWebhook > 0) {
            $contagem['webhook_calls_apagados'] = WebhookCall::query()->where('created_at', '<', now()->subDays($diasWebhook))->delete();
        }

        foreach (ContactImport::query()->whereNotNull('arquivo')->whereIn('status', [ContactImport::STATUS_CONCLUIDA, ContactImport::STATUS_FALHOU])->get() as $importacao) {
            Storage::disk('local')->delete((string) $importacao->arquivo);
            $importacao->forceFill(['arquivo' => null])->save();
            $contagem['arquivos_apagados']++;
        }

        // Exportações de campanha (CSV com telefone, às vezes em claro) mais velhas que o prazo.
        $diasExportacoes = max(1, (int) config('disparo.retencao.exportacoes_dias', 7));

        foreach (Storage::disk('local')->files('exportacoes') as $arquivo) {
            if (Storage::disk('local')->lastModified($arquivo) < now()->subDays($diasExportacoes)->getTimestamp()) {
                Storage::disk('local')->delete($arquivo);
                $contagem['exportacoes_apagadas']++;
            }
        }

        return $contagem;
    }
}
