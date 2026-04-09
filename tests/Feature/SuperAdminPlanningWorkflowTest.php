<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\Service;
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

    public function test_pas_and_pao_can_validate_directly_when_direct_approval_mode_is_active(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->createPlanningFixture();

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.workflow.update'), [
                'actions_service_validation_enabled' => '1',
                'actions_direction_validation_enabled' => '1',
                'actions_rejection_comment_required' => '1',
                'pas_workflow_mode' => 'direct_approval',
                'pao_workflow_mode' => 'direct_approval',
                'pta_workflow_mode' => 'full',
            ])
            ->assertRedirect(route('workspace.super-admin.workflow.edit'));

        $this->actingAs($superAdmin)
            ->post(route('workspace.pas.submit', $fixture['pas']))
            ->assertRedirect(route('workspace.pas.index'));

        $this->actingAs($superAdmin)
            ->post(route('workspace.pao.submit', $fixture['pao']))
            ->assertRedirect(route('workspace.pao.index'));

        $this->assertSame('valide', $fixture['pas']->fresh()->statut);
        $this->assertSame('valide', $fixture['pao']->fresh()->statut);
    }

    public function test_pta_lock_is_blocked_when_workflow_stops_at_validation(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $fixture = $this->createPlanningFixture();

        $fixture['pta']->update([
            'statut' => 'valide',
            'valide_le' => now(),
            'valide_par' => $superAdmin->id,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('workspace.super-admin.workflow.update'), [
                'actions_service_validation_enabled' => '1',
                'actions_direction_validation_enabled' => '1',
                'actions_rejection_comment_required' => '1',
                'pas_workflow_mode' => 'full',
                'pao_workflow_mode' => 'full',
                'pta_workflow_mode' => 'approval_only',
            ])
            ->assertRedirect(route('workspace.super-admin.workflow.edit'));

        $this->actingAs($superAdmin)
            ->from(route('workspace.pta.index'))
            ->post(route('workspace.pta.lock', $fixture['pta']))
            ->assertRedirect(route('workspace.pta.index'))
            ->assertSessionHasErrors('general');

        $this->assertSame('valide', $fixture['pta']->fresh()->statut);
    }

    /**
     * @return array{pas: Pas, pao: Pao, pta: Pta}
     */
    private function createPlanningFixture(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DIR-WF',
            'libelle' => 'Direction Workflow',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-WF',
            'libelle' => 'Service Workflow',
            'actif' => true,
        ]);

        $pas = Pas::query()->create([
            'titre' => 'PAS workflow',
            'periode_debut' => 2026,
            'periode_fin' => 2028,
            'statut' => 'brouillon',
        ]);
        $pas->directions()->sync([$direction->id]);

        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'AXE-WF',
            'libelle' => 'Axe Workflow',
            'ordre' => 1,
        ]);

        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'OBJ-WF',
            'libelle' => 'Objectif Workflow',
            'ordre' => 1,
        ]);

        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => 2026,
            'titre' => 'PAO workflow',
            'statut' => 'brouillon',
        ]);

        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA workflow',
            'statut' => 'brouillon',
        ]);

        return compact('pas', 'pao', 'pta');
    }
}
