<?php

namespace Tests\Feature\Segmentos;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use App\Services\Segmentos\ConsultaDeSegmento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** As regras novas do painel: quem recebeu / não recebeu um template e quem esteve numa campanha. */
class ConsultaDeSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private Contact $recebeu;

    private Contact $naoRecebeu;

    protected function setUp(): void
    {
        parent::setUp();

        $sender = Sender::factory()->create();
        $this->recebeu = Contact::factory()->create();
        $this->naoRecebeu = Contact::factory()->create();

        WhatsappMessage::query()->create(['sender_id' => $sender->id, 'contact_id' => null, 'telefone' => $this->recebeu->telefone, 'direcao' => WhatsappMessage::OUTBOUND, 'tipo' => WhatsappMessage::TIPO_TEMPLATE, 'template_nome' => 'oferta_maquininha_v1', 'status' => WhatsappMessage::STATUS_DELIVERED, 'origem' => WhatsappMessage::ORIGEM_HISTORICO_KAPSO, 'enviado_em' => now()->subDays(20)]);
        // Falha não conta como recebido.
        WhatsappMessage::query()->create(['sender_id' => $sender->id, 'contact_id' => $this->naoRecebeu->id, 'telefone' => $this->naoRecebeu->telefone, 'direcao' => WhatsappMessage::OUTBOUND, 'tipo' => WhatsappMessage::TIPO_TEMPLATE, 'template_nome' => 'oferta_maquininha_v1', 'status' => WhatsappMessage::STATUS_FAILED, 'origem' => WhatsappMessage::ORIGEM_CAMPANHA, 'enviado_em' => now()->subDays(2)]);
    }

    public function test_recebeu_e_nao_recebeu_template(): void
    {
        $ids = fn (array $regras): array => ConsultaDeSegmento::query($regras)->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->recebeu->id], $ids(['recebeu_template' => ['nome' => 'oferta_maquininha_v1']]));
        $this->assertSame([$this->naoRecebeu->id], $ids(['nao_recebeu_template' => ['nome' => 'oferta_maquininha_v1']]));
        // Janela de dias: o envio tem 20 dias.
        $this->assertSame([], $ids(['recebeu_template' => ['nome' => 'oferta_maquininha_v1', 'dias' => 10]]));
        $this->assertSame([$this->recebeu->id], $ids(['recebeu_template' => ['nome' => 'oferta_maquininha_v1', 'dias' => 30]]));
        // Nome vazio = regra ignorada.
        $this->assertCount(2, $ids(['recebeu_template' => ['nome' => '', 'dias' => 30]]));
    }

    public function test_esteve_na_campanha_com_status(): void
    {
        $campanha = Campaign::factory()->create();
        CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $this->recebeu->id, 'telefone' => $this->recebeu->telefone, 'status' => CampaignRecipient::STATUS_LIDO]);
        CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $this->naoRecebeu->id, 'telefone' => $this->naoRecebeu->telefone, 'status' => CampaignRecipient::STATUS_FALHOU]);

        $ids = fn (array $regras): array => ConsultaDeSegmento::query($regras)->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->recebeu->id, $this->naoRecebeu->id], $ids(['campanha' => ['campaign_id' => $campanha->id]]));
        $this->assertSame([$this->naoRecebeu->id], $ids(['campanha' => ['campaign_id' => $campanha->id, 'status' => [CampaignRecipient::STATUS_FALHOU]]]));
        $this->assertSame([$this->recebeu->id], $ids(['campanha' => ['campaign_id' => $campanha->id, 'status' => [CampaignRecipient::STATUS_LIDO, CampaignRecipient::STATUS_RESPONDIDO]]]));
    }
}
