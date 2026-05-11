export function getAnbgChartTheme() {
  const isDark = document.documentElement.classList.contains('dark');

  return {
    isDark,
    text: isDark ? '#cbd5e1' : '#334155',
    muted: isDark ? '#94a3b8' : '#64748b',
    grid: isDark ? 'rgba(148,163,184,0.08)' : 'rgba(148,163,184,0.18)',
    tooltipBackground: isDark ? 'rgba(10,18,40,0.98)' : 'rgba(255,255,255,0.99)',
    tooltipTitle: isDark ? '#f1f5f9' : '#0f172a',
    tooltipBody: isDark ? '#cbd5e1' : '#334155',
    tooltipBorder: isDark ? 'rgba(148,163,184,0.14)' : 'rgba(203,213,225,0.9)',
    surfaceStroke: isDark ? 'rgba(51,65,85,0.92)' : 'rgba(203,213,225,0.92)',
    emphasis: isDark ? '#e2e8f0' : '#1c203d',
    emphasisFill: isDark ? 'rgba(226,232,240,0.14)' : 'rgba(28,32,61,0.16)',
    tooltipShadow: isDark ? 'rgba(0,0,0,0.5)' : 'rgba(15,23,42,0.1)',
  };
}

export function applyAnbgChartDefaults(Chart) {
  if (!Chart) {
    return getAnbgChartTheme();
  }

  const theme = getAnbgChartTheme();

  if (Chart.defaults.plugins.datalabels !== undefined) {
    Chart.defaults.plugins.datalabels = false;
  }

  Chart.defaults.color = theme.text;
  Chart.defaults.borderColor = theme.grid;
  Chart.defaults.font.family = 'Manrope, Public Sans, ui-sans-serif, system-ui, sans-serif';
  Chart.defaults.font.size = 12;

  // Lines — premium
  Chart.defaults.elements.line.borderWidth = 3;
  Chart.defaults.elements.line.borderJoinStyle = 'round';
  Chart.defaults.elements.line.borderCapStyle = 'round';
  Chart.defaults.elements.line.tension = 0.42;

  // Points — solid with white ring
  Chart.defaults.elements.point.radius = 4;
  Chart.defaults.elements.point.hoverRadius = 7;
  Chart.defaults.elements.point.hoverBorderWidth = 2.5;
  Chart.defaults.elements.point.hitRadius = 12;
  Chart.defaults.elements.point.borderWidth = 2.5;

  // Bars — rounded tops, no bottom skip
  Chart.defaults.elements.bar.borderSkipped = false;
  Chart.defaults.elements.bar.borderRadius = 8;
  Chart.defaults.elements.bar.inflateAmount = 0.5;

  // Legend — compact, bottom-left
  Chart.defaults.plugins.legend.align = 'start';
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
  Chart.defaults.plugins.legend.labels.boxWidth = 9;
  Chart.defaults.plugins.legend.labels.boxHeight = 9;
  Chart.defaults.plugins.legend.labels.padding = 16;
  Chart.defaults.plugins.legend.labels.color = theme.text;
  Chart.defaults.plugins.legend.labels.font = { size: 11, weight: '700' };

  // Tooltip — premium glass
  Chart.defaults.plugins.tooltip.backgroundColor = theme.tooltipBackground;
  Chart.defaults.plugins.tooltip.titleColor = theme.tooltipTitle;
  Chart.defaults.plugins.tooltip.bodyColor = theme.tooltipBody;
  Chart.defaults.plugins.tooltip.borderColor = theme.tooltipBorder;
  Chart.defaults.plugins.tooltip.borderWidth = 1;
  Chart.defaults.plugins.tooltip.cornerRadius = 16;
  Chart.defaults.plugins.tooltip.padding = { x: 16, y: 12 };
  Chart.defaults.plugins.tooltip.boxPadding = 6;
  Chart.defaults.plugins.tooltip.usePointStyle = true;
  Chart.defaults.plugins.tooltip.displayColors = true;
  Chart.defaults.plugins.tooltip.titleFont = { size: 13, weight: '800' };
  Chart.defaults.plugins.tooltip.bodyFont = { size: 12, weight: '600' };
  Chart.defaults.plugins.tooltip.footerFont = { size: 11, weight: '700' };

  return theme;
}

window.getAnbgChartTheme = getAnbgChartTheme;
window.applyAnbgChartDefaults = applyAnbgChartDefaults;
