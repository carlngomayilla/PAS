<?php

namespace Tests\Feature;

use App\Enums\MeetingStatus;
use App\Enums\MeetingType;
use App\Models\Direction;
use App\Models\InstitutionalReport;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Service;
use App\Models\User;
use App\Services\Meetings\MeetingAccessService;
use App\Services\UserWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MeetingWebTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_workspace_is_scoped_and_shows_actions_according_to_the_role(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        $fixture = $this->fixture();
        $otherDirection = Direction::factory()->create();
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $ownMeeting = Meeting::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service,
            'label' => 'Réunion visible du service',
            'responsible_id' => $fixture['chief']->id,
            'created_by' => $fixture['chief']->id,
        ]);
        $otherMeeting = Meeting::factory()->create([
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
            'meeting_type' => MeetingType::Service,
            'label' => 'Réunion confidentielle autre direction',
        ]);

        $this->assertTrue((bool) $fixture['chief']->is_active);
        $this->assertTrue(app(MeetingAccessService::class)->canViewModule($fixture['chief']));
        $this->assertTrue(Gate::forUser($fixture['chief'])->allows('viewAny', Meeting::class));

        $this->actingAs($fixture['chief'])
            ->get(route('workspace.meetings.index'))
            ->assertOk()
            ->assertSee('Réunions & procès-verbaux')
            ->assertSee($ownMeeting->label)
            ->assertDontSee($otherMeeting->label)
            ->assertSee('Programmer et notifier');

        $this->actingAs($fixture['agent'])
            ->get(route('workspace.meetings.index'))
            ->assertOk()
            ->assertSee($ownMeeting->label)
            ->assertDontSee('Programmer et notifier');

        $this->actingAs($fixture['chief'])
            ->get(route('workspace.meetings.show', $otherMeeting))
            ->assertForbidden();
    }

    public function test_schedule_validation_rejects_cross_scope_and_inconsistent_participants(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        $fixture = $this->fixture();
        $otherDirection = Direction::factory()->create();
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $outsideParticipant = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]);

        $this->actingAs($fixture['chief'])
            ->post(route('workspace.meetings.store'), [
                'direction_id' => $fixture['direction']->id,
                'service_id' => $fixture['service']->id,
                'meeting_type' => MeetingType::Service->value,
                'label' => 'Réunion valide sauf participant',
                'location' => 'Salle polyvalente',
                'responsible_id' => $fixture['chief']->id,
                'participant_ids' => [$outsideParticipant->id],
                'scheduled_date' => '2026-08-07',
                'scheduled_time' => '09:00',
            ])
            ->assertSessionHasErrors('participant_ids');

        $this->actingAs($fixture['chief'])
            ->post(route('workspace.meetings.store'), [
                'direction_id' => $otherDirection->id,
                'service_id' => $otherService->id,
                'meeting_type' => MeetingType::Service->value,
                'label' => 'Tentative hors périmètre',
                'location' => 'Salle externe',
                'responsible_id' => $outsideParticipant->id,
                'scheduled_date' => '2026-08-07',
                'scheduled_time' => '09:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_minutes_cannot_be_uploaded_before_the_meeting_time(): void
    {
        Carbon::setTestNow('2026-08-06 08:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $meeting = Meeting::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service,
            'current_scheduled_date' => '2026-08-07',
            'original_scheduled_date' => '2026-08-07',
            'scheduled_time' => '09:00',
            'year' => 2026,
            'quarter' => 3,
            'month' => 8,
            'created_by' => $fixture['chief']->id,
            'responsible_id' => $fixture['chief']->id,
        ]);

        $this->actingAs($fixture['chief'])
            ->post(route('workspace.meetings.reports.store', $meeting), [
                'report' => UploadedFile::fake()->create('pv.pdf', 100, 'application/pdf'),
                'summary' => 'Synthèse suffisamment complète pour être acceptée par la validation.',
            ])
            ->assertSessionHasErrors('workflow');

        $this->assertDatabaseCount('meeting_reports', 0);
    }

    public function test_planification_cannot_download_before_sciq_but_can_after_the_first_visa(): void
    {
        Carbon::setTestNow('2026-08-06 10:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $meeting = Meeting::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service,
            'status' => MeetingStatus::EnValidationSciq,
            'created_by' => $fixture['chief']->id,
        ]);
        Storage::disk('local')->put('tests/pv.pdf', 'contenu-pv');
        $report = MeetingReport::factory()->create([
            'meeting_id' => $meeting->id,
            'file_path' => 'tests/pv.pdf',
            'original_file_name' => 'pv.pdf',
            'file_size' => 10,
            'is_encrypted' => false,
            'status' => MeetingStatus::EnValidationSciq,
            'uploaded_by' => $fixture['chief']->id,
        ]);

        $this->actingAs($fixture['planning'])
            ->get(route('workspace.meetings.reports.download', [$meeting, $report]))
            ->assertForbidden();

        $meeting->update(['status' => MeetingStatus::EnValidationPlanification]);
        $report->update(['status' => MeetingStatus::EnValidationPlanification]);

        $this->actingAs($fixture['planning'])
            ->get(route('workspace.meetings.reports.download', [$meeting, $report]))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_director_can_open_and_download_a_service_meeting_in_their_direction(): void
    {
        Carbon::setTestNow('2026-08-06 10:00:00');
        Storage::fake('local');
        $fixture = $this->fixture();
        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $fixture['direction']->id,
            'service_id' => null,
        ]);
        $meeting = Meeting::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'meeting_type' => MeetingType::Service,
            'status' => MeetingStatus::EnValidationSciq,
            'created_by' => $fixture['chief']->id,
            'responsible_id' => $fixture['chief']->id,
        ]);
        Storage::disk('local')->put('tests/director-pv.pdf', '%PDF-1.4 director test');
        $report = MeetingReport::factory()->create([
            'meeting_id' => $meeting->id,
            'file_path' => 'tests/director-pv.pdf',
            'original_file_name' => 'director-pv.pdf',
            'file_size' => 22,
            'mime_type' => 'application/pdf',
            'is_encrypted' => false,
            'status' => MeetingStatus::EnValidationSciq,
            'uploaded_by' => $fixture['chief']->id,
        ]);

        $this->actingAs($director)
            ->get(route('workspace.meetings.index'))
            ->assertOk()
            ->assertSee($meeting->label);

        $this->actingAs($director)
            ->get(route('workspace.meetings.show', $meeting))
            ->assertOk();

        $this->actingAs($director)
            ->get(route('workspace.meetings.reports.download', [$meeting, $report]))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');

        $otherDirection = Direction::factory()->create();
        $outsideDirector = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $otherDirection->id,
            'service_id' => null,
        ]);

        $this->actingAs($outsideDirector)
            ->get(route('workspace.meetings.index'))
            ->assertOk()
            ->assertDontSee($meeting->label);

        $this->actingAs($outsideDirector)
            ->get(route('workspace.meetings.show', $meeting))
            ->assertForbidden();

        $this->actingAs($outsideDirector)
            ->get(route('workspace.meetings.reports.download', [$meeting, $report]))
            ->assertForbidden();
    }

    public function test_the_old_register_hides_legacy_meetings_and_the_sidebar_targets_the_new_module(): void
    {
        $fixture = $this->fixture();
        InstitutionalReport::factory()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'title' => 'Ancienne réunion archivée',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'submitted_by' => $fixture['chief']->id,
        ]);
        InstitutionalReport::factory()->create([
            'report_type' => InstitutionalReport::TYPE_ACTIVITY,
            'title' => 'Rapport activité conservé',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'submitted_by' => $fixture['chief']->id,
        ]);

        $this->actingAs($fixture['chief'])
            ->get(route('workspace.reports.index'))
            ->assertOk()
            ->assertSee('Rapport activité conservé')
            ->assertDontSee('Ancienne réunion archivée')
            ->assertSee('Réunions & PV')
            ->assertSee('href="'.route('workspace.meetings.index').'"', false);

        $module = collect(app(UserWorkspaceService::class)->modulesFor($fixture['chief']))
            ->firstWhere('code', 'reports');
        $this->assertIsArray($module);
        $this->assertSame('/workspace/reunions', $module['endpoint']);
        $this->assertSame('Réunions & PV', $module['label']);
    }

    /** @return array{direction:Direction,service:Service,chief:User,agent:User,planning:User} */
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
            'agent' => User::factory()->create([
                'role' => User::ROLE_AGENT,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
            ]),
            'planning' => User::factory()->create(['role' => User::ROLE_PLANIFICATION]),
        ];
    }
}
