<?php

namespace Tests\Feature;

use App\Services\ManagedKpiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityGaugeRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_gauge_is_removed_from_dashboard_surfaces(): void
    {
        $files = [
            resource_path('views/partials/dashboard-analytics/_panel-charts.blade.php'),
            resource_path('views/partials/dashboard-analytics/_panel-tables.blade.php'),
            resource_path('views/workspace/actions/suivi.blade.php'),
            resource_path('js/dashboard-render.js'),
            resource_path('js/admin-shell.js'),
            app_path('Http/Controllers/DashboardController.php'),
            app_path('Services/Exports/ReportingWorkbookExporter.php'),
        ];

        $combined = collect($files)
            ->map(fn (string $path): string => (string) file_get_contents($path))
            ->implode("\n");

        $this->assertStringNotContainsString('dashboard-kpi-gauge-qualite', $combined);
        $this->assertStringNotContainsString("key: 'qualite'", $combined);
        $this->assertStringNotContainsString("['qualite'", $combined);
        $this->assertStringNotContainsString('summary.qualite', $combined);
        $this->assertStringNotContainsString('Qual.', $combined);
        $this->assertStringNotContainsString("metricLabel('qualite')", $combined);
        $this->assertStringNotContainsString("['kpiSummary']['qualite']", $combined);
    }

    public function test_managed_kpi_settings_do_not_accept_quality_code(): void
    {
        $settings = app(ManagedKpiSettings::class);

        $this->assertNotContains('qualite', $settings->codes());
        $this->assertArrayNotHasKey('qualite', $settings->labels());
    }
}
