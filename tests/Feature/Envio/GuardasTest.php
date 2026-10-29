<?php

declare(strict_types=1);

namespace Tests\Feature\Envio;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Envio\Guardas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cada motivo das guardas (KB 5), do remetente e do contato, inclusive os que nasceram da revisão de
 * 28/10: consentimento de base externa, objetivo comercial barrado para quem parou marketing mesmo em
 * UTILITY, carência 131049, recusa, provável bloqueio, dia da semana, fuso do contato pelo DDD, frequência
 * MARKETING global entre remetentes e o intervalo mínimo da régua.
 */
class GuardasTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $marketing;

    private MessageTemplate $utility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create()->refresh();
        $this->marketing = MessageTemplate::factory()->for($this->sender)->create();
        $this->utility = MessageTemplate::factory()->for($this->sender)->utility()->create();
    }

    /** @param  array<string, mixed>  $extra */
    private function template(Sender $sender, Contact $contato, array $extra = []): WhatsappMessage
    {
        return WhatsappMessage::query()->create($extra + [
            'sender_id' => $sender->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'template_nome' => 'x',
            'status' => WhatsappMessage::STATUS_SENT,
            'origem' => WhatsappMessage::ORIGEM_CAMPANHA,
            'enviado_em' => now()->subDays(3),
            'payload' => ['categoria' => Sender::CATEGORIA_UTILITY],
        ]);
    }

    public function test_motivos_do_remetente_na_ordem(): void
    {
        $this->assertNull(Guardas::motivoDoRemetente($this->sender, $this->marketing));

        $pausado = Sender::factory()->create(['pausado_em' => now()])->refresh();
        $this->assertSame(Guardas::REMETENTE_PAUSADO, Guardas::motivoDoRemetente($pausado));

        $inativo = Sender::factory()->create(['ativo' => false])->refresh();
        $this->assertSame(Guardas::REMETENTE_PAUSADO, Guardas::motivoDoRemetente($inativo));

        $red = Sender::factory()->create(['qualidade' => Sender::QUALIDADE_RED])->refresh();
        $this->assertSame(Guardas::QUALIDADE_RED, Guardas::motivoDoRemetente($red));

        $pendente = MessageTemplate::factory()->for($this->sender)->create(['nome' => 'pendente_v1', 'status' => 'PENDING']);
        $this->assertSame(Guardas::TEMPLATE_NAO_APROVADO, Guardas::motivoDoRemetente($this->sender, $pendente));

        $soUtility = Sender::factory()->soUtility()->create()->refresh();
        $this->assertSame(Guardas::CATEGORIA_NAO_PERMITIDA, Guardas::motivoDoRemetente($soUtility, $this->marketing));

        $fraco = MessageTemplate::factory()->for($this->sender)->create(['nome' => 'fraco_v1', 'qualidade' => ['score' => 'RED']]);
        $this->assertSame(Guardas::TEMPLATE_QUALIDADE, Guardas::motivoDoRemetente($this->sender, $fraco));

        $comercial = Sender::factory()->create(['janela_inicio' => '08:00', 'janela_fim' => '20:00'])->refresh();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 07:00:00', 'America/Sao_Paulo'));
        $this->assertSame(Guardas::FORA_DA_JANELA, Guardas::motivoDoRemetente($comercial, $this->marketing));
        // A prévia não liga para a janela: diz o que sairia quando ela abrir.
        $this->assertNull(Guardas::motivoDoRemetente($comercial, null, simulacao: true));
        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'America/Sao_Paulo'));
        $this->assertNull(Guardas::motivoDoRemetente($comercial, $this->marketing));

        $pequeno = Sender::factory()->create(['teto_diario' => 2])->refresh();
        foreach (Contact::factory()->count(2)->create() as $contato) {
            $this->template($pequeno, $contato, ['enviado_em' => now()->subHours(2)]);
        }
        $this->assertSame(Guardas::TETO_DIARIO, Guardas::motivoDoRemetente($pequeno));
        $this->assertNull(Guardas::motivoDoRemetente($pequeno, null, simulacao: true));
    }

    public function test_optout_e_contato_invalido(): void
    {
        $this->assertSame(Guardas::OPTOUT, Guardas::motivoDoContato(Contact::factory()->optout()->create(), $this->sender, $this->utility));
        $this->assertSame(Guardas::CONTATO_INVALIDO, Guardas::motivoDoContato(Contact::factory()->create(['status' => Contact::STATUS_INVALIDO]), $this->sender, $this->utility));
        $this->assertSame(Guardas::CONTATO_INVALIDO, Guardas::motivoDoContato(Contact::factory()->create(['status' => Contact::STATUS_ANONIMIZADO]), $this->sender, $this->utility));
        $this->assertNull(Guardas::motivoDoContato(Contact::factory()->create(), $this->sender, $this->utility));
    }

    public function test_suprimido_tudo_vale_em_todo_remetente_e_marketing_so_no_remetente(): void
    {
        $contato = Contact::factory()->create();
        $outro = Sender::factory()->create()->refresh();

        Suppression::suprimir($contato->telefone, Suppression::MOTIVO_META_MARKETING_STOPPED, 'webhook', $this->sender->id, Suppression::ESCOPO_MARKETING);

        $this->assertSame(Guardas::SUPRIMIDO, Guardas::motivoDoContato($contato, $this->sender, $this->marketing));
        $this->assertNull(Guardas::motivoDoContato($contato, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_AVISAR]));
        $this->assertNull(Guardas::motivoDoContato($contato, $outro, $this->marketing));

        // Objetivo comercial nunca contorna a preferência de marketing com template de utilidade (KB 2.4).
        $this->assertSame(Guardas::SUPRIMIDO, Guardas::motivoDoContato($contato, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_RECUPERAR]));
        $this->assertSame(Guardas::SUPRIMIDO, Guardas::motivoDoContato($contato, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_OFERTAR]));

        // "Pare" bloqueia tudo, em todo remetente - o contato vira optout antes de a supressão ser consultada.
        $outroContato = Contact::factory()->create();
        Suppression::suprimir($outroContato->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta', $this->sender->id);
        $this->assertSame(Guardas::OPTOUT, Guardas::motivoDoContato($outroContato->fresh(), $outro, $this->utility));
    }

    public function test_sem_consentimento_para_origem_externa_ou_base_que_exige_e_so_em_marketing(): void
    {
        $lead = Contact::factory()->externo()->create();

        $this->assertSame(Guardas::SEM_CONSENTIMENTO, Guardas::motivoDoContato($lead, $this->sender, $this->marketing));
        $this->assertSame(Guardas::SEM_CONSENTIMENTO, Guardas::motivoDoContato($lead, $this->sender, $this->marketing, ['exige_consentimento' => false]));
        $this->assertNull(Guardas::motivoDoContato($lead, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_AVISAR]));
        $this->assertNull(Guardas::motivoDoContato(Contact::factory()->externo()->comConsentimento()->create(), $this->sender, $this->marketing));

        $interno = Contact::factory()->create(['consentimento_origem' => null, 'consentimento_em' => null]);
        $this->assertNull(Guardas::motivoDoContato($interno, $this->sender, $this->marketing));
        $this->assertSame(Guardas::SEM_CONSENTIMENTO, Guardas::motivoDoContato($interno, $this->sender, $this->marketing, ['exige_consentimento' => true]));
    }

    public function test_sem_whatsapp_carencia_131049_recusou_e_provavel_bloqueio(): void
    {
        $semWhats = Contact::factory()->create(['sem_whatsapp_em' => now()->subDays(10)]);
        $this->assertSame(Guardas::SEM_WHATSAPP, Guardas::motivoDoContato($semWhats, $this->sender, $this->utility));
        $this->assertNull(Guardas::motivoDoContato(Contact::factory()->create(['sem_whatsapp_em' => now()->subDays(100)]), $this->sender, $this->utility));

        $carencia = Contact::factory()->create(['marketing_carencia_ate' => now()->addDays(3)]);
        $this->assertSame(Guardas::MARKETING_CARENCIA, Guardas::motivoDoContato($carencia, $this->sender, $this->marketing));
        $this->assertNull(Guardas::motivoDoContato($carencia, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_AVISAR]));
        $this->assertSame(Guardas::MARKETING_CARENCIA, Guardas::motivoDoContato($carencia, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_RECUPERAR]));
        $this->assertNull(Guardas::motivoDoContato(Contact::factory()->create(['marketing_carencia_ate' => now()->subDay()]), $this->sender, $this->marketing));

        $recusou = Contact::factory()->create(['recusou_em' => now()->subDays(10)]);
        $this->assertSame(Guardas::RECUSOU, Guardas::motivoDoContato($recusou, $this->sender, $this->marketing));
        $this->assertNull(Guardas::motivoDoContato($recusou, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_AVISAR]));
        $this->assertNull(Guardas::motivoDoContato(Contact::factory()->create(['recusou_em' => now()->subDays(100)]), $this->sender, $this->marketing));

        $bloqueio = Contact::factory()->create(['provavel_bloqueio_em' => now()->subDays(30)]);
        $this->assertSame(Guardas::PROVAVEL_BLOQUEIO, Guardas::motivoDoContato($bloqueio, $this->sender, $this->marketing));
        $this->assertNull(Guardas::motivoDoContato($bloqueio, $this->sender, $this->utility, ['objetivo' => Campaign::OBJETIVO_AVISAR]));
        $this->assertNull(Guardas::motivoDoContato(Contact::factory()->create(['provavel_bloqueio_em' => now()->subDays(200)]), $this->sender, $this->marketing));
    }

    public function test_dia_da_semana_da_campanha(): void
    {
        $contato = Contact::factory()->create();
        $regras = ['regras' => ['dias_da_semana' => [1, 2, 3, 4, 5]]];

        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00:00', 'America/Sao_Paulo')); // domingo
        $this->assertSame(Guardas::DIA_DA_SEMANA, Guardas::motivoDoContato($contato, $this->sender, $this->utility, $regras));
        $this->assertNull(Guardas::motivoDoContato($contato, $this->sender, $this->utility, $regras + ['simulacao' => true]));

        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'America/Sao_Paulo')); // segunda
        $this->assertNull(Guardas::motivoDoContato($contato, $this->sender, $this->utility, $regras));
    }

    public function test_janela_no_fuso_do_contato_pelo_ddd(): void
    {
        $sender = Sender::factory()->create(['janela_inicio' => '08:00', 'janela_fim' => '20:00'])->refresh();
        $template = MessageTemplate::factory()->for($sender)->utility()->create();
        $manaus = Contact::factory()->create(['telefone' => '+5592991234567']);
        $rio = Contact::factory()->create(['telefone' => '+5521991234567']);

        // 08:30 de Brasília = 07:30 em Manaus (DDD 92): a janela de 08:00 ainda não abriu lá.
        $this->travelTo(CarbonImmutable::parse('2026-10-28 08:30:00', 'America/Sao_Paulo'));
        $this->assertNull(Guardas::motivoDoRemetente($sender, $template));
        $this->assertSame(Guardas::FORA_DA_JANELA_CONTATO, Guardas::motivoDoContato($manaus, $sender, $template));
        $this->assertNull(Guardas::motivoDoContato($rio, $sender, $template));
        $this->assertTrue(Guardas::adia(Guardas::FORA_DA_JANELA_CONTATO));

        $this->travelTo(CarbonImmutable::parse('2026-10-28 09:30:00', 'America/Sao_Paulo'));
        $this->assertNull(Guardas::motivoDoContato($manaus, $sender, $template));
    }

    public function test_conversa_aberta_por_sete_dias_no_mesmo_remetente(): void
    {
        $contato = Contact::factory()->create();
        $outro = Sender::factory()->create()->refresh();

        $this->template($this->sender, $contato, ['enviado_em' => now()->subDays(3)]);

        $this->assertSame(Guardas::CONVERSA_ABERTA, Guardas::motivoDoContato($contato, $this->sender, $this->utility));
        $this->assertSame(Guardas::CONVERSA_ABERTA, Guardas::motivoDoContato($contato, $this->sender, $this->marketing));
        // Só UTILITY pode ignorar a conversa aberta (KB 2.8).
        $this->assertNull(Guardas::motivoDoContato($contato, $this->sender, $this->utility, ['regras' => ['ignorar_conversa_aberta' => true]]));
        $this->assertSame(Guardas::CONVERSA_ABERTA, Guardas::motivoDoContato($contato, $this->sender, $this->marketing, ['regras' => ['ignorar_conversa_aberta' => true]]));
        // Outro remetente não abre conversa neste.
        $this->assertNull(Guardas::motivoDoContato($contato, $outro, $this->utility));

        $antigo = Contact::factory()->create();
        $this->template($this->sender, $antigo, ['enviado_em' => now()->subDays(10)]);
        $this->assertNull(Guardas::motivoDoContato($antigo, $this->sender, $this->utility));

        $falhou = Contact::factory()->create();
        $this->template($this->sender, $falhou, ['status' => WhatsappMessage::STATUS_FAILED, 'enviado_em' => now()->subDay()]);
        $this->assertNull(Guardas::motivoDoContato($falhou, $this->sender, $this->utility));
    }

    public function test_frequencia_marketing_e_global_entre_remetentes_1_em_30_e_3_em_90_dias(): void
    {
        $outroA = Sender::factory()->create()->refresh();
        $outroB = Sender::factory()->create()->refresh();

        // Um MARKETING de outro remetente há 10 dias: 1/30 d estourado para a mesma empresa.
        $contato = Contact::factory()->create();
        $this->template($outroA, $contato, ['enviado_em' => now()->subDays(10), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        $this->assertSame(Guardas::FREQUENCIA, Guardas::motivoDoContato($contato, $this->sender, $this->marketing));
        // UTILITY do mesmo remetente não entra na conta de marketing.
        $this->assertNull(Guardas::motivoDoContato($contato, $this->sender, $this->utility));

        // Há 40 dias já não conta nos 30, e um só não chega aos 3/90.
        $folgado = Contact::factory()->create();
        $this->template($outroA, $folgado, ['enviado_em' => now()->subDays(40), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        $this->assertNull(Guardas::motivoDoContato($folgado, $this->sender, $this->marketing));

        // Três MARKETING em 90 dias, de remetentes diferentes: 3/90 d estourado.
        $saturado = Contact::factory()->create();
        $this->template($outroA, $saturado, ['enviado_em' => now()->subDays(40), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        $this->template($outroB, $saturado, ['enviado_em' => now()->subDays(50), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        $this->template($outroA, $saturado, ['enviado_em' => now()->subDays(60), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        $this->assertSame(Guardas::FREQUENCIA, Guardas::motivoDoContato($saturado, $this->sender, $this->marketing));
        $this->assertTrue(Guardas::frequenciaEstourada($saturado, $this->sender, true));
        $this->assertFalse(Guardas::frequenciaEstourada($saturado, $this->sender, false));
    }

    public function test_frequencia_utility_e_por_remetente_2_em_30_dias(): void
    {
        $contato = Contact::factory()->create();
        $this->template($this->sender, $contato, ['enviado_em' => now()->subDays(10)]);
        $this->template($this->sender, $contato, ['enviado_em' => now()->subDays(20)]);

        $this->assertSame(Guardas::FREQUENCIA, Guardas::motivoDoContato($contato, $this->sender, $this->utility));

        $outro = Sender::factory()->create()->refresh();
        $this->assertNull(Guardas::motivoDoContato($contato, $outro, $this->utility));

        $um = Contact::factory()->create();
        $this->template($this->sender, $um, ['enviado_em' => now()->subDays(10)]);
        $this->assertNull(Guardas::motivoDoContato($um, $this->sender, $this->utility));
    }

    public function test_regua_usa_o_intervalo_minimo_dela_entre_templates_de_regua(): void
    {
        $contexto = fn (int $horas): array => [
            'origem' => Guardas::ORIGEM_REGUA,
            'regras' => ['ignorar_conversa_aberta' => true],
            'intervalo_minimo_horas' => $horas,
        ];
        $contato = Contact::factory()->create();
        $this->template($this->sender, $contato, ['origem' => WhatsappMessage::ORIGEM_REGUA, 'enviado_em' => now()->subHours(24)]);

        $this->assertSame(Guardas::FREQUENCIA, Guardas::motivoDoContato($contato, $this->sender, $this->utility, $contexto(48)));
        $this->assertNull(Guardas::motivoDoContato($contato, $this->sender, $this->utility, $contexto(12)));
        // Sem intervalo informado vale o padrão global (regua_min_dias = 5).
        $this->assertSame(Guardas::FREQUENCIA, Guardas::motivoDoContato($contato, $this->sender, $this->utility, ['origem' => Guardas::ORIGEM_REGUA, 'regras' => ['ignorar_conversa_aberta' => true]]));
        // Template de campanha não conta no intervalo da régua; só no total de 30 d.
        $deCampanha = Contact::factory()->create();
        $this->template($this->sender, $deCampanha, ['enviado_em' => now()->subHours(24)]);
        $this->assertNull(Guardas::motivoDoContato($deCampanha, $this->sender, $this->utility, $contexto(48)));

        // Exatamente no limite passa (o passo "aguardar 48 h" é avaliado 48 h depois).
        $noLimite = Contact::factory()->create();
        $this->template($this->sender, $noLimite, ['origem' => WhatsappMessage::ORIGEM_REGUA, 'enviado_em' => now()->subHours(48)]);
        $this->assertNull(Guardas::motivoDoContato($noLimite, $this->sender, $this->utility, $contexto(48)));

        // Total de qualquer origem em 30 d (regua_max_30d = 4).
        $cheio = Contact::factory()->create();
        foreach ([8, 12, 16, 20] as $dias) {
            $this->template($this->sender, $cheio, ['enviado_em' => now()->subDays($dias)]);
        }
        $this->assertSame(Guardas::FREQUENCIA, Guardas::motivoDoContato($cheio, $this->sender, $this->utility, $contexto(48)));
    }

    /** O histórico pré-carregado em lote (prévia) decide igual à consulta por contato. */
    public function test_historico_em_lote_da_o_mesmo_resultado_que_a_consulta(): void
    {
        $outro = Sender::factory()->create()->refresh();
        $contatos = [
            'limpo' => Contact::factory()->create(),
            'conversa' => Contact::factory()->create(),
            'frequencia_utility' => Contact::factory()->create(),
            'marketing_30' => Contact::factory()->create(),
            'marketing_90' => Contact::factory()->create(),
            'regua' => Contact::factory()->create(),
            'falhou' => Contact::factory()->create(),
        ];

        $this->template($this->sender, $contatos['conversa'], ['enviado_em' => now()->subDays(2)]);
        $this->template($this->sender, $contatos['frequencia_utility'], ['enviado_em' => now()->subDays(10)]);
        $this->template($this->sender, $contatos['frequencia_utility'], ['enviado_em' => now()->subDays(20)]);
        $this->template($outro, $contatos['marketing_30'], ['enviado_em' => now()->subDays(10), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        foreach ([40, 50, 60] as $dias) {
            $this->template($outro, $contatos['marketing_90'], ['enviado_em' => now()->subDays($dias), 'payload' => ['categoria' => Sender::CATEGORIA_MARKETING]]);
        }
        $this->template($this->sender, $contatos['regua'], ['origem' => WhatsappMessage::ORIGEM_REGUA, 'enviado_em' => now()->subDays(2)]);
        $this->template($this->sender, $contatos['falhou'], ['status' => WhatsappMessage::STATUS_FAILED, 'enviado_em' => now()->subDay()]);

        $historico = Guardas::historicoPorTelefone($this->sender, array_map(fn (Contact $c): string => $c->telefone, $contatos));

        $this->assertArrayNotHasKey($contatos['limpo']->telefone, $historico);
        $this->assertArrayNotHasKey($contatos['falhou']->telefone, $historico);
        $this->assertSame(2, $historico[$contatos['frequencia_utility']->telefone]['templates_30d']);
        $this->assertSame(1, $historico[$contatos['marketing_30']->telefone]['marketing_30d']);
        $this->assertSame(3, $historico[$contatos['marketing_90']->telefone]['marketing_90d']);
        $this->assertNotNull($historico[$contatos['regua']->telefone]['regua_ultimo_em']);

        foreach ([$this->marketing, $this->utility] as $template) {
            foreach ([
                [],
                ['regras' => ['ignorar_conversa_aberta' => true]],
                ['origem' => Guardas::ORIGEM_REGUA, 'regras' => ['ignorar_conversa_aberta' => true], 'intervalo_minimo_horas' => 72],
            ] as $contexto) {
                foreach ($contatos as $nome => $contato) {
                    $this->assertSame(
                        Guardas::motivoDoContato($contato, $this->sender, $template, $contexto),
                        Guardas::motivoDoContato($contato, $this->sender, $template, $contexto, $historico[$contato->telefone] ?? Guardas::HISTORICO_VAZIO),
                        $nome.' / '.$template->categoria.' / '.json_encode($contexto),
                    );
                }
            }
        }
    }
}
