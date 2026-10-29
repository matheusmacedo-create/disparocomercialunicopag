<?php

declare(strict_types=1);

namespace Tests\Feature\Metricas;

use App\Models\Sender;
use App\Models\WhatsappMessage;
use App\Services\Metricas\AderenciaDeTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AderenciaDeTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function mensagem(Sender $sender, array $attrs): WhatsappMessage
    {
        return WhatsappMessage::query()->create(array_merge([
            'sender_id' => $sender->id,
            'telefone' => '+5521998765432',
            'direcao' => WhatsappMessage::OUTBOUND,
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'status' => 'delivered',
            'origem' => 'historico_kapso',
            'enviado_em' => now()->subDays(5),
        ], $attrs));
    }

    public function test_conta_entregues_lidos_e_respostas_por_citacao_ou_janela_e_ordena_pela_taxa(): void
    {
        $sender = Sender::factory()->create();

        // oferta: 3 envios; 1 respondido por citação, 1 respondido na janela de 72 h, 1 sem resposta (resposta tardia).
        $this->mensagem($sender, ['wamid' => 'o1', 'template_nome' => 'oferta', 'status' => 'read', 'telefone' => '+5521911110001']);
        $this->mensagem($sender, ['wamid' => 'o2', 'template_nome' => 'oferta', 'status' => 'delivered', 'telefone' => '+5521911110002']);
        $this->mensagem($sender, ['wamid' => 'o3', 'template_nome' => 'oferta', 'status' => 'sent', 'telefone' => '+5521911110003']);
        $this->mensagem($sender, ['wamid' => 'i1', 'direcao' => WhatsappMessage::INBOUND, 'tipo' => 'text', 'status' => 'received', 'telefone' => '+5521911110001', 'context_wamid' => 'o1', 'enviado_em' => now()->subDays(1)]);
        $this->mensagem($sender, ['wamid' => 'i2', 'direcao' => WhatsappMessage::INBOUND, 'tipo' => 'text', 'status' => 'received', 'telefone' => '+5521911110002', 'enviado_em' => now()->subDays(5)->addHours(10)]);
        $this->mensagem($sender, ['wamid' => 'i3', 'direcao' => WhatsappMessage::INBOUND, 'tipo' => 'text', 'status' => 'received', 'telefone' => '+5521911110003', 'enviado_em' => now()->subDays(5)->addHours(80)]);

        // aviso: 2 envios, nenhuma resposta; 1 falhou (não conta).
        $this->mensagem($sender, ['wamid' => 'a1', 'template_nome' => 'aviso', 'status' => 'read', 'telefone' => '+5521922220001']);
        $this->mensagem($sender, ['wamid' => 'a2', 'template_nome' => 'aviso', 'status' => 'delivered', 'telefone' => '+5521922220002']);
        $this->mensagem($sender, ['wamid' => 'a3', 'template_nome' => 'aviso', 'status' => 'failed', 'telefone' => '+5521922220003']);

        // raro: 1 envio, abaixo do mínimo.
        $this->mensagem($sender, ['wamid' => 'r1', 'template_nome' => 'raro', 'status' => 'read', 'telefone' => '+5521933330001']);

        $linhas = app(AderenciaDeTemplates::class)->calcular(now()->subDays(90), null, 2);

        $this->assertSame(['oferta', 'aviso'], $linhas->pluck('template')->all());
        $oferta = $linhas->first();
        $this->assertSame([3, 2, 1, 2, 66.7, 33.3, 66.7], [$oferta['enviados'], $oferta['entregues'], $oferta['lidos'], $oferta['respondidos'], $oferta['taxa_entrega'], $oferta['taxa_leitura'], $oferta['taxa_resposta']]);
        $aviso = $linhas->last();
        $this->assertSame([2, 0, 0.0], [$aviso['enviados'], $aviso['respondidos'], $aviso['taxa_resposta']]);
    }
}
