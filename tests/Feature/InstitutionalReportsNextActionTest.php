<?php

namespace Tests\Feature;

use App\Models\InstitutionalReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionalReportsNextActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_institutional_reports_index_displays_next_action_for_visible_reports(): void
    {
        $viewer = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'is_active' => true,
        ]);
        $submitter = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
        ]);

        InstitutionalReport::factory()->create([
            'title' => 'Rapport brouillon',
            'status' => InstitutionalReport::STATUS_DRAFT,
            'submitted_by' => $submitter->id,
        ]);
        InstitutionalReport::factory()->create([
            'title' => 'Rapport SCIQ',
            'status' => InstitutionalReport::STATUS_SUBMITTED_SCIQ,
            'submitted_by' => $submitter->id,
        ]);
        InstitutionalReport::factory()->create([
            'title' => 'Rapport retourne',
            'status' => InstitutionalReport::STATUS_RETURNED,
            'submitted_by' => $submitter->id,
        ]);
        InstitutionalReport::factory()->create([
            'title' => 'Rapport verifie',
            'status' => InstitutionalReport::STATUS_VERIFIED,
            'submitted_by' => $submitter->id,
        ]);

        $this->actingAs($viewer)
            ->get(route('workspace.reports.index'))
            ->assertOk()
            ->assertSee('Prochaine action')
            ->assertSee('Soumettre au SCIQ')
            ->assertSee('Contrôle attendu du SCIQ')
            ->assertSee('Correction attendue du déposant')
            ->assertSee('Circuit terminé');
    }
}
