<?php

namespace Database\Factories;

use App\Models\MonitoredService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredService>
 */
class MonitoredServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Service',
            'url' => fake()->url(),
            'category' => fake()->optional()->randomElement(['API', 'Website', 'Portal', 'Integration']),
            'expected_status_code' => 200,
            'expected_keyword' => fake()->optional()->word(),
            'check_interval_minutes' => fake()->randomElement([5, 10, 15, 30, 60]),
            'warning_response_ms' => 1500,
            'critical_response_ms' => 3000,
            'is_active' => fake()->boolean(85),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
