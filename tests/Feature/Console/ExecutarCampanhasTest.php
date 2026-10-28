<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** `disparo:executar-campanhas`: roda a campanha em andamento (Kapso falsa), inicia a aprovada vencida, filtra por --campanha. */
class ExecutarCampanhasTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create();
        $this->template = MessageTemplate::factory()->for($this->sender)->create();

        $enviadas = 0;
        Http::fake([
            'api.kapso.test/meta/whatsapp/*/messages' => function () use (&$enviadas) {
                $enviadas++;

                return Http::response(['messages' => [['id' => 'wamid.'.$enviadas]]]);
            },
            'api.kapso.test/meta/whatsapp/*/message_templates*' => Http::response(['data' => [$this->template->definicao() + ['id' => '1']]]),
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*' => Http::response(['data' => ['id' => $this->sender->phone_number_id, 'quality_rating' => 'GREEN', 'kind' => 'production', 'business_account_id' => $this->sender->waba_id]]),
        ]);
    }

    private function campanhaComDestinatarios(int $quantos, string $status = Campaign::STATUS_EM_ANDAMENTO): Campaign
    {
        $campanha = Campaign::factory()->for($this->sender)->create(['template_nome' => $this->template->nome]);
        $campanha->forceFill(['status' => $status, 'tranches_enviadas' => 1, 'total_destinatarios' => $quantos, 'iniciada_em' => $status === Campaign::STATUS_EM_ANDAMENTO ? now() : null])->save();

        foreach (Contact::factory()->count($quantos)->create() as $contato) {
            CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $contato->id, 'telefone' => $contato->telefone, 'variaveis' => ['body.1' => 'Ana'], 'status' => CampaignRecipient::STATUS_PENDENTE]);
        }

        return $campanha->refresh();
    }

    public function test_roda_a_campanha_em_andamento_ate_concluir(): void
    {
        $campanha = $this->campanhaComDestinatarios(2);

        $this->artisan('disparo:executar-campanhas')
            ->expectsOutputToContain('#'.$campanha->id.' '.$campanha->nome)
            ->assertSuccessful();

        $this->assertSame(2, WhatsappMessage::query()->where('status', WhatsappMessage::STATUS_SENT)->count());
        $campanha->refresh();
        $this->assertSame(2, $campanha->enviados);
        $this->assertSame(Campaign::STATUS_CONCLUIDA, $campanha->status);
    }

    public function test_aprovada_com_horario_vencido_comeca_sozinha_e_a_futura_espera(): void
    {
        $vencida = $this->campanhaComDestinatarios(1, Campaign::STATUS_APROVADA);
        $vencida->forceFill(['agendada_para' => now()->subMinute()])->save();
        $futura = $this->campanhaComDestinatarios(1, Campaign::STATUS_APROVADA);
        $futura->forceFill(['agendada_para' => now()->addHour()])->save();

        $this->artisan('disparo:executar-campanhas')->assertSuccessful();

        $vencida->refresh();
        $this->assertSame(Campaign::STATUS_CONCLUIDA, $vencida->status);
        $this->assertNotNull($vencida->iniciada_em);
        $this->assertTrue($vencida->events()->where('evento', 'iniciada')->where('detalhes->automatico', true)->exists());
        $this->assertSame(Campaign::STATUS_APROVADA, $futura->fresh()->status);
        $this->assertSame(1, WhatsappMessage::query()->count());
    }

    public function test_uma_rodada_por_remetente_por_tick_e_filtro_por_campanha(): void
    {
        $primeira = $this->campanhaComDestinatarios(1);
        $primeira->forceFill(['prioridade' => 1])->save();
        $segunda = $this->campanhaComDestinatarios(1);
        $segunda->forceFill(['prioridade' => 9])->save();

        $this->artisan('disparo:executar-campanhas')->assertSuccessful();

        $this->assertSame(Campaign::STATUS_CONCLUIDA, $primeira->fresh()->status);
        $this->assertSame(Campaign::STATUS_EM_ANDAMENTO, $segunda->fresh()->status);

        $this->artisan('disparo:executar-campanhas --campanha='.$segunda->id)->assertSuccessful();
        $this->assertSame(Campaign::STATUS_CONCLUIDA, $segunda->fresh()->status);

        $this->artisan('disparo:executar-campanhas')->expectsOutputToContain('Nenhuma campanha pronta')->assertSuccessful();
    }

    public function test_em_simulacao_avisa_e_nada_sai(): void
    {
        config(['disparo.envio_habilitado' => false]);
        $campanha = $this->campanhaComDestinatarios(2);

        $this->artisan('disparo:executar-campanhas')->expectsOutputToContain('SIMULAÇÃO')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
        $this->assertSame(2, $campanha->recipients()->where('status', CampaignRecipient::STATUS_SIMULADO)->count());
    }
}
