<?php

namespace Database\Factories;

use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Direction;
use App\Models\Meeting;
use App\Models\MeetingPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scheduledAt = now()->addDays(7)->startOfHour();

        return [
            'meeting_plan_id' => null,
            'direction_id' => Direction::factory(),
            'service_id' => null,
            'meeting_type' => MeetingType::Direction,
            'label' => fake()->sentence(4),
            'location' => fake()->randomElement(['Salle de conférence', 'Visioconférence']),
            'agenda' => fake()->paragraph(),
            'participant_ids' => [],
            'year' => $scheduledAt->year,
            'quarter' => MeetingPlan::quarterForMonth($scheduledAt->month),
            'month' => $scheduledAt->month,
            'original_scheduled_date' => $scheduledAt->toDateString(),
            'current_scheduled_date' => $scheduledAt->toDateString(),
            'scheduled_time' => $scheduledAt->format('H:i'),
            'held_at' => null,
            'status' => MeetingStatus::Programmee,
            'is_extra' => false,
            'was_postponed' => false,
            'postponement_count' => 0,
            'created_by' => User::factory(),
            'responsible_id' => null,
        ];
    }
}
