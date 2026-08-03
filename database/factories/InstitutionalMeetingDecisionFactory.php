<?php

namespace Database\Factories;

use App\Models\InstitutionalMeetingDecision;
use App\Models\InstitutionalReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstitutionalMeetingDecision>
 */
class InstitutionalMeetingDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institutional_report_id' => InstitutionalReport::factory(),
            'description' => fake()->sentence(10),
            'responsible_id' => User::factory(),
            'priority' => 'normal',
            'due_at' => fake()->dateTimeBetween('+1 day', '+2 months')->format('Y-m-d'),
            'status' => InstitutionalMeetingDecision::STATUS_TO_DO,
            'created_by' => User::factory(),
        ];
    }
}
