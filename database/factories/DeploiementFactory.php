<?php

namespace Database\Factories;

use App\Features\Deploiement\Models\Deploiement;
use App\Features\Projets\Models\Projet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deploiement>
 */
class DeploiementFactory extends Factory
{
    protected $model = Deploiement::class;

    public function definition(): array
    {
        return [
            'deployment_id' => 'ci_' . fake()->numerify('##########'),
            'ci_run_id'     => fake()->numerify('##########'),
            'cd_run_id'     => null,
            'projet_id'     => Projet::factory(),
            'nom_projet'    => fake()->words(3, true),
            'version_projet' => null,
            'commit_hash'   => fake()->optional()->regexify('[a-f0-9]{7}'),
            'branche'       => 'main',
            'environnement' => 'PPR',
            'final_statut'  => 'EN_ATTENTE',
            'ci_statut'     => 'SUCCES',
            'cd_statut'     => 'EN_ATTENTE',
            'package_hash'  => null,
            'nom_package'   => null,
            'logs'          => null,
            'commence_a'    => now(),
            'fini_a'        => null,
            'duree'         => null,
            'declenche_par' => null,
            'deploye_sur_serveur_par' => null,
            'app'           => fake()->slug(2),
            'version'       => null,
        ];
    }

    public function succes(): static
    {
        return $this->state([
            'final_statut' => 'SUCCES',
            'ci_statut'    => 'SUCCES',
            'cd_statut'    => 'SUCCES',
            'fini_a'       => now(),
            'duree'        => 120,
        ]);
    }

    public function echec(): static
    {
        return $this->state([
            'final_statut' => 'ECHEC',
            'ci_statut'    => 'ECHEC',
            'cd_statut'    => 'EN_ATTENTE',
        ]);
    }

    public function enCours(): static
    {
        return $this->state([
            'final_statut' => 'EN_COURS',
            'ci_statut'    => 'SUCCES',
            'cd_statut'    => 'EN_COURS',
        ]);
    }
}
