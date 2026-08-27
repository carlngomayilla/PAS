<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\Direction;
use App\Models\User;
use App\Services\Analytics\AnalyticsCacheVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_planning_model_mutation_invalidates_all_analytics_caches(): void
    {
        $versions = app(AnalyticsCacheVersionService::class);

        $reportingVersion = $versions->reportingVersion();
        $dashboardVersion = $versions->dashboardVersion();
        $alertsVersion = $versions->alertsVersion();

        Direction::query()->create([
            'code' => 'CACHE-INV',
            'libelle' => 'Direction invalidation cache',
            'actif' => true,
        ]);

        $this->assertGreaterThan($reportingVersion, $versions->reportingVersion());
        $this->assertGreaterThan($dashboardVersion, $versions->dashboardVersion());
        $this->assertGreaterThan($alertsVersion, $versions->alertsVersion());
    }

    public function test_a_delegation_mutation_invalidates_all_analytics_caches(): void
    {
        $direction = Direction::factory()->create();
        $delegant = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $direction->id]);
        $delegate = User::factory()->create(['role' => User::ROLE_SERVICE, 'direction_id' => $direction->id]);
        $versions = app(AnalyticsCacheVersionService::class);

        $reportingVersion = $versions->reportingVersion();
        $dashboardVersion = $versions->dashboardVersion();
        $alertsVersion = $versions->alertsVersion();

        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $delegate->id,
            'role_scope' => Delegation::SCOPE_DIRECTION,
            'direction_id' => $direction->id,
            'permissions' => ['planning_read'],
            'motif' => 'Couverture test invalidation cache',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDay(),
            'statut' => 'active',
            'cree_par' => $delegant->id,
        ]);

        $this->assertGreaterThan($reportingVersion, $versions->reportingVersion());
        $this->assertGreaterThan($dashboardVersion, $versions->dashboardVersion());
        $this->assertGreaterThan($alertsVersion, $versions->alertsVersion());
    }

    public function test_dashboard_inputs_are_registered_with_the_shared_cache_observer(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        foreach ([
            'ActionKpi',
            'ActionLog',
            'BudgetOverrunRequest',
            'DeadlineExtensionRequest',
            'Delegation',
            'Direction',
            'FinancialTransaction',
            'Justificatif',
            'Kpi',
            'KpiMesure',
            'ObjectifOperationnel',
            'Pao',
            'PaoObjectifOperationnel',
            'PasAxe',
            'PasObjectif',
            'Pta',
            'Service',
            'SousAction',
            'User',
        ] as $model) {
            $this->assertStringContainsString(
                $model.'::observe(PlanningCacheObserver::class)',
                $provider
            );
        }

        $justificatif = file_get_contents(app_path('Models/Justificatif.php'));
        $this->assertStringNotContainsString('app(AnalyticsCacheVersionService::class)', $justificatif);

        $actionLog = file_get_contents(app_path('Models/ActionLog.php'));
        $this->assertStringNotContainsString('app(AnalyticsCacheVersionService::class)', $actionLog);
    }
}
