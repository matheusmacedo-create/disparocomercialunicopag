<?php

declare(strict_types=1);

namespace Tests\Feature\Importacao;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\ContactImport;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\Importacao\ImportadorKapso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Histórico da Kapso com a API falsa: duas páginas por cursor, inbound classificada com o contexto do
 * template (citado ou recente), broadcasts casados com o que já existia, rate limit respeitado.
 */
class ImportadorKapsoTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private CarbonImmutable $agora;

    /** @var list<int> */
    private array $esperas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agora = CarbonImmutable::parse('2026-10-28 12:00:00', 'America/Sao_Paulo');
        $this->travelTo($this->agora);
        $this->sender = Sender::factory()->create(['display_phone_number' => '+55 21 90000-0000']);
        MessageTemplate::factory()->for($this->sender)->create();
        MessageTemplate::factory()->for($this->sender)->utility()->create();
    }

    private function importador(): ImportadorKapso
    {
        return app(ImportadorKapso::class)->aoDormir(function (int $segundos): void {
            $this->esperas[] = $segundos;
        });
    }

    private function msg(string $wamid, string $direcao, string $telefone, int $ts, array $extra = []): array
    {
        $base = ['id' => $wamid, 'timestamp' => (string) $ts, 'kapso' => ['direction' => $direcao, 'whatsapp_conversation_id' => 'conv-'.$telefone]];
        $base[$direcao === 'inbound' ? 'from' : 'to'] = $telefone;

        return array_merge_recursive($base, $extra);
    }

    private function template(string $wamid, string $telefone, int $ts, string $nome, string $status = 'delivered'): array
    {
        return $this->msg($wamid, 'outbound', $telefone, $ts, ['type' => 'template', 'template' => ['name' => $nome, 'language' => ['code' => 'pt_BR']], 'kapso' => ['status' => $status]]);
    }

    private function fakeKapso(): void
    {
        $t = fn (string $modificador): int => $this->agora->modify($modificador)->getTimestamp();
        $inboundJaFalhou = false;

        Http::fake(['api.kapso.test/*' => function (Request $request) use ($t, &$inboundJaFalhou) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $caminho = (string) parse_url($url, PHP_URL_PATH);

            if (str_ends_with($caminho, '/conversations')) {
                return Http::response(['data' => [
                    ['id' => 'conv-1', 'kapso' => ['phone_number' => '+5521998765432', 'contact_name' => 'Maria 🌸', 'last_inbound_at' => $this->agora->subDays(3)->addHour()->toIso8601String(), 'last_outbound_at' => $this->agora->subDays(3)->toIso8601String()]],
                    ['id' => 'conv-2', 'kapso' => ['phone_number' => '5521988887777', 'contact_name' => '🌸']],
                ], 'paging' => ['cursors' => ['after' => 'fim']]]);
            }

            if (str_ends_with($caminho, '/messages') && ($q['direction'] ?? '') === 'outbound') {
                if (($q['after'] ?? null) === 'c2') {
                    return Http::response(['data' => [
                        $this->template('wamid.out3', '5521966665555', $t('-1 day'), 'aviso_conta_v1'),
                        $this->template('wamid.out2', '5521988887777', $t('-5 days'), 'oferta_maquininha_v1', 'sent'),
                    ], 'paging' => ['cursors' => ['after' => 'c3']]]);
                }

                return Http::response(['data' => [
                    $this->template('wamid.out1', '5521998765432', $t('-3 days'), 'oferta_maquininha_v1'),
                    // Já existe no banco (saiu daqui como `sent`): a Kapso diz que foi lida.
                    $this->template('wamid.local1', '5521977776666', $t('-2 hours'), 'oferta_maquininha_v1', 'read'),
                ], 'paging' => ['cursors' => ['after' => 'c2'], 'next' => 'https://api.kapso.test/x?after=c2']], 200, ['X-RateLimit-Remaining' => '5']);
            }

            if (str_ends_with($caminho, '/messages') && ($q['direction'] ?? '') === 'inbound') {
                if (! $inboundJaFalhou) {
                    $inboundJaFalhou = true;

                    return Http::response(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '2']);
                }

                return Http::response(['data' => [
                    $this->msg('wamid.in1', 'inbound', '5521998765432', $t('-3 days +1 hour'), ['type' => 'text', 'text' => ['body' => 'não quero'], 'context' => ['id' => 'wamid.out1']]),
                    $this->msg('wamid.in2', 'inbound', '5521988887777', $t('-5 days +2 hours'), ['type' => 'text', 'text' => ['body' => 'Não']]),
                    $this->msg('wamid.in3', 'inbound', '5521966665555', $t('-1 day +1 hour'), ['type' => 'text', 'text' => ['body' => 'não']]),
                    $this->msg('wamid.in4', 'inbound', '5521955554444', $t('-10 days'), ['type' => 'text', 'text' => ['body' => 'Por favor apaguem meus dados']]),
                    $this->msg('wamid.in5', 'inbound', '5521944443333', $t('-20 days'), ['type' => 'text', 'text' => ['body' => 'cancelar']]),
                ], 'paging' => ['cursors' => ['after' => 'fim']]]);
            }

            if (str_ends_with($caminho, '/contacts')) {
                return Http::response(['data' => [['id' => 'kc-1', 'wa_id' => '5521998765432', 'profile_name' => 'Maria Perfil']]]);
            }

            if (str_ends_with($caminho, '/whatsapp/broadcasts')) {
                return Http::response(['data' => [
                    ['id' => 'b1', 'template_name' => 'oferta_maquininha_v1', 'created_at' => $this->agora->subHours(2)->toIso8601String()],
                    ['id' => 'b-velho', 'template_name' => 'oferta_maquininha_v1', 'created_at' => $this->agora->subDays(400)->toIso8601String()],
                    // A lista é da conta inteira: rascunho e broadcast de outro número não entram (conferido em produção, KB 7).
                    ['id' => 'b-rascunho', 'status' => 'draft', 'template_name' => 'oferta_maquininha_v1', 'created_at' => $this->agora->subHours(1)->toIso8601String()],
                    ['id' => 'b-outro', 'phone_number_id' => '999', 'template_name' => 'oferta_maquininha_v1', 'created_at' => $this->agora->subHours(1)->toIso8601String()],
                ]]);
            }

            if (str_contains($caminho, '/broadcasts/b1/recipients')) {
                return Http::response(['data' => [
                    ['id' => 'r1', 'phone_number' => '+5521977776666', 'status' => 'sent', 'sent_at' => $this->agora->subHours(2)->addMinutes(5)->toIso8601String()],
                    ['id' => 'r2', 'phone_number' => '+5521933332222', 'status' => 'suppressed', 'created_at' => $this->agora->subHours(2)->toIso8601String()],
                    ['id' => 'r3', 'phone_number' => '+5521922221111', 'status' => 'failed', 'error_details' => ['code' => 131026, 'message' => 'not a whatsapp user'], 'sent_at' => $this->agora->subHours(2)->toIso8601String()],
                    ['id' => 'r4', 'phone_number' => '+5521911110000', 'status' => 'failed', 'error_details' => ['code' => '131049'], 'sent_at' => $this->agora->subHours(2)->toIso8601String()],
                ]]);
            }

            return Http::response(['data' => []], 200);
        }]);
    }

    public function test_importa_historico_com_cursor_classificacao_em_contexto_e_broadcasts(): void
    {
        $this->fakeKapso();
        $local = Contact::factory()->create(['telefone' => '+5521977776666']);
        $existente = WhatsappMessage::query()->create([
            'sender_id' => $this->sender->id, 'contact_id' => $local->id, 'telefone' => $local->telefone, 'direcao' => 'outbound', 'wamid' => 'wamid.local1',
            'tipo' => 'template', 'template_nome' => 'oferta_maquininha_v1', 'status' => 'sent', 'origem' => 'campanha', 'enviado_em' => $this->agora->subHours(2), 'payload' => ['categoria' => 'MARKETING'],
        ]);

        $importacao = $this->importador()->importar($this->sender, ['contatos' => true, 'broadcasts' => true]);

        $this->assertSame(ContactImport::STATUS_CONCLUIDA, $importacao->status);
        $this->assertSame(ContactImport::TIPO_KAPSO, $importacao->tipo);
        $r = $importacao->resultado;

        // Janela inicial (180 dias) e cursor gravado no remetente.
        $this->assertTrue($this->sender->refresh()->historico_importado_ate->equalTo($this->agora));
        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/messages') && str_contains($req->url(), 'direction=outbound') && str_contains((string) $req['since'], $this->agora->subDays(180)->format('Y-m-d')) && $req['fields'] === 'kapso(default)');

        // Rate limit: página com poucas requisições restantes esperou 15 s; o 429 esperou o Retry-After.
        $this->assertSame([15, 2], $this->esperas);
        $this->assertSame(2, $r['esperas']);

        // Mensagens: 2 páginas de outbound (4 itens, 1 já existia) + 5 inbound, sem texto gravado.
        $this->assertSame(8, $r['mensagens']['novas']);
        $this->assertSame(1, $r['mensagens']['ja_existiam']);
        $this->assertSame(0, WhatsappMessage::query()->where('origem', 'historico_kapso')->whereNotNull('conteudo')->count());
        $this->assertSame('read', $existente->refresh()->status, 'a entrega da mensagem local avançou com o que a Kapso disse');
        $this->assertSame('conv-5521998765432', WhatsappMessage::query()->where('wamid', 'wamid.out1')->value('kapso_conversation_id'));
        $this->assertSame('MARKETING', WhatsappMessage::query()->where('wamid', 'wamid.out1')->first()->payload['categoria'], 'categoria vem do cache de templates');

        // "não quero" citando o template nosso = pediu para parar (histórico).
        $maria = Contact::query()->where('telefone', '+5521998765432')->firstOrFail();
        $this->assertSame(Contact::STATUS_OPTOUT, $maria->status);
        $this->assertSame(Suppression::MOTIVO_HISTORICO, Suppression::bloqueia($maria->telefone, null, null));
        $supressao = Suppression::query()->where('telefone_hash', $maria->telefone_hash)->firstOrFail();
        $this->assertSame(['historico_kapso', 'wamid.in1', 'classificado no histórico como parar'], [$supressao->origem, $supressao->wamid, $supressao->detalhes], 'o texto do cliente não vai para a supressão');
        $this->assertSame($this->agora->subDays(3)->addHour()->toIso8601String(), $supressao->created_at->toIso8601String(), 'criada no momento da resposta');
        $this->assertSame('Maria 🌸', $maria->nome, 'perfil do WhatsApp só preencheu vazio');
        $this->assertSame('Maria', $maria->primeiro_nome);
        $this->assertSame('Maria Perfil', $maria->atributos['whatsapp_profile'], '--contatos atualiza o perfil, não o nome');
        $this->assertSame(['kc-1', 'conv-1'], [$maria->kapso_contact_id, $maria->kapso_conversation_id]);
        $this->assertTrue($maria->ultima_resposta_em->equalTo($this->agora->subDays(3)->addHour()));

        // "Não" a template MARKETING recente (sem citar) = recusa, não bloqueio.
        $joao = Contact::query()->where('telefone', '+5521988887777')->firstOrFail();
        $this->assertSame(Contact::STATUS_ATIVO, $joao->status);
        $this->assertSame(1, $joao->recusas_total);
        $this->assertTrue($joao->recusou_em->equalTo($this->agora->subDays(5)->addHours(2)));
        $this->assertNull($joao->nome, 'perfil só de emoji não vira nome');
        $this->assertSame(1, $r['recusas']);

        // "não" a UTILITY não é recusa; "cancelar" sem template nosso não bloqueia.
        $this->assertSame(0, Contact::query()->where('telefone', '+5521966665555')->value('recusas_total'));
        $this->assertSame(Contact::STATUS_ATIVO, Contact::query()->where('telefone', '+5521944443333')->value('status'));

        // Pedido LGPD: bloqueia e abre a trilha.
        $lgpd = Contact::query()->where('telefone', '+5521955554444')->firstOrFail();
        $this->assertSame(Contact::STATUS_OPTOUT, $lgpd->status);
        $this->assertSame(1, ContactEvent::query()->where('telefone_hash', $lgpd->telefone_hash)->where('evento', 'pedido_lgpd')->count());
        $this->assertSame(['parar' => 1, 'pedido_lgpd' => 1, 'nao_agora' => 2, 'em_resposta_a_template' => 3], $r['classificadas']);
        $this->assertSame(['historico' => 2, 'meta_marketing_stopped' => 1], $r['supressoes']);

        // Broadcasts: r1 casou com a mensagem local (nada duplicado), o velho ficou fora da janela.
        $this->assertSame(1, $r['broadcasts']['fora_da_janela']);
        $this->assertSame([1, 1], [$r['broadcasts']['rascunhos'], $r['broadcasts']['de_outro_numero']]);
        $this->assertSame([1, 3], [$r['broadcasts']['casados'], $r['broadcasts']['criados']]);
        $this->assertSame(1, WhatsappMessage::query()->where('telefone', '+5521977776666')->where('template_nome', 'oferta_maquininha_v1')->count());
        $this->assertSame('broadcast:b1:r1', $existente->refresh()->external_key);
        $this->assertSame(Suppression::MOTIVO_META_MARKETING_STOPPED, Suppression::bloqueia('+5521933332222', $this->sender, 'MARKETING'));
        $this->assertNull(Suppression::bloqueia('+5521933332222', $this->sender, 'UTILITY'), 'escopo marketing');
        $this->assertSame(Contact::STATUS_ATIVO, Contact::query()->where('telefone', '+5521933332222')->value('status'));
        $this->assertNotNull(Contact::query()->where('telefone', '+5521922221111')->value('sem_whatsapp_em'));
        $this->assertTrue(Contact::query()->where('telefone', '+5521911110000')->first()->emCarenciaDeMarketing());
        $this->assertSame('failed', WhatsappMessage::query()->where('external_key', 'broadcast:b1:r2')->value('status'));

        // Rodar de novo não duplica nada.
        $this->esperas = [];
        $segunda = $this->importador()->importar($this->sender, ['broadcasts' => true, 'desde' => $this->agora->subDays(30)]);
        $this->assertSame(0, $segunda->resultado['mensagens']['novas']);
        $this->assertSame(4, $segunda->resultado['broadcasts']['ja_existiam']);
        $this->assertSame(1, $joao->refresh()->recusas_total, 'recusa não conta duas vezes');
    }

    public function test_incremental_parte_do_cursor_menos_a_folga(): void
    {
        Http::fake(['api.kapso.test/*' => Http::response(['data' => []])]);
        $this->sender->forceFill(['historico_importado_ate' => $this->agora->subDay()])->save();

        $importacao = $this->importador()->importar($this->sender, ['incremental' => true]);

        $esperado = $this->agora->subDay()->subHour()->toIso8601String();
        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/messages') && $req['since'] === $esperado && $req['until'] === $this->agora->toIso8601String());
        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/conversations') && $req['last_active_since'] === $esperado);
        $this->assertTrue($importacao->parametros['incremental']);
        $this->assertTrue($this->sender->refresh()->historico_importado_ate->equalTo($this->agora));
    }

    public function test_comando_importa_todos_os_remetentes_kapso(): void
    {
        Http::fake(['api.kapso.test/*' => Http::response(['data' => []])]);
        Sender::factory()->meta()->create();

        $this->artisan('disparo:importar-historico-kapso', ['--todos' => true, '--incremental' => true])
            ->expectsOutputToContain('concluída')
            ->assertSuccessful();

        $this->assertSame(1, ContactImport::query()->where('tipo', 'kapso')->count());
    }

    public function test_com_a_opcao_conteudo_grava_o_texto_e_completa_o_que_foi_importado_sem_ele(): void
    {
        $this->fakeKapso();
        $primeira = $this->importador()->importar($this->sender, []);
        $this->assertSame(0, WhatsappMessage::query()->where('origem', 'historico_kapso')->whereNotNull('conteudo')->count(), 'padrão: só metadados');
        $this->assertFalse($primeira->parametros['conteudo']);

        $segunda = $this->importador()->importar($this->sender, ['conteudo' => true, 'desde' => $this->agora->subDays(30)]);

        $this->assertTrue($segunda->parametros['conteudo']);
        $this->assertSame('não quero', WhatsappMessage::query()->where('wamid', 'wamid.in1')->value('conteudo'), 'texto como veio, sem normalizar');
        $this->assertGreaterThan(0, $segunda->resultado['mensagens']['texto_completado'] ?? 0, 'mensagens da primeira importação ganharam o texto');
    }
}
