<?php

namespace Database\Factories;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingReport>
 */
class MeetingReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'file_path' => 'meetings/reports/'.fake()->uuid().'.enc',
            'original_file_name' => 'pv-reunion.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', fake()->uuid()),
            'is_encrypted' => true,
            'version' => 1,
            'status' => MeetingStatus::EnValidationSciq,
            'summary' => fake()->paragraph(),
            'uploaded_by' => User::factory(),
            'uploaded_at' => now(),
        ];
    }
}
