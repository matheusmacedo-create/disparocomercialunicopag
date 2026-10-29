<?php

namespace Database\Factories;

use App\Models\ContactList;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContactList> */
class ContactListFactory extends Factory
{
    protected $model = ContactList::class;

    public function definition(): array
    {
        return [
            'nome' => 'Base '.fake()->unique()->numberBetween(1, 99999),
            'dono' => ContactList::DONO_UNICOPAG,
            'exige_consentimento' => false,
            'ativa' => true,
        ];
    }

    public function unicodrop(): static
    {
        return $this->state(['dono' => ContactList::DONO_UNICODROP, 'exige_consentimento' => true]);
    }
}
