<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardControlScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function controlRoleProvider(): array
    {
        return [
            'planification' => [User::ROLE_PLANIFICATION],
            'SCIQ' => [User::ROLE_SCIQ],
            'suivi SCIQ global' => [User::ROLE_SCIQ_SUIVI_GLOBAL],
            'chef planification' => [User::ROLE_CHEF_PLANIFICATION],
            'chef unité SCIQ' => [User::ROLE_CHEF_UNITE_SCIQ],
        ];
    }

    #[DataProvider('controlRoleProvider')]
    public function test_control_profiles_can_filter_all_active_directions_and_services(string $role): void
    {
        [$homeDirection, $homeService] = $this->createOrganization('HOME', 'Périmètre utilisateur');
        [$otherDirection, $otherService] = $this->createOrganization('OTHER', 'Périmètre transversal');
        $inactiveDirection = Direction::query()->create([
            'code' => 'INACTIVE',
            'libelle' => 'Direction inactive',
            'actif' => false,
        ]);
        $user = User::factory()->create([
            'role' => $role,
            'direction_id' => $homeDirection->id,
            'service_id' => $homeService->id,
            'password_changed_at' => now(),
        ]);

        $this->assertTrue($user->hasCrossOrganizationDashboardAccess());

        $response = $this->actingAs($user)->get(route('dashboard', [
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]));

        $response->assertOk();
        $response->assertSee('data-synthesis-direction-select', false);
        $response->assertSee('Toutes directions');
        $response->assertSee('OTHER - Périmètre transversal');
        $response->assertSee('OTHER-SERVICE - Service Périmètre transversal');
        $response->assertDontSee($inactiveDirection->libelle);
    }

    public function test_service_profile_does_not_gain_cross_organization_dashboard_filters(): void
    {
        [$direction, $service] = $this->createOrganization('LOCAL', 'Périmètre local');
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $this->assertFalse($user->hasCrossOrganizationDashboardAccess());

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-synthesis-direction-select', false);
    }

    public function test_service_from_another_direction_is_ignored(): void
    {
        [$homeDirection, $homeService] = $this->createOrganization('FIRST', 'Première direction');
        [$selectedDirection] = $this->createOrganization('SECOND', 'Deuxième direction');
        $user = User::factory()->create([
            'role' => User::ROLE_PLANIFICATION,
            'direction_id' => $homeDirection->id,
            'service_id' => $homeService->id,
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', [
            'direction_id' => $selectedDirection->id,
            'service_id' => $homeService->id,
        ]));

        $response->assertOk();
        $response->assertSee('SECOND-SERVICE - Service Deuxième direction');
        $response->assertSee('<option value="all" selected>Tous services', false);
    }

    #[DataProvider('controlRoleProvider')]
    public function test_control_profiles_apply_direction_filter_to_dashboard_data(string $role): void
    {
        [$homeDirection, $homeService] = $this->createOrganization('HOME-DATA', 'Périmètre utilisateur');
        [$selectedDirection, $selectedService] = $this->createOrganization('TARGET', 'Périmètre sélectionné');
        $siblingService = Service::query()->create([
            'direction_id' => $selectedDirection->id,
            'code' => 'TARGET-SIBLING',
            'libelle' => 'Service voisin',
            'actif' => true,
        ]);
        $homeAction = $this->createDashboardAction($homeDirection, $homeService, 'HOME', 'Action du périmètre utilisateur');
        $selectedAction = $this->createDashboardAction($selectedDirection, $selectedService, 'TARGET', 'Action du service sélectionné');
        $siblingAction = $this->createDashboardAction($selectedDirection, $siblingService, 'SIBLING', 'Action du service voisin');
        $user = User::factory()->create([
            'role' => $role,
            'direction_id' => $homeDirection->id,
            'service_id' => $homeService->id,
            'password_changed_at' => now(),
        ]);

        $directionResponse = $this->actingAs($user)->get(route('dashboard', [
            'dashboardTab' => 'advanced',
            'direction_id' => $selectedDirection->id,
            'service_id' => 'all',
        ]));

        $directionResponse->assertOk();
        $directionMetrics = $directionResponse->viewData('metrics');
        $directionDashboard = $directionResponse->viewData('dashboardData');
        $directionActionLabels = collect($directionDashboard['action_rows'] ?? [])
            ->pluck('libelle')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(2, data_get($directionMetrics, 'totals.actions_total'));
        $this->assertSame(
            collect([$selectedAction->libelle, $siblingAction->libelle])->sort()->values()->all(),
            $directionActionLabels,
        );
        $this->assertSame($selectedDirection->id, data_get($directionDashboard, 'direction_selector.selected_id'));
        $this->assertNull(data_get($directionDashboard, 'direction_selector.service_selected_id'));
        $this->assertNotContains($homeAction->libelle, $directionActionLabels);
    }

    #[DataProvider('controlRoleProvider')]
    public function test_control_profiles_apply_service_filter_to_dashboard_data(string $role): void
    {
        [$homeDirection, $homeService] = $this->createOrganization('HOME-SERVICE', 'Périmètre utilisateur');
        [$selectedDirection, $selectedService] = $this->createOrganization('TARGET-SERVICE', 'Périmètre sélectionné');
        $siblingService = Service::query()->create([
            'direction_id' => $selectedDirection->id,
            'code' => 'TARGET-SERVICE-SIBLING',
            'libelle' => 'Service voisin',
            'actif' => true,
        ]);
        $homeAction = $this->createDashboardAction($homeDirection, $homeService, 'HOME-SERVICE', 'Action du périmètre utilisateur');
        $selectedAction = $this->createDashboardAction($selectedDirection, $selectedService, 'TARGET-SERVICE', 'Action du service sélectionné');
        $siblingAction = $this->createDashboardAction($selectedDirection, $siblingService, 'SIBLING-SERVICE', 'Action du service voisin');
        $user = User::factory()->create([
            'role' => $role,
            'direction_id' => $homeDirection->id,
            'service_id' => $homeService->id,
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', [
            'dashboardTab' => 'advanced',
            'direction_id' => $selectedDirection->id,
            'service_id' => $selectedService->id,
        ]));

        $response->assertOk();
        $metrics = $response->viewData('metrics');
        $dashboard = $response->viewData('dashboardData');
        $actionLabels = collect($dashboard['action_rows'] ?? [])->pluck('libelle')->all();
        $this->assertSame(1, data_get($metrics, 'totals.actions_total'));
        $this->assertSame(
            [$selectedAction->libelle],
            $actionLabels,
        );
        $this->assertNotContains($siblingAction->libelle, $actionLabels);
        $this->assertNotContains($homeAction->libelle, $actionLabels);
        $this->assertSame($selectedDirection->id, data_get($dashboard, 'direction_selector.selected_id'));
        $this->assertSame($selectedService->id, data_get($dashboard, 'direction_selector.service_selected_id'));
    }

    public function test_local_profile_ignores_forged_cross_organization_filters_without_leaking_data(): void
    {
        [$localDirection, $localService] = $this->createOrganization('LOCAL-DATA', 'Périmètre local');
        [$externalDirection, $externalService] = $this->createOrganization('EXTERNAL', 'Périmètre externe');
        $localAction = $this->createDashboardAction($localDirection, $localService, 'LOCAL', 'Action strictement locale');
        $externalAction = $this->createDashboardAction($externalDirection, $externalService, 'EXTERNAL', 'Action confidentielle externe');
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $localDirection->id,
            'service_id' => $localService->id,
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard', [
            'dashboardTab' => 'advanced',
            'direction_id' => $externalDirection->id,
            'service_id' => $externalService->id,
        ]));

        $response->assertOk();
        $metrics = $response->viewData('metrics');
        $dashboard = $response->viewData('dashboardData');
        $actionLabels = collect($dashboard['action_rows'] ?? [])->pluck('libelle')->all();
        $this->assertSame(1, data_get($metrics, 'totals.actions_total'));
        $this->assertSame([$localAction->libelle], $actionLabels);
        $this->assertNotContains($externalAction->libelle, $actionLabels);
        $this->assertFalse(data_get($dashboard, 'direction_selector.enabled'));
        $this->assertNull(data_get($dashboard, 'direction_selector.selected_id'));
        $this->assertNull(data_get($dashboard, 'direction_selector.service_selected_id'));
        $response->assertDontSee('data-synthesis-direction-select', false);
    }

    /**
     * @return array{Direction, Service}
     */
    private function createOrganization(string $code, string $label): array
    {
        $direction = Direction::query()->create([
            'code' => $code,
            'libelle' => $label,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => $code.'-SERVICE',
            'libelle' => 'Service '.$label,
            'actif' => true,
        ]);

        return [$direction, $service];
    }

    private function createDashboardAction(
        Direction $direction,
        Service $service,
        string $suffix,
        string $label
    ): Action {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $pao = Pao::query()
            ->where('direction_id', $direction->id)
            ->where('annee', now()->year)
            ->first();

        if ($pao instanceof Pao) {
            $pas = Pas::query()->findOrFail($pao->pas_id);
            $strategicObjective = PasObjectif::query()->findOrFail($pao->pas_objectif_id);
            $axis = PasAxe::query()->findOrFail($strategicObjective->pas_axe_id);
        } else {
            $pas = Pas::query()->create([
                'titre' => 'PAS '.$suffix,
                'periode_debut' => now()->year,
                'periode_fin' => now()->year + 2,
                'statut' => 'actif',
            ]);
            $axis = PasAxe::query()->create([
                'pas_id' => $pas->id,
                'code' => 'AXE-'.$suffix,
                'libelle' => 'Axe '.$suffix,
                'ordre' => 1,
            ]);
            $strategicObjective = PasObjectif::query()->create([
                'pas_axe_id' => $axis->id,
                'code' => 'OS-'.$suffix,
                'libelle' => 'Objectif stratégique '.$suffix,
                'date_echeance' => now()->addYears(2)->toDateString(),
                'ordre' => 1,
            ]);
            $pao = Pao::query()->create([
                'pas_id' => $pas->id,
                'pas_objectif_id' => $strategicObjective->id,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'annee' => now()->year,
                'titre' => 'PAO '.$suffix,
                'objectif_operationnel' => 'Objectif opérationnel '.$suffix,
                'statut' => Pao::STATUS_VALIDE,
            ]);
        }
        $operationalObjective = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axis->id,
            'pas_objectif_id' => $strategicObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif opérationnel '.$suffix,
            'echeance' => now()->addYear()->toDateString(),
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA '.$suffix,
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        return Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $operationalObjective->id,
            'libelle' => $label,
            'description' => 'Action de vérification du périmètre dashboard',
            'contexte_action' => Action::CONTEXT_PILOTAGE,
            'type_cible' => 'quantitative',
            'type_indicateur' => 'quantitatif',
            'unite_cible' => 'dossiers',
            'quantite_cible' => 10,
            'quantite_a_realiser' => 10,
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addMonth()->toDateString(),
            'date_echeance' => now()->addMonth()->toDateString(),
            'echeance_cible' => now()->addMonth()->toDateString(),
            'responsable_id' => $agent->id,
            'statut' => 'en_cours',
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'statut_validation' => ActionTrackingService::VALIDATION_NON_SOUMISE,
            'statut_parametrage' => 'parametre',
            'progression_reelle' => 40,
            'progression_theorique' => 50,
            'seuil_alerte_progression' => 10,
            'financement_requis' => false,
        ]);
    }
}
