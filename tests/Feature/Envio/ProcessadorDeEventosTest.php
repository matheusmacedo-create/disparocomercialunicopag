<?php

declare(strict_types=1);

namespace Tests\Feature\Envio;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Reguas\ProcessadorDeReguas;
use App\Services\Webhooks\ClassificadorDeResposta;
use App\Services\Webhooks\EventoDeMensagem;
use App\Services\Webhooks\ProcessadorDeEventos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O processador de eventos chamado direto, sem HTTP (KB 10): entrega que só avança, lida antes de
 * entregue, falha tardia, resposta ao template citado vs o último em 7 dias, opt-in por botão, régua que
 * sai ao responder e passo de texto que a resposta dispara.
 */
class ProcessadorDeEventosTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private Contact $contato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create()->refresh();
        $this->contato = Contact::factory()->create();
    }

    /** @param  array<string, mixed>  $extra */
    private function template(string $wamid, array $extra = []): WhatsappMessage
    {
        return WhatsappMessage::query()->create($extra + [
            'sender_id' => $this->sender->id,
            'contact_id' => $this->contato->id,
            'telefone' => $this->contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'wamid' => $wamid,
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'template_nome' => 'oferta_maquininha_v1',
            'status' => WhatsappMessage::STATUS_SENT,
            'origem' => WhatsappMessage::ORIGEM_CAMPANHA,
            'enviado_em' => now()->subHour(),
            'payload' => ['categoria' => Sender::CATEGORIA_MARKETING],
        ]);
    }

    private function evento(string $wamid, string $status, ?int $timestamp = null, ?string $codigo = null, ?string $erro = null): array
    {
        return app(ProcessadorDeEventos::class)->processar($this->sender, new EventoDeMensagem(
            tipo: EventoDeMensagem::STATUS, wamid: $wamid, status: $status, timestamp: $timestamp ?? now()->getTimestamp(), telefone: ltrim($this->contato->telefone, '+'), codigoErro: $codigo, erro: $erro,
        ));
    }

    /** @param  list<string>  $textos */
    private function resposta(array $textos, ?string $contextWamid = null, bool $foiBotao = false, string $wamid = 'wamid.in'): array
    {
        return app(ProcessadorDeEventos::class)->processar($this->sender, new EventoDeMensagem(
            tipo: EventoDeMensagem::RECEBIDA, wamid: $wamid, timestamp: now()->getTimestamp(), telefone: ltrim($this->contato->telefone, '+'),
            tipoMensagem: $foiBotao ? 'button' : 'text', textos: $textos, contextWamid: $contextWamid, foiBotao: $foiBotao,
        ));
    }

    public function test_entrega_so_avanca_e_lida_implica_entregue(): void
    {
        $campanha = Campaign::factory()->for($this->sender)->emAndamento()->create();
        $destinatario = CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $this->contato->id, 'telefone' => $this->contato->telefone, 'status' => CampaignRecipient::STATUS_ENVIADO, 'enviado_em' => now()->subHour()]);
        $campanha->forceFill(['enviados' => 1])->save();
        $mensagem = $this->template('wamid.a', ['campaign_recipient_id' => $destinatario->id]);

        $this->evento('wamid.a', WhatsappMessage::STATUS_DELIVERED, now()->subMinutes(30)->getTimestamp());
        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, $mensagem->fresh()->status);
        $this->assertSame(CampaignRecipient::STATUS_ENTREGUE, $destinatario->fresh()->status);
        $this->assertSame(1, $this->contato->fresh()->entregues_total);
        $this->assertSame(1, $campanha->fresh()->entregues);

        // `sent` atrasado não volta a escada.
        $resultado = $this->evento('wamid.a', WhatsappMessage::STATUS_SENT, now()->subMinutes(40)->getTimestamp());
        $this->assertFalse($resultado['mudou']);
        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, $mensagem->fresh()->status);

        $this->evento('wamid.a', WhatsappMessage::STATUS_READ);
        $mensagem->refresh();
        $this->assertSame(WhatsappMessage::STATUS_READ, $mensagem->status);
        $this->assertNotNull($mensagem->lido_em);
        $this->assertSame(CampaignRecipient::STATUS_LIDO, $destinatario->fresh()->status);
        $this->assertSame(1, $this->contato->fresh()->lidos_total);
        $this->assertSame(1, $campanha->fresh()->lidos);
        // Repetição da Kapso é inócua.
        $this->assertFalse($this->evento('wamid.a', WhatsappMessage::STATUS_READ)['mudou']);
        $this->assertSame(1, $this->contato->fresh()->lidos_total);
    }

    public function test_lida_antes_de_entregue_marca_os_dois(): void
    {
        $mensagem = $this->template('wamid.b');

        $this->evento('wamid.b', WhatsappMessage::STATUS_READ);

        $mensagem->refresh();
        $this->assertSame(WhatsappMessage::STATUS_READ, $mensagem->status);
        $this->assertNotNull($mensagem->entregue_em);
        $this->assertNotNull($mensagem->lido_em);
        $contato = $this->contato->fresh();
        $this->assertSame(1, $contato->entregues_total);
        $this->assertSame(1, $contato->lidos_total);

        // `delivered` que chega depois não mexe em nada.
        $this->evento('wamid.b', WhatsappMessage::STATUS_DELIVERED);
        $this->assertSame(WhatsappMessage::STATUS_READ, $mensagem->fresh()->status);
    }

    public function test_falha_tardia_nao_apaga_entrega_confirmada(): void
    {
        $campanha = Campaign::factory()->for($this->sender)->emAndamento()->create();
        $destinatario = CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => $this->contato->id, 'telefone' => $this->contato->telefone, 'status' => CampaignRecipient::STATUS_ENVIADO, 'enviado_em' => now()->subHour()]);
        $mensagem = $this->template('wamid.c', ['campaign_recipient_id' => $destinatario->id]);

        $this->evento('wamid.c', WhatsappMessage::STATUS_DELIVERED);
        $this->evento('wamid.c', WhatsappMessage::STATUS_FAILED, null, '131026', 'Message undeliverable');

        $mensagem->refresh();
        $this->assertSame(WhatsappMessage::STATUS_DELIVERED, $mensagem->status);
        $this->assertNull($mensagem->codigo_erro);
        $this->assertSame(CampaignRecipient::STATUS_ENTREGUE, $destinatario->fresh()->status);
        $this->assertNull($this->contato->fresh()->sem_whatsapp_em);

        // Falha de verdade (sem entrega) marca o destinatário, o contato e a campanha.
        $outro = CampaignRecipient::query()->create(['campaign_id' => $campanha->id, 'contact_id' => Contact::factory()->create()->id, 'telefone' => '+5521977777777', 'status' => CampaignRecipient::STATUS_ENVIADO, 'enviado_em' => now()]);
        $this->template('wamid.d', ['campaign_recipient_id' => $outro->id, 'contact_id' => $outro->contact_id, 'telefone' => $outro->telefone]);
        $campanha->forceFill(['enviados' => 2])->save();
        $this->evento('wamid.d', WhatsappMessage::STATUS_FAILED, null, '131026', 'Message undeliverable');
        $this->assertSame(CampaignRecipient::STATUS_FALHOU, $outro->fresh()->status);
        $this->assertSame('131026', $outro->fresh()->codigo_erro);
        $this->assertNotNull($outro->contact->fresh()->sem_whatsapp_em);
        $this->assertSame(1, $campanha->fresh()->falhas);
        $this->assertSame(1, $campanha->fresh()->enviados);
    }

    public function test_status_de_wamid_desconhecido_vira_linha_webhook_para_o_teto(): void
    {
        $this->evento('wamid.kyc', WhatsappMessage::STATUS_SENT);

        $mensagem = WhatsappMessage::query()->where('wamid', 'wamid.kyc')->sole();
        $this->assertSame(WhatsappMessage::ORIGEM_WEBHOOK, $mensagem->origem);
        $this->assertSame($this->contato->id, $mensagem->contact_id);
        $this->assertSame(WhatsappMessage::OUTBOUND, $mensagem->direcao);
    }

    public function test_resposta_ao_template_citado_vence_o_ultimo_em_sete_dias(): void
    {
        $campanhaAntiga = Campaign::factory()->for($this->sender)->emAndamento()->create();
        $campanhaNova = Campaign::factory()->for($this->sender)->emAndamento()->create();
        $antigo = CampaignRecipient::query()->create(['campaign_id' => $campanhaAntiga->id, 'contact_id' => $this->contato->id, 'telefone' => $this->contato->telefone, 'status' => CampaignRecipient::STATUS_ENTREGUE, 'enviado_em' => now()->subDays(10)]);
        $novo = CampaignRecipient::query()->create(['campaign_id' => $campanhaNova->id, 'contact_id' => $this->contato->id, 'telefone' => $this->contato->telefone, 'status' => CampaignRecipient::STATUS_ENTREGUE, 'enviado_em' => now()->subDays(2)]);
        $msgAntiga = $this->template('wamid.antigo', ['campaign_recipient_id' => $antigo->id, 'enviado_em' => now()->subDays(10), 'status' => WhatsappMessage::STATUS_DELIVERED]);
        $msgNova = $this->template('wamid.novo', ['campaign_recipient_id' => $novo->id, 'enviado_em' => now()->subDays(2), 'status' => WhatsappMessage::STATUS_DELIVERED]);

        // Citou o antigo: é a ele que responde, mesmo fora dos 7 dias.
        $resultado = $this->resposta(['Quero sim'], 'wamid.antigo', false, 'wamid.in1');
        $this->assertSame($msgAntiga->id, $resultado['responde_a']);
        $this->assertSame(CampaignRecipient::STATUS_RESPONDIDO, $antigo->fresh()->status);
        $this->assertSame(CampaignRecipient::STATUS_ENTREGUE, $novo->fresh()->status);
        $this->assertSame(1, $campanhaAntiga->fresh()->respondidos);

        // Sem citação: o último template em 7 dias.
        $resultado = $this->resposta(['E aí?'], null, false, 'wamid.in2');
        $this->assertSame($msgNova->id, $resultado['responde_a']);
        $this->assertSame(CampaignRecipient::STATUS_RESPONDIDO, $novo->fresh()->status);
        $this->assertNull($resultado['classificacao']);

        $contato = $this->contato->fresh();
        $this->assertSame(2, $contato->respostas_total);
        $this->assertNotNull($contato->ultima_resposta_em);
        $this->assertSame(2, WhatsappMessage::query()->where('direcao', WhatsappMessage::INBOUND)->count());

        // "Não" a um MARKETING é recusa (carência), não bloqueio; "pare" bloqueia para sempre.
        $this->resposta(['Não'], null, false, 'wamid.in3');
        $this->assertNotNull($this->contato->fresh()->recusou_em);
        $this->assertSame(Contact::STATUS_ATIVO, $this->contato->fresh()->status);

        $resultado = $this->resposta(['Pare'], null, false, 'wamid.in4');
        $this->assertSame(ClassificadorDeResposta::PARAR, $resultado['classificacao']);
        $this->assertSame(Contact::STATUS_OPTOUT, $this->contato->fresh()->status);
        $this->assertNotNull(Suppression::bloqueia($this->contato->telefone, $this->sender, Sender::CATEGORIA_UTILITY));

        // Mais de 7 dias sem template e sem citação: não é resposta a nada nosso.
        $outroContato = Contact::factory()->create();
        $this->contato = $outroContato;
        $this->template('wamid.velho', ['contact_id' => $outroContato->id, 'telefone' => $outroContato->telefone, 'enviado_em' => now()->subDays(9)]);
        $resultado = $this->resposta(['Cancelar'], null, false, 'wamid.in5');
        $this->assertNull($resultado['responde_a']);
        $this->assertNull($resultado['classificacao'], '"cancelar" solto no atendimento não bloqueia');
        $this->assertSame(Contact::STATUS_ATIVO, $outroContato->fresh()->status);
    }

    public function test_opt_in_pelo_botao_quero_receber_registra_consentimento(): void
    {
        $this->contato = Contact::factory()->externo()->create();
        $this->template('wamid.apresentacao');

        $resultado = $this->resposta(['Quero receber'], 'wamid.apresentacao', true);

        $this->assertTrue($resultado['optin']);
        $contato = $this->contato->fresh();
        $this->assertSame('whatsapp_optin', $contato->consentimento_origem);
        $this->assertSame('whatsapp', $contato->consentimento_canal);
        $this->assertNotNull($contato->consentimento_em);
        $this->assertStringContainsString('wamid.apresentacao', (string) $contato->consentimento_detalhes);
        $this->assertTrue($contato->temConsentimento());

        // Texto digitado (não botão) não é opt-in.
        $outro = Contact::factory()->externo()->create();
        $this->contato = $outro;
        $this->template('wamid.apresentacao2');
        $resultado = $this->resposta(['quero receber'], null, false, 'wamid.in9');
        $this->assertArrayNotHasKey('optin', $resultado);
        $this->assertFalse($outro->fresh()->temConsentimento());
    }

    public function test_inscricao_sai_ao_responder_e_pare_encerra_com_pedido_para_parar(): void
    {
        $journey = Journey::factory()->for($this->sender)->ativa()->create();
        $journey->steps()->create(['ordem' => 1, 'tipo' => JourneyStep::TIPO_TEMPLATE, 'template_nome' => 'oferta_maquininha_v1', 'template_idioma' => 'pt_BR']);
        $journey->steps()->create(['ordem' => 2, 'tipo' => JourneyStep::TIPO_AGUARDAR, 'aguardar_horas' => 48]);
        $journey->steps()->create(['ordem' => 3, 'tipo' => JourneyStep::TIPO_TEMPLATE, 'template_nome' => 'oferta_maquininha_v1', 'template_idioma' => 'pt_BR']);
        $inscricao = $journey->inscrever($this->contato, 'manual');
        $inscricao->forceFill(['passo_atual' => 3, 'proximo_em' => now()->addHours(47)])->save();
        $this->template('wamid.regua', ['origem' => WhatsappMessage::ORIGEM_REGUA, 'journey_enrollment_id' => $inscricao->id]);

        // "Agora não" não tira da régua.
        $this->resposta(['agora não'], null, false, 'wamid.in1');
        $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $inscricao->fresh()->status);

        $this->resposta(['Oi, tenho interesse sim'], null, false, 'wamid.in2');
        $inscricao->refresh();
        $this->assertSame(JourneyEnrollment::STATUS_SAIU, $inscricao->status);
        $this->assertSame('respondeu', $inscricao->motivo_saida);
        $this->assertNull($inscricao->chave_ativa);
        $this->assertNotNull($inscricao->encerrada_em);

        // Outro inscrito que pede para parar sai com o motivo certo e vira bloqueio.
        $this->contato = Contact::factory()->create();
        $outra = $journey->inscrever($this->contato, 'manual');
        $this->template('wamid.regua2', ['origem' => WhatsappMessage::ORIGEM_REGUA, 'journey_enrollment_id' => $outra->id]);
        $this->resposta(['Parar'], null, false, 'wamid.in3');
        $this->assertSame('pediu_para_parar', $outra->fresh()->motivo_saida);
        $this->assertSame(JourneyEnrollment::STATUS_SAIU, $outra->fresh()->status);
        $this->assertSame(Contact::STATUS_OPTOUT, $this->contato->fresh()->status);
    }

    public function test_resposta_dispara_o_passo_de_texto_que_a_regua_esperava(): void
    {
        $journey = Journey::factory()->for($this->sender)->ativa()->create();
        $journey->steps()->create(['ordem' => 1, 'tipo' => JourneyStep::TIPO_TEMPLATE, 'template_nome' => 'oferta_maquininha_v1', 'template_idioma' => 'pt_BR']);
        $journey->steps()->create(['ordem' => 2, 'tipo' => JourneyStep::TIPO_AGUARDAR, 'aguardar_horas' => 72]);
        $journey->steps()->create(['ordem' => 3, 'tipo' => JourneyStep::TIPO_TEXTO, 'variaveis' => ['texto' => 'Oi {{primeiro_nome}}, o link do cadastro: https://unicopag.com.br/cadastro']]);
        $inscricao = $journey->inscrever($this->contato, 'manual');
        // A inscrição está no "aguardar": o próximo passo é o texto, marcado para daqui a 72 h.
        $inscricao->forceFill(['passo_atual' => 3, 'proximo_em' => now()->addHours(72)])->save();
        $this->template('wamid.regua', ['origem' => WhatsappMessage::ORIGEM_REGUA, 'journey_enrollment_id' => $inscricao->id]);

        $this->resposta(['Quero saber mais'], 'wamid.regua', true);

        $inscricao->refresh();
        $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $inscricao->status, 'a resposta não tira da régua: o passo de texto é o que vem');
        $this->assertTrue($inscricao->proximo_em->lte(now()));

        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => Http::response(['messages' => [['id' => 'wamid.texto']]])]);
        $resultado = app(ProcessadorDeReguas::class)->rodar($journey);

        $this->assertSame(1, $resultado['textos']);
        Http::assertSent(fn (Request $request): bool => ($request->data()['type'] ?? null) === 'text' && str_starts_with((string) $request->data()['text']['body'], 'Oi '.$this->contato->primeiro_nome.', o link'));
        $texto = WhatsappMessage::query()->where('wamid', 'wamid.texto')->sole();
        $this->assertSame(WhatsappMessage::ORIGEM_REGUA, $texto->origem);
        $this->assertSame($inscricao->id, $texto->journey_enrollment_id);
        $this->assertSame(JourneyEnrollment::STATUS_CONCLUIDA, $inscricao->fresh()->status);
        $this->assertSame('fim_dos_passos', $inscricao->fresh()->motivo_saida);
    }
}
