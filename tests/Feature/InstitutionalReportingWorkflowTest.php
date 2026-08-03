<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\InstitutionalMeetingDecision;
use App\Models\InstitutionalReport;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionalReportingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_report_is_verified_by_the_full_sciq_and_planning_chain(): void
    {
        $fixture = $this->createFixture();
        $report = $this->reportWithAttachment($fixture['reporter'], $fixture['direction'], $fixture['service']);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.submit', $report))
            ->assertRedirect(route('workspace.reports.index', ['tab' => 'review']));
        $this->assertSame(InstitutionalReport::STATUS_SUBMITTED_SCIQ, $report->fresh()->status);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.review', $report), ['decision' => 'approve', 'note' => 'Tentative interdite.'])
            ->assertForbidden();

        foreach ([
            $fixture['sciq'] => InstitutionalReport::STATUS_SUBMITTED_PLANNING,
            $fixture['planning'] => InstitutionalReport::STATUS_SUBMITTED_SCIQ_CHIEF,
            $fixture['sciqChief'] => InstitutionalReport::STATUS_SUBMITTED_PLANNING_CHIEF,
            $fixture['planningChief'] => InstitutionalReport::STATUS_VERIFIED,
        ] as $reviewerId => $expectedStatus) {
            $reviewer = User::query()->findOrFail($reviewerId);
            $this->actingAs($reviewer)
                ->post(route('workspace.reports.review', $report), ['decision' => 'approve', 'note' => 'Dossier vérifié et transmis.'])
                ->assertRedirect(route('workspace.reports.show', $report));

            $this->assertSame($expectedStatus, $report->fresh()->status);
        }

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'institutional_reports',
            'action' => 'institutional_report_review',
            'entite_id' => $report->id,
        ]);
    }

    public function test_a_scheduled_meeting_can_only_enter_the_review_chain_after_its_minutes_are_uploaded(): void
    {
        $fixture = $this->createFixture();
        $meeting = InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'title' => 'Réunion de service mensuelle',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => '2026-08-10 09:00:00',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.submit', $meeting))
            ->assertSessionHasErrors('report');

        $meeting->justificatifs()->create([
            'categorie' => 'rapport_institutionnel',
            'nom_original' => 'compte-rendu.pdf',
            'chemin_stockage' => 'tests/compte-rendu.pdf',
            'mime_type' => 'application/pdf',
            'taille_octets' => 1200,
            'description' => 'Compte rendu de la réunion.',
            'ajoute_par' => $fixture['reporter']->id,
        ]);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.resubmit', $meeting), [
                'summary' => 'Décisions, responsables et échéances de la réunion.',
                'held_at' => '2026-08-10 09:30:00',
            ])
            ->assertRedirect(route('workspace.reports.show', $meeting));

        $meeting->refresh();
        $this->assertSame(InstitutionalReport::STATUS_SUBMITTED_SCIQ, $meeting->status);
        $this->assertSame('2026-08-10 09:30:00', $meeting->held_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_service_chief_can_schedule_and_postpone_a_meeting_within_the_same_quarter(): void
    {
        $fixture = $this->createFixture();
        $participant = User::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
        ]);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.store'), [
                'report_type' => InstitutionalReport::TYPE_MEETING,
                'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
                'direction_id' => $fixture['direction']->id,
                'service_id' => $fixture['service']->id,
                'scheduled_at' => '2026-08-10 09:00:00',
                'location' => 'Salle de reunion',
                'responsible_id' => $fixture['reporter']->id,
                'participant_ids' => [$fixture['reporter']->id, $participant->id],
            ])
            ->assertRedirect(route('workspace.reports.index', ['tab' => 'schedule']));

        $meeting = InstitutionalReport::query()->sole();
        $this->assertSame(InstitutionalReport::MEETING_TYPE_SERVICE, $meeting->meeting_type);
        $this->assertSame([$fixture['reporter']->id, $participant->id], $meeting->participant_ids);
        $this->assertSame('2026-08-10 09:00:00', $meeting->original_scheduled_at?->format('Y-m-d H:i:s'));

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.postpone', $meeting), [
                'scheduled_at' => '2026-09-08 10:00:00',
                'reason' => 'Indisponibilite ponctuelle des participants essentiels.',
            ])
            ->assertRedirect(route('workspace.reports.show', $meeting));

        $meeting->refresh();
        $this->assertSame('2026-09-08 10:00:00', $meeting->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $meeting->postponement_count);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.postpone', $meeting), [
                'scheduled_at' => '2026-10-01 10:00:00',
                'reason' => 'Cette date ne doit pas sortir du trimestre initial.',
            ])
            ->assertSessionHasErrors('scheduled_at');
    }

    public function test_an_agent_cannot_schedule_or_postpone_a_meeting_outside_the_management_roles(): void
    {
        $fixture = $this->createFixture();
        $agent = User::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
        ]);

        $this->actingAs($agent)
            ->post(route('workspace.reports.store'), [
                'report_type' => InstitutionalReport::TYPE_MEETING,
                'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
                'direction_id' => $fixture['direction']->id,
                'service_id' => $fixture['service']->id,
                'scheduled_at' => '2026-08-10 09:00:00',
                'location' => 'Salle de reunion',
                'responsible_id' => $agent->id,
                'participant_ids' => [$agent->id],
            ])
            ->assertForbidden();

        $meeting = InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion de coordination',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => '2026-08-10 09:00:00',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);

        $this->actingAs($agent)
            ->post(route('workspace.reports.postpone', $meeting), [
                'scheduled_at' => '2026-09-08 10:00:00',
                'reason' => 'Tentative non autorisee de modification du calendrier.',
            ])
            ->assertForbidden();
    }

    public function test_a_meeting_cancellation_is_traced_and_prevents_any_later_postponement(): void
    {
        $fixture = $this->createFixture();
        $meeting = InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion a annuler',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => '2026-08-10 09:00:00',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.cancel', $meeting), [
                'reason' => 'Indisponibilite complete des participants a cette date.',
            ])
            ->assertRedirect(route('workspace.reports.show', $meeting));

        $this->assertNotNull($meeting->fresh()->cancelled_at);
        $this->assertSame('Indisponibilite complete des participants a cette date.', $meeting->fresh()->cancellation_reason);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.postpone', $meeting), [
                'scheduled_at' => '2026-09-08 10:00:00',
                'reason' => 'Tentative de report apres annulation de la reunion.',
            ])
            ->assertForbidden();
    }

    public function test_meeting_decisions_are_scoped_to_the_meeting_manager_or_assigned_responsible(): void
    {
        $fixture = $this->createFixture();
        $assignee = User::factory()->create([
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
        ]);
        $meeting = InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion de suivi',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => '2026-08-10 09:00:00',
            'held_at' => '2026-08-10 10:00:00',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);

        $this->actingAs($fixture['reporter'])
            ->post(route('workspace.reports.decisions.store', $meeting), [
                'description' => 'Mettre a jour la liste des livrables du trimestre.',
                'responsible_id' => $assignee->id,
                'priority' => 'high',
                'due_at' => '2026-08-20',
            ])
            ->assertRedirect(route('workspace.reports.show', $meeting));

        $decision = InstitutionalMeetingDecision::query()->sole();
        $this->assertSame(InstitutionalMeetingDecision::STATUS_TO_DO, $decision->status);

        $this->actingAs($assignee)
            ->patch(route('workspace.reports.decisions.update', [$meeting, $decision]), [
                'status' => InstitutionalMeetingDecision::STATUS_COMPLETED,
                'follow_up_note' => 'Livrables mis a jour et transmis.',
            ])
            ->assertRedirect(route('workspace.reports.show', $meeting));

        $this->assertSame(InstitutionalMeetingDecision::STATUS_COMPLETED, $decision->fresh()->status);
        $this->assertNotNull($decision->fresh()->completed_at);
        $this->actingAs($fixture['reporter'])
            ->get(route('workspace.reports.show', $meeting))
            ->assertOk()
            ->assertSee('Mettre a jour la liste des livrables du trimestre.');
    }

    public function test_meeting_reminders_only_target_meetings_due_on_the_requested_day(): void
    {
        $fixture = $this->createFixture();
        InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion avec rappel',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => now()->addDays(7)->setTime(9, 0),
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);
        InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion sans rappel aujourd hui',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => now()->addDays(3)->setTime(9, 0),
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);

        $this->artisan('meetings:send-reminders', ['--days' => '7', '--dry-run' => true])
            ->expectsOutputToContain('Reunion avec rappel')
            ->expectsOutputToContain('1 rappel(s) simulé(s).')
            ->assertSuccessful();
    }

    public function test_meeting_filters_and_excel_export_follow_the_users_accessible_scope(): void
    {
        $fixture = $this->createFixture();
        $heldMeeting = InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion tenue a exporter',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => '2026-08-10 09:00:00',
            'held_at' => '2026-08-10 09:30:00',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);
        InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_MEETING,
            'meeting_type' => InstitutionalReport::MEETING_TYPE_SERVICE,
            'title' => 'Reunion annulee a exclure',
            'direction_id' => $fixture['direction']->id,
            'service_id' => $fixture['service']->id,
            'scheduled_at' => '2026-08-11 09:00:00',
            'cancelled_at' => now(),
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $fixture['reporter']->id,
        ]);

        $this->actingAs($fixture['reporter'])
            ->get(route('workspace.reports.index', ['tab' => 'schedule', 'status' => 'held']))
            ->assertOk()
            ->assertSee($heldMeeting->title)
            ->assertDontSee('Reunion annulee a exclure');

        $this->actingAs($fixture['reporter'])
            ->get(route('workspace.reports.export', ['format' => 'xlsx', 'status' => 'held']))
            ->assertOk()
            ->assertDownload('rapport-reunions.xlsx');

        $this->actingAs($fixture['reporter'])
            ->get(route('workspace.reports.export', ['format' => 'pdf', 'status' => 'held']))
            ->assertOk()
            ->assertDownload('rapport-reunions.pdf');

        $this->actingAs($fixture['reporter'])
            ->get(route('workspace.reports.export', ['format' => 'docx', 'status' => 'held']))
            ->assertOk()
            ->assertDownload('rapport-reunions.docx');

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'institutional_reports',
            'action' => 'institutional_meeting_export',
            'entite_id' => $heldMeeting->id,
        ]);
    }

    /** @return array{direction:Direction,service:Service,reporter:User,sciq:int,planning:int,sciqChief:int,planningChief:int} */
    private function createFixture(): array
    {
        $direction = Direction::query()->create(['code' => 'DOP', 'libelle' => 'Direction opérationnelle', 'actif' => true]);
        $service = Service::query()->create(['direction_id' => $direction->id, 'code' => 'SOP', 'libelle' => 'Service opérationnel', 'actif' => true]);
        $reporter = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id, 'service_id' => $service->id]);

        return [
            'direction' => $direction,
            'service' => $service,
            'reporter' => $reporter,
            'sciq' => User::factory()->create(['role' => User::ROLE_SCIQ])->id,
            'planning' => User::factory()->create(['role' => User::ROLE_PLANIFICATION])->id,
            'sciqChief' => User::factory()->create(['role' => User::ROLE_CHEF_UNITE_SCIQ])->id,
            'planningChief' => User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION])->id,
        ];
    }

    private function reportWithAttachment(User $reporter, Direction $direction, Service $service): InstitutionalReport
    {
        $report = InstitutionalReport::query()->create([
            'report_type' => InstitutionalReport::TYPE_ACTIVITY,
            'title' => 'Rapport mensuel du service',
            'summary' => 'Activités réalisées et incidents relevés.',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $reporter->id,
        ]);
        $report->justificatifs()->create([
            'categorie' => 'rapport_institutionnel',
            'nom_original' => 'rapport.pdf',
            'chemin_stockage' => 'tests/rapport.pdf',
            'mime_type' => 'application/pdf',
            'taille_octets' => 1200,
            'description' => 'Rapport institutionnel.',
            'ajoute_par' => $reporter->id,
        ]);

        return $report;
    }
}
