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
            'nom'           => fake()->name(),
            'email'         => fake()->unique()->safeEmail(),
            'email_verifie' => now(),
            'mot_de_passe'  => static::$password ??= Hash::make('password'),
            'token_souvenir' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verifie' => null,
        ]);
    }
}
