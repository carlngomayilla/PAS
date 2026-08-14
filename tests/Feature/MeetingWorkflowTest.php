<?php

namespace Tests\Feature;

use App\Enums\MeetingApprovalDecision;
use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Direction;
use App\Models\Meeting;
use App\Models\Service;
use App\Models\User;
use App\Services\Meetings\MeetingWorkflowService;
use App\Services\PersonalTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MeetingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_complete_two_level_workflow_validates_and_locks_the_minutes(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);

        $plan = $workflow->definePlan([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service->value,
            'year' => 2026,
            'month' => 8,
            'expected_count' => 1,
        ], $fixture['sciq']);

        $meeting = $this->schedule($workflow, $fixture, '2026-08-07', '09:00');
        $this->assertSame($plan->id, $meeting->meeting_plan_id);
        $this->assertFalse($meeting->is_extra);
        $this->assertDatabaseHas('meeting_notifications', [
            'meeting_plan_id' => $plan->id,
            'meeting_id' => null,
            'notification_type' => 'plan_published',
        ]);

        Carbon::setTestNow('2026-08-07 10:00:00');
        $report = $workflow->submitReport(
            $meeting,
            UploadedFile::fake()->create('pv-service.pdf', 120, 'application/pdf'),
            ['summary' => 'Synthèse complète des échanges, décisions et responsabilités arrêtées.'],
            $fixture['chief']
        );

        $this->assertSame(MeetingStatus::EnValidationSciq, $meeting->fresh()->status);
        $this->assertSame(1, $report->version);
        Storage::disk('local')->assertExists($report->file_path);

        $report = $workflow->review($report, MeetingApprovalDecision::Validated, 'PV conforme au cadre attendu.', $fixture['sciq']);
        $this->assertSame(MeetingStatus::EnValidationPlanification, $meeting->fresh()->status);

        $report = $workflow->review($report, MeetingApprovalDecision::Validated, 'Visa final accordé.', $fixture['planning']);
        $this->assertSame(MeetingStatus::ValideeDefinitivement, $meeting->fresh()->status);
        $this->assertTrue($report->fresh()->isLocked());
        $this->assertCount(2, $report->approvals()->get());
        $this->assertDatabaseHas('meeting_status_histories', [
            'meeting_id' => $meeting->id,
            'new_status' => MeetingStatus::ValideeDefinitivement->value,
        ]);
    }

    public function test_a_correction_creates_a_new_version_and_restarts_with_sciq(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);
        $meeting = $this->schedule($workflow, $fixture, '2026-08-06', '09:00');

        Carbon::setTestNow('2026-08-06 10:00:00');
        $first = $workflow->submitReport(
            $meeting,
            UploadedFile::fake()->create('pv-v1.pdf', 100, 'application/pdf'),
            ['summary' => 'Première synthèse du procès-verbal soumise au contrôle du SCIQ.'],
            $fixture['chief']
        );
        $workflow->review($first, MeetingApprovalDecision::CorrectionRequested, 'Préciser les responsables et les échéances.', $fixture['sciq']);

        $this->assertSame(MeetingStatus::ACorriger, $meeting->fresh()->status);
        $second = $workflow->submitReport(
            $meeting->fresh(),
            UploadedFile::fake()->create('pv-v2.pdf', 110, 'application/pdf'),
            ['summary' => 'Version corrigée avec responsables, livrables et échéances explicites.'],
            $fixture['chief']
        );

        $this->assertSame(2, $second->version);
        $this->assertSame(MeetingStatus::EnValidationSciq, $second->status);
        $this->assertDatabaseCount('meeting_reports', 2);
        $this->assertDatabaseHas('meeting_reports', ['id' => $first->id, 'status' => MeetingStatus::ACorriger->value]);
    }

    public function test_scope_order_self_review_and_separation_of_visas_are_enforced(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);
        $otherDirection = Direction::factory()->create();
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $otherChief = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]);

        try {
            $workflow->scheduleMeeting([
                'direction_id' => $fixture['direction']->id,
                'service_id' => $fixture['service']->id,
                'meeting_type' => MeetingType::Service->value,
                'label' => 'Tentative hors périmètre',
                'location' => 'Salle 2',
                'responsible_id' => $fixture['chief']->id,
                'scheduled_date' => '2026-08-07',
                'scheduled_time' => '09:00',
            ], $otherChief);
            $this->fail('La programmation hors périmètre aurait dû être refusée.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ne pouvez pas programmer', $exception->getMessage());
        }

        $meeting = $this->schedule($workflow, $fixture, '2026-08-06', '09:00');
        Carbon::setTestNow('2026-08-06 10:00:00');
        $report = $workflow->submitReport(
            $meeting,
            UploadedFile::fake()->create('pv.pdf', 100, 'application/pdf'),
            ['summary' => 'Synthèse suffisamment détaillée pour engager le circuit de validation.'],
            $fixture['chief']
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("n'êtes pas autorisé");
        $workflow->review($report, MeetingApprovalDecision::Validated, null, $fixture['planning']);
    }

    public function test_the_same_person_cannot_pose_both_visas(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);
        $administrator = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $meeting = $this->schedule($workflow, $fixture, '2026-08-06', '09:00');

        Carbon::setTestNow('2026-08-06 10:00:00');
        $report = $workflow->submitReport(
            $meeting,
            UploadedFile::fake()->create('pv.pdf', 100, 'application/pdf'),
            ['summary' => 'Synthèse suffisamment détaillée pour la séparation des deux visas.'],
            $fixture['chief']
        );
        $report = $workflow->review($report, MeetingApprovalDecision::Validated, null, $administrator);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deux visas distincts');
        $workflow->review($report, MeetingApprovalDecision::Validated, null, $administrator);
    }

    public function test_the_uploader_cannot_review_their_own_minutes(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);
        $administrator = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $meeting = $this->schedule($workflow, $fixture, '2026-08-06', '09:00');

        Carbon::setTestNow('2026-08-06 10:00:00');
        $report = $workflow->submitReport(
            $meeting,
            UploadedFile::fake()->create('pv-admin.pdf', 100, 'application/pdf'),
            ['summary' => 'Synthèse déposée par un administrateur qui ne peut pas se contrôler lui-même.'],
            $administrator
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('propre dépôt');
        $workflow->review($report, MeetingApprovalDecision::Validated, null, $administrator);
    }

    public function test_personal_tasks_route_minutes_and_visas_to_the_expected_roles(): void
    {
        Carbon::setTestNow('2026-08-06 10:00:00');
        $fixture = $this->fixture();
        $meeting = Meeting::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service,
            'status' => MeetingStatus::PvAttendu,
            'current_scheduled_date' => '2026-08-06',
            'original_scheduled_date' => '2026-08-06',
            'scheduled_time' => '08:00',
            'created_by' => $fixture['chief']->id,
            'responsible_id' => $fixture['chief']->id,
        ]);

        $chiefTasks = collect(app(PersonalTaskService::class)->forUser($fixture['chief'], 100)['items']);
        $this->assertTrue($chiefTasks->contains('key', 'meeting-owner:'.$meeting->id));

        $meeting->update(['status' => MeetingStatus::EnValidationSciq]);
        $report = $meeting->reports()->create([
            'file_path' => 'tests/pv.pdf',
            'original_file_name' => 'pv.pdf',
            'file_size' => 10,
            'mime_type' => 'application/pdf',
            'is_encrypted' => false,
            'version' => 1,
            'status' => MeetingStatus::EnValidationSciq,
            'summary' => 'Synthèse du PV à contrôler.',
            'uploaded_by' => $fixture['chief']->id,
            'uploaded_at' => now(),
        ]);

        $sciqTasks = collect(app(PersonalTaskService::class)->forUser($fixture['sciq'], 100)['items']);
        $this->assertTrue($sciqTasks->contains('key', 'meeting-review:'.$meeting->id.':'.MeetingStatus::EnValidationSciq->value));
        $this->assertSame($report->id, $meeting->currentReport()->value('id'));
    }

    public function test_an_elapsed_meeting_becomes_a_notified_minutes_task(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);
        $meeting = $this->schedule($workflow, $fixture, '2026-08-06', '09:00');

        Carbon::setTestNow('2026-08-06 10:00:00');
        $this->assertSame(1, $workflow->markDueMeetingsAsAwaitingReport());
        $this->assertSame(MeetingStatus::PvAttendu, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_notifications', [
            'meeting_id' => $meeting->id,
            'user_id' => $fixture['chief']->id,
            'notification_type' => 'report_expected',
        ]);
    }

    public function test_postponing_to_another_month_reattaches_the_plan_and_recalculates_extra_meetings(): void
    {
        Carbon::setTestNow('2026-08-01 08:00:00');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);

        $august = $workflow->definePlan([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service->value,
            'year' => 2026,
            'month' => 8,
            'expected_count' => 1,
        ], $fixture['sciq']);
        $september = $workflow->definePlan([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service->value,
            'year' => 2026,
            'month' => 9,
            'expected_count' => 1,
        ], $fixture['sciq']);

        $meeting = $this->schedule($workflow, $fixture, '2026-08-20', '09:00');
        $updated = $workflow->postponeMeeting(
            $meeting,
            '2026-09-10',
            '10:00',
            'Disponibilité commune des participants confirmée en septembre.',
            $fixture['chief']
        );

        $this->assertSame($september->id, $updated->meeting_plan_id);
        $this->assertSame(MeetingStatus::Reportee, $updated->status);
        $this->assertFalse($updated->is_extra);
        $this->assertSame(0, $august->meetings()->count());
    }

    public function test_an_elapsed_meeting_cannot_be_cancelled_or_postponed_by_a_service_chief(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        $fixture = $this->fixture();
        $workflow = app(MeetingWorkflowService::class);
        $meeting = $this->schedule($workflow, $fixture, '2026-08-06', '09:00');
        Carbon::setTestNow('2026-08-06 10:00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('échue ne peut plus être annulée');
        $workflow->cancelMeeting($meeting, 'Annulation tardive qui doit être systématiquement refusée.', $fixture['chief']);
    }

    /** @return array{direction:Direction,service:Service,chief:User,sciq:User,planning:User} */
    private function fixture(): array
    {
        $direction = Direction::factory()->create(['code' => 'DOP', 'libelle' => 'Direction des opérations']);
        $service = Service::factory()->create([
            'direction_id' => $direction->id,
            'code' => 'SOP',
            'libelle' => 'Service des opérations',
        ]);

        return [
            'direction' => $direction,
            'service' => $service,
            'chief' => User::factory()->create([
                'role' => User::ROLE_SERVICE,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
            ]),
            'sciq' => User::factory()->create(['role' => User::ROLE_SCIQ]),
            'planning' => User::factory()->create(['role' => User::ROLE_PLANIFICATION]),
        ];
    }

    /** @param array{direction:Direction,service:Service,chief:User,sciq:User,planning:User} $fixture */
    private function schedule(
        MeetingWorkflowService $workflow,
        array $fixture,
        string $date,
        string $time
    ): Meeting {
        return $workflow->scheduleMeeting([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service->value,
            'label' => 'Réunion mensuelle du service',
            'location' => 'Salle de conférence',
            'agenda' => 'Résultats du mois et actions prioritaires.',
            'responsible_id' => $fixture['chief']->id,
            'participant_ids' => [$fixture['chief']->id],
            'scheduled_date' => $date,
            'scheduled_time' => $time,
        ], $fixture['chief']);
    }
}
