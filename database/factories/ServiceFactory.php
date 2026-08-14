<?php

namespace Database\Factories;

use App\Models\Direction;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction_id' => Direction::factory(),
            'code' => fake()->unique()->bothify('SRV-###??'),
            'libelle' => 'Service '.fake()->unique()->words(2, true),
            'type' => 'service',
            'has_global_view' => false,
            'has_global_write' => false,
            'has_dual_interface' => false,
            'is_control_unit' => false,
            'is_operational' => true,
            'actif' => true,
        ];
    }
}
