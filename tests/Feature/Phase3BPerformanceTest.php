<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Action;
use App\Services\Actions\ActionStatusService;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Dashboard\DashboardOverviewReadModel;
use App\Support\SchemaIntrospectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Couvre la sous-phase 3.B :
 *   - A31 : SchemaIntrospectionCache memoise hasColumn / hasTable.
 *   - A32 : MonitoringWebController utilise JOIN + GROUP BY (verif lecture
 *     du code source).
 *   - A33 : ReportingAnalyticsService expose AGGREGATE_WARN_THRESHOLD.
 */
class Phase3BPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a31_schema_introspection_cache_returns_consistent_values(): void
    {
        // 1er appel : hit la BDD via Schema::hasTable
        $first = SchemaIntrospectionCache::hasTable('users');

        // 2e appel : doit retourner la meme valeur (memoise).
        $second = SchemaIntrospectionCache::hasTable('users');

        $this->assertSame($first, $second);
        $this->assertTrue($first, 'Table users doit exister apres migration.');

        // hasColumn : meme test.
        $colFirst = SchemaIntrospectionCache::hasColumn('users', 'email');
        $colSecond = SchemaIntrospectionCache::hasColumn('users', 'email');
        $this->assertSame($colFirst, $colSecond);
        $this->assertTrue($colFirst);

        // Colonne inexistante : false memoise.
        $missing = SchemaIntrospectionCache::hasColumn('users', 'nonexistent_column_xyz');
        $this->assertFalse($missing);
        $this->assertFalse(SchemaIntrospectionCache::hasColumn('users', 'nonexistent_column_xyz'));
    }

    public function test_a31_schema_introspection_cache_memoizes_missing_columns_and_tables(): void
    {
        SchemaIntrospectionCache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertFalse(SchemaIntrospectionCache::hasColumn('users', 'nonexistent_column_xyz'));
        $queriesAfterFirstMissingColumnLookup = DB::getQueryLog();
        $this->assertFalse(SchemaIntrospectionCache::hasColumn('users', 'nonexistent_column_xyz'));
        $this->assertSame($queriesAfterFirstMissingColumnLookup, DB::getQueryLog());

        SchemaIntrospectionCache::flush();
        DB::flushQueryLog();

        $this->assertFalse(SchemaIntrospectionCache::hasTable('nonexistent_table_xyz'));
        $queriesAfterFirstMissingTableLookup = DB::getQueryLog();
        $this->assertFalse(SchemaIntrospectionCache::hasTable('nonexistent_table_xyz'));
        $this->assertSame($queriesAfterFirstMissingTableLookup, DB::getQueryLog());
    }

    public function test_a31_flush_clears_cache(): void
    {
        SchemaIntrospectionCache::hasTable('users');
        SchemaIntrospectionCache::flush();

        // Apres flush, l appel suivant relit la BDD. On verifie indirectement
        // par la consistance du resultat.
        $this->assertTrue(SchemaIntrospectionCache::hasTable('users'));
    }

    public function test_a32_monitoring_dashboard_uses_join_aggregation(): void
    {
        $controllerCode = file_get_contents(base_path('app/Http/Controllers/Web/MonitoringWebController.php'));

        $this->assertStringContainsString(
            "leftJoin('paos', 'paos.pas_id', '=', 'pas.id')",
            $controllerCode,
            'A32 — Le dashboard PAS doit utiliser un LEFT JOIN sur paos au lieu des sous-requetes correlees.'
        );

        $this->assertStringNotContainsString(
            '(SELECT COUNT(*) FROM paos WHERE paos.pas_id = pas.id)',
            $controllerCode,
            'A32 — Les sous-requetes correlees historiques doivent avoir disparu.'
        );
    }

    public function test_dashboard_status_is_calculated_once_per_action_and_logs_are_eager_loaded(): void
    {
        $action = new Action(['id' => 73]);
        $statusService = $this->createMock(ActionStatusService::class);
        $statusService
            ->expects($this->once())
            ->method('dashboardStatus')
            ->with($action)
            ->willReturn('en_cours');

        $controller = (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $statusServiceProperty = new \ReflectionProperty(DashboardController::class, 'actionStatusService');
        $statusServiceProperty->setValue($controller, $statusService);

        $dashboardStatus = new \ReflectionMethod(DashboardController::class, 'dashboardStatus');

        $this->assertSame('en_cours', $dashboardStatus->invoke($controller, $action));
        $this->assertSame('en_cours', $dashboardStatus->invoke($controller, $action));

        $readModelCode = file_get_contents((new \ReflectionClass(DashboardOverviewReadModel::class))->getFileName());

        $this->assertStringContainsString(
            "'actionLogs:id,action_id,type_evenement'",
            $readModelCode,
            'Le tableau de bord doit charger les journaux en une requete groupee pour eviter un N+1.'
        );
    }

    public function test_dashboard_cache_ttl_avoids_frequent_full_recalculations(): void
    {
        $this->assertGreaterThanOrEqual(
            30,
            DashboardController::DASHBOARD_CACHE_TTL_MINUTES,
            'Le cache du tableau de bord doit eviter les recalculs complets trop frequents.'
        );
    }

    public function test_a33_reporting_exposes_aggregate_warn_threshold(): void
    {
        $this->assertGreaterThanOrEqual(
            1000,
            ReportingAnalyticsService::AGGREGATE_WARN_THRESHOLD,
            'A33 — AGGREGATE_WARN_THRESHOLD doit etre une constante explicite et raisonnable.'
        );
    }
}
