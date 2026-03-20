import { applyAnbgChartDefaults } from './chart-theme';

async function bootDashboardCharts() {
  const hasDashboardCharts =
    document.getElementById('dashboard-status-mix-chart') ||
    document.getElementById('dashboard-kpi-line-chart') ||
    document.getElementById('dashboard-unit-summary-chart') ||
    document.getElementById('dashboard-kpi-grouped-chart') ||
    document.getElementById('dashboard-interannual-chart') ||
    document.getElementById('dashboard-radar-chart') ||
    document.getElementById('dashboard-scatter-chart');

  if (!hasDashboardCharts) {
    return;
  }

  const { default: Chart } = await import('chart.js/auto');
  window.Chart = Chart;
  applyAnbgChartDefaults(Chart);
  document.dispatchEvent(new CustomEvent('anbg:dashboard-assets-ready'));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    void bootDashboardCharts();
  });
} else {
  void bootDashboardCharts();
}
