<?php

namespace Database\Factories;

use App\Features\Auth\Models\User;
use App\Features\Equipes\Models\Equipe;
use App\Features\Projets\Models\Projet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Projet>
 */
class ProjetFactory extends Factory
{
    protected $model = Projet::class;

    public function definition(): array
    {
        return [
            'equipe_id'           => Equipe::factory(),
            'cree_par_id'         => User::factory()->administrateur(),
            'nom'                 => fake()->words(3, true),
            'description'         => fake()->optional()->sentence(),
            'stack_technologique' => ['Laravel', 'React'],
            'actif'               => true,
            'duree_projet'        => fake()->optional()->randomElement(['3 mois', '6 mois', '1 an']),
            'url_depot'           => null,
        ];
    }

    public function avecDepot(): static
    {
        return $this->state([
            'url_depot' => 'https://github.com/organisation/mon-repo',
        ]);
    }

    public function archive(): static
    {
        return $this->state(['actif' => false]);
    }
}
