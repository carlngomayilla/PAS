<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardChartRuntimeSafetyTest extends TestCase
{
    public function test_chart_mount_is_deferred_until_its_host_has_layout_dimensions(): void
    {
        $script = $this->dashboardScript();
        $mountChart = $this->functionSource($script, 'function mountChart(', 'function plotlyFigureHasData(');
        $renderabilityCheck = strpos($mountChart, 'if (!chartHostIsRenderable(host))');
        $destroyChart = strpos($mountChart, 'destroyChart(id);');

        $this->assertStringContainsString('function chartHostIsRenderable(host)', $script);
        $this->assertStringContainsString('!host.isConnected', $script);
        $this->assertStringContainsString("styles.display === 'none'", $script);
        $this->assertStringContainsString("styles.visibility === 'hidden'", $script);
        $this->assertStringContainsString('bounds.width > 0 && bounds.height > 0', $script);
        $this->assertNotFalse($renderabilityCheck);
        $this->assertNotFalse($destroyChart);
        $this->assertTrue($renderabilityCheck < $destroyChart);
        $this->assertStringContainsString("host.dataset.chartState = 'deferred';", $mountChart);
    }

    public function test_annotation_and_resize_plugins_are_guarded_by_chart_visibility(): void
    {
        $script = $this->dashboardScript();
        $resizeCharts = $this->functionSource($script, 'function resizeCharts()', 'async function render()');

        $this->assertStringContainsString('annotation: false,', $script);
        $this->assertStringContainsString('...kpiAnnotations(', $script);
        $this->assertStringContainsString("if (chartHostIsRenderable(host) && typeof chart.resize === 'function')", $resizeCharts);
        $this->assertStringContainsString("console.error('Impossible de redimensionner un graphique du tableau de bord.'", $resizeCharts);
        $this->assertStringContainsString("document.querySelectorAll('.dashboard-plotly-host.is-plotly-rendered')", $resizeCharts);
        $this->assertStringContainsString('if (!chartHostIsRenderable(host))', $resizeCharts);
        $this->assertStringContainsString("console.error('Impossible de redimensionner un graphique Plotly du tableau de bord.'", $resizeCharts);
    }

    public function test_zero_only_chart_payloads_use_an_explicit_empty_state(): void
    {
        $script = $this->dashboardScript();
        $mountChart = $this->functionSource($script, 'function mountChart(', 'function plotlyFigureHasData(');

        $this->assertStringContainsString('function chartConfigHasVisibleData(config)', $script);
        $this->assertStringContainsString('Math.abs(numeric) > 0.0001', $script);
        $this->assertStringContainsString('Toutes les valeurs sont à 0 pour le périmètre et la période sélectionnés.', $mountChart);
        $this->assertStringContainsString('mountChartEmptyState(', $mountChart);
    }

    private function dashboardScript(): string
    {
        $script = file_get_contents(resource_path('js/dashboard-render.js'));

        $this->assertIsString($script);

        return $script;
    }

    private function functionSource(string $script, string $startMarker, string $endMarker): string
    {
        $start = strpos($script, $startMarker);
        $end = strpos($script, $endMarker, $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }
}
