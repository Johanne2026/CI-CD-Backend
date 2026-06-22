<?php

namespace Database\Factories;

use App\Features\Auth\Models\User;
use App\Features\Equipes\Models\Equipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipe>
 */
class EquipeFactory extends Factory
{
    protected $model = Equipe::class;

    public function definition(): array
    {
        return [
            'proprietaire_id' => User::factory()->administrateur(),
            'nom'             => fake()->company() . ' Team',
            'description'     => fake()->optional()->sentence(),
        ];
    }
}
