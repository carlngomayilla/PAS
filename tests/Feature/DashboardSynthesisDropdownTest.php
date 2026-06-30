<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardSynthesisDropdownTest extends TestCase
{
    public function test_dashboard_synthesis_dropdown_has_dedicated_javascript_binding(): void
    {
        $view = (string) file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $script = (string) file_get_contents(resource_path('js/dashboard-render.js'));

        $this->assertStringContainsString('data-dashboard-synthesis-selector', $view);
        $this->assertStringContainsString('function bindSynthesisSelectors()', $script);
        $this->assertStringContainsString("summary.addEventListener('click'", $script);
        $this->assertStringContainsString("event.preventDefault();", $script);
        $this->assertStringContainsString("details.open = shouldOpen;", $script);
    }

    public function test_dashboard_synthesis_view_exposes_decision_filters_and_advanced_tab(): void
    {
        $view = (string) file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $overview = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-overview.blade.php'));
        $tables = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-tables.blade.php'));
        $script = (string) file_get_contents(resource_path('js/dashboard-render.js'));

        $this->assertStringContainsString('data-dashboard-synthesis-filter-form', $view);
        $this->assertStringContainsString('name="periode"', $view);
        $this->assertStringContainsString('$synthesisPeriodOptions', $view);
        $this->assertStringContainsString('name="statut_suivi"', $view);
        $this->assertStringContainsString('name="statut_delai"', $view);
        $this->assertStringContainsString('name="alerte_echeance"', $view);
        $this->assertStringContainsString('data-synthesis-direction-select', $view);
        $this->assertStringContainsString('data-synthesis-service-select', $view);
        $this->assertStringContainsString('Analyse avancee', $view);
        $this->assertStringContainsString("data-dashboard-panel=\"advanced\"", $tables);
        $this->assertStringContainsString("const panelKeys = ['overview', 'charts', 'advanced'];", $script);
        $this->assertStringContainsString('$baseSynthesisQuery', $overview);
        $this->assertStringContainsString('Alertes critiques', $overview);
        $this->assertStringNotContainsString('@if (false', $overview);
    }

    public function test_dashboard_charts_focus_on_status_and_pta_evolution_graphs(): void
    {
        $charts = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-charts.blade.php'));

        $this->assertStringNotContainsString('Graphiques de decision', $charts);
        $this->assertStringNotContainsString('Evolution mensuelle', $charts);
        $this->assertStringNotContainsString('Services PTA', $charts);
        $this->assertStringNotContainsString('Meilleures actions', $charts);
        $this->assertStringContainsString('dashboard-status-mix-chart', $charts);
        $this->assertStringContainsString('dashboard-pta-axis-rate-chart', $charts);
        $this->assertStringContainsString('dashboard-pta-monthly-rate-chart', $charts);
        $this->assertStringContainsString('dashboard-canvas-evolution', $charts);
        $this->assertStringNotContainsString('@if (false', $charts);
    }
}
