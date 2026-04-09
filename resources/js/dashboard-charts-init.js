async function bootDashboardCharts() {
  const hasDashboardCharts =
    document.querySelector('[data-dashboard-tabs]') ||
    document.getElementById('dashboard-gantt-chart') ||
    document.getElementById('dashboard-critical-gantt-chart');

  if (!hasDashboardCharts) {
    return;
  }

  let chartJs;
  let chartTheme;

  try {
    [chartJs, chartTheme] = await Promise.all([
      import('chart.js'),
      import('./chart-theme'),
    ]);
  } catch (error) {
    console.error('Impossible de charger le socle des graphiques.', error);
    return;
  }

  const optionalModules = await Promise.allSettled([
    import('chartjs-chart-matrix'),
    import('chartjs-chart-treemap'),
    import('d3'),
  ]);

  const { Chart, registerables } = chartJs;
  const matrixModule = optionalModules[0].status === 'fulfilled' ? optionalModules[0].value : null;
  const treemapModule = optionalModules[1].status === 'fulfilled' ? optionalModules[1].value : null;
  const d3 = optionalModules[2].status === 'fulfilled' ? optionalModules[2].value : null;

  Chart.register(...registerables);

  if (matrixModule?.MatrixController && matrixModule?.MatrixElement) {
    Chart.register(matrixModule.MatrixController, matrixModule.MatrixElement);
  }

  if (treemapModule?.TreemapController && treemapModule?.TreemapElement) {
    Chart.register(treemapModule.TreemapController, treemapModule.TreemapElement);
  }

  chartTheme.applyAnbgChartDefaults(Chart);

  window.Chart = Chart;
  if (d3) {
    window.d3 = d3;
  }
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
