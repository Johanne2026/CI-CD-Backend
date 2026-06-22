<?php

namespace Database\Factories;

use App\Features\Auth\Models\User;
use App\Features\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'utilisateur_id' => User::factory(),
            'titre'          => fake()->sentence(4),
            'message'        => fake()->sentence(),
            'type'           => 'info',
            'est_lu'         => false,
        ];
    }

    public function lue(): static
    {
        return $this->state(['est_lu' => true]);
    }

    public function nonLue(): static
    {
        return $this->state(['est_lu' => false]);
    }
}
