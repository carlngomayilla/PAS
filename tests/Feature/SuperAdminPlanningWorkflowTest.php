<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminPlanningWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Anciennes logiques workflow PAS/PAO/PTA RETIRÉES.
     *
     * Les modes paramétrables `direct_approval`, `approval_only`, `full` n'existent plus.
     * Selon la nouvelle logique métier ANBG validée :
     *   - PAS : Actif → Clôturé → Archivé (validé hors application)
     *   - PAO : En cours → Validé automatiquement (champs obligatoires complets) → Clôturé → Archivé
     *   - PTA : En cours → Clôturé → Archivé (pas de statut « Validé »)
     *
     * Le seul mode disponible est désormais « canonical » (cycle métier canonique PAS ANBG).
     * Les tests qui validaient les anciens modes ont été supprimés car ils testaient du code mort.
     */
    public function test_canonical_planning_workflow_is_the_only_mode_available(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $modes = app(\App\Services\WorkflowSettings::class)->planningWorkflowModes();

        $this->assertArrayHasKey('canonical', $modes);
        $this->assertCount(1, $modes, 'Un seul mode workflow doit être disponible : canonical.');

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.workflow.update'), [
                'actions_service_validation_enabled' => '1',
                'actions_direction_validation_enabled' => '1',
                'actions_rejection_comment_required' => '1',
                'pas_workflow_mode' => 'canonical',
                'pao_workflow_mode' => 'canonical',
                'pta_workflow_mode' => 'canonical',
            ])
            ->assertRedirect(route('workspace.super-admin.workflow.edit'));

        $this->assertSame('canonical', app(\App\Services\WorkflowSettings::class)->planningWorkflowMode('pas'));
        $this->assertSame('canonical', app(\App\Services\WorkflowSettings::class)->planningWorkflowMode('pao'));
        $this->assertSame('canonical', app(\App\Services\WorkflowSettings::class)->planningWorkflowMode('pta'));
    }
}
