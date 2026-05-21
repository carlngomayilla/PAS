<?php

namespace Tests\Feature;

use App\Services\Analytics\ReportingAnalyticsService;
use App\Support\SchemaIntrospectionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a33_reporting_exposes_aggregate_warn_threshold(): void
    {
        $this->assertGreaterThanOrEqual(
            1000,
            ReportingAnalyticsService::AGGREGATE_WARN_THRESHOLD,
            'A33 — AGGREGATE_WARN_THRESHOLD doit etre une constante explicite et raisonnable.'
        );
    }
}
