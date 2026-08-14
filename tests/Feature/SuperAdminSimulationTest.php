<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class SuperAdminSimulationTest extends TestCase
{
    use CreatesAdminUser;
    use RefreshDatabase;

    public function test_super_admin_can_run_fixed_workflow_simulation(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->get(route('workspace.super-admin.simulation.index'))
            ->assertOk()
            ->assertSee('Simulation d’impact')
            ->assertSee('Circuit cible verrouillé')
            ->assertSee('Agent → Chef de service → Contrôle SCIQ → Planification');

        $response = $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.simulation.run'), [
                'actions_service_validation_enabled' => '1',
                'actions_direction_validation_enabled' => '0',
                'actions_auto_complete_when_target_reached' => '1',
                'actions_min_progress_for_closure' => '70',
            ]);

        $response
            ->assertRedirect(route('workspace.super-admin.simulation.index'))
            ->assertSessionHas('simulation_result.simulated.workflow_chain_label', 'Agent -> Chef de service -> Controle SCIQ -> Planification')
            ->assertSessionHas('simulation_result.payload.actions_service_validation_enabled', '1')
            ->assertSessionHas('simulation_result.payload.actions_direction_validation_enabled', '0');

        $this->assertDatabaseHas('journal_audit', [
            'module' => 'super_admin',
            'action' => 'platform_simulation_run',
        ]);
    }

    public function test_non_super_admin_cannot_access_or_run_simulation(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('workspace.super-admin.simulation.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('workspace.super-admin.simulation.run'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseMissing('journal_audit', [
            'action' => 'platform_simulation_run',
        ]);
    }

    public function test_fixed_workflow_cannot_be_tampered_with(): void
    {
        $superAdmin = $this->createSuperAdminUser();

        $this->actingAs($superAdmin)
            ->from(route('workspace.super-admin.simulation.index'))
            ->post(route('workspace.super-admin.simulation.run'), array_merge($this->validPayload(), [
                'actions_service_validation_enabled' => '0',
                'actions_direction_validation_enabled' => '1',
            ]))
            ->assertRedirect(route('workspace.super-admin.simulation.index'))
            ->assertSessionHasErrors([
                'actions_service_validation_enabled',
                'actions_direction_validation_enabled',
            ]);

        $this->assertDatabaseMissing('journal_audit', [
            'action' => 'platform_simulation_run',
        ]);
    }

    public function test_simulation_uses_real_kpis_and_excludes_terminal_actions_from_candidates(): void
    {
        $superAdmin = $this->createSuperAdminUser();
        $pta = $this->createPta();

        $statisticalAction = $this->createAction($pta, 'Action statistique', [
            'progression_reelle' => 100,
            'statut' => ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            'statut_dynamique' => ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
        ]);
        ActionKpi::query()->create([
            'action_id' => $statisticalAction->id,
            'kpi_delai' => 82.5,
            'kpi_performance' => 73,
            'kpi_global' => 77.75,
        ]);

        $this->createAction($pta, 'Action ouverte à 100 %', [
            'progression_reelle' => 100,
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
        ]);
        $this->createAction($pta, 'Action ouverte à 75 %', [
            'progression_reelle' => 75,
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
        ]);
        $this->createAction($pta, 'Action suspendue', [
            'progression_reelle' => 100,
            'statut' => ActionTrackingService::STATUS_SUSPENDU,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
        ]);
        $this->createAction($pta, 'Action annulée', [
            'progression_reelle' => 100,
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_ANNULE,
        ]);

        $response = $this->actingAs($superAdmin)
            ->post(route('workspace.super-admin.simulation.run'), $this->validPayload());

        $response
            ->assertSessionHas('simulation_result.population.actions_total', 5)
            ->assertSessionHas('simulation_result.population.open_actions_total', 2)
            ->assertSessionHas('simulation_result.population.terminal_actions_total', 3)
            ->assertSessionHas('simulation_result.simulated.closure_eligible_actions', 2)
            ->assertSessionHas('simulation_result.impact.auto_complete_candidates', 1)
            ->assertSessionHas('simulation_result.dashboard_preview.dg.kpis.0.value', 82.5)
            ->assertSessionHas('simulation_result.dashboard_preview.dg.kpis.1.value', 73.0)
            ->assertSessionHas('simulation_result.dashboard_preview.dg.kpis.3.value', 77.75);
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(): array
    {
        return [
            'actions_service_validation_enabled' => '1',
            'actions_direction_validation_enabled' => '0',
            'actions_auto_complete_when_target_reached' => '1',
            'actions_min_progress_for_closure' => '70',
        ];
    }

    private function createPta(): Pta
    {
        $direction = Direction::query()->create([
            'code' => 'SIM-DIR',
            'libelle' => 'Direction simulation',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SIM-SRV',
            'libelle' => 'Service simulation',
            'actif' => true,
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS simulation',
            'periode_debut' => 2026,
            'periode_fin' => 2030,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PAO simulation',
            'annee' => 2026,
        ]);

        return Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA simulation',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function createAction(Pta $pta, string $label, array $state): Action
    {
        $action = Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pta->pao_id,
            'libelle' => $label,
            'type_cible' => 'qualitative',
        ]);

        $action->forceFill(array_merge([
            'progression_reelle' => 0,
            'statut' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_dynamique' => ActionTrackingService::STATUS_NON_DEMARRE,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
        ], $state))->save();

        return $action;
    }
}
