<?php

declare(strict_types=1);

namespace Tests\Feature\Envio;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\Sender;
use App\Models\Suppression;
use App\Support\Permissions;
use App\Support\Telefone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supressões (KB 4.8): escopo `tudo` é de todo remetente, `marketing` é do remetente; liberar tem limites; chave repetida não estoura. */
class SuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_escopo_tudo_forca_sender_id_nulo_e_marca_o_contato(): void
    {
        $sender = Sender::factory()->create()->refresh();
        $outro = Sender::factory()->create()->refresh();
        $contato = Contact::factory()->create();

        $this->assertTrue(Suppression::suprimir($contato->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta', $sender->id, Suppression::ESCOPO_TUDO, 'wamid.1', 'pare'));

        $registro = Suppression::query()->sole();
        $this->assertNull($registro->sender_id);
        $this->assertSame(Telefone::hash($contato->telefone), $registro->telefone_hash);
        $this->assertSame(Telefone::hash($contato->telefone).':todos', $registro->chave);

        $this->assertSame(Suppression::MOTIVO_PEDIU_PARA_PARAR, Suppression::bloqueia($contato->telefone, $sender, Sender::CATEGORIA_UTILITY));
        $this->assertSame(Suppression::MOTIVO_PEDIU_PARA_PARAR, Suppression::bloqueia($contato->telefone, $outro, Sender::CATEGORIA_MARKETING));
        $this->assertSame(Suppression::MOTIVO_PEDIU_PARA_PARAR, Suppression::bloqueia($contato->telefone, null, null));

        $contato->refresh();
        $this->assertSame(Contact::STATUS_OPTOUT, $contato->status);
        $this->assertSame('pediu_para_parar', $contato->optout_motivo);
        $this->assertSame('resposta', $contato->optout_origem);
        $this->assertSame(1, ContactEvent::query()->where('telefone_hash', $registro->telefone_hash)->where('evento', 'bloqueado')->count());
    }

    public function test_marketing_e_por_remetente_e_nao_mexe_no_contato(): void
    {
        $sender = Sender::factory()->create()->refresh();
        $outro = Sender::factory()->create()->refresh();
        $contato = Contact::factory()->create();

        Suppression::suprimir($contato->telefone, Suppression::MOTIVO_META_MARKETING_STOPPED, 'webhook', $sender->id, Suppression::ESCOPO_MARKETING);

        $this->assertSame($sender->id, Suppression::query()->sole()->sender_id);
        $this->assertSame(Suppression::MOTIVO_META_MARKETING_STOPPED, Suppression::bloqueia($contato->telefone, $sender, Sender::CATEGORIA_MARKETING));
        $this->assertNull(Suppression::bloqueia($contato->telefone, $sender, Sender::CATEGORIA_UTILITY));
        $this->assertNull(Suppression::bloqueia($contato->telefone, $outro, Sender::CATEGORIA_MARKETING));
        $this->assertSame(Contact::STATUS_ATIVO, $contato->fresh()->status);

        $this->assertArrayHasKey(Telefone::hash($contato->telefone), Suppression::hashesBloqueados($sender, Sender::CATEGORIA_MARKETING));
        $this->assertArrayNotHasKey(Telefone::hash($contato->telefone), Suppression::hashesBloqueados($sender, Sender::CATEGORIA_UTILITY));
        $this->assertArrayNotHasKey(Telefone::hash($contato->telefone), Suppression::hashesBloqueados($outro, Sender::CATEGORIA_MARKETING));

        // A Meta avisou que o cliente retomou: só a supressão de marketing deste remetente cai.
        Suppression::retomarMarketing($contato->telefone, $sender->id);
        $this->assertNull(Suppression::bloqueia($contato->telefone, $sender, Sender::CATEGORIA_MARKETING));
    }

    public function test_liberar_nunca_vale_para_denuncia_nem_para_a_preferencia_da_meta(): void
    {
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $sender = Sender::factory()->create()->refresh();

        $denunciou = Contact::factory()->create();
        Suppression::suprimir($denunciou->telefone, Suppression::MOTIVO_DENUNCIOU, 'resposta');
        $registro = Suppression::query()->where('telefone_hash', Telefone::hash($denunciou->telefone))->sole();
        $this->assertFalse($registro->liberavel());
        $this->assertFalse($registro->liberar($admin->id, 'engano'));
        $this->assertNotNull(Suppression::bloqueia($denunciou->telefone, $sender, Sender::CATEGORIA_UTILITY));
        $this->assertSame(Contact::STATUS_OPTOUT, $denunciou->fresh()->status);

        $meta = Contact::factory()->create();
        Suppression::suprimir($meta->telefone, Suppression::MOTIVO_META_MARKETING_STOPPED, 'webhook', $sender->id, Suppression::ESCOPO_MARKETING);
        $registro = Suppression::query()->where('telefone_hash', Telefone::hash($meta->telefone))->sole();
        $this->assertFalse($registro->liberar($admin->id, 'engano'));
        $this->assertNotNull(Suppression::bloqueia($meta->telefone, $sender, Sender::CATEGORIA_MARKETING));

        // Falso positivo do classificador: libera, com trilha, e o contato volta a ativo.
        $falsoPositivo = Contact::factory()->create();
        Suppression::suprimir($falsoPositivo->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta', null, Suppression::ESCOPO_TUDO, null, 'nao quero receber pelo pix');
        $registro = Suppression::query()->where('telefone_hash', Telefone::hash($falsoPositivo->telefone))->sole();
        $this->assertTrue($registro->liberar($admin->id, 'era atendimento, não pedido para parar'));
        $this->assertNull(Suppression::bloqueia($falsoPositivo->telefone, $sender, Sender::CATEGORIA_UTILITY));
        $this->assertSame(Contact::STATUS_ATIVO, $falsoPositivo->fresh()->status);
        $this->assertSame(1, ContactEvent::query()->where('evento', 'bloqueio_liberado')->count());

        // Liberada, a chave fica livre para um novo pedido do mesmo telefone.
        $this->assertTrue(Suppression::suprimir($falsoPositivo->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta'));
        $this->assertNotNull(Suppression::bloqueia($falsoPositivo->telefone, $sender, Sender::CATEGORIA_UTILITY));
    }

    public function test_chave_repetida_nao_estoura_e_o_primeiro_momento_fica(): void
    {
        $contato = Contact::factory()->create();

        $this->travelTo(now()->subDays(2));
        $this->assertTrue(Suppression::suprimir($contato->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta', null, Suppression::ESCOPO_TUDO, 'wamid.1'));
        $primeiro = Suppression::query()->sole();

        $this->travelBack();
        $this->assertFalse(Suppression::suprimir($contato->telefone, Suppression::MOTIVO_MANUAL, 'painel', null, Suppression::ESCOPO_TUDO, 'wamid.2'));

        $this->assertSame(1, Suppression::query()->count());
        $registro = Suppression::query()->sole();
        $this->assertSame($primeiro->id, $registro->id);
        $this->assertSame(Suppression::MOTIVO_PEDIU_PARA_PARAR, $registro->motivo);
        $this->assertSame('wamid.1', $registro->wamid);
        $this->assertSame($primeiro->created_at->toDateTimeString(), $registro->created_at->toDateTimeString());

        // Corrida: a linha apareceu entre a leitura e a gravação (webhook e painel no mesmo segundo).
        $outro = Contact::factory()->create();
        Suppression::query()->create([
            'telefone_hash' => Telefone::hash($outro->telefone),
            'telefone' => $outro->telefone,
            'chave' => Suppression::chaveDe($outro->telefone, null),
            'escopo' => Suppression::ESCOPO_TUDO,
            'motivo' => Suppression::MOTIVO_IMPORTACAO,
            'created_at' => now(),
        ]);
        $this->assertFalse(Suppression::suprimir($outro->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta'));
        $this->assertSame(Suppression::MOTIVO_IMPORTACAO, Suppression::query()->where('telefone_hash', Telefone::hash($outro->telefone))->sole()->motivo);
    }

    public function test_detalhes_passam_pelo_scrubber_de_pii(): void
    {
        $contato = Contact::factory()->create();

        Suppression::suprimir($contato->telefone, Suppression::MOTIVO_PEDIU_PARA_PARAR, 'resposta', null, Suppression::ESCOPO_TUDO, null, 'cliente ana@exemplo.com do '.$contato->telefone.' pediu');

        $detalhes = (string) Suppression::query()->sole()->detalhes;
        $this->assertStringNotContainsString('ana@exemplo.com', $detalhes);
        $this->assertStringNotContainsString(ltrim($contato->telefone, '+'), $detalhes);
    }

    public function test_recusa_de_marketing_global_nao_engole_o_pare_e_a_liberacao_cabe_na_chave(): void
    {
        $telefone = '+5521998765432';

        $this->assertTrue(Suppression::suprimir($telefone, Suppression::MOTIVO_RECUSAS, 'resposta', null, Suppression::ESCOPO_MARKETING));
        $this->assertTrue(Suppression::suprimir($telefone, 'pediu_para_parar', 'resposta', null, Suppression::ESCOPO_TUDO), 'o "pare" é gravado mesmo com a recusa de marketing já existente');

        $this->assertSame(2, Suppression::query()->where('telefone_hash', Telefone::hash($telefone))->count());
        $this->assertSame('pediu_para_parar', Suppression::bloqueia($telefone, null, null));

        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $tudo = Suppression::query()->where('escopo', Suppression::ESCOPO_TUDO)->sole();
        $tudo->liberar($admin->id, 'falso positivo');
        $this->assertLessThanOrEqual(120, mb_strlen((string) $tudo->fresh()->chave));
        $this->assertSame(Suppression::MOTIVO_RECUSAS, Suppression::bloqueia($telefone, null, 'MARKETING'), 'a recusa de marketing continua valendo');
    }
}
