<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        $nome = fake()->firstName().' '.fake()->lastName();

        return [
            'telefone' => '+5521'.fake()->unique()->numerify('9########'),
            'nome' => $nome,
            'email' => fake()->unique()->safeEmail(),
            'empresa' => fake()->company(),
            'origem' => 'unicopag',
            'tipo' => 'seller_inativo',
            'status' => Contact::STATUS_ATIVO,
            'atributos' => [],
            'consentimento_origem' => 'cliente_ativo',
            'consentimento_em' => now(),
        ];
    }

    public function externo(): static
    {
        return $this->state(['origem' => 'unicodrop', 'tipo' => 'lead', 'consentimento_origem' => null, 'consentimento_em' => null]);
    }

    public function comConsentimento(): static
    {
        return $this->state(['consentimento_origem' => 'whatsapp_optin', 'consentimento_em' => now(), 'consentimento_canal' => 'whatsapp']);
    }

    public function optout(): static
    {
        return $this->state(['status' => Contact::STATUS_OPTOUT, 'optout_em' => now(), 'optout_motivo' => 'pediu_para_parar', 'optout_origem' => 'resposta']);
    }
}
