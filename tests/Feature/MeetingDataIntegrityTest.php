<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Direction;
use App\Models\Meeting;
use App\Models\MeetingNotification;
use App\Models\MeetingPlan;
use App\Models\MeetingReport;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MeetingDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_scope_key_is_derived_from_the_meeting_type_and_structure(): void
    {
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);

        $directionPlan = MeetingPlan::query()->create([
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'meeting_type' => MeetingType::Direction,
            'year' => 2026,
            'quarter' => 3,
            'month' => 8,
            'expected_count' => 1,
        ]);

        $servicePlan = MeetingPlan::query()->create([
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'meeting_type' => MeetingType::Service,
            'year' => 2026,
            'quarter' => 3,
            'month' => 8,
            'expected_count' => 1,
        ]);

        $this->assertNull($directionPlan->service_id);
        $this->assertSame('direction:'.$direction->id, $directionPlan->scope_key);
        $this->assertSame('service:'.$service->id, $servicePlan->scope_key);
    }

    public function test_service_plan_without_service_is_rejected_before_persistence(): void
    {
        $direction = Direction::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Le service du plan de réunions est obligatoire.');

        MeetingPlan::query()->create([
            'direction_id' => $direction->id,
            'service_id' => null,
            'meeting_type' => MeetingType::Service,
            'year' => 2026,
            'quarter' => 3,
            'month' => 8,
            'expected_count' => 1,
        ]);
    }

    public function test_legacy_meeting_report_defaults_to_unencrypted_storage(): void
    {
        $meeting = Meeting::factory()->create();
        $report = MeetingReport::query()->create([
            'meeting_id' => $meeting->id,
            'file_path' => 'legacy/pv.pdf',
            'original_file_name' => 'pv.pdf',
            'file_size' => 10,
            'mime_type' => 'application/pdf',
            'version' => 1,
            'status' => MeetingStatus::EnValidationSciq,
        ]);

        $this->assertFalse($report->is_encrypted);
        $this->assertDatabaseHas('meeting_reports', [
            'id' => $report->id,
            'is_encrypted' => false,
        ]);
    }

    public function test_deleting_a_plan_preserves_its_notification_history(): void
    {
        $direction = Direction::factory()->create();
        $plan = MeetingPlan::query()->create([
            'direction_id' => $direction->id,
            'meeting_type' => MeetingType::Direction,
            'year' => 2026,
            'quarter' => 3,
            'month' => 8,
            'expected_count' => 1,
        ]);
        $notification = MeetingNotification::query()->create([
            'meeting_plan_id' => $plan->id,
            'user_id' => User::factory()->create()->id,
            'notification_type' => MeetingNotification::TYPE_PLAN_PUBLISHED,
            'message' => 'Plan publié.',
            'sent_at' => now(),
        ]);

        $plan->delete();

        $this->assertDatabaseHas('meeting_notifications', [
            'id' => $notification->id,
            'meeting_plan_id' => null,
        ]);
    }
}
