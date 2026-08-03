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
        $this->assertStringContainsString('event.preventDefault();', $script);
        $this->assertStringContainsString('details.open = shouldOpen;', $script);
    }

    public function test_dashboard_synthesis_view_exposes_decision_filters_and_advanced_tab(): void
    {
        $view = (string) file_get_contents(resource_path('views/partials/dashboard-analytics.blade.php'));
        $overview = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-overview.blade.php'));
        $tables = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-tables.blade.php'));
        $detailTables = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-synthesis-tables.blade.php'));
        $hierarchy = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-synthesis-hierarchy.blade.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/DashboardController.php'));
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $script = (string) file_get_contents(resource_path('js/dashboard-render.js'));

        $this->assertStringContainsString('data-dashboard-synthesis-filter-form', $view);
        $this->assertStringContainsString('name="periode"', $view);
        $this->assertStringContainsString('$synthesisPeriodOptions', $view);
        $this->assertStringContainsString('name="statut_suivi"', $view);
        $this->assertStringContainsString('name="statut_delai"', $view);
        $this->assertStringContainsString('name="alerte_echeance"', $view);
        $this->assertStringContainsString('data-synthesis-direction-select', $view);
        $this->assertStringContainsString('data-synthesis-service-select', $view);
        $this->assertStringContainsString('Vue detaillee', $view);
        $this->assertStringContainsString('data-dashboard-panel="advanced"', $tables);
        $this->assertStringContainsString("const panelKeys = ['overview', 'charts', 'advanced'];", $script);
        $this->assertStringContainsString('$baseSynthesisQuery', $overview);
        $this->assertStringNotContainsString('workspace.tasks._dashboard-card', $overview);
        $this->assertStringNotContainsString('$personalTaskItems', $overview);
        $this->assertStringContainsString('Alertes critiques', $overview);
        $this->assertStringContainsString('_panel-synthesis-hierarchy', $overview);
        $this->assertStringContainsString('Vue synthetique des axes', $hierarchy);
        $this->assertStringNotContainsString("Vue synthetique d'avancement PAS", $hierarchy);
        $this->assertStringContainsString('$showSynthesisTablesInOverview ?? false', $overview);
        $this->assertStringContainsString('_panel-synthesis-tables', $tables);
        $this->assertStringContainsString('Tableaux de synthese', $detailTables);
        $this->assertStringContainsString('dashboard-synthesis-table', $detailTables);
        $this->assertStringContainsString('$agentActionCellLevels', $view);
        $this->assertStringContainsString("\$agentActionCellLevels = [1 => 'action', 2 => 'operational-objective', 3 => 'pta', 10 => 'sub-action'];", $view);
        $this->assertStringContainsString("'cell_levels' => \$agentActionCellLevels", $view);
        $this->assertStringContainsString('dashboard-synthesis-hierarchy-cell', $detailTables);
        $this->assertStringContainsString('dashboard-synthesis-level-', $detailTables);
        $this->assertStringContainsString('dashboard-synthesis-level-operational-objective', $css);
        $this->assertStringContainsString('dashboard-synthesis-level-sub-action', $css);
        $this->assertStringContainsString('.dashboard-synthesis-hierarchy-card .dashboard-synthesis-node', $css);
        $this->assertStringContainsString('.dashboard-synthesis-hierarchy-card .dashboard-synthesis-node-pas', $css);
        $this->assertStringContainsString('.dashboard-synthesis-hierarchy-card .dashboard-synthesis-node-axis', $css);
        $this->assertStringContainsString('.dashboard-synthesis-hierarchy-card .dashboard-synthesis-kpi-axis', $css);
        $this->assertStringContainsString('.dashboard-synthesis-hierarchy-card .dashboard-synthesis-kpi-late', $css);
        $this->assertStringContainsString('data-dashboard-row-detail', $detailTables);
        $this->assertStringContainsString("\$rowUrl = (string) (\$row['url'] ?? '');", $detailTables);
        $this->assertStringContainsString('<a href="{{ $rowUrl }}"', $detailTables);
        $this->assertStringContainsString("'url' => (string) (\$row['url'] ?? ''), 'cells' => [", $view);
        $this->assertStringContainsString("['rmo_id' => (int) \$group['agent_id']]", $controller);
        $this->assertStringNotContainsString('@if (false', $overview);
    }

    public function test_dashboard_charts_use_requested_visual_renderings_only(): void
    {
        $charts = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-charts.blade.php'));

        $this->assertStringNotContainsString('Graphiques de decision', $charts);
        $this->assertStringNotContainsString('Evolution mensuelle', $charts);
        $this->assertStringNotContainsString('Services PTA', $charts);
        $this->assertStringNotContainsString('Meilleures actions', $charts);
        $this->assertStringNotContainsString('dashboard-status-mix-chart', $charts);
        $this->assertStringContainsString('Graphiques du PTA trimestriel', $charts);
        $this->assertStringContainsString('dashboard-pta-{{ $chartKey }}-chart-charts', $charts);
        $this->assertStringContainsString('Progression des axes du PTA sur les trois mois', $charts);
        $this->assertStringContainsString('Évolution du taux global du PTA', $charts);
        $this->assertStringContainsString('Taux globaux des axes stratégiques', $charts);
        $this->assertStringContainsString('Taux de réalisation par direction ou service', $charts);
        $this->assertStringNotContainsString('dashboard-kpi-gauge-', $charts);
        $this->assertStringContainsString("Évolution de l'avancement du PAS", $charts);
        $this->assertStringContainsString('Avancement des axes stratégiques', $charts);
        $this->assertStringContainsString('Avancement des objectifs opérationnels', $charts);
        $this->assertStringContainsString('Exécution trimestrielle des PTA', $charts);
        $this->assertStringContainsString('Répartition des actions par statut', $charts);
        $this->assertStringContainsString('Lecture des niveaux de pilotage', $charts);
        $this->assertStringContainsString('charts-requested-meta', $charts);
        $this->assertStringContainsString('dashboard-requested-area-interactive-chart', $charts);
        $this->assertStringContainsString('dashboard-requested-pie-legend-chart', $charts);
        $this->assertStringContainsString('dashboard-requested-radial-label-chart', $charts);
        $this->assertStringNotContainsString('dashboard-requested-pie-custom-label-chart', $charts);
        $this->assertStringNotContainsString('dashboard-requested-pie-label-list-chart', $charts);
        $this->assertStringNotContainsString('@if (false', $charts);
    }
}
