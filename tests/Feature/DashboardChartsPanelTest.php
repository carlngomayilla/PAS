<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardChartsPanelTest extends TestCase
{
    /**
     * Les quatre graphiques du PTA trimestriel ont ete deplaces de la « Vue
     * detaillee » vers l'onglet « Graphiques » : ils doivent y etre declares,
     * et plus dans le panneau des tableaux.
     */
    public function test_quarterly_pta_charts_live_in_the_charts_tab_only(): void
    {
        $charts = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-charts.blade.php'));
        $tables = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-tables.blade.php'));

        $this->assertStringContainsString('data-dashboard-panel="charts"', $charts);
        $this->assertStringContainsString('dashboard-pta-{{ $chartKey }}-chart-charts', $charts);

        foreach (['axis-progression', 'monthly-rate', 'axis-rate', 'service-rate'] as $chartKey) {
            $this->assertStringContainsString("'".$chartKey."'", $charts);
            $this->assertStringNotContainsString('dashboard-pta-'.$chartKey.'-chart-details', $tables);
        }
    }

    /**
     * Chaque conteneur de graphique declare dans l'onglet « Graphiques » doit
     * avoir un `mountChart()` correspondant, sinon la carte reste vide.
     */
    public function test_every_charts_tab_host_is_mounted_by_the_renderer(): void
    {
        $charts = (string) file_get_contents(resource_path('views/partials/dashboard-analytics/_panel-charts.blade.php'));
        $script = (string) file_get_contents(resource_path('js/dashboard-render.js'));

        preg_match_all('/id="(dashboard-[a-z0-9-]+-chart)"/', $charts, $matches);
        $hostIds = array_values(array_unique($matches[1]));

        $this->assertCount(6, $hostIds);

        foreach ($hostIds as $hostId) {
            $this->assertStringContainsString(
                "mountChart('".$hostId."'",
                $script,
                "Le graphique {$hostId} est declare dans l'onglet Graphiques mais n'est jamais monte."
            );
        }

        // Les quatre graphiques PTA sont montes pour les deux emplacements.
        $this->assertStringContainsString("['charts', 'details'].forEach((location) =>", $script);

        foreach (['axis-rate', 'service-rate', 'axis-progression', 'monthly-rate'] as $chartKey) {
            $this->assertStringContainsString('dashboard-pta-'.$chartKey.'-chart-${location}', $script);
        }
    }

    /**
     * Un conteneur masque (onglet inactif) a une hauteur nulle : le rendu est
     * differe puis rejoue au changement d'onglet. Sans cela, les graphiques de
     * l'onglet « Graphiques » resteraient vides.
     */
    public function test_deferred_charts_are_re_rendered_when_a_tab_becomes_visible(): void
    {
        $script = (string) file_get_contents(resource_path('js/dashboard-render.js'));

        $this->assertStringContainsString("host.dataset.chartState = 'deferred';", $script);
        $this->assertStringContainsString('[data-chart-state="deferred"]', $script);
        $this->assertStringContainsString('function activateTab(key, syncUrl)', $script);
        $this->assertStringContainsString('Promise.resolve(render()).finally(() => {', $script);
    }
}
