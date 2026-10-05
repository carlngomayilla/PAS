<?php

namespace Tests\Feature;

use App\Models\InstitutionalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionalReportWorkflowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_index_exposes_period_filters_for_reconciliation(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.reports.index', ['year' => '2026', 'quarter' => '2', 'month' => '4']))
            ->assertOk()
            ->assertSee('Exercice')
            ->assertSee('Trimestre')
            ->assertSee('Mois')
            ->assertSee('data-auto-filter-form', false)
            ->assertSee('name="year"', false)
            ->assertSee('name="quarter"', false)
            ->assertSee('name="month"', false);
    }

    public function test_report_detail_shows_verification_circuit_and_next_action(): void
    {
        $submitter = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'password_changed_at' => now(),
        ]);
        $report = InstitutionalReport::factory()->create([
            'submitted_by' => $submitter->id,
            'status' => InstitutionalReport::STATUS_SUBMITTED_PLANNING,
            'title' => 'Rapport activite trimestriel',
            'review_history' => [
                [
                    'at' => now()->subDay()->toIso8601String(),
                    'user_id' => $submitter->id,
                    'user' => $submitter->name,
                    'role' => $submitter->roleLabel(),
                    'event' => 'submitted_sciq',
                    'message' => 'Soumis au SCIQ pour verification.',
                    'note' => null,
                ],
            ],
        ]);

        $this->actingAs($submitter)
            ->get(route('workspace.reports.show', $report))
            ->assertOk()
            ->assertSee('Prochaine action')
            ->assertSee('Vérification attendue de la Planification')
            ->assertSee('Circuit de vérification')
            ->assertSee('SCIQ → Planification → Chefs')
            ->assertSee('Contrôle SCIQ')
            ->assertSee('Vérification Planification')
            ->assertSee('Les visas documentaires ne clôturent pas une action.');
    }

    public function test_returned_report_detail_shows_latest_correction_reason(): void
    {
        $submitter = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'password_changed_at' => now(),
        ]);
        $controller = User::factory()->create([
            'role' => User::ROLE_SCIQ,
            'password_changed_at' => now(),
        ]);
        $report = InstitutionalReport::factory()->create([
            'submitted_by' => $submitter->id,
            'status' => InstitutionalReport::STATUS_RETURNED,
            'title' => 'Rapport incident sans piece complete',
            'review_history' => [
                [
                    'at' => now()->subHour()->toIso8601String(),
                    'user_id' => $controller->id,
                    'user' => $controller->name,
                    'role' => $controller->roleLabel(),
                    'event' => 'returned',
                    'message' => 'Retour au deposant pour correction.',
                    'note' => 'Joindre la piece justificative signee avant nouvelle soumission.',
                ],
            ],
        ]);

        $this->actingAs($submitter)
            ->get(route('workspace.reports.show', $report))
            ->assertOk()
            ->assertSee('Correction attendue du déposant')
            ->assertSee('Dernier motif de correction')
            ->assertSee('Joindre la piece justificative signee avant nouvelle soumission.')
            ->assertSee('Suspendu');
    }
}
