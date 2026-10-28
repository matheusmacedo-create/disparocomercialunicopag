<?php

declare(strict_types=1);

namespace Tests\Feature\Reguas;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\Segment;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Models\Tag;
use App\Models\WhatsappMessage;
use App\Services\Envio\Guardas;
use App\Services\Envio\TravaDoRemetente;
use App\Services\Reguas\ProcessadorDeReguas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A rodada das réguas (KB 4.9, 8): passos com viagem no tempo, `max_por_dia`, gatilhos de base e tag,
 * reavaliação de segmento com `reinscrever_apos_dias`, intervalo mínimo, simulação, trava do remetente,
 * disjuntor, conversão e passos pulados visíveis.
 */
class ProcessadorDeReguasTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'America/Sao_Paulo'));
        $this->sender = Sender::factory()->create()->refresh();
        $this->template = MessageTemplate::factory()->for($this->sender)->semVariaveis()->create();
        $this->fakeMensagens(fn (Request $r) => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]]));
    }

    /** Stubs de `Http::fake` acumulam: para trocar a resposta do POST /messages a Factory recomeça. */
    private function fakeMensagens(mixed $resposta): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::preventStrayRequests();
        Http::fake(['api.kapso.test/meta/whatsapp/*/messages' => $resposta]);
    }

    /** @param  list<array<string, mixed>>  $passos */
    private function regua(array $passos, array $extra = []): Journey
    {
        $journey = Journey::factory()->for($this->sender)->ativa()->create($extra);

        foreach ($passos as $i => $passo) {
            $journey->steps()->create($passo + ['ordem' => $i + 1, 'template_idioma' => 'pt_BR']);
        }

        return $journey->refresh();
    }

    /** @return array<string, mixed> */
    private function template(): array
    {
        return ['tipo' => JourneyStep::TIPO_TEMPLATE, 'template_nome' => $this->template->nome];
    }

    private function posts(): int
    {
        $n = 0;
        Http::assertSent(function (Request $request) use (&$n): bool {
            if ($request->method() === 'POST') {
                $n++;
            }

            return true;
        });

        return $n;
    }

    public function test_regua_de_template_aguardar_condicao_template_com_viagem_no_tempo(): void
    {
        $journey = $this->regua([
            $this->template(),
            ['tipo' => JourneyStep::TIPO_AGUARDAR, 'aguardar_horas' => 48],
            ['tipo' => JourneyStep::TIPO_CONDICAO, 'condicao' => JourneyStep::CONDICAO_SE_NAO_RESPONDEU],
            $this->template(),
        ], ['intervalo_minimo_horas' => 48]);
        $calado = Contact::factory()->create();
        $falante = Contact::factory()->create();
        $journey->inscrever($calado, 'manual');
        $journey->inscrever($falante, 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(2, $resultado['enviados'], json_encode($resultado));
        $this->assertSame(2, $resultado['aguardando']);
        $this->assertSame(2, $this->posts());

        foreach ([$calado, $falante] as $contato) {
            $inscricao = $contato->journeyEnrollments()->sole();
            $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $inscricao->status);
            $this->assertSame(3, $inscricao->passo_atual, 'durante o aguardar, o próximo passo é a condição');
            $this->assertSame('2026-10-30 10:00:00', $inscricao->proximo_em->toDateTimeString());
            $this->assertNotNull($inscricao->ultimo_wamid);
            $this->assertSame(1, $inscricao->messages()->count());
            $this->assertSame(WhatsappMessage::ORIGEM_REGUA, $inscricao->messages()->sole()->origem);
        }

        // Dez minutos depois nada vence.
        $this->travelTo(now()->addMinutes(10));
        $this->assertSame(0, app(ProcessadorDeReguas::class)->rodar()['inscricoes']);

        // Quem respondeu no meio (sem sair da régua) não recebe o segundo template.
        $falante->forceFill(['ultima_resposta_em' => now()->addHours(5)])->save();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'America/Sao_Paulo'));
        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(2, $resultado['inscricoes']);
        $this->assertSame(1, $resultado['enviados']);
        $this->assertSame(1, $resultado['concluidas']);
        $this->assertSame(1, $resultado['encerradas']);

        $doCalado = $calado->journeyEnrollments()->sole();
        $this->assertSame(JourneyEnrollment::STATUS_CONCLUIDA, $doCalado->status);
        $this->assertSame('fim_dos_passos', $doCalado->motivo_saida);
        $this->assertSame(2, $doCalado->messages()->count());
        $this->assertNull($doCalado->passos_pulados);

        $doFalante = $falante->journeyEnrollments()->sole();
        $this->assertSame(JourneyEnrollment::STATUS_CONCLUIDA, $doFalante->status);
        $this->assertSame('condicao_nao_atendida', $doFalante->motivo_saida);
        $this->assertSame(1, $doFalante->messages()->count());
    }

    public function test_max_por_dia_da_regua_segura_as_outras_inscricoes_ate_o_dia_seguinte(): void
    {
        $journey = $this->regua([$this->template()], ['max_por_dia' => 2]);
        $contatos = Contact::factory()->count(3)->create();
        $contatos->each(fn (Contact $c) => $journey->inscrever($c, 'manual'));

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(2, $resultado['enviados']);
        $this->assertContains($journey->id, $resultado['reguas_no_teto']);
        $this->assertSame(2, app(ProcessadorDeReguas::class)->templatesDaReguaNas24h($journey));
        $this->assertSame(1, JourneyEnrollment::query()->where('status', JourneyEnrollment::STATUS_ATIVA)->count());

        $this->travelTo(now()->addHours(2));
        $this->assertSame(0, app(ProcessadorDeReguas::class)->rodar()['enviados']);

        $this->travelTo(now()->addHours(23));
        $this->assertSame(1, app(ProcessadorDeReguas::class)->rodar()['enviados']);
        $this->assertSame(0, JourneyEnrollment::query()->where('status', JourneyEnrollment::STATUS_ATIVA)->count());
    }

    public function test_gatilhos_de_base_e_tag_inscrevem_ao_entrar(): void
    {
        $lista = ContactList::factory()->create();
        $outraLista = ContactList::factory()->create();
        $tag = Tag::query()->create(['nome' => 'voltou']);
        $daLista = $this->regua([$this->template()], ['gatilho' => Journey::GATILHO_LISTA, 'gatilho_ref' => $lista->id]);
        $daTag = $this->regua([$this->template()], ['gatilho' => Journey::GATILHO_TAG, 'gatilho_ref' => $tag->id]);
        $rascunho = Journey::factory()->for($this->sender)->create(['gatilho' => Journey::GATILHO_LISTA, 'gatilho_ref' => $lista->id]);
        $rascunho->steps()->create(['ordem' => 1] + $this->template());

        $contato = Contact::factory()->create();
        $lista->contacts()->attach($contato->id, ['adicionado_em' => now()]);

        $this->assertSame(1, $daLista->enrollments()->count());
        $this->assertSame(0, $rascunho->enrollments()->count(), 'régua em rascunho não inscreve');
        $this->assertSame('lista', $daLista->enrollments()->sole()->motivo_saida);

        // Repetir a pertinência ou entrar em outra base não duplica.
        $lista->contacts()->syncWithoutDetaching([$contato->id => ['adicionado_em' => now()]]);
        $outraLista->contacts()->attach($contato->id, ['adicionado_em' => now()]);
        $this->assertSame(1, $daLista->enrollments()->count());

        // Pelo lado do contato também, e optout não entra.
        $outro = Contact::factory()->create();
        $outro->lists()->attach($lista->id, ['adicionado_em' => now()]);
        $this->assertSame(2, $daLista->enrollments()->count());
        $parado = Contact::factory()->optout()->create();
        $lista->contacts()->attach($parado->id, ['adicionado_em' => now()]);
        $this->assertSame(2, $daLista->enrollments()->count());

        $contato->tags()->attach($tag->id, ['created_at' => now()]);
        $this->assertSame(1, $daTag->enrollments()->count());
        $tag->contacts()->syncWithoutDetaching([$outro->id]);
        $this->assertSame(2, $daTag->enrollments()->count());
        $this->assertNotNull($outro->tags()->first()->pivot->created_at);
    }

    public function test_segmento_reavalia_e_respeita_reinscrever_apos_dias(): void
    {
        $segmento = Segment::query()->create(['nome' => 'Leads', 'regras' => ['tipos' => ['lead']]]);
        $journey = $this->regua([$this->template()], ['gatilho' => Journey::GATILHO_SEGMENTO, 'gatilho_ref' => $segmento->id, 'reinscrever_apos_dias' => null]);
        $leads = Contact::factory()->count(2)->create(['tipo' => 'lead']);
        Contact::factory()->create(['tipo' => 'seller_inativo']);

        $resultado = app(ProcessadorDeReguas::class)->reavaliarSegmentos();
        $this->assertSame(2, $resultado['inscritos']);
        $this->assertSame(2, $resultado['por_regua'][$journey->id]);
        $this->assertSame(0, app(ProcessadorDeReguas::class)->reavaliarSegmentos()['inscritos']);

        // Concluída e sem reinscrição: nunca volta.
        $inscricao = $leads->first()->journeyEnrollments()->sole();
        $inscricao->encerrar(JourneyEnrollment::STATUS_CONCLUIDA, 'fim_dos_passos');
        $this->assertSame(0, app(ProcessadorDeReguas::class)->reavaliarSegmentos()['inscritos']);

        // Com reinscrição em 7 dias: só depois do prazo.
        $journey->forceFill(['reinscrever_apos_dias' => 7])->save();
        $this->assertSame(0, app(ProcessadorDeReguas::class)->reavaliarSegmentos()['inscritos']);
        $this->travelTo(now()->addDays(8));
        $this->assertSame(1, app(ProcessadorDeReguas::class)->reavaliarSegmentos()['inscritos']);
        $this->assertSame(2, $leads->first()->journeyEnrollments()->count());
        $this->assertSame('segmento', $leads->first()->journeyEnrollments()->latest('id')->first()->motivo_saida);
    }

    public function test_intervalo_minimo_da_regua_pula_o_segundo_template_e_registra_o_motivo(): void
    {
        $journey = $this->regua([$this->template(), $this->template()], ['intervalo_minimo_horas' => 48]);
        $contato = Contact::factory()->create();
        $inscricao = $journey->inscrever($contato, 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(1, $resultado['enviados']);
        $this->assertSame(1, $resultado['pulados']);
        $inscricao->refresh();
        $this->assertSame(JourneyEnrollment::STATUS_CONCLUIDA, $inscricao->status);
        $this->assertSame(Guardas::FREQUENCIA, $inscricao->ultimo_motivo);
        $this->assertCount(1, $inscricao->passos_pulados);
        $this->assertSame(['passo' => 2, 'tipo' => 'template', 'motivo' => Guardas::FREQUENCIA], array_diff_key($inscricao->passos_pulados[0], ['em' => 1]));

        // Sem intervalo (0) os dois saem: a conversa aberta é ignorada em UTILITY quando a régua pede.
        $livre = $this->regua([$this->template(), $this->template()], ['intervalo_minimo_horas' => 0]);
        $outro = Contact::factory()->create();
        $livre->inscrever($outro, 'manual');
        $this->assertSame(2, app(ProcessadorDeReguas::class)->rodar($livre)['enviados']);
    }

    public function test_em_simulacao_a_rodada_nao_envia_e_nao_mexe_na_inscricao(): void
    {
        config(['disparo.envio_habilitado' => false]);
        $journey = $this->regua([$this->template()]);
        $inscricao = $journey->inscrever(Contact::factory()->create(), 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertTrue($resultado['simulacao']);
        $this->assertSame(1, $resultado['inscricoes']);
        $this->assertSame(0, $resultado['enviados']);
        Http::assertNothingSent();
        $this->assertSame(1, $inscricao->fresh()->passo_atual);
        $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $inscricao->fresh()->status);
    }

    public function test_remetente_ocupado_com_outra_rodada_e_pulado(): void
    {
        $journey = $this->regua([$this->template()]);
        $journey->inscrever(Contact::factory()->create(), 'manual');
        $trava = Cache::lock(TravaDoRemetente::chave($this->sender), 600);
        $this->assertTrue($trava->get());

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(TravaDoRemetente::EM_ANDAMENTO, $resultado['remetentes_pulados'][$this->sender->id]);
        $this->assertSame(0, $resultado['enviados']);
        Http::assertNothingSent();

        $trava->release();
        $this->assertSame(1, app(ProcessadorDeReguas::class)->rodar()['enviados']);
    }

    public function test_remetente_fora_da_janela_ou_no_teto_espera(): void
    {
        $sender = Sender::factory()->create(['janela_inicio' => '08:00', 'janela_fim' => '20:00', 'teto_diario' => 1])->refresh();
        $template = MessageTemplate::factory()->for($sender)->semVariaveis()->create();
        $this->sender = $sender;
        $this->template = $template;
        $journey = $this->regua([$this->template()]);
        $journey->inscrever(Contact::factory()->create(), 'manual');
        $journey->inscrever(Contact::factory()->create(), 'manual');

        $this->travelTo(CarbonImmutable::parse('2026-10-28 21:00:00', 'America/Sao_Paulo'));
        $resultado = app(ProcessadorDeReguas::class)->rodar();
        $this->assertSame(Guardas::FORA_DA_JANELA, $resultado['remetentes_pulados'][$sender->id]);
        Http::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00:00', 'America/Sao_Paulo'));
        $resultado = app(ProcessadorDeReguas::class)->rodar();
        $this->assertSame(1, $resultado['enviados']);
        $this->assertSame(ProcessadorDeReguas::PAROU_TETO, $resultado['parou']);

        $this->assertSame(Guardas::TETO_DIARIO, app(ProcessadorDeReguas::class)->rodar()['remetentes_pulados'][$sender->id]);
        $this->assertSame(1, JourneyEnrollment::query()->where('status', JourneyEnrollment::STATUS_ATIVA)->count());
    }

    public function test_conta_impedida_na_regua_pausa_o_remetente_e_para_a_rodada(): void
    {
        $this->fakeMensagens(Http::response(['error' => ['message' => 'Payment issue', 'code' => 141006]], 400));
        $journey = $this->regua([$this->template()]);
        $inscricao = $journey->inscrever(Contact::factory()->create(), 'manual');
        $journey->inscrever(Contact::factory()->create(), 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame('conta_impedida', $resultado['parou']);
        $this->assertSame(1, $resultado['falhas']);
        $this->sender->refresh();
        $this->assertTrue($this->sender->pausado());
        $this->assertSame('2026-10-28 12:00:00', $this->sender->sonda_em->toDateTimeString());
        $this->assertSame(1, SenderEvent::query()->where('sender_id', $this->sender->id)->where('evento', 'pausado')->count());

        // A inscrição fica no mesmo passo, com o motivo, esperando o remetente voltar.
        $inscricao->refresh();
        $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $inscricao->status);
        $this->assertSame(1, $inscricao->passo_atual);
        $this->assertSame('141006', $inscricao->ultimo_motivo);
        $this->assertSame(Guardas::REMETENTE_PAUSADO, app(ProcessadorDeReguas::class)->rodar()['remetentes_pulados'][$this->sender->id]);
    }

    public function test_bloqueio_por_qualidade_na_regua_cancela_marketing_em_andamento(): void
    {
        $this->fakeMensagens(Http::response(['error' => ['message' => 'Spam rate limit', 'code' => 131048]], 400));
        $marketing = MessageTemplate::factory()->for($this->sender)->create();
        $campanha = Campaign::factory()->for($this->sender)->emAndamento()->create(['template_nome' => $marketing->nome]);
        $journey = $this->regua([$this->template()]);
        $journey->inscrever(Contact::factory()->create(), 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame('bloqueio_qualidade', $resultado['parou']);
        $this->assertTrue($this->sender->fresh()->bloqueadoPorQualidade());
        $this->assertSame(Campaign::STATUS_CANCELADA, $campanha->fresh()->status);
    }

    public function test_passos_tag_texto_e_encerrar(): void
    {
        $tag = Tag::query()->create(['nome' => 'nutrido']);
        $journey = $this->regua([
            ['tipo' => JourneyStep::TIPO_TAG, 'tag_id' => $tag->id],
            ['tipo' => JourneyStep::TIPO_TEXTO, 'variaveis' => ['texto' => 'Segue o link: https://unicopag.com.br/x']],
            ['tipo' => JourneyStep::TIPO_ENCERRAR],
            $this->template(),
        ]);
        $respondeu = Contact::factory()->create(['ultima_resposta_em' => now()->subHours(2)]);
        $calado = Contact::factory()->create(['ultima_resposta_em' => now()->subDays(2)]);
        $journey->inscrever($respondeu, 'manual');
        $journey->inscrever($calado, 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(1, $resultado['textos']);
        $this->assertSame(1, $resultado['pulados']);
        $this->assertSame(2, $resultado['concluidas']);
        $this->assertSame(0, $resultado['enviados'], 'o template depois do "encerrar" nunca sai');
        $this->assertTrue($respondeu->tags()->where('tags.id', $tag->id)->exists());
        $this->assertTrue($calado->tags()->where('tags.id', $tag->id)->exists());

        $doCalado = $calado->journeyEnrollments()->sole();
        $this->assertSame('encerrar', $doCalado->motivo_saida);
        $this->assertSame('janela_fechada', $doCalado->ultimo_motivo);
        $this->assertSame(1, WhatsappMessage::query()->where('tipo', WhatsappMessage::TIPO_TEXTO)->count());
    }

    public function test_passo_pulado_por_guarda_fica_visivel_na_inscricao(): void
    {
        $journey = $this->regua([$this->template(), ['tipo' => JourneyStep::TIPO_AGUARDAR, 'aguardar_horas' => 1]]);
        $parado = Contact::factory()->create();
        $inscricao = $journey->inscrever($parado, 'manual');
        $parado->marcarOptout('pediu_para_parar', 'resposta');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(0, $resultado['enviados']);
        $this->assertSame(1, $resultado['pulados']);
        $inscricao->refresh();
        $this->assertSame(Guardas::OPTOUT, $inscricao->ultimo_motivo);
        $this->assertSame(Guardas::OPTOUT, $inscricao->passos_pulados[0]['motivo']);
        $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $inscricao->status);
        $this->assertSame(2, $inscricao->passo_atual + 0 - 1, 'o aguardar rodou e aponta para o fim');
        Http::assertNothingSent();
    }

    public function test_fora_da_janela_no_fuso_do_contato_adia_para_a_abertura_la(): void
    {
        $sender = Sender::factory()->create(['janela_inicio' => '08:00', 'janela_fim' => '20:00'])->refresh();
        $this->sender = $sender;
        $this->template = MessageTemplate::factory()->for($sender)->semVariaveis()->create();
        $journey = $this->regua([$this->template()]);
        $manaus = Contact::factory()->create(['telefone' => '+5592991234567']);
        $this->travelTo(CarbonImmutable::parse('2026-10-28 08:30:00', 'America/Sao_Paulo'));
        $inscricao = $journey->inscrever($manaus, 'manual');

        $resultado = app(ProcessadorDeReguas::class)->rodar();

        $this->assertSame(1, $resultado['adiados']);
        $inscricao->refresh();
        $this->assertSame(Guardas::FORA_DA_JANELA_CONTATO, $inscricao->ultimo_motivo);
        // 08:05 em Manaus = 09:05 em Brasília.
        $this->assertSame('2026-10-28 09:05:00', $inscricao->proximo_em->toDateTimeString());
        Http::assertNothingSent();
    }

    public function test_converter_encerra_com_converteu_e_respeita_sair_ao_converter(): void
    {
        $journey = $this->regua([$this->template()]);
        $inscricao = $journey->inscrever(Contact::factory()->create(), 'manual');

        $this->assertTrue($inscricao->converter('vendas'));
        $inscricao->refresh();
        $this->assertSame(JourneyEnrollment::STATUS_SAIU, $inscricao->status);
        $this->assertSame(JourneyEnrollment::MOTIVO_CONVERTEU, $inscricao->motivo_saida);
        $this->assertSame('vendas', $inscricao->conversao_origem);
        $this->assertNotNull($inscricao->convertido_em);
        $this->assertNull($inscricao->chave_ativa);
        $this->assertFalse($inscricao->converter('kyc'), 'a primeira conversão fica');
        $this->assertSame('vendas', $inscricao->fresh()->conversao_origem);

        $fica = $this->regua([$this->template()], ['sair_ao_converter' => false]);
        $outra = $fica->inscrever(Contact::factory()->create(), 'manual');
        $this->assertFalse($outra->converter('kyc'));
        $this->assertSame(JourneyEnrollment::STATUS_ATIVA, $outra->fresh()->status);
        $this->assertSame('kyc', $outra->fresh()->conversao_origem);
    }
}
