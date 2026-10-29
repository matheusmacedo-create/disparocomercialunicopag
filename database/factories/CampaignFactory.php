<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Sender;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'nome' => 'Campanha '.fake()->unique()->numberBetween(1, 99999),
            'sender_id' => Sender::factory(),
            'objetivo' => Campaign::OBJETIVO_RECUPERAR,
            'template_nome' => 'oferta_maquininha_v1',
            'template_idioma' => 'pt_BR',
            'prioridade' => 5,
            'variaveis' => ['corpo' => ['{{primeiro_nome|tudo bem}}']],
            'publico' => [],
            'regras' => [],
        ];
    }

    /** Já em andamento, como o executor espera (status fora do $fillable: forceFill no afterCreating). */
    public function emAndamento(): static
    {
        return $this->afterCreating(fn (Campaign $c) => $c->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO, 'iniciada_em' => now(), 'tranches_enviadas' => 1])->save());
    }

    public function aprovada(): static
    {
        return $this->afterCreating(fn (Campaign $c) => $c->forceFill(['status' => Campaign::STATUS_APROVADA, 'aprovado_em' => now()])->save());
    }
}
