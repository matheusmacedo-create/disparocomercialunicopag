<?php

namespace Database\Factories;

use App\Models\Sender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sender> */
class SenderFactory extends Factory
{
    protected $model = Sender::class;

    public function definition(): array
    {
        return [
            'nome' => 'Comercial '.fake()->unique()->numberBetween(1, 9999),
            'provider' => Sender::PROVIDER_KAPSO,
            'finalidade' => Sender::FINALIDADE_COMERCIAL,
            'phone_number_id' => (string) fake()->unique()->numberBetween(1000000000000000, 9999999999999999),
            'waba_id' => (string) fake()->numberBetween(1000000000000000, 9999999999999999),
            'display_phone_number' => '+55 21 9'.fake()->numberBetween(1000, 9999).'-'.fake()->numberBetween(1000, 9999),
            'api_key' => 'chave-de-teste',
            'webhook_secret' => 'segredo-de-teste',
            'categorias_permitidas' => [Sender::CATEGORIA_MARKETING, Sender::CATEGORIA_UTILITY],
            'teto_diario' => 200,
            'msgs_por_minuto' => 600,
            'janela_inicio' => '00:00',
            'janela_fim' => '23:59',
            'fuso' => 'America/Sao_Paulo',
            'qualidade' => Sender::QUALIDADE_GREEN,
            'ativo' => true,
        ];
    }

    public function meta(): static
    {
        return $this->state(['provider' => Sender::PROVIDER_META, 'api_key' => 'token-meta-de-teste']);
    }

    public function soUtility(): static
    {
        return $this->state(['categorias_permitidas' => [Sender::CATEGORIA_UTILITY]]);
    }
}
