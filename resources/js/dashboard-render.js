let dashboardBooted = false;

function bootDashboardRender(force = false) {
  if (dashboardBooted && !force) {
    return;
  }

  const payloadNode = document.getElementById('anbg-dashboard-payload');

  if (!payloadNode) {
    return;
  }

  let parsed = {};

  try {
    parsed = JSON.parse(payloadNode.textContent || '{}');
  } catch (error) {
    console.error('Payload dashboard illisible.', error);
    return;
  }

  dashboardBooted = true;
  window.__anbgDashboardLastPayloadText = payloadNode.textContent || '';
  const payload = parsed.dashboardData || {};
  const roleDashboard = payload.role_dashboard || {};
  const reporting = parsed.reportingAnalytics || {};
  const ganttRows = parsed.ganttRows || [];
  const statusCards = payload.status_cards || [];
  const monthly = payload.monthly || [];
  const unitRows = payload.unit_rows || [];
  const interannual = payload.interannual || [];
  const scatterPoints = payload.scatter_points || [];
  const radarDatasets = payload.radar_datasets || [];
  const actionRows = payload.action_rows || [];
  const reportingCharts = reporting.charts || {};
  const panelKeys = ['overview', 'charts', 'tables'];
  const panelAliases = {
    overview: 'overview',
    synthese: 'overview',
    charts: 'charts',
    graphes: 'charts',
    kpi: 'charts',
    gantt: 'charts',
    analytics: 'charts',
    actions: 'tables',
    tables: 'tables',
  };
  const chartInstances = {};
  const compactFormatter = new Intl.NumberFormat('fr-FR', {
    notation: 'compact',
    maximumFractionDigits: 1,
  });
  const decimalFormatter = new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: 1,
  });
  const ANBG = {
    primary: '#1C203D',
    secondary: '#3996D3',
    light: '#E8F3FB',
    white: '#FFFFFF',
    dark: '#101A33',
    muted: '#64748B',
    success: '#8FC043',
    warning: '#F9B13C',
    danger: '#B42318',
  };
  let assetBootstrapPromise = null;
  let optionalChartPluginsPromise = null;
  let renderInFlight = false;
  let renderQueued = false;

  const centerTextPlugin = {
    id: 'anbgCenterText',
    afterDraw(chart, _args, options) {
      if (!options || !Array.isArray(options.lines) || !chart.chartArea) {
        return;
      }

      const { ctx, chartArea } = chart;
      const centerX = (chartArea.left + chartArea.right) / 2;
      const centerY = options.half
        ? chartArea.top + chartArea.height * 0.76
        : (chartArea.top + chartArea.bottom) / 2;

      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';

      options.lines.forEach((line) => {
        ctx.font = line.font || '700 14px Manrope, Public Sans, ui-sans-serif, system-ui, sans-serif';
        ctx.fillStyle = line.color || '#0f172a';
        ctx.fillText(line.text || '', centerX, centerY + (line.offsetY || 0));
      });

      ctx.restore();
    },
  };

  function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
  }

  function mergeObjects(target, source) {
    Object.keys(source || {}).forEach((key) => {
      const sourceValue = source[key];

      if (isObject(sourceValue)) {
        if (!isObject(target[key])) {
          target[key] = {};
        }

        mergeObjects(target[key], sourceValue);
        return;
      }

      target[key] = sourceValue;
    });

    return target;
  }

  function dashboardTheme() {
    if (typeof window.getAnbgChartTheme === 'function') {
      return window.getAnbgChartTheme();
    }

    const isDark = document.documentElement.classList.contains('dark');

    return {
      isDark,
      text: isDark ? '#cbd5e1' : '#334155',
      muted: isDark ? '#94a3b8' : '#64748b',
      grid: isDark ? 'rgba(148,163,184,0.1)' : 'rgba(148,163,184,0.22)',
      tooltipBackground: isDark ? 'rgba(15,23,42,0.97)' : 'rgba(255,255,255,0.98)',
      tooltipTitle: isDark ? '#f1f5f9' : '#0f172a',
      tooltipBody: isDark ? '#cbd5e1' : '#334155',
      tooltipBorder: isDark ? 'rgba(148,163,184,0.16)' : 'rgba(148,163,184,0.28)',
      emphasis: isDark ? '#e2e8f0' : '#1c203d',
    };
  }

  function palette() {
    return [ANBG.primary, ANBG.secondary, ANBG.success, ANBG.warning, ANBG.danger, ANBG.dark, ANBG.secondary, ANBG.primary];
  }

  function toneForIndex(index) {
    const colors = palette();
    return colors[index % colors.length];
  }

  function alphaColor(hex, opacity) {
    const normalized = hex.replace('#', '');
    const value = normalized.length === 3
      ? normalized.split('').map((part) => part + part).join('')
      : normalized;

    const red = parseInt(value.slice(0, 2), 16);
    const green = parseInt(value.slice(2, 4), 16);
    const blue = parseInt(value.slice(4, 6), 16);

    return `rgba(${red}, ${green}, ${blue}, ${opacity})`;
  }

  function truncateLabel(value, limit = 18) {
    const label = String(value ?? '');
    return label.length > limit ? `${label.slice(0, limit - 1)}...` : label;
  }

  function formatNumber(value, digits = 1) {
    const numeric = Number(value ?? 0);

    if (!Number.isFinite(numeric)) {
      return '0';
    }

    return digits === 0
      ? new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(numeric)
      : decimalFormatter.format(numeric);
  }

  function formatCompact(value) {
    const numeric = Number(value ?? 0);

    if (!Number.isFinite(numeric)) {
      return '0';
    }

    return Math.abs(numeric) >= 1000
      ? compactFormatter.format(numeric)
      : formatNumber(numeric, 0);
  }

  function formatAxisTick(scale, value, ticks, limit = 18) {
    const raw = scale?.getLabelForValue ? scale.getLabelForValue(value) : value;
    const numeric = Number(raw);

    if (typeof raw === 'string' && !Number.isFinite(numeric)) {
      return truncateLabel(raw, ticks.length > 8 ? Math.min(limit, 12) : limit);
    }

    return formatCompact(Number.isFinite(numeric) ? numeric : value);
  }

  function colorForStatus(label, index) {
    const status = String(label || '').toLowerCase();

    if (status.includes('retard')) {
      return ANBG.danger;
    }

    if (status.includes('avance') || status.includes('valide') || status.includes('acheve')) {
      return ANBG.success;
    }

    if (status.includes('non') || status.includes('rejet')) {
      return ANBG.muted;
    }

    if (status.includes('soumis') || status.includes('cours')) {
      return ANBG.secondary;
    }

    return toneForIndex(index);
  }

  function gaugeColor(value) {
    const numeric = Number.isFinite(Number(value)) ? Number(value) : 0;

    if (numeric >= 80) {
      return ANBG.success;
    }

    if (numeric >= 60) {
      return ANBG.secondary;
    }

    return ANBG.warning;
  }

  function finiteNumber(value, fallback = 0) {
    const numeric = Number(value);

    return Number.isFinite(numeric) ? numeric : fallback;
  }

  function reportingFunnelTone(label, index, opacity = 1) {
    const normalized = String(label || '').toLowerCase();
    let color = toneForIndex(index);

    if (normalized.includes('pas')) {
      color = ANBG.primary;
    } else if (normalized.includes('pao')) {
      color = ANBG.secondary;
    } else if (normalized.includes('pta')) {
      color = ANBG.secondary;
    } else if (normalized.includes('action')) {
      color = ANBG.dark;
    }

    return opacity >= 1 ? color : alphaColor(color, opacity);
  }

  function reportingTreemapTone(index) {
    const tones = [
      ANBG.primary,
      ANBG.secondary,
      alphaColor(ANBG.secondary, 0.62),
      alphaColor(ANBG.primary, 0.48),
      ANBG.dark,
    ];

    return tones[index % tones.length];
  }

  function chartGradient(chart, color) {
    const { ctx, chartArea } = chart;

    if (!chartArea) {
      return alphaColor(color, 0.22);
    }

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, alphaColor(color, 0.58));
    gradient.addColorStop(0.4, alphaColor(color, 0.26));
    gradient.addColorStop(0.75, alphaColor(color, 0.08));
    gradient.addColorStop(1, alphaColor(color, 0.01));

    return gradient;
  }

  function barGradient(chart, color) {
    const { ctx, chartArea } = chart;

    if (!chartArea) {
      return alphaColor(color, 0.92);
    }

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, alphaColor(color, 1));
    gradient.addColorStop(0.5, alphaColor(color, 0.88));
    gradient.addColorStop(1, alphaColor(color, 0.7));

    return gradient;
  }

  function ensurePlugins() {
    if (
      typeof window.Chart !== 'undefined' &&
      window.Chart.registry?.plugins?.get &&
      !window.Chart.registry.plugins.get('anbgCenterText')
    ) {
      window.Chart.register(centerTextPlugin);
    }
  }

  function kpiAnnotations() {
    return {};
  }

  function barDataLabels(formatFn) {
    return false;
  }

  function destroyChart(id) {
    if (chartInstances[id] && typeof chartInstances[id].destroy === 'function') {
      chartInstances[id].destroy();
    }

    delete chartInstances[id];
  }

  function rememberHostFallback(host) {
    if (!host || host.__anbgDashboardFallbackMarkup) {
      return;
    }

    if (host.querySelector('.dashboard-chart-fallback, .dashboard-gauge-fallback, .dashboard-chart-empty')) {
      host.__anbgDashboardFallbackMarkup = host.innerHTML;
    }
  }

  function restoreHostFallback(host, message) {
    if (!host) {
      return;
    }

    const fallbackMarkup = host.__anbgDashboardFallbackMarkup || '';

    if (fallbackMarkup !== '') {
      host.innerHTML = fallbackMarkup;
      host.dataset.chartState = 'fallback';
      return;
    }

    mountChartEmptyState(host, message);
  }

  function filterByPeriod(data, count) {
    if (!Array.isArray(data)) return data;
    return count > 0 && count < data.length ? data.slice(-count) : data;
  }

  function bindPeriodButtons(containerSelector, rebuildFn) {
    const container = document.querySelector(containerSelector);
    if (!container) return;
    container.querySelectorAll('[data-period]').forEach((btn) => {
      btn.addEventListener('click', () => {
        container.querySelectorAll('[data-period]').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        rebuildFn(Number(btn.dataset.period) || 0);
      });
    });
  }

  function chartDatasetHasData(dataset) {
    if (!dataset || typeof dataset !== 'object') {
      return false;
    }

    if (Array.isArray(dataset.data) && dataset.data.length > 0) {
      return true;
    }

    if (Array.isArray(dataset.tree) && dataset.tree.length > 0) {
      return true;
    }

    return false;
  }

  function chartConfigHasData(config) {
    const chartData = config?.data || {};

    if (Array.isArray(chartData.datasets) && chartData.datasets.length > 0) {
      return chartData.datasets.some(chartDatasetHasData);
    }

    return Array.isArray(chartData.labels) && chartData.labels.length > 0;
  }

  function mountChartEmptyState(host, message) {
    host.innerHTML = '';
    host.dataset.chartState = 'empty';

    const empty = document.createElement('div');
    empty.className = 'dashboard-chart-empty';
    empty.textContent = message || host.dataset.emptyMessage || 'Aucune donnée disponible pour ce graphique.';
    host.appendChild(empty);
  }

  function metricSortKey(label) {
    const normalized = String(label || '').toLowerCase();

    if (normalized.includes('delai')) {
      return 'kpi_delai_desc';
    }

    if (normalized.includes('performance') || normalized.includes('perf')) {
      return 'kpi_performance_desc';
    }

    if (normalized.includes('conformite') || normalized.includes('conf')) {
      return 'kpi_conformite_desc';
    }

    if (normalized.includes('global')) {
      return 'kpi_global_desc';
    }

    if (normalized.includes('progress')) {
      return 'progression_desc';
    }

    return '';
  }

  function withQuery(url, params) {
    if (!url) {
      return '';
    }

    const parsed = new URL(url, window.location.origin);
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') {
        return;
      }

      parsed.searchParams.set(key, String(value));
    });

    return `${parsed.pathname}${parsed.search}`;
  }

  function stopNativeEvent(event) {
    if (!event) {
      return;
    }

    if (typeof event.preventDefault === 'function') {
      event.preventDefault();
    }
    if (typeof event.stopPropagation === 'function') {
      event.stopPropagation();
    }
    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    }
  }

  function bindChartDrilldown(chart, resolver) {
    const canvas = chart?.canvas;

    if (!canvas || typeof resolver !== 'function') {
      return;
    }

    const resolveUrl = (event) => {
      const elements = chart.getElementsAtEventForMode(event, 'nearest', { intersect: true }, true);

      if (!Array.isArray(elements) || elements.length === 0) {
        return '';
      }

      return resolver({
        chart,
        event,
        elements,
        element: elements[0],
      }) || '';
    };

    canvas.addEventListener('click', (event) => {
      const url = resolveUrl(event);

      if (!url) {
        return;
      }

      stopNativeEvent(event);
      window.location.assign(url);
    });

    canvas.addEventListener('mousemove', (event) => {
      canvas.style.cursor = resolveUrl(event) ? 'pointer' : '';
    });

    canvas.addEventListener('mouseleave', () => {
      canvas.style.cursor = '';
    });
  }

  function mountChart(id, config, drilldownResolver = null) {
    const host = document.getElementById(id);

    if (!host) {
      return;
    }

    rememberHostFallback(host);
    destroyChart(id);

    if (typeof window.Chart === 'undefined') {
      restoreHostFallback(host, 'Moteur graphique indisponible.');
      return;
    }

    if (!chartConfigHasData(config)) {
      restoreHostFallback(host);
      return;
    }

    host.innerHTML = '';
    const canvas = document.createElement('canvas');
    host.appendChild(canvas);

    try {
      chartInstances[id] = new window.Chart(canvas, config);
    } catch (error) {
      console.error(`Impossible d'afficher le graphique ${id}.`, error);
      restoreHostFallback(host, "Impossible d'afficher ce graphique.");
      return;
    }

    host.dataset.chartState = 'ready';
    bindChartDrilldown(chartInstances[id], drilldownResolver);

    // Inject export button into the closest showcase-panel header
    const panel = host.closest('.showcase-panel, .dashboard-card, article');
    if (panel && !panel.querySelector('.chart-export-btn')) {
      const headerRow = panel.querySelector('.mb-4.flex, .mb-3.flex, [class*="justify-between"]');
      if (headerRow) {
        const exportBtn = document.createElement('button');
        exportBtn.type = 'button';
        exportBtn.className = 'chart-export-btn';
        exportBtn.setAttribute('aria-label', 'Télécharger le graphique');
        exportBtn.title = 'Télécharger en PNG';
        exportBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
        exportBtn.addEventListener('click', () => {
          const chart = chartInstances[id];
          if (!chart) return;
          const url = chart.toBase64Image('image/png', 1);
          const link = document.createElement('a');
          link.href = url;
          link.download = (id || 'graphique') + '.png';
          document.body.appendChild(link);
          link.click();
          link.remove();
          if (window.anbgToast) {
            window.anbgToast({ tone: 'success', message: 'Graphique téléchargé.', duration: 2500 });
          }
        });
        headerRow.appendChild(exportBtn);
      }
    }
  }

  async function ensureChartRegisterables() {
    if (typeof window.Chart === 'undefined') {
      return;
    }

    try {
      const [{ registerables }, chartTheme] = await Promise.all([
        import('chart.js'),
        import('./chart-theme'),
      ]);

      if (Array.isArray(registerables) && typeof window.Chart.register === 'function') {
        window.Chart.register(...registerables);
      }

      if (typeof chartTheme.applyAnbgChartDefaults === 'function') {
        chartTheme.applyAnbgChartDefaults(window.Chart);
        window.getAnbgChartTheme = chartTheme.getAnbgChartTheme;
        window.applyAnbgChartDefaults = chartTheme.applyAnbgChartDefaults;
      }
    } catch (error) {
      console.error('Impossible de preparer Chart.js.', error);
    }
  }

  async function ensureOptionalChartPlugins({ needD3 = false } = {}) {
    if (typeof window.Chart === 'undefined') {
      return;
    }

    const needsD3 = needD3 && typeof window.d3 === 'undefined';

    if (!needsD3) {
      return;
    }

    if (!optionalChartPluginsPromise) {
      optionalChartPluginsPromise = (async () => {
        const imports = await Promise.allSettled([
          needsD3 ? import('d3') : Promise.resolve(null),
        ]);

        const d3Module = imports[0].status === 'fulfilled' ? imports[0].value : null;

        if (d3Module && typeof window.d3 === 'undefined') {
          window.d3 = d3Module;
        }
      })().catch((error) => {
        console.error('Impossible de charger les plugins graphiques du dashboard.', error);
      }).finally(() => {
        optionalChartPluginsPromise = null;
      });
    }

    await optionalChartPluginsPromise;
  }

  async function ensureDashboardAssets({ needD3 = false } = {}) {
    const hasChart = typeof window.Chart !== 'undefined';

    if (hasChart) {
      await ensureChartRegisterables();
      await ensureOptionalChartPlugins({ needD3 });
      return;
    }

    if (!assetBootstrapPromise) {
      assetBootstrapPromise = (async () => {
        try {
          if (typeof window.Chart === 'undefined') {
            const [{ Chart, registerables }, chartTheme] = await Promise.all([
              import('chart.js'),
              import('./chart-theme'),
            ]);

            const optionalModules = await Promise.allSettled([
              import('d3'),
            ]);

            const d3Module = optionalModules[0].status === 'fulfilled' ? optionalModules[0].value : null;

            Chart.register(...registerables);

            chartTheme.applyAnbgChartDefaults(Chart);
            window.Chart = Chart;
            window.getAnbgChartTheme = chartTheme.getAnbgChartTheme;
            window.applyAnbgChartDefaults = chartTheme.applyAnbgChartDefaults;

            if (d3Module) {
              window.d3 = d3Module;
            }

            return;
          }

          await ensureOptionalChartPlugins({ needD3 });
        } catch (error) {
          console.error('Impossible de charger les ressources du dashboard.', error);
        }
      })().finally(() => {
        assetBootstrapPromise = null;
      });
    }

    await assetBootstrapPromise;
    await ensureChartRegisterables();
    await ensureOptionalChartPlugins({ needD3 });
  }

  function safeRenderStep(label, callback) {
    try {
      callback();
    } catch (error) {
      console.error(`Echec de rendu du bloc dashboard: ${label}`, error);
    }
  }

  window.anbgDashboardRuntime = {
    getChart(id) {
      return chartInstances[id] || null;
    },
    getChartIds() {
      return Object.keys(chartInstances);
    },
  };

  function bindRowLinks() {
    document.querySelectorAll('[data-row-link]').forEach((row) => {
      const rowUrl = row.dataset.rowLink || '';

      if (!rowUrl) {
        return;
      }

      if (row.dataset.rowLinkBound === '1') {
        return;
      }

      row.dataset.rowLinkBound = '1';
      row.tabIndex = 0;
      row.setAttribute('role', 'link');

      const navigate = (event) => {
        if (event.target.closest('a, button, input, select, textarea, label, form')) {
          return;
        }

        stopNativeEvent(event);
        window.location.assign(rowUrl);
      };

      row.addEventListener('click', navigate);
      row.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        navigate(event);
      });
    });
  }

  function bindChartDisclosurePanels() {
    const panels = new Set();

    document.querySelectorAll('.dashboard-chart-host').forEach((host) => {
      const panel = host.closest('article, .showcase-panel, .dashboard-advanced-card, .dashboard-card');

      if (panel) {
        panels.add(panel);
      }
    });

    panels.forEach((panel) => {
      if (panel.dataset.chartDisclosureBound === '1') {
        return;
      }

      const header = panel.querySelector('.chart-panel-head, .dashboard-advanced-head, .mb-4.flex, .mb-3.flex, [class*="justify-between"]');
      const body = panel.querySelector('.dashboard-canvas, .dashboard-gauge-grid-4, .dashboard-gauge-grid, .dashboard-chart-host');

      if (!header || !body || header.contains(body)) {
        return;
      }

      panel.dataset.chartDisclosureBound = '1';
      panel.classList.add('chart-disclosure-panel');
      body.classList.add('chart-disclosure-body');

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'chart-disclosure-toggle';
      button.setAttribute('aria-expanded', 'true');
      button.setAttribute('aria-label', 'Replier ou deployer le graphique');
      button.title = 'Replier ou deployer';
      button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

      const setExpanded = (expanded) => {
        panel.classList.toggle('chart-disclosure-collapsed', !expanded);
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        if (expanded) {
          body.style.maxHeight = `${body.scrollHeight}px`;
          window.setTimeout(() => {
            body.style.maxHeight = '';
            resizeCharts();
          }, 220);
          return;
        }

        body.style.maxHeight = `${body.scrollHeight}px`;
        window.requestAnimationFrame(() => {
          body.style.maxHeight = '0px';
        });
      };

      button.addEventListener('click', () => {
        setExpanded(panel.classList.contains('chart-disclosure-collapsed'));
      });

      header.appendChild(button);
    });
  }

  function baseConfig(type, overrides) {
    const theme = dashboardTheme();

    const base = {
      type,
      data: {
        labels: [],
        datasets: [],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        resizeDelay: 90,
        normalized: true,
        layout: {
          padding: {
            top: 6,
            right: 8,
            bottom: 2,
            left: 4,
          },
        },
        animation: {
          duration: 600,
          easing: 'easeOutCubic',
          delay(context) {
            if (context.type !== 'data' || context.mode !== 'default') {
              return 0;
            }

            const dataIndex = Number(context.dataIndex);

            return Number.isFinite(dataIndex) ? dataIndex * 22 : 0;
          },
        },
        interaction: {
          mode: 'index',
          intersect: false,
        },
        plugins: {
          legend: {
            position: 'bottom',
            maxHeight: 54,
            labels: {
              usePointStyle: true,
              pointStyle: 'circle',
              boxWidth: 10,
              boxHeight: 10,
              padding: 16,
              color: theme.text,
              font: {
                size: 11,
                weight: '700',
              },
              filter(item, data) {
                const dataset = data?.datasets?.[item.datasetIndex];
                return !!dataset && Array.isArray(dataset.data) && dataset.data.length > 0;
              },
            },
          },
          tooltip: {
            backgroundColor: theme.tooltipBackground,
            titleColor: theme.tooltipTitle,
            bodyColor: theme.tooltipBody,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            padding: 12,
            cornerRadius: 14,
            boxPadding: 5,
            usePointStyle: true,
            displayColors: true,
            titleFont: {
              size: 13,
              weight: '800',
            },
            bodyFont: {
              size: 12,
              weight: '600',
            },
            callbacks: {
              label(context) {
                let raw = context.parsed?.y ?? context.parsed?.x ?? context.parsed ?? context.raw ?? 0;

                if (raw && typeof raw === 'object') {
                  raw = raw.y ?? raw.x ?? raw.v ?? raw.value ?? 0;
                }

                return ` ${context.dataset.label || 'Valeur'}: ${formatNumber(raw)}`;
              },
            },
          },
          datalabels: false,
        },
      },
    };

    return mergeObjects(base, overrides || {});
  }

  function cartesianScales(extra) {
    const theme = dashboardTheme();

    return mergeObjects({
      x: {
        grid: {
          display: false,
          drawTicks: false,
        },
        ticks: {
          color: theme.muted,
          autoSkip: true,
          autoSkipPadding: 14,
          maxRotation: 0,
          minRotation: 0,
          maxTicksLimit: 8,
          padding: 8,
          font: {
            size: 11,
            weight: '600',
          },
          callback(value, index, ticks) {
            return formatAxisTick(this, value, ticks, 18);
          },
        },
        border: {
          color: theme.grid,
        },
      },
      y: {
        beginAtZero: true,
        grid: {
          color: theme.grid,
          borderDash: [4, 4],
          drawTicks: false,
        },
        ticks: {
          color: theme.muted,
          maxTicksLimit: 6,
          padding: 10,
          font: {
            size: 11,
            weight: '600',
          },
          callback(value) {
            return formatAxisTick(this, value, this.ticks || [], 16);
          },
        },
        border: {
          display: false,
        },
      },
    }, extra || {});
  }

  function mountGauge(id, label, value, href = '') {
    const theme = dashboardTheme();
    const numeric = Math.max(0, Math.min(100, finiteNumber(value, 0)));
    const color = gaugeColor(numeric);

    mountChart(id, baseConfig('doughnut', {
      data: {
        labels: [label, 'Reste'],
        datasets: [{
          data: [numeric, Math.max(0, 100 - numeric)],
          backgroundColor: [
            color,
            theme.isDark ? 'rgba(30,41,59,0.92)' : 'rgba(241,245,249,0.98)',
          ],
          borderWidth: 0,
          hoverOffset: 4,
          borderRadius: 6,
        }],
      },
      options: {
        rotation: 270,
        circumference: 180,
        cutout: '78%',
        plugins: {
          legend: { display: false },
          tooltip: {
            filter: (item) => item.dataIndex === 0,
            callbacks: {
              title: () => label,
              label: (ctx) => ` Score : ${Math.round(numeric)} %`,
              afterLabel: () => numeric >= 80 ? ' ✔ Objectif atteint' : numeric >= 60 ? ' ⚡ À surveiller' : ' ✖ Sous le seuil',
            },
          },
          anbgCenterText: {
            half: true,
            lines: [
              {
                text: `${Math.round(numeric)}%`,
                color,
                font: '800 26px Manrope, Public Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: -4,
              },
              {
                text: label,
                color: theme.muted,
                font: '600 11px Manrope, Public Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: 18,
              },
            ],
          },
        },
      },
    }), ({ element }) => element?.index === 0 ? href : '');
  }

  function mountRoleCharts() {
    const comparison = roleDashboard.comparison_chart || {};
    const status = roleDashboard.status_chart || {};
    const trend = roleDashboard.trend_chart || {};
    const support = roleDashboard.support_chart || {};

    if (Array.isArray(comparison.labels) && comparison.labels.length > 0) {
      const chartType = comparison.type || 'bar';
      const indexAxis = comparison.index_axis || 'x';
      const stacked = Boolean(comparison.stacked);

      mountChart('dashboard-role-comparison-chart', baseConfig(chartType, {
        data: {
          labels: comparison.labels,
          datasets: (comparison.datasets || []).map((dataset, index) => {
            const color = dataset.color || toneForIndex(index);
            return {
              label: dataset.label || `Serie ${index + 1}`,
              data: (dataset.data || []).map((value) => finiteNumber(value, 0)),
              borderColor: color,
              backgroundColor: chartType === 'line'
                ? (context) => chartGradient(context.chart, color)
                : (context) => barGradient(context.chart, color),
              hoverBackgroundColor: color,
              hoverBorderColor: color,
              borderWidth: chartType === 'line' ? 2.5 : 1,
              borderRadius: chartType === 'bar' ? 14 : 0,
              maxBarThickness: 34,
              tension: 0.38,
              fill: chartType === 'line',
              pointRadius: chartType === 'line' ? 4 : 0,
              pointHoverRadius: chartType === 'line' ? 7 : 0,
              pointBackgroundColor: color,
              pointBorderColor: '#ffffff',
              pointBorderWidth: 2,
            };
          }),
        },
        options: {
          indexAxis,
          scales: cartesianScales({
            x: { stacked },
            y: { stacked },
          }),
        },
      }), ({ element }) => (comparison.urls || [])[element?.index] || '');
    }

    if (Array.isArray(status.labels) && status.labels.length > 0) {
      const theme = dashboardTheme();

      mountChart('dashboard-role-status-chart', baseConfig('doughnut', {
        data: {
          labels: status.labels,
          datasets: [{
            data: (status.values || []).map((value) => finiteNumber(value, 0)),
            backgroundColor: (status.labels || []).map((label, index) => alphaColor(colorForStatus(label, index), 0.92)),
            borderColor: theme.isDark ? '#0f172a' : '#ffffff',
            borderWidth: 3,
            hoverOffset: 12,
            borderRadius: 4,
          }],
        },
        options: {
          cutout: '74%',
        },
      }), ({ element }) => (status.urls || [])[element?.index] || '');
    }

    if (Array.isArray(trend.labels) && trend.labels.length > 0) {
      mountChart('dashboard-role-trend-chart', baseConfig('line', {
        data: {
          labels: trend.labels,
          datasets: (trend.datasets || []).map((dataset, index) => {
            const color = dataset.color || toneForIndex(index);
            return {
              label: dataset.label || `Serie ${index + 1}`,
              data: (dataset.data || []).map((value) => finiteNumber(value, 0)),
              borderColor: color,
              backgroundColor: (context) => chartGradient(context.chart, color),
              pointBackgroundColor: color,
              pointBorderColor: '#ffffff',
              pointBorderWidth: 2,
              pointRadius: 4,
              pointHoverRadius: 7,
              tension: 0.38,
              fill: true,
              borderWidth: 2.5,
            };
          }),
        },
        options: {
          scales: cartesianScales(),
          plugins: { ...kpiAnnotations() },
        },
      }), ({ element }) => (trend.urls || [])[element?.index] || '');
    }

    if (Array.isArray(support.labels) && support.labels.length > 0) {
      const chartType = support.type || 'bar';
      const indexAxis = support.index_axis || 'x';
      const stacked = Boolean(support.stacked);

      mountChart('dashboard-role-support-chart', baseConfig(chartType, {
        data: {
          labels: support.labels,
          datasets: (support.datasets || []).map((dataset, index) => {
            const color = dataset.color || toneForIndex(index);
            return {
              label: dataset.label || `Serie ${index + 1}`,
              data: (dataset.data || []).map((value) => finiteNumber(value, 0)),
              borderColor: color,
              backgroundColor: chartType === 'line'
                ? (context) => chartGradient(context.chart, color)
                : (context) => barGradient(context.chart, color),
              hoverBackgroundColor: color,
              hoverBorderColor: color,
              borderWidth: chartType === 'line' ? 2.5 : 1,
              borderRadius: chartType === 'bar' ? 14 : 0,
              maxBarThickness: 32,
              tension: 0.38,
              fill: chartType === 'line',
              pointRadius: chartType === 'line' ? 4 : 0,
              pointHoverRadius: chartType === 'line' ? 7 : 0,
              pointBackgroundColor: color,
              pointBorderColor: '#ffffff',
              pointBorderWidth: 2,
            };
          }),
        },
        options: {
          indexAxis,
          scales: cartesianScales({
            x: { stacked },
            y: { stacked },
          }),
        },
      }), ({ element }) => (support.urls || [])[element?.index] || '');
    }
  }

  function renderGanttChart(hostId, rows, meta) {
    const host = document.getElementById(hostId);

    if (!host || typeof window.d3 === 'undefined') {
      return;
    }

    host.innerHTML = '';

    if (!Array.isArray(rows) || rows.length === 0) {
      return;
    }

    const d3 = window.d3;
    const prepared = rows
      .map((row) => {
        const start = new Date(meta.startAccèssor(row));
        let end = new Date(meta.endAccèssor(row));

        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
          return null;
        }

        if (end <= start) {
          end = new Date(start.getTime() + 86400000);
        }

        return {
          label: meta.labelAccèssor(row),
          subLabel: meta.subLabelAccèssor ? meta.subLabelAccèssor(row) : '',
          start,
          end,
          progress: Math.max(0, Math.min(100, Number(meta.progressAccèssor(row) || 0))),
          color: meta.colorAccèssor ? meta.colorAccèssor(row) : '#3996D3',
          rightLabel: meta.rightLabelAccèssor ? meta.rightLabelAccèssor(row) : '',
          url: meta.urlAccèssor ? meta.urlAccèssor(row) : null,
        };
      })
      .filter(Boolean);

    if (prepared.length === 0) {
      return;
    }

    const width = Math.max(host.clientWidth || 820, 820);
    const left = meta.leftMargin || 232;
    const right = meta.rightMargin || 68;
    const rowHeight = meta.rowHeight || 52;
    const top = 46;
    const bottom = 30;
    const height = top + bottom + (prepared.length * rowHeight);
    const minDate = d3.min(prepared, (item) => item.start);
    let maxDate = d3.max(prepared, (item) => item.end);

    if (minDate.getTime() === maxDate.getTime()) {
      maxDate = new Date(maxDate.getTime() + 86400000);
    }

    const x = d3.scaleTime()
      .domain([minDate, maxDate])
      .range([left, width - right]);

    const svg = d3.select(host)
      .append('svg')
      .attr('class', 'dashboard-gantt-svg')
      .attr('viewBox', `0 0 ${width} ${height}`)
      .attr('preserveAspectRatio', 'xMidYMid meet');

    const axis = d3.axisTop(x)
      .ticks(meta.axisTicks || d3.timeMonth.every(1))
      .tickFormat(meta.tickFormat || d3.timeFormat('%b'));

    svg.append('g')
      .attr('class', 'dashboard-gantt-axis')
      .attr('transform', `translate(0,${top})`)
      .call(axis);

    const today = new Date();

    if (today >= minDate && today <= maxDate) {
      svg.append('line')
        .attr('class', 'dashboard-gantt-today-line')
        .attr('x1', x(today))
        .attr('x2', x(today))
        .attr('y1', top + 6)
        .attr('y2', height - bottom + 6);
    }

    const rowsGroup = svg.append('g').attr('transform', `translate(0,${top + 16})`);

    rowsGroup.selectAll('g')
      .data(prepared)
      .enter()
      .append('g')
      .attr('transform', (_item, index) => `translate(0,${index * rowHeight})`)
      .each(function drawRow(item) {
        const group = d3.select(this);

        if (item.url) {
          group.style('cursor', 'pointer').on('click', () => {
            window.location.href = item.url;
          });
        }

        const label = String(item.label || '');

        group.append('text')
          .attr('class', 'dashboard-gantt-label')
          .attr('x', 12)
          .attr('y', 14)
          .text(label.length > 34 ? `${label.slice(0, 31)}...` : label);

        if (item.subLabel) {
          group.append('text')
            .attr('class', 'dashboard-gantt-meta')
            .attr('x', 12)
            .attr('y', 31)
            .text(item.subLabel);
        }

        const startX = x(item.start);
        const endX = x(item.end);
        const widthValue = Math.max(6, endX - startX);

        group.append('rect')
          .attr('class', 'dashboard-gantt-bg')
          .attr('x', left)
          .attr('y', 9)
          .attr('rx', 999)
          .attr('ry', 999)
          .attr('width', Math.max(10, width - left - right))
          .attr('height', 16);

        group.append('rect')
          .attr('class', 'dashboard-gantt-plan')
          .attr('x', startX)
          .attr('y', 9)
          .attr('rx', 999)
          .attr('ry', 999)
          .attr('width', widthValue)
          .attr('height', 16)
          .attr('fill', item.color);

        group.append('rect')
          .attr('class', 'dashboard-gantt-real')
          .attr('x', startX)
          .attr('y', 9)
          .attr('rx', 999)
          .attr('ry', 999)
          .attr('width', Math.max(4, widthValue * (item.progress / 100)))
          .attr('height', 16)
          .attr('fill', item.color);

        group.append('text')
          .attr('class', 'dashboard-gantt-right')
          .attr('x', width - right + 8)
          .attr('y', 21)
          .text(item.rightLabel);
      });
  }

  function renderGantts() {
    const actionById = {};

    actionRows.forEach((row) => {
      actionById[row.id] = row;
    });

    renderGanttChart('dashboard-gantt-chart', ganttRows, {
      startAccèssor: (row) => row.date_debut,
      endAccèssor: (row) => row.date_fin,
      labelAccèssor: (row) => row.libelle,
      subLabelAccèssor: (row) => `${row.responsable || ''} | ${row.date_debut_label || ''} - ${row.date_fin_label || ''}`,
      progressAccèssor: (row) => row.progression,
      colorAccèssor: (row) => row.color || '#3996D3',
      rightLabelAccèssor: (row) => {
        const action = actionById[row.id] || {};
        return String(Math.round(Number(action.kpi_global || 0)));
      },
      urlAccèssor: (row) => row.url,
      axisTicks: window.d3 ? window.d3.timeMonth.every(1) : null,
      tickFormat: window.d3 ? window.d3.timeFormat('%b') : null,
    });

    const critical = reportingCharts.critical_gantt || { items: [] };

    renderGanttChart('dashboard-critical-gantt-chart', critical.items || [], {
      startAccèssor: (row) => row.start,
      endAccèssor: (row) => row.end,
      labelAccèssor: (row) => row.label,
      subLabelAccèssor: (row) => `${row.start} - ${row.end} | ${row.status}`,
      progressAccèssor: (row) => row.progress,
      colorAccèssor: (row) => gaugeColor(Number(row.progress || 0)),
      rightLabelAccèssor: (row) => `S ${Number(row.score || 0).toFixed(1)}`,
      urlAccèssor: (row) => row.url,
      axisTicks: window.d3 ? window.d3.timeWeek.every(1) : null,
      tickFormat: window.d3 ? window.d3.timeFormat('%d %b') : null,
    });
  }

  function mountStatusDonut(hostId = 'dashboard-status-mix-chart', cards = statusCards, total = (payload.totals && payload.totals.actions_total) || 0) {
    const theme = dashboardTheme();

    mountChart(hostId, baseConfig('doughnut', {
      data: {
        labels: cards.map((item) => item.label),
        datasets: [{
          data: cards.map((item) => Number(item.count || 0)),
          backgroundColor: cards.map((item, index) => alphaColor(item.color || colorForStatus(item.label, index), 0.9)),
          borderColor: theme.isDark ? '#0f172a' : '#ffffff',
          borderWidth: 4,
          hoverOffset: 10,
          hoverBorderColor: theme.isDark ? '#0f172a' : '#ffffff',
        }],
      },
      options: {
        cutout: '74%',
        plugins: {
          anbgCenterText: {
            lines: [
              {
                text: String(total || 0),
                color: theme.emphasis,
                font: '800 28px Manrope, Public Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: -8,
              },
              {
                text: 'Actions',
                color: theme.muted,
                font: '700 11px Manrope, Public Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: 16,
              },
            ],
          },
        },
      },
    }), ({ element }) => cards[element?.index]?.href || '');
  }

  function mountMonthlyKpiLine(hostId = 'dashboard-kpi-line-chart', rows = monthly, period = 0) {
    const filtered = filterByPeriod(rows, period);
    const kpiDatasets = [
      { label: 'Délai',       key: 'delai',       color: '#3996D3' },
      { label: 'Performance', key: 'performance', color: '#16A34A' },
      { label: 'Conformité',  key: 'conformite',  color: '#D97706' },
      { label: 'Global',      key: 'global',      color: '#7C3AED' },
    ];

    mountChart(hostId, baseConfig('line', {
      data: {
        labels: filtered.map((item) => item.label),
        datasets: kpiDatasets.map(({ label, key, color }) => ({
          label,
          data: filtered.map((item) => Number(item[key] || 0)),
          borderColor: color,
          backgroundColor: (context) => chartGradient(context.chart, color),
          fill: true,
          tension: 0.42,
          pointRadius: 4,
          pointHoverRadius: 8,
          pointBackgroundColor: color,
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2.5,
          borderWidth: 3,
        })),
      },
      options: {
        scales: cartesianScales({ y: { max: 100 } }),
        plugins: {
          ...kpiAnnotations(),
          tooltip: {
            callbacks: {
              title(items) {
                return items[0]?.label ? `Période : ${items[0].label}` : '';
              },
              label(context) {
                const value = Number(context.parsed?.y ?? 0);
                const bar = value >= 80 ? '▓▓▓' : value >= 60 ? '▓▓░' : '▓░░';
                return ` ${context.dataset.label} : ${formatNumber(value)} %  ${bar}`;
              },
              afterBody(items) {
                const vals = items.map((i) => Number(i.parsed?.y ?? 0)).filter(Number.isFinite);
                if (vals.length < 2) return [];
                const avg = vals.reduce((a, b) => a + b, 0) / vals.length;
                return [``, ` Moyenne globale : ${formatNumber(avg)} %`];
              },
            },
          },
        },
      },
    }), ({ element, chart }) => {
      const row = filtered[element?.index];
      const dataset = chart?.data?.datasets?.[element?.datasetIndex];

      if (!row?.url) {
        return '';
      }

      return withQuery(row.url, { sort: metricSortKey(dataset?.label) });
    });
  }

  function mountKpiGaugeSet(prefix, scores, official = true) {
    const actionsIndexUrl = payload.actions_index_url || '/workspace/actions';
    const officialFilters = isObject(payload.official_action_filters) ? payload.official_action_filters : {};
    const definitions = [
      ['delai', 'Délai'],
      ['performance', 'Performance'],
      ['conformite', 'Conformité'],
    ];

    definitions.forEach(([key, label]) => {
      const query = official
        ? { ...officialFilters, sort: metricSortKey(label) }
        : { sort: metricSortKey(label) };

      mountGauge(
        `${prefix}${key}`,
        label,
        scores && scores[key],
        withQuery(actionsIndexUrl, query)
      );
    });
  }

  function mountUnitSummary() {
    const percentLabel = (v) => (v > 0 ? `${Math.round(v)}%` : '');

    mountChart('dashboard-unit-summary-chart', baseConfig('bar', {
      data: {
        labels: unitRows.map((item) => item.label),
        datasets: [
          {
            label: 'Indicateur KPI moyen',
            data: unitRows.map((item) => Number(item.kpi_global || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#3996D3'),
            borderRadius: 10,
            maxBarThickness: 34,
          },
          {
            label: 'Progression moyenne',
            data: unitRows.map((item) => Number(item.progression_moyenne || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#8FC043'),
            borderRadius: 10,
            maxBarThickness: 34,
          },
        ],
      },
      options: {
        scales: cartesianScales({ y: { max: 100 } }),
        plugins: {
          datalabels: barDataLabels(percentLabel),
          ...kpiAnnotations(),
          tooltip: {
            callbacks: {
              title(items) {
                return items[0]?.label || '';
              },
              label(context) {
                const value = Number(context.parsed?.y ?? 0);
                return ` ${context.dataset.label} : ${Math.round(value)} %`;
              },
              afterLabel(context) {
                const row = unitRows[context.dataIndex];
                if (!row) return '';
                return ` Actions : ${row.actions_total ?? 0}  |  Alertes : ${row.alertes ?? 0}`;
              },
            },
          },
        },
      },
    }), ({ element }) => unitRows[element?.index]?.url || '');
  }

  function mountReportingCharts() {
    const statusByUnit = reportingCharts.status_by_unit || { labels: [], datasets: [] };

    mountChart('dashboard-report-status-unit-chart', baseConfig('bar', {
      data: {
        labels: statusByUnit.labels,
        datasets: (statusByUnit.datasets || []).map((dataset, index) => ({
          label: dataset.label,
          data: dataset.data,
          backgroundColor: alphaColor(colorForStatus(dataset.label, index), 0.86),
          borderColor: colorForStatus(dataset.label, index),
          borderWidth: 1,
          borderRadius: 6,
          maxBarThickness: 28,
          stack: 'statuses',
        })),
      },
      options: {
        scales: cartesianScales({
          x: { stacked: true },
          y: { stacked: true },
        }),
      },
    }), ({ element }) => (((statusByUnit.urls || [])[element?.datasetIndex] || [])[element?.index]) || '');

    const progressWeekly = reportingCharts.progress_weekly || { labels: [], reel: [], theorique: [] };

    function buildProgressChartConfig(data) {
      return baseConfig('line', {
        data: {
          labels: data.labels,
          datasets: [
            {
              label: 'Avancement réel',
              data: data.reel,
              borderColor: ANBG.secondary,
              backgroundColor: (context) => chartGradient(context.chart, ANBG.secondary),
              fill: true,
              tension: 0.38,
              pointRadius: 4,
              pointHoverRadius: 7,
              pointBackgroundColor: ANBG.secondary,
              pointBorderColor: '#ffffff',
              pointBorderWidth: 2,
              borderWidth: 2.5,
            },
            {
              label: 'Progression théorique',
              data: data.theorique,
              borderColor: ANBG.primary,
              backgroundColor: (context) => chartGradient(context.chart, ANBG.primary),
              fill: true,
              tension: 0.38,
              borderDash: [8, 5],
              pointRadius: 4,
              pointHoverRadius: 7,
              pointBackgroundColor: ANBG.primary,
              pointBorderColor: '#ffffff',
              pointBorderWidth: 2,
              borderWidth: 2,
            },
          ],
        },
        options: {
          scales: cartesianScales({ y: { max: 100 } }),
          plugins: {
            ...kpiAnnotations(),
            tooltip: {
              callbacks: {
                title: (items) => items[0]?.label ? `Semaine : ${items[0].label}` : '',
                label: (ctx) => ` ${ctx.dataset.label} : ${formatNumber(ctx.parsed?.y ?? 0)} %`,
                afterBody(items) {
                  const reel = Number(items.find((i) => i.datasetIndex === 0)?.parsed?.y ?? 0);
                  const theo = Number(items.find((i) => i.datasetIndex === 1)?.parsed?.y ?? 0);
                  if (!reel && !theo) return [];
                  const ecart = reel - theo;
                  const sign = ecart >= 0 ? '+' : '';
                  return [``, ` Écart réel/théorique : ${sign}${formatNumber(ecart)} pts`];
                },
              },
            },
          },
        },
      });
    }

    mountChart('dashboard-report-progress-chart', buildProgressChartConfig(progressWeekly),
      ({ element }) => (progressWeekly.urls || [])[element?.index] || '');

    bindPeriodButtons('[data-period-chart="report-progress"]', (n) => {
      const d = {
        labels: filterByPeriod(progressWeekly.labels || [], n),
        reel: filterByPeriod(progressWeekly.reel || [], n),
        theorique: filterByPeriod(progressWeekly.theorique || [], n),
        urls: filterByPeriod(progressWeekly.urls || [], n),
      };
      mountChart('dashboard-report-progress-chart', buildProgressChartConfig(d),
        ({ element }) => (d.urls || [])[element?.index] || '');
    });

    const kpiTrend = reportingCharts.kpi_trend || { labels: [], valeurs: [], cibles: [], seuils: [] };

    function buildKpiTrendChartConfig(data) {
      return baseConfig('bar', {
        data: {
          labels: data.labels,
          datasets: [
            {
              type: 'bar',
              label: 'Valeur',
              data: data.valeurs,
              backgroundColor: (context) => barGradient(context.chart, ANBG.secondary),
              borderRadius: 9,
              maxBarThickness: 32,
            },
            {
              type: 'line',
              label: 'Cible',
              data: data.cibles,
              borderColor: ANBG.success,
              backgroundColor: (context) => chartGradient(context.chart, ANBG.success),
              borderWidth: 2.5,
              tension: 0.38,
              pointRadius: 4,
              pointHoverRadius: 7,
              pointBackgroundColor: ANBG.success,
              pointBorderColor: '#ffffff',
              pointBorderWidth: 2,
              fill: false,
            },
            {
              type: 'line',
              label: 'Seuil',
              data: data.seuils,
              borderColor: ANBG.warning,
              backgroundColor: (context) => chartGradient(context.chart, ANBG.warning),
              borderWidth: 2,
              borderDash: [7, 5],
              tension: 0.38,
              pointRadius: 3,
              pointHoverRadius: 6,
              fill: false,
            },
          ],
        },
        options: {
          scales: cartesianScales({ y: { max: 100 } }),
          plugins: { ...kpiAnnotations() },
        },
      });
    }

    mountChart('dashboard-report-kpi-trend-chart', buildKpiTrendChartConfig(kpiTrend),
      ({ element }) => (kpiTrend.urls || [])[element?.index] || '');

    bindPeriodButtons('[data-period-chart="report-kpi-trend"]', (n) => {
      const d = {
        labels: filterByPeriod(kpiTrend.labels || [], n),
        valeurs: filterByPeriod(kpiTrend.valeurs || [], n),
        cibles: filterByPeriod(kpiTrend.cibles || [], n),
        seuils: filterByPeriod(kpiTrend.seuils || [], n),
        urls: filterByPeriod(kpiTrend.urls || [], n),
      };
      mountChart('dashboard-report-kpi-trend-chart', buildKpiTrendChartConfig(d),
        ({ element }) => (d.urls || [])[element?.index] || '');
    });
  }

  function resizeCharts() {
    Object.values(chartInstances).forEach((chart) => {
      if (chart && typeof chart.resize === 'function') {
        chart.resize();
      }
    });
  }

  async function render() {
    if (renderInFlight) {
      renderQueued = true;
      return;
    }

    renderInFlight = true;

    try {
      const needsD3 = Boolean(
        document.getElementById('dashboard-gantt-chart') ||
        document.getElementById('dashboard-critical-gantt-chart')
      );

      await ensureDashboardAssets({ needD3: needsD3 });

      const hasChart = typeof window.Chart !== 'undefined';
      const hasD3 = typeof window.d3 !== 'undefined';

      if (!hasChart && !hasD3) {
        document.addEventListener('anbg:dashboard-assets-ready', () => {
          void render();
        }, { once: true });
        return;
      }

      if (hasChart) {
        safeRenderStep('plugins', ensurePlugins);

        if (typeof window.applyAnbgChartDefaults === 'function') {
          window.applyAnbgChartDefaults(window.Chart);
        }

        safeRenderStep('role charts', mountRoleCharts);
        safeRenderStep('kpi gauges', () => mountKpiGaugeSet('dashboard-kpi-gauge-', payload.global_scores || {}, true));
        safeRenderStep('monthly line', () => {
          mountMonthlyKpiLine();
          bindPeriodButtons('[data-period-chart="kpi-line"]', (n) => mountMonthlyKpiLine('dashboard-kpi-line-chart', monthly, n));
        });
        safeRenderStep('unit summary', mountUnitSummary);
        safeRenderStep('reporting charts', mountReportingCharts);
      }

      if (hasD3) {
        safeRenderStep('gantt', renderGantts);
      }

      safeRenderStep('row links', bindRowLinks);
      safeRenderStep('chart disclosure panels', bindChartDisclosurePanels);

      window.requestAnimationFrame(() => {
        resizeCharts();
        window.setTimeout(resizeCharts, 180);
      });
    } finally {
      renderInFlight = false;

      if (renderQueued) {
        renderQueued = false;
        window.setTimeout(() => {
          void render();
        }, 0);
      }
    }
  }

  function activateTab(key, syncUrl) {
    let nextKey = panelAliases[key] || key;

    if (!panelKeys.includes(nextKey)) {
      nextKey = 'overview';
    }

    if (tabsRoot) {
      tabsRoot.querySelectorAll('[data-dashboard-tab]').forEach((tabButton) => {
        const active = tabButton.getAttribute('data-dashboard-tab') === nextKey;
        tabButton.classList.toggle('dashboard-tab-active', active);
        tabButton.classList.toggle('dashboard-tab-inactive', !active);
        tabButton.setAttribute('aria-current', active ? 'page' : 'false');
      });
    }

    document.querySelectorAll('[data-dashboard-panel]').forEach((panel) => {
      panel.classList.toggle('active', panel.getAttribute('data-dashboard-panel') === nextKey);
    });

    if (syncUrl) {
      const currentUrl = new URL(window.location.href);
      currentUrl.searchParams.set('dashboardTab', nextKey);
      window.history.replaceState({}, '', currentUrl.toString());
    }

    window.setTimeout(() => {
      Promise.resolve(render()).finally(() => {
        resizeCharts();
        window.setTimeout(() => {
          resizeCharts();
        }, 180);
      });
    }, 90);
  }

  function bindSynthesisSelectors() {
    const selectors = Array.from(document.querySelectorAll('[data-dashboard-synthesis-selector]'));

    if (selectors.length === 0) {
      return;
    }

    const closeAll = (except = null) => {
      selectors.forEach((details) => {
        if (!(details instanceof HTMLDetailsElement) || details === except) {
          return;
        }

        details.open = false;
        const summary = details.querySelector('summary');
        if (summary instanceof HTMLElement) {
          summary.setAttribute('aria-expanded', 'false');
        }
      });
    };

    selectors.forEach((details) => {
      if (!(details instanceof HTMLDetailsElement) || details.dataset.synthesisBound === '1') {
        return;
      }

      details.dataset.synthesisBound = '1';
      const summary = details.querySelector('summary');

      if (summary instanceof HTMLElement) {
        summary.setAttribute('aria-haspopup', 'menu');
        summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');

        summary.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();

          const shouldOpen = !details.open;
          closeAll(details);
          details.open = shouldOpen;
          summary.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });
      }

      details.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
          details.open = false;
          if (summary instanceof HTMLElement) {
            summary.setAttribute('aria-expanded', 'false');
          }
        });
      });
    });

    if (document.body.dataset.dashboardSynthesisOutsideBound !== '1') {
      document.body.dataset.dashboardSynthesisOutsideBound = '1';

      document.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-dashboard-synthesis-selector]')) {
          return;
        }

        closeAll();
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeAll();
        }
      });
    }
  }

  const tabsRoot = document.querySelector('[data-dashboard-tabs]');

  if (tabsRoot) {
    bindSynthesisSelectors();

    tabsRoot.querySelectorAll('[data-dashboard-tab]').forEach((button) => {
      button.addEventListener('click', (event) => {
        const targetKey = panelAliases[button.getAttribute('data-dashboard-tab')] || button.getAttribute('data-dashboard-tab');
        if (!document.querySelector(`[data-dashboard-panel="${targetKey}"]`)) {
          return;
        }

        event.preventDefault();
        activateTab(targetKey, true);
      });
    });

    let initialKey = new URLSearchParams(window.location.search).get('dashboardTab');

    if (!initialKey && window.location.hash && window.location.hash.indexOf('#dashboard-') === 0) {
      initialKey = window.location.hash.replace('#dashboard-', '');
    }

    activateTab(panelAliases[initialKey] || initialKey || 'overview', false);
  }

  window.__anbgDashboardRenderCurrent = () => render();
  window.__anbgDashboardResizeCurrent = () => resizeCharts();

  if (!tabsRoot) {
    document.addEventListener('anbg:dashboard-assets-ready', () => {
      void render();
    }, { once: true });

    if (typeof window.Chart !== 'undefined' || typeof window.d3 !== 'undefined') {
      void render();
    } else {
      void ensureDashboardAssets({
        needD3: Boolean(document.getElementById('dashboard-gantt-chart')),
      }).then(() => render());
    }
  }
}

