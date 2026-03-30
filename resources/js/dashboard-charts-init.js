async function bootDashboardCharts() {
  const hasDashboardCharts =
    document.querySelector('[data-dashboard-tabs]') ||
    document.getElementById('dashboard-gantt-chart') ||
    document.getElementById('dashboard-critical-gantt-chart');

  if (!hasDashboardCharts) {
    return;
  }

  const [
    chartJs,
    matrixModule,
    treemapModule,
    d3,
    chartTheme,
  ] = await Promise.all([
    import('chart.js'),
    import('chartjs-chart-matrix'),
    import('chartjs-chart-treemap'),
    import('d3'),
    import('./chart-theme'),
  ]);

  const { Chart, registerables } = chartJs;
  const { MatrixController, MatrixElement } = matrixModule;
  const { TreemapController, TreemapElement } = treemapModule;

  Chart.register(
    ...registerables,
    MatrixController,
    MatrixElement,
    TreemapController,
    TreemapElement,
  );

  chartTheme.applyAnbgChartDefaults(Chart);

  window.Chart = Chart;
  window.d3 = d3;
  window.getAnbgChartTheme = chartTheme.getAnbgChartTheme;
  window.applyAnbgChartDefaults = chartTheme.applyAnbgChartDefaults;

  document.dispatchEvent(new CustomEvent('anbg:dashboard-assets-ready'));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    void bootDashboardCharts();
  });
} else {
  void bootDashboardCharts();
}
