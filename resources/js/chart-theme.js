export function getAnbgChartTheme() {
  const isDark = document.documentElement.classList.contains('dark');

  return {
    isDark,
    text: isDark ? '#e2e8f0' : '#334155',
    muted: isDark ? '#94a3b8' : '#64748b',
    grid: isDark ? 'rgba(100,116,139,0.28)' : 'rgba(148,163,184,0.22)',
    tooltipBackground: isDark ? 'rgba(6,12,28,0.96)' : 'rgba(255,255,255,0.98)',
    tooltipTitle: isDark ? '#f8fafc' : '#0f172a',
    tooltipBody: isDark ? '#e2e8f0' : '#334155',
    tooltipBorder: isDark ? 'rgba(57,150,211,0.26)' : 'rgba(148,163,184,0.28)',
    surfaceStroke: isDark ? 'rgba(148,163,184,0.22)' : 'rgba(203,213,225,0.92)',
    emphasis: isDark ? '#f8e932' : '#1c203d',
    emphasisFill: isDark ? 'rgba(248,233,50,0.16)' : 'rgba(28,32,61,0.16)',
  };
}

export function applyAnbgChartDefaults(Chart) {
  if (!Chart) {
    return getAnbgChartTheme();
  }

  const theme = getAnbgChartTheme();

  Chart.defaults.color = theme.text;
  Chart.defaults.borderColor = theme.grid;
  Chart.defaults.font.family = 'Instrument Sans, ui-sans-serif, system-ui, sans-serif';
  Chart.defaults.plugins.legend.labels.color = theme.text;
  Chart.defaults.plugins.tooltip.backgroundColor = theme.tooltipBackground;
  Chart.defaults.plugins.tooltip.titleColor = theme.tooltipTitle;
  Chart.defaults.plugins.tooltip.bodyColor = theme.tooltipBody;
  Chart.defaults.plugins.tooltip.borderColor = theme.tooltipBorder;
  Chart.defaults.plugins.tooltip.borderWidth = 1;

  return theme;
}

window.getAnbgChartTheme = getAnbgChartTheme;
window.applyAnbgChartDefaults = applyAnbgChartDefaults;
