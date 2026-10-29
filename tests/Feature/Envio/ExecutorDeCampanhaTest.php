<?php

declare(strict_types=1);

namespace Tests\Feature\Envio;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\WhatsappMessage;
use App\Services\Envio\ExecutorDeCampanha;
use App\Services\Envio\Guardas;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * As classes de recusa do executor (KB 2.12, 5): bloqueio por qualidade sem sonda, conta impedida com
 * sonda, transitória com retentativa, timeout depois do POST, recusas do contato, template reclassificado,
 * aprovação invalidada, `enviando` órfão, tranche com disjuntor de engajamento, orçamento e janela.
 */
class ExecutorDeCampanhaTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $marketing;

    private MessageTemplate $utility;

    protected function setUp(): void
    {
        parent::setUp();

        config(['disparo.lote.backoff_maximo_segundos' => 0]);
        $this->sender = Sender::factory()->create()->refresh();
        $this->marketing = MessageTemplate::factory()->for($this->sender)->create();
        $this->utility = MessageTemplate::factory()->for($this->sender)->semVariaveis()->create();
    }

    /** @param  callable|PromiseInterface|array<mixed>|null  $mensagens  resposta do POST /messages */
    private function fakeKapso(mixed $mensagens = null): void
    {
        $mensagens ??= fn (Request $request) => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]]);

        // Stubs de `Http::fake` acumulam: para trocar a resposta entre fases do teste, a Factory recomeça.
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::preventStrayRequests();

        Http::fake([
            'api.kapso.test/meta/whatsapp/*/messages' => $mensagens,
            'api.kapso.test/meta/whatsapp/*/message_templates*' => fn (Request $request) => Http::response(['data' => MessageTemplate::query()
                ->where('sender_id', $this->sender->id)
                ->where('nome', (string) ($request->data()['name'] ?? ''))
                ->get()
                ->map(fn (MessageTemplate $t) => $t->definicao() + ['id' => $t->meta_id])
                ->all()]),
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*' => Http::response(['data' => ['id' => $this->sender->phone_number_id, 'quality_rating' => 'GREEN', 'kind' => 'production', 'business_account_id' => $this->sender->waba_id]]),
        ]);
    }

    /** @return array<string, mixed> */
    private function recusa(string|int $codigo, string $mensagem = 'recusado', int $status = 400): array
    {
        return ['error' => ['message' => $mensagem, 'code' => $codigo]];
    }

    /** Campanha em andamento com N destinatários pendentes (sem passar pelo fluxo: o executor é o alvo). */
    private function campanha(MessageTemplate $template, int $destinatarios, array $extra = [], ?Sender $sender = null): Campaign
    {
        $sender ??= $this->sender;
        $campanha = Campaign::factory()->for($sender)->emAndamento()->create(array_merge([
            'template_nome' => $template->nome,
            'objetivo' => $template->categoria === Sender::CATEGORIA_MARKETING ? Campaign::OBJETIVO_OFERTAR : Campaign::OBJETIVO_AVISAR,
            'variaveis' => [],
        ], $extra));

        foreach (Contact::factory()->count($destinatarios)->create() as $contato) {
            $this->destinatario($campanha, $contato);
        }

        $campanha->recontar();

        return $campanha->refresh();
    }

    private function destinatario(Campaign $campanha, Contact $contato, array $extra = []): CampaignRecipient
    {
        return CampaignRecipient::query()->create($extra + [
            'campaign_id' => $campanha->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'variaveis' => [],
            'status' => CampaignRecipient::STATUS_PENDENTE,
        ]);
    }

    public function test_131048_bloqueia_por_qualidade_sem_sonda_e_cancela_marketing_em_andamento(): void
    {
        $this->fakeKapso(Http::response($this->recusa(131048, 'Spam rate limit hit'), 400));
        $campanha = $this->campanha($this->marketing, 2);
        $outraMarketing = $this->campanha($this->marketing, 1);
        $utility = $this->campanha($this->utility, 1);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame('bloqueio_qualidade', $resultado['parou']);
        $this->sender->refresh();
        $this->assertTrue($this->sender->bloqueadoPorQualidade());
        $this->assertNull($this->sender->sonda_em);
        $this->assertFalse($this->sender->sondaDevida());
        $this->assertSame(0, $this->sender->tetoEfetivo());

        $this->assertSame(Campaign::STATUS_CANCELADA, $campanha->fresh()->status);
        $this->assertSame(Campaign::STATUS_CANCELADA, $outraMarketing->fresh()->status);
        $this->assertSame(Campaign::STATUS_EM_ANDAMENTO, $utility->fresh()->status);
        // O destinatário não é culpado: volta à fila.
        $this->assertSame(2, $campanha->recipients()->where('status', CampaignRecipient::STATUS_PENDENTE)->count());
        $this->assertSame(1, $campanha->events()->where('evento', 'cancelada')->count());

        // A rodada seguinte não manda nada: o remetente só volta pela mão de alguém.
        $this->fakeKapso();
        $this->travelTo(now()->addHours(5));
        $this->assertSame(Guardas::REMETENTE_PAUSADO, app(ExecutorDeCampanha::class)->rodar($utility->fresh())['parou']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_141006_conta_impedida_pausa_com_sonda_de_120_minutos_e_a_sonda_despausa(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'America/Sao_Paulo'));
        $this->fakeKapso(Http::response($this->recusa(141006, 'Payment issue'), 400));
        $campanha = $this->campanha($this->utility, 3);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame('conta_impedida', $resultado['parou']);
        $this->sender->refresh();
        $this->assertTrue($this->sender->pausado());
        $this->assertFalse($this->sender->bloqueadoPorQualidade());
        $this->assertSame('2026-10-28 12:00:00', $this->sender->sonda_em->toDateTimeString());
        $this->assertSame(Campaign::STATUS_PAUSADA, $campanha->fresh()->status);
        $this->assertSame(3, $campanha->recipients()->where('status', CampaignRecipient::STATUS_PENDENTE)->count());
        $this->assertSame(1, $campanha->events()->where('evento', 'disjuntor')->count());

        // Antes da hora da sonda nada sai.
        $this->fakeKapso();
        $this->travelTo(now()->addMinutes(60));
        $this->assertSame(Guardas::REMETENTE_PAUSADO, app(ExecutorDeCampanha::class)->rodar($campanha->fresh())['parou']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        // Na hora, sai 1 como sonda; passou, o remetente despausa e a campanha pausada pelo disjuntor segue.
        $this->fakeKapso();
        $this->travelTo(now()->addMinutes(61));
        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->fresh());

        $this->assertSame('sonda_passou', $resultado['parou']);
        $this->assertSame(1, $resultado['enviados']);
        Http::assertSentCount(1); // só o envio (o template foi reconferido na Meta mais cedo, uma vez por dia)
        $this->sender->refresh();
        $this->assertFalse($this->sender->pausado());
        $this->assertNull($this->sender->sonda_em);
        $campanha->refresh();
        $this->assertSame(Campaign::STATUS_EM_ANDAMENTO, $campanha->status);
        $this->assertSame(2, $campanha->recipients()->where('status', CampaignRecipient::STATUS_PENDENTE)->count());
        $this->assertSame(1, $campanha->events()->where('evento', 'sonda_passou')->count());
    }

    public function test_sonda_que_esbarra_de_novo_na_conta_reagenda(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'America/Sao_Paulo'));
        $this->fakeKapso(Http::response($this->recusa(141006, 'Payment issue'), 400));
        $campanha = $this->campanha($this->utility, 2);
        app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->travelTo(now()->addMinutes(121));
        $this->assertSame('sonda_falhou', app(ExecutorDeCampanha::class)->rodar($campanha->fresh())['parou']);
        $this->sender->refresh();
        $this->assertTrue($this->sender->pausado());
        $this->assertSame('2026-10-28 14:01:00', $this->sender->sonda_em->toDateTimeString());
    }

    public function test_429_duas_vezes_adia_com_proxima_tentativa_e_a_terceira_seguida_pausa_por_instabilidade(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'America/Sao_Paulo'));
        $this->fakeKapso(Http::response(['error' => ['message' => 'Too many requests', 'code' => 4]], 429));
        $campanha = $this->campanha($this->utility, 3);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame('instabilidade', $resultado['parou']);
        $this->assertSame(3, $resultado['adiados']);
        $campanha->refresh();
        $this->assertSame(Campaign::STATUS_PAUSADA, $campanha->status);
        $this->assertStringContainsString('instável', (string) $campanha->pausada_motivo);
        // O remetente não tem culpa de instabilidade do provedor.
        $this->assertFalse($this->sender->fresh()->pausado());

        foreach ($campanha->recipients as $destinatario) {
            $this->assertSame(CampaignRecipient::STATUS_PENDENTE, $destinatario->status);
            $this->assertSame(1, $destinatario->tentativas);
            $this->assertSame('4', $destinatario->codigo_erro);
            $this->assertTrue($destinatario->proxima_tentativa_em->gte(now()->addSeconds(60)), 'mínimo de 60 s entre tentativas');
        }

        // Duas tentativas por destinatário: na segunda falha ele vira `falhou`.
        $campanha->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO])->save();
        $this->travelTo(now()->addMinutes(5));
        app(ExecutorDeCampanha::class)->rodar($campanha->fresh());
        $this->assertSame(3, $campanha->recipients()->where('status', CampaignRecipient::STATUS_FALHOU)->count());
    }

    public function test_timeout_depois_do_post_vira_enviado_incerto_e_conta_no_teto_e_na_conversa_aberta(): void
    {
        $this->fakeKapso(Http::failedConnection('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'));
        $campanha = $this->campanha($this->utility, 1);
        $contato = $campanha->recipients()->sole()->contact;

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame(1, $resultado['enviados']);
        $destinatario = $campanha->recipients()->sole();
        $this->assertSame(CampaignRecipient::STATUS_ENVIADO_INCERTO, $destinatario->status);
        $this->assertSame('timeout_envio', $destinatario->codigo_erro);
        $this->assertNull($destinatario->wamid);

        $mensagem = WhatsappMessage::query()->sole();
        $this->assertSame(WhatsappMessage::STATUS_PENDING, $mensagem->status);
        $this->assertSame('timeout_envio', $mensagem->codigo_erro);
        $this->assertTrue((bool) ($mensagem->payload['timeout_envio'] ?? false));

        // Pode ter saído: conta no teto, na conversa aberta e na frequência (reenviar duplicaria).
        $this->assertSame(1, $this->sender->templatesNasUltimas24h());
        $this->assertTrue(Guardas::conversaAberta($contato, $this->sender));
        $this->assertSame(Guardas::CONVERSA_ABERTA, Guardas::motivoDoContato($contato, $this->sender, $this->utility));
        $this->assertNotNull($contato->fresh()->ultimo_template_em);

        // Conexão que nem abriu (`timeout`) é transitória e retenta, sem contar como envio.
        $this->fakeKapso(Http::failedConnection());
        $outra = $this->campanha($this->utility, 1);
        $resultado = app(ExecutorDeCampanha::class)->rodar($outra);
        $this->assertSame(1, $resultado['adiados']);
        $this->assertSame(CampaignRecipient::STATUS_PENDENTE, $outra->recipients()->sole()->status);
        $this->assertSame(1, $this->sender->templatesNasUltimas24h());
    }

    public function test_131026_falha_o_destinatario_e_marca_sem_whatsapp(): void
    {
        $this->fakeKapso(Http::response($this->recusa(131026, 'Message undeliverable'), 400));
        $campanha = $this->campanha($this->utility, 1);
        $contato = $campanha->recipients()->sole()->contact;

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame(1, $resultado['falhas']);
        $this->assertNull($resultado['parou']);
        $destinatario = $campanha->recipients()->sole();
        $this->assertSame(CampaignRecipient::STATUS_FALHOU, $destinatario->status);
        $this->assertSame('131026', $destinatario->codigo_erro);
        $this->assertNotNull($contato->fresh()->sem_whatsapp_em);
        $this->assertSame(1, $contato->fresh()->falhas_total);
        $this->assertFalse($this->sender->fresh()->pausado());
        $this->assertSame(Campaign::STATUS_CONCLUIDA, $campanha->fresh()->status);
        $this->assertSame(Guardas::SEM_WHATSAPP, Guardas::motivoDoContato($contato->fresh(), $this->sender, $this->utility));
    }

    public function test_variavel_vazia_falha_local_sem_chamar_o_provedor(): void
    {
        $this->fakeKapso();
        $template = MessageTemplate::factory()->for($this->sender)->utility()->create();
        $campanha = Campaign::factory()->for($this->sender)->emAndamento()->create([
            'template_nome' => $template->nome,
            'objetivo' => Campaign::OBJETIVO_AVISAR,
            'variaveis' => ['corpo' => ['{{primeiro_nome}}', '{{email}}']],
        ]);
        $this->destinatario($campanha, Contact::factory()->create(['email' => null]));

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->refresh());

        $this->assertSame(1, $resultado['falhas']);
        $destinatario = $campanha->recipients()->sole();
        $this->assertSame(CampaignRecipient::STATUS_FALHOU, $destinatario->status);
        $this->assertSame('variavel_vazia', $destinatario->codigo_erro);
        $this->assertStringContainsString('{{2}}', (string) $destinatario->erro);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame('local', WhatsappMessage::query()->sole()->payload['classe']);
        $this->assertFalse($this->sender->fresh()->pausado());
    }

    public function test_template_reclassificado_pela_meta_volta_para_aprovacao(): void
    {
        $this->fakeKapso();
        $campanha = $this->campanha($this->marketing, 1, ['template_categoria' => Sender::CATEGORIA_UTILITY]);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame('categoria_mudou', $resultado['parou']);
        $campanha->refresh();
        $this->assertSame(Campaign::STATUS_AGUARDANDO_APROVACAO, $campanha->status);
        $this->assertStringContainsString('reclassificou', (string) $campanha->pausada_motivo);
        $this->assertSame(1, $campanha->events()->where('evento', 'categoria_mudou')->count());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_hash_da_aprovacao_divergente_volta_para_aprovacao(): void
    {
        $this->fakeKapso();
        $campanha = $this->campanha($this->utility, 1, ['aprovacao_hash' => str_repeat('a', 64)]);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha);

        $this->assertSame('aprovacao_invalidada', $resultado['parou']);
        $this->assertSame(Campaign::STATUS_AGUARDANDO_APROVACAO, $campanha->fresh()->status);
        $this->assertSame(1, $campanha->events()->where('evento', 'aprovacao_invalidada')->count());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        // Com o hash certo a rodada segue.
        $campanha->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO, 'pausada_motivo' => null])->save();
        $campanha->forceFill(['aprovacao_hash' => $campanha->hashDaAprovacao()])->save();
        $this->assertSame(1, app(ExecutorDeCampanha::class)->rodar($campanha->fresh())['enviados']);
    }

    public function test_enviando_orfao_ha_dez_minutos_vira_enviado_incerto(): void
    {
        $this->fakeKapso();
        $campanha = $this->campanha($this->utility, 1);
        $orfao = $this->destinatario($campanha, Contact::factory()->create(), ['status' => CampaignRecipient::STATUS_ENVIANDO]);
        $recente = $this->destinatario($campanha, Contact::factory()->create(), ['status' => CampaignRecipient::STATUS_ENVIANDO]);
        CampaignRecipient::query()->whereKey($orfao->id)->update(['updated_at' => now()->subMinutes(11)]);
        CampaignRecipient::query()->whereKey($recente->id)->update(['updated_at' => now()->subMinutes(2)]);

        app(ExecutorDeCampanha::class)->rodar($campanha->refresh());

        $this->assertSame(CampaignRecipient::STATUS_ENVIADO_INCERTO, $orfao->fresh()->status);
        $this->assertSame('processo_interrompido', $orfao->fresh()->motivo);
        $this->assertSame(CampaignRecipient::STATUS_ENVIANDO, $recente->fresh()->status);
    }

    public function test_tranche_com_nao_entregues_dispara_o_disjuntor_de_engajamento(): void
    {
        $this->fakeKapso();
        $campanha = Campaign::factory()->for($this->sender)->create([
            'template_nome' => $this->marketing->nome,
            'objetivo' => Campaign::OBJETIVO_OFERTAR,
            'variaveis' => [],
        ]);
        $campanha->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO, 'iniciada_em' => now()->subHours(2), 'tranches_enviadas' => 0, 'tranche_aguardando_ate' => now()->subMinute()])->save();

        // Primeira leva de 20 há 40 min: 18 entregues, 2 só "enviadas" (quem bloqueou não recebe) = 10%.
        foreach (range(1, 20) as $i) {
            $this->destinatario($campanha, Contact::factory()->create(), [
                'status' => $i <= 18 ? CampaignRecipient::STATUS_ENTREGUE : CampaignRecipient::STATUS_ENVIADO,
                'enviado_em' => now()->subMinutes(40),
                'entregue_em' => $i <= 18 ? now()->subMinutes(39) : null,
            ]);
        }
        $this->destinatario($campanha, Contact::factory()->create());

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->refresh());

        $this->assertSame('engajamento', $resultado['parou']);
        $this->assertSame(Campaign::STATUS_CANCELADA, $campanha->fresh()->status);
        $this->assertTrue($this->sender->fresh()->bloqueadoPorQualidade());
        $this->assertStringContainsString('engajamento', (string) $this->sender->fresh()->bloqueio_qualidade_motivo);
        $evento = $campanha->events()->where('evento', 'engajamento')->first();
        $this->assertNotNull($evento);
        $this->assertStringContainsString('não foram entregues', (string) $evento->detalhes['motivo']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        // Com 19 entregues (5%) a tranche é liberada e a campanha segue.
        $this->sender->fresh()->religar();
        $outra = Campaign::factory()->for($this->sender)->create(['template_nome' => $this->marketing->nome, 'objetivo' => Campaign::OBJETIVO_OFERTAR, 'variaveis' => []]);
        $outra->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO, 'iniciada_em' => now()->subHours(2), 'tranches_enviadas' => 0, 'tranche_aguardando_ate' => now()->subMinute()])->save();
        foreach (range(1, 20) as $i) {
            $this->destinatario($outra, Contact::factory()->create(), [
                'status' => $i <= 19 ? CampaignRecipient::STATUS_LIDO : CampaignRecipient::STATUS_ENVIADO,
                'enviado_em' => now()->subMinutes(40),
                'entregue_em' => $i <= 19 ? now()->subMinutes(39) : null,
                'lido_em' => $i <= 19 ? now()->subMinutes(38) : null,
            ]);
        }
        $this->destinatario($outra, Contact::factory()->create());

        $resultado = app(ExecutorDeCampanha::class)->rodar($outra->refresh());
        $this->assertSame(1, $resultado['enviados']);
        $this->assertSame(1, $outra->fresh()->tranches_enviadas);
        $this->assertSame(1, $outra->events()->where('evento', 'tranche_liberada')->count());
    }

    public function test_orcamento_desconta_a_reserva_das_reguas_e_o_teto_de_marketing(): void
    {
        $this->fakeKapso();
        $sender = Sender::factory()->create(['teto_diario' => 10])->refresh();
        $utility = MessageTemplate::factory()->for($sender)->semVariaveis()->create();
        $marketing = MessageTemplate::factory()->for($sender)->create();
        $this->sender = $sender;

        // Teto 10: reserva das réguas = 3 (30%), teto de MARKETING = 3.
        $avisos = $this->campanha($utility, 10, [], $sender);
        $resultado = app(ExecutorDeCampanha::class)->rodar($avisos);
        $this->assertSame(7, $resultado['enviados']);
        $this->assertSame(Guardas::TETO_DIARIO, $resultado['parou']);
        $this->assertSame(3, $avisos->recipients()->where('status', CampaignRecipient::STATUS_PENDENTE)->count());

        // A régua usa a reserva dela: o que ela mandou não abre espaço para a campanha.
        $this->assertSame(7, $sender->templatesNasUltimas24h());
        $this->assertSame(Guardas::TETO_DIARIO, app(ExecutorDeCampanha::class)->rodar($avisos->fresh())['parou']);

        $outro = Sender::factory()->create(['teto_diario' => 10])->refresh();
        $ofertaTemplate = MessageTemplate::factory()->for($outro)->create();
        $this->sender = $outro;
        $oferta = $this->campanha($ofertaTemplate, 10, [], $outro);
        $resultado = app(ExecutorDeCampanha::class)->rodar($oferta);
        $this->assertSame(3, $resultado['enviados']);
        $this->assertSame(Guardas::TETO_DIARIO, $resultado['parou']);
    }

    public function test_fora_da_janela_do_remetente_espera_e_dia_da_semana_adia_o_destinatario(): void
    {
        $this->fakeKapso();
        $sender = Sender::factory()->create(['janela_inicio' => '08:00', 'janela_fim' => '20:00'])->refresh();
        $template = MessageTemplate::factory()->for($sender)->semVariaveis()->create();
        $this->sender = $sender;
        $campanha = $this->campanha($template, 1, ['regras' => ['dias_da_semana' => [1, 2, 3, 4, 5]]], $sender);

        // Sábado à noite: a rodada nem começa, nada muda no destinatário.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 21:00:00', 'America/Sao_Paulo'));
        $this->assertSame(Guardas::FORA_DA_JANELA, app(ExecutorDeCampanha::class)->rodar($campanha)['parou']);
        $this->assertNull($campanha->recipients()->sole()->proxima_tentativa_em);

        // Domingo de manhã: o remetente pode, mas a campanha é de dia útil - o destinatário é adiado.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'America/Sao_Paulo'));
        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->fresh());
        $this->assertSame(1, $resultado['adiados']);
        $destinatario = $campanha->recipients()->sole();
        $this->assertSame(CampaignRecipient::STATUS_PENDENTE, $destinatario->status);
        $this->assertSame(Guardas::DIA_DA_SEMANA, $destinatario->motivo);
        $this->assertSame('2026-11-01 10:05:00', $destinatario->proxima_tentativa_em->toDateTimeString());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');

        // Segunda-feira, dentro da janela: sai.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, app(ExecutorDeCampanha::class)->rodar($campanha->fresh())['enviados']);
    }
}
