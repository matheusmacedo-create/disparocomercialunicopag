<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Journeys\Pages\CreateJourney;
use App\Filament\Resources\Journeys\Pages\EditJourney;
use App\Filament\Resources\Journeys\Pages\ViewJourney;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Régua pelo painel: passos na ordem do Repeater, aprovação por outro usuário, edição volta a rascunho. */
class ReguasPainelTest extends TestCase
{
    use RefreshDatabase;

    private Sender $sender;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = Sender::factory()->create();
        $this->template = MessageTemplate::factory()->for($this->sender)->create();
    }

    public function test_operador_cria_regua_com_passos_e_admin_aprova_e_ativa(): void
    {
        $operador = $this->usuario(Permissions::ROLE_OPERATOR);
        $admin = $this->usuario(Permissions::ROLE_ADMIN);
        $this->actingAs($operador);

        Livewire::test(CreateJourney::class)
            ->fillForm([
                'nome' => 'Recuperação D+0 / D+5',
                'sender_id' => $this->sender->id,
                'objetivo' => 'recuperar',
                'gatilho' => Journey::GATILHO_MANUAL,
                'intervalo_minimo_horas' => 48,
                'max_por_dia' => 30,
                'passos' => [
                    ['tipo' => JourneyStep::TIPO_TEMPLATE, 'template_chave' => $this->template->nome.'|pt_BR', 'mapeamento' => [['variavel' => 'primeiro_nome', 'padrao' => 'tudo bem']]],
                    ['tipo' => JourneyStep::TIPO_AGUARDAR, 'aguardar_horas' => 120],
                    ['tipo' => JourneyStep::TIPO_TEXTO, 'texto' => 'Oi {{primeiro_nome|tudo bem}}, segue o link.'],
                    ['tipo' => JourneyStep::TIPO_CONDICAO, 'condicao' => JourneyStep::CONDICAO_SE_NAO_RESPONDEU],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $regua = Journey::query()->sole();
        $this->assertSame($operador->id, $regua->criado_por);
        $this->assertSame(Journey::STATUS_RASCUNHO, $regua->status);
        $this->assertSame([1, 2, 3, 4], $regua->steps()->pluck('ordem')->all());
        $this->assertSame(['{{primeiro_nome|tudo bem}}'], $regua->steps()->first()->variaveis['corpo']);
        $this->assertSame(120, $regua->steps()->where('ordem', 2)->first()->aguardar_horas);
        $this->assertSame('Oi {{primeiro_nome|tudo bem}}, segue o link.', $regua->steps()->where('ordem', 3)->first()->variaveis['texto']);

        Livewire::test(ViewJourney::class, ['record' => $regua->getRouteKey()])
            ->assertOk()
            ->callAction('enviar_para_aprovacao')
            ->assertHasNoActionErrors()
            ->assertActionHidden('aprovar')
            ->call('mountAction', 'aprovar')
            ->call('callMountedAction');

        $this->assertSame(Journey::STATUS_AGUARDANDO_APROVACAO, $regua->refresh()->status);

        $this->actingAs($admin);
        Livewire::test(ViewJourney::class, ['record' => $regua->getRouteKey()])
            ->callAction('aprovar', data: ['observacao' => 'ok'])
            ->assertHasNoActionErrors()
            ->callAction('ativar')
            ->assertHasNoActionErrors();

        $regua->refresh();
        $this->assertSame(Journey::STATUS_ATIVA, $regua->status);
        $this->assertTrue($regua->ativa);
        $this->assertSame($admin->id, $regua->aprovado_por);

        // Inscrição manual pelo telefone.
        $contato = Contact::factory()->create();
        Livewire::test(ViewJourney::class, ['record' => $regua->getRouteKey()])
            ->callAction('inscrever', data: ['telefone' => $contato->telefone])
            ->assertHasNoActionErrors();
        $this->assertSame(1, $regua->enrollments()->count());

        // Editar a régua ativa regrava os passos e volta a rascunho.
        Livewire::test(EditJourney::class, ['record' => $regua->getRouteKey()])
            ->assertOk()
            ->fillForm(['passos' => [['tipo' => JourneyStep::TIPO_AGUARDAR, 'aguardar_horas' => 24]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $regua->refresh();
        $this->assertSame(Journey::STATUS_RASCUNHO, $regua->status);
        $this->assertFalse($regua->ativa);
        $this->assertSame(1, $regua->steps()->count());
    }
}
