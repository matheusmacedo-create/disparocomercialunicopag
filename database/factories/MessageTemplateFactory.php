<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use App\Models\Sender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageTemplate> */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    public function definition(): array
    {
        return [
            'sender_id' => Sender::factory(),
            'meta_id' => (string) fake()->numberBetween(100000000000000, 999999999999999),
            'nome' => 'oferta_maquininha_v1',
            'idioma' => 'pt_BR',
            'categoria' => Sender::CATEGORIA_MARKETING,
            'status' => MessageTemplate::STATUS_APPROVED,
            'parameter_format' => 'POSITIONAL',
            'componentes' => [
                ['type' => 'BODY', 'text' => 'Oi {{1}}, a ÚnicoPag tem uma oferta para a sua loja. Responda SAIR para não receber mais.'],
                ['type' => 'FOOTER', 'text' => 'Contato oficial ÚnicoPag · CNPJ 52.254.162/0001-50'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quero saber mais'], ['type' => 'QUICK_REPLY', 'text' => 'Parar promoções']]],
            ],
            'sincronizado_em' => now(),
        ];
    }

    public function utility(): static
    {
        return $this->state([
            'nome' => 'aviso_conta_v1',
            'categoria' => Sender::CATEGORIA_UTILITY,
            'componentes' => [
                ['type' => 'BODY', 'text' => 'Olá {{1}}. Aviso sobre a sua conta ÚnicoPag: situação {{2}}.'],
            ],
        ]);
    }

    public function semVariaveis(): static
    {
        return $this->state(['nome' => 'aviso_fixo_v1', 'categoria' => Sender::CATEGORIA_UTILITY, 'componentes' => [['type' => 'BODY', 'text' => 'Aviso fixo da ÚnicoPag.']]]);
    }
}
