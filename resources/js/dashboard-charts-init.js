async function bootDashboardCharts() {
  const hasDashboardCharts =
    document.getElementById('volumesChart') ||
    document.getElementById('alertsChart') ||
    document.getElementById('statusChart');

  if (!hasDashboardCharts) {
    return;
  }

  const { default: Chart } = await import('chart.js/auto');
  window.Chart = Chart;
  document.dispatchEvent(new CustomEvent('anbg:dashboard-assets-ready'));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    void bootDashboardCharts();
  });
} else {
  void bootDashboardCharts();
}
