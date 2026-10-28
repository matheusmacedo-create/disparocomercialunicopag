<?php

declare(strict_types=1);

namespace Tests\Feature\Importacao;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Sender;
use App\Models\WebhookCall;
use App\Models\WhatsappMessage;
use App\Services\Importacao\Retencao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetencaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_apaga_conteudo_antigo_anonimiza_lead_parado_e_poda_o_resto(): void
    {
        Storage::fake('local');
        config(['disparo.retencao.conteudo_dias' => 180, 'disparo.retencao.lead_sem_interacao_meses' => 12, 'disparo.retencao.failed_jobs_dias' => 7, 'disparo.retencao.webhook_calls_dias' => 30]);
        $sender = Sender::factory()->create();
        $contato = Contact::factory()->create();

        $velha = WhatsappMessage::query()->create(['sender_id' => $sender->id, 'contact_id' => $contato->id, 'telefone' => $contato->telefone, 'direcao' => 'inbound', 'tipo' => 'text', 'conteudo' => 'oi', 'status' => 'received', 'origem' => 'webhook', 'enviado_em' => now()->subDays(200), 'payload' => ['x' => 1]]);
        $nova = WhatsappMessage::query()->create(['sender_id' => $sender->id, 'contact_id' => $contato->id, 'telefone' => $contato->telefone, 'direcao' => 'inbound', 'tipo' => 'text', 'conteudo' => 'oi', 'status' => 'received', 'origem' => 'webhook', 'enviado_em' => now()->subDays(10)]);

        $campanha = Campaign::factory()->for($sender)->create();
        $destinatario = CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $contato->id, 'telefone' => $contato->telefone, 'status' => 'enviado', 'variaveis' => ['nome' => 'x']]);
        CampaignRecipient::query()->whereKey($destinatario->id)->update(['created_at' => now()->subDays(200)]);

        $leadParado = Contact::factory()->externo()->create(['telefone' => '+5521911111111']);
        $leadRecente = Contact::factory()->externo()->create(['telefone' => '+5521922222222']);
        $leadQueRespondeu = Contact::factory()->externo()->create(['telefone' => '+5521933333333', 'ultima_resposta_em' => now()->subMonths(14)]);
        $leadComCampanhaRespondida = Contact::factory()->externo()->create(['telefone' => '+5521944444444']);
        CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $leadComCampanhaRespondida->id, 'telefone' => $leadComCampanhaRespondida->telefone, 'status' => 'respondido']);
        Contact::query()->whereIn('id', [$leadParado->id, $leadQueRespondeu->id, $leadComCampanhaRespondida->id])->update(['created_at' => now()->subMonths(13)]);

        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(10)],
            ['uuid' => 'b', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDay()],
        ]);
        WebhookCall::query()->create(['provider' => 'kapso', 'created_at' => now()->subDays(40)]);
        WebhookCall::query()->create(['provider' => 'kapso', 'created_at' => now()->subDays(2)]);

        Storage::disk('local')->put('importacoes/1/original.csv', 'telefone');
        $importacao = ContactImport::query()->create(['tipo' => 'csv', 'status' => ContactImport::STATUS_CONCLUIDA, 'arquivo' => 'importacoes/1/original.csv']);

        $contagem = app(Retencao::class)->executar();

        $this->assertSame(['mensagens_sem_conteudo' => 1, 'destinatarios_sem_variaveis' => 1, 'leads_anonimizados' => 1, 'failed_jobs_apagados' => 1, 'webhook_calls_apagados' => 1, 'arquivos_apagados' => 1, 'exportacoes_apagadas' => 0], $contagem);
        $this->assertNull($velha->refresh()->conteudo);
        $this->assertNull($velha->payload);
        $this->assertSame('oi', $nova->refresh()->conteudo);
        $this->assertNull($destinatario->refresh()->variaveis);
        $this->assertSame(Contact::STATUS_ANONIMIZADO, $leadParado->refresh()->status);
        $this->assertSame(Contact::STATUS_ATIVO, $leadRecente->refresh()->status);
        $this->assertSame(Contact::STATUS_ATIVO, $leadQueRespondeu->refresh()->status);
        $this->assertSame(Contact::STATUS_ATIVO, $leadComCampanhaRespondida->refresh()->status);
        $this->assertSame(Contact::STATUS_ATIVO, $contato->refresh()->status, 'seller com conta não é lead');
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, WebhookCall::query()->count());
        Storage::disk('local')->assertMissing('importacoes/1/original.csv');
        $this->assertNull($importacao->refresh()->arquivo);

        // Com 0 meses, lead nenhum é anonimizado.
        config(['disparo.retencao.lead_sem_interacao_meses' => 0]);
        $this->assertSame(0, app(Retencao::class)->executar()['leads_anonimizados']);
    }

    public function test_apaga_exportacoes_de_campanha_mais_velhas_que_o_prazo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exportacoes/campanha-1-velha.csv', 'telefone;status');
        Storage::disk('local')->put('exportacoes/campanha-2-nova.csv', 'telefone;status');
        touch(Storage::disk('local')->path('exportacoes/campanha-1-velha.csv'), now()->subDays(10)->getTimestamp());

        $resultado = app(Retencao::class)->executar();

        $this->assertSame(1, $resultado['exportacoes_apagadas']);
        Storage::disk('local')->assertMissing('exportacoes/campanha-1-velha.csv');
        Storage::disk('local')->assertExists('exportacoes/campanha-2-nova.csv');
    }
}
