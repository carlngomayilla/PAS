<?php

namespace Database\Factories;

use App\Models\InstitutionalReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstitutionalReport>
 */
class InstitutionalReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_type' => InstitutionalReport::TYPE_ACTIVITY,
            'title' => fake()->sentence(5),
            'summary' => fake()->paragraph(),
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => User::factory(),
        ];
    }
}
