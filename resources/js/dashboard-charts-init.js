async function bootDashboardCharts() {
  const hasDashboardCharts =
    document.querySelector('[data-dashboard-tabs]') ||
    document.getElementById('dashboard-gantt-chart') ||
    document.getElementById('dashboard-critical-gantt-chart') ||
    document.getElementById('pilotage-unit-chart') ||
    document.getElementById('pilotage-status-chart') ||
    document.getElementById('pilotage-perf-radar') ||
    document.getElementById('pilotage-progress-chart');

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
    import('d3'),
  ]);

  const { Chart, registerables } = chartJs;
  const d3 = optionalModules[0].status === 'fulfilled' ? optionalModules[0].value : null;

  Chart.register(...registerables);

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
