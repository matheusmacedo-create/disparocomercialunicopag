<?php

namespace Database\Factories;

use App\Models\Journey;
use App\Models\Sender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Journey> */
class JourneyFactory extends Factory
{
    protected $model = Journey::class;

    public function definition(): array
    {
        return [
            'nome' => 'Régua '.fake()->unique()->numberBetween(1, 99999),
            'sender_id' => Sender::factory(),
            'objetivo' => 'recuperar',
            'gatilho' => Journey::GATILHO_MANUAL,
            'sair_ao_responder' => true,
            'sair_ao_converter' => true,
            'ignora_conversa_aberta' => true,
            'intervalo_minimo_horas' => 48,
            'max_por_dia' => 50,
        ];
    }

    public function ativa(): static
    {
        return $this->afterCreating(fn (Journey $j) => $j->forceFill(['status' => Journey::STATUS_ATIVA, 'ativa' => true, 'aprovado_em' => now()])->save());
    }
}