if (!window.__anbgDashboardGlobalBindings) {
  window.__anbgDashboardGlobalBindings = true;

  window.__anbgDashboardBoot = bootDashboardRender;

  window.addEventListener('anbg:theme-changed', () => {
    if (typeof window.__anbgDashboardRenderCurrent === 'function') {
      void window.__anbgDashboardRenderCurrent();
    }
  });

  document.addEventListener('anbg:dashboard-assets-ready', () => {
    if (typeof window.__anbgDashboardRenderCurrent === 'function') {
      void window.__anbgDashboardRenderCurrent();
    }
  });

  document.addEventListener('anbg:dashboard-payload-ready', () => {
    dashboardBooted = false;
    bootDashboardRender(true);
  });

  window.addEventListener('resize', () => {
    window.clearTimeout(window.__anbgDashboardResizeTimer);
    window.__anbgDashboardResizeTimer = window.setTimeout(() => {
      if (typeof window.__anbgDashboardResizeCurrent === 'function') {
        window.__anbgDashboardResizeCurrent();
      }
    }, 90);
  });

  document.addEventListener('anbg:page-soft-refreshed', () => {
    // Reboot uniquement si le payload JSON a réellement changé. Sinon les
    // charts D3/Chart.js ne sont pas retracés inutilement (élimine le flash).
    const payloadNode = document.getElementById('anbg-dashboard-payload');
    const nextPayloadText = payloadNode?.textContent || '';

    if (nextPayloadText === window.__anbgDashboardLastPayloadText) {
      return;
    }

    window.__anbgDashboardLastPayloadText = nextPayloadText;
    dashboardBooted = false;
    bootDashboardRender(true);
  });
}

window.__anbgDashboardBoot = bootDashboardRender;

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootDashboardRender, { once: true });
} else {
  bootDashboardRender();
}
