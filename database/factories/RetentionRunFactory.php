<?php

namespace Database\Factories;

use App\Models\RetentionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionRun>
 */
class RetentionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => RetentionRun::SCOPE_DATA,
            'mode' => RetentionRun::MODE_DRY_RUN,
            'status' => RetentionRun::STATUS_COMPLETED,
            'source' => 'web',
            'candidates' => ['pas' => fake()->numberBetween(0, 10)],
            'processed' => ['pas' => 0],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ];
    }
}
