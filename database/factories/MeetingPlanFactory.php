<?php

namespace Database\Factories;

use App\Enums\MeetingType;
use App\Models\Direction;
use App\Models\MeetingPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingPlan>
 */
class MeetingPlanFactory extends Factory
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
            'service_id' => null,
            'scope_key' => fn (array $attributes): string => 'direction:'.$attributes['direction_id'],
            'meeting_type' => MeetingType::Direction,
            'year' => now()->year,
            'quarter' => MeetingPlan::quarterForMonth(now()->month),
            'month' => now()->month,
            'expected_count' => 2,
            'created_by' => User::factory(),
        ];
    }
}
