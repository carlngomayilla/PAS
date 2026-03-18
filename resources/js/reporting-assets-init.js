async function bootReportingAssets() {
  const hasReportingCharts =
    document.getElementById('chart-funnel') ||
    document.querySelector('.chart-panel');

  if (!hasReportingCharts) {
    return;
  }

  const [{ default: Chart }, html2canvasModule] = await Promise.all([
    import('chart.js/auto'),
    import('html2canvas'),
  ]);

  window.Chart = Chart;
  window.html2canvas = html2canvasModule.default;
  document.dispatchEvent(new CustomEvent('anbg:reporting-assets-ready'));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    void bootReportingAssets();
  });
} else {
  void bootReportingAssets();
}
