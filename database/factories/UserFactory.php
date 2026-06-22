<?php

namespace Database\Factories;

use App\Features\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'nom'                       => fake()->lastName(),
            'prenom'                    => fake()->firstName(),
            'email'                     => fake()->unique()->safeEmail(),
            'username_outil_cicd'       => fake()->unique()->userName(),
            'mot_de_passe'              => 'password',   // cast 'hashed' s'applique
            'api_token'                 => null,
            'token_github'              => null,
            'date_inscription'          => now(),
            'role'                      => 'securite',
            'doit_changer_mot_de_passe' => false,
        ];
    }

    /** Rôle administrateur */
    public function administrateur(): static
    {
        return $this->state(['role' => 'administrateur']);
    }

    /** Rôle administrateur_cloud_doi */
    public function cloudDoi(): static
    {
        return $this->state(['role' => 'administrateur_cloud_doi']);
    }

    /** Rôle securite */
    public function securite(): static
    {
        return $this->state(['role' => 'securite']);
    }

    /** Utilisateur avec un api_token déjà renseigné (connecté) */
    public function connecte(): static
    {
        $token = Str::random(60);
        return $this->state(['api_token' => hash('sha256', $token)]);
    }

    /** Utilisateur qui doit changer son mot de passe */
    public function doitChangerMdp(): static
    {
        return $this->state(['doit_changer_mot_de_passe' => true]);
    }
}
