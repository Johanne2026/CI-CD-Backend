<?php

namespace Database\Factories;

use App\Features\Deploiement\Models\Deploiement;
use App\Features\Logs\Models\Log;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Log>
 */
class LogFactory extends Factory
{
    protected $model = Log::class;

    public function definition(): array
    {
        return [
            'deploiement_id' => Deploiement::factory(),
            'source'         => fake()->randomElement(['CI', 'CD']),
            'niveau'         => 'INFO',
            'contenu_ci'     => null,
            'contenu_cd'     => null,
            'created_at'     => now(),
        ];
    }

    public function ci(): static
    {
        return $this->state(['source' => 'CI', 'contenu_ci' => "=== BUILD ===\n2026-01-01 10:00:00 | [BUILD] Step OK"]);
    }

    public function cd(): static
    {
        return $this->state(['source' => 'CD', 'contenu_cd' => "2026-01-01 10:05:00 | Deploiement SUCCESS"]);
    }
}
