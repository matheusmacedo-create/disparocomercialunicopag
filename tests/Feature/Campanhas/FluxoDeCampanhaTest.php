<?php

declare(strict_types=1);

namespace Tests\Feature\Campanhas;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Campanhas\FluxoDeCampanha;
use App\Services\Envio\ExecutorDeCampanha;
use App\Services\Envio\Guardas;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O caminho feliz de ponta a ponta: prévia → aprovação (quatro olhos) → execução, com a Kapso falsa, e o
 * mesmo caminho em simulação (DISPARO_ENVIO_HABILITADO=false) marcando `simulado` com os mesmos motivos.
 */
class FluxoDeCampanhaTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $template;

    private ContactList $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create();
        $this->template = MessageTemplate::factory()->for($this->sender)->create();
        $this->lista = ContactList::factory()->create();
        $this->lista->contacts()->attach(Contact::factory()->count(5)->create()->pluck('id')->all(), ['adicionado_em' => now()]);

        // Campanha MARKETING de 5: a primeira tranche leva 3 (ceil de 50%) e espera os webhooks.
        config(['disparo.tranches.primeira_max' => 50, 'disparo.tranches.primeira_fracao' => 0.5]);
    }

    private function campanha(array $extra = []): Campaign
    {
        return Campaign::factory()->for($this->sender)->create(array_merge([
            'template_nome' => $this->template->nome,
            'publico' => ['listas' => [$this->lista->id]],
        ], $extra))->refresh();
    }

    private function fakeKapso(): void
    {
        Http::fake([
            'api.kapso.test/meta/whatsapp/*/messages' => Http::sequence()->push(['messages' => [['id' => 'wamid.1']]])->push(['messages' => [['id' => 'wamid.2']]])->push(['messages' => [['id' => 'wamid.3']]])->push(['messages' => [['id' => 'wamid.4']]])->push(['messages' => [['id' => 'wamid.5']]])->push(['messages' => [['id' => 'wamid.6']]]),
            'api.kapso.test/meta/whatsapp/*/message_templates*' => Http::response(['data' => [$this->template->definicao() + ['id' => '1']]]),
            'api.kapso.test/platform/v1/whatsapp/phone_numbers/*' => Http::response(['data' => ['id' => $this->sender->phone_number_id, 'quality_rating' => 'GREEN', 'kind' => 'production', 'business_account_id' => $this->sender->waba_id]]),
        ]);
    }

    public function test_previa_aprovacao_por_outro_usuario_e_execucao_real(): void
    {
        $this->fakeKapso();
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $campanha = $this->campanha();
        $campanha->forceFill(['criado_por' => $operador->id])->save();

        $fluxo = app(FluxoDeCampanha::class);

        $previa = $fluxo->simular($campanha, $operador);
        $this->assertSame(5, $previa['elegiveis']);
        $this->assertSame(0, $previa['excluidos']);
        $this->assertSame('MARKETING', $previa['categoria']);
        $this->assertGreaterThan(0, $previa['custo_estimado']);

        $fluxo->enviarParaAprovacao($campanha->refresh(), $operador);
        $this->assertSame(Campaign::STATUS_AGUARDANDO_APROVACAO, $campanha->refresh()->status);

        // O operador não aprova a própria campanha.
        try {
            $fluxo->aprovar($campanha->refresh(), $operador, 5);
            $this->fail('operador sem approve campaigns');
        } catch (AuthorizationException) {
        }

        // O admin precisa digitar o número certo.
        try {
            $fluxo->aprovar($campanha->refresh(), $admin, 4);
            $this->fail('número errado');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('destinatarios', $e->errors());
        }

        $montagem = $fluxo->aprovar($campanha->refresh(), $admin, 5);
        $campanha->refresh();
        $this->assertSame(5, $montagem['criados']);
        $this->assertSame(Campaign::STATUS_APROVADA, $campanha->status);
        $this->assertSame('MARKETING', $campanha->template_categoria);
        $this->assertNotNull($campanha->aprovacao_hash);
        $this->assertSame(5, $campanha->recipients()->where('status', CampaignRecipient::STATUS_PENDENTE)->count());

        $fluxo->iniciar($campanha->refresh(), $admin, agora: true);
        $this->assertSame(Campaign::STATUS_EM_ANDAMENTO, $campanha->refresh()->status);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->refresh());

        // Primeira tranche de MARKETING: 3 de 5 saíram e a campanha espera os webhooks.
        $this->assertSame(3, $resultado['enviados'], json_encode($resultado));
        $this->assertSame('tranche_enviada', $resultado['parou']);
        $campanha->refresh();
        $this->assertSame(3, $campanha->enviados);
        $this->assertSame(3, WhatsappMessage::query()->where('status', WhatsappMessage::STATUS_SENT)->count());
        $this->assertNotNull($campanha->tranche_aguardando_ate);
        $this->assertSame(Campaign::STATUS_EM_ANDAMENTO, $campanha->status);

        // Segunda rodada antes do fim da espera não manda nada.
        $this->assertSame('aguardando_tranche', app(ExecutorDeCampanha::class)->rodar($campanha->refresh())['parou']);
        $this->assertSame(3, WhatsappMessage::query()->where('status', WhatsappMessage::STATUS_SENT)->count());

        // Passada a espera, sem sinal ruim (amostra pequena não julga), o resto sai e a campanha conclui.
        $this->travelTo(now()->addMinutes(100));
        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->refresh());
        $this->assertSame(2, $resultado['enviados'], json_encode($resultado));
        $campanha->refresh();
        $this->assertSame(Campaign::STATUS_CONCLUIDA, $campanha->status);
        $this->assertSame(5, $campanha->enviados);
        $this->assertSame(1, $campanha->tranches_enviadas);
        $this->assertNotNull($campanha->concluida_em);
    }

    public function test_em_simulacao_marca_simulado_com_os_mesmos_motivos_e_nada_sai(): void
    {
        config(['disparo.envio_habilitado' => false]);
        Http::fake();
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $outro = $this->usuario(Permissions::ROLE_ADMIN);

        // Um bloqueado e um sem WhatsApp entram na prévia como excluídos.
        $bloqueado = Contact::factory()->create();
        Suppression::suprimir($bloqueado->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'teste');
        $semWhats = Contact::factory()->create(['sem_whatsapp_em' => now()->subDay()]);
        $this->lista->contacts()->attach([$bloqueado->id, $semWhats->id], ['adicionado_em' => now()]);

        $campanha = $this->campanha();
        $campanha->forceFill(['criado_por' => $outro->id])->save();
        $fluxo = app(FluxoDeCampanha::class);

        $previa = $fluxo->simular($campanha, $admin);
        $this->assertSame(5, $previa['elegiveis']);
        $this->assertSame(2, $previa['excluidos']);
        $this->assertSame(1, $previa['motivos'][Guardas::SUPRIMIDO]);
        $this->assertSame(1, $previa['motivos'][Guardas::SEM_WHATSAPP]);
        $this->assertStringContainsString('SIMULAÇÃO', implode(' ', $previa['avisos']));

        $fluxo->enviarParaAprovacao($campanha->refresh(), $admin);
        $fluxo->aprovar($campanha->refresh(), $admin, 5);
        $fluxo->iniciar($campanha->refresh(), $admin, agora: true);

        $resultado = app(ExecutorDeCampanha::class)->rodar($campanha->refresh());

        $this->assertSame(5, $resultado['simulados']);
        $this->assertSame(0, $resultado['enviados']);
        Http::assertNothingSent();
        $this->assertSame(5, $campanha->recipients()->where('status', CampaignRecipient::STATUS_SIMULADO)->count());
        $this->assertSame(2, $campanha->recipients()->where('status', CampaignRecipient::STATUS_PULADO)->count());
        $this->assertSame(0, WhatsappMessage::query()->where('status', '!=', WhatsappMessage::STATUS_FAILED)->count());
        $this->assertSame(Campaign::STATUS_CONCLUIDA, $campanha->refresh()->status);
    }

    public function test_auto_aprovacao_exige_espera_e_respeita_teto(): void
    {
        Http::fake();
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $campanha = $this->campanha();
        $campanha->forceFill(['criado_por' => $admin->id])->save();
        $fluxo = app(FluxoDeCampanha::class);

        $fluxo->simular($campanha, $admin);
        $fluxo->enviarParaAprovacao($campanha->refresh(), $admin);

        try {
            $fluxo->aprovar($campanha->refresh(), $admin, 5);
            $this->fail('quem criou não aprova sem auto-aprovação');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('aprovacao', $e->errors());
        }

        config(['disparo.auto_aprovacao.teto_destinatarios' => 3]);

        try {
            $fluxo->aprovar($campanha->refresh(), $admin, 5, autoAprovacao: true);
            $this->fail('acima do teto da auto-aprovação');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Auto-aprovação só até 3', implode(' ', $e->errors()['aprovacao']));
        }

        config(['disparo.auto_aprovacao.teto_destinatarios' => 200, 'disparo.auto_aprovacao.espera_minutos' => 30]);
        $montagem = $fluxo->aprovar($campanha->refresh(), $admin, 5, autoAprovacao: true);

        $this->assertTrue($montagem['agendada_para']->gte(now()->addMinutes(29)));
        $this->assertSame(Campaign::STATUS_APROVADA, $campanha->refresh()->status);

        // "Iniciar agora" não pula a espera obrigatória.
        $this->expectException(ValidationException::class);
        $fluxo->iniciar($campanha->refresh(), $admin, agora: true);
    }

    public function test_editar_depois_da_previa_invalida_a_aprovacao(): void
    {
        Http::fake();
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $outro = $this->usuario(Permissions::ROLE_ADMIN);
        $campanha = $this->campanha();
        $campanha->forceFill(['criado_por' => $outro->id])->save();
        $fluxo = app(FluxoDeCampanha::class);

        $fluxo->simular($campanha, $admin);
        $fluxo->enviarParaAprovacao($campanha->refresh(), $admin);
        $fluxo->aprovar($campanha->refresh(), $admin, 5);

        $campanha->refresh()->fill(['variaveis' => ['corpo' => ['{{nome}}']]])->save();
        $fluxo->aoEditar($campanha->refresh());

        $this->assertSame(Campaign::STATUS_RASCUNHO, $campanha->refresh()->status);
        $this->assertNull($campanha->aprovacao_hash);
        $this->assertSame(0, $campanha->recipients()->count());

        // Sem prévia nova, nem enviar para aprovação.
        $this->expectException(ValidationException::class);
        $fluxo->enviarParaAprovacao($campanha->refresh(), $admin);
    }

    public function test_campanha_sem_publico_nao_passa_na_validacao(): void
    {
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $campanha = $this->campanha(['publico' => []]);

        try {
            app(FluxoDeCampanha::class)->simular($campanha, $admin);
            $this->fail('sem público');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Defina o público', implode(' ', $e->errors()['campanha']));
        }
    }

    /** `status`/`aprovado_por` fora do $fillable: um `set()` forjado no Livewire não aprova nada. */
    public function test_status_nao_e_preenchivel_pelo_formulario(): void
    {
        $campanha = $this->campanha();

        $this->expectException(MassAssignmentException::class);
        $campanha->fill(['status' => Campaign::STATUS_APROVADA, 'aprovado_por' => 1]);
    }
}
