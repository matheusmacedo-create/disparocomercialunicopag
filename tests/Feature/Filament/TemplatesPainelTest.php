<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\MessageTemplates\Pages\ViewMessageTemplate;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TemplatesPainelTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_sao_so_leitura_e_avisam_categoria_nao_permitida(): void
    {
        $sender = Sender::factory()->soUtility()->create();
        $template = MessageTemplate::factory()->for($sender)->create();

        $this->actingAs($this->usuario(Permissions::ROLE_OPERATOR));

        $this->assertFalse(MessageTemplateResource::canCreate());
        $this->assertFalse(MessageTemplateResource::canEdit($template));
        $this->assertFalse(MessageTemplateResource::canDelete($template));
        $this->get('/admin/message-templates/create')->assertNotFound();
        $this->get("/admin/message-templates/{$template->id}/edit")->assertNotFound();

        $this->get('/admin/message-templates')->assertOk()->assertSee($template->nome);
        $this->get("/admin/message-templates/{$template->id}")
            ->assertOk()
            ->assertSee('Categoria não permitida neste remetente')
            ->assertSee('Oi Ana, a ÚnicoPag tem uma oferta');

        // Sincronizar é de quem gere remetentes.
        Livewire::test(ViewMessageTemplate::class, ['record' => $template->getRouteKey()])->assertActionHidden('sincronizar');

        $this->actingAs($this->usuario(Permissions::ROLE_ADMIN));
        Livewire::test(ViewMessageTemplate::class, ['record' => $template->getRouteKey()])->assertActionVisible('sincronizar')->callAction('sincronizar')->assertHasNoActionErrors();
    }
}
