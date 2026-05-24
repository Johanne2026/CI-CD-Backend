<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Features\Auth\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'nom'                 => fake()->lastName(),
            'prenom'              => fake()->firstName(),
            'username_outil_cicd' => fake()->unique()->userName(),
            'mot_de_passe'        => static::$password ??= Hash::make('password'),
            'api_token'           => null,
            'token_outil_cicd'    => null,
            'date_inscription'    => now(),
            'role'                => fake()->randomElement(['administrateur', 'administrateur_cloud_doi', 'securite']),
        ];
    }
}
