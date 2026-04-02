let dashboardBooted = false;

function bootDashboardRender() {
  if (dashboardBooted) {
    return;
  }

  const payloadNode = document.getElementById('anbg-dashboard-payload');

  if (!payloadNode) {
    return;
  }

  dashboardBooted = true;

  const parsed = JSON.parse(payloadNode.textContent || '{}');
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
  const panelKeys = ['overview', 'kpi', 'actions', 'gantt', 'tables', 'analytics'];
  const chartInstances = {};
  const compactFormatter = new Intl.NumberFormat('fr-FR', {
    notation: 'compact',
    maximumFractionDigits: 1,
  });
  const decimalFormatter = new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: 1,
  });
  const ANBG = {
    primary: '#1E3A8A',
    secondary: '#3B82F6',
    light: '#EFF6FF',
    white: '#FFFFFF',
    dark: '#1F2937',
    muted: '#6B7280',
    success: '#10B981',
    warning: '#F59E0B',
    danger: '#EF4444',
  };

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
        ctx.font = line.font || '700 14px Inter, ui-sans-serif, system-ui, sans-serif';
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
      text: isDark ? '#e2e8f0' : '#334155',
      muted: isDark ? '#94a3b8' : '#64748b',
      grid: isDark ? 'rgba(100,116,139,0.28)' : 'rgba(148,163,184,0.22)',
      tooltipBackground: isDark ? 'rgba(6,12,28,0.96)' : 'rgba(255,255,255,0.98)',
      tooltipTitle: isDark ? '#f8fafc' : '#0f172a',
      tooltipBody: isDark ? '#e2e8f0' : '#334155',
      tooltipBorder: isDark ? 'rgba(57,150,211,0.26)' : 'rgba(148,163,184,0.28)',
      emphasis: isDark ? '#f8e932' : '#1c203d',
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
    return label.length > limit ? `${label.slice(0, limit - 1)}…` : label;
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

    if (status.includes('risque')) {
      return ANBG.warning;
    }

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
      return alphaColor(color, 0.18);
    }

    const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    gradient.addColorStop(0, alphaColor(color, 0.34));
    gradient.addColorStop(1, alphaColor(color, 0.04));

    return gradient;
  }

  function barGradient(chart, color) {
    const { ctx, chartArea } = chart;

    if (!chartArea) {
      return alphaColor(color, 0.88);
    }

    const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
    gradient.addColorStop(0, alphaColor(color, 0.82));
    gradient.addColorStop(1, alphaColor(color, 1));

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

  function destroyChart(id) {
    if (chartInstances[id] && typeof chartInstances[id].destroy === 'function') {
      chartInstances[id].destroy();
    }

    delete chartInstances[id];
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

    if (normalized.includes('qualite') || normalized.includes('qual')) {
      return 'kpi_qualite_desc';
    }

    if (normalized.includes('risque')) {
      return 'kpi_risque_desc';
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

    if (!host || typeof window.Chart === 'undefined') {
      return;
    }

    destroyChart(id);
    host.innerHTML = '';

    const canvas = document.createElement('canvas');
    host.appendChild(canvas);

    chartInstances[id] = new window.Chart(canvas.getContext('2d'), config);
    bindChartDrilldown(chartInstances[id], drilldownResolver);
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
          duration: 360,
          easing: 'easeOutQuart',
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
                const raw = context.parsed?.y ?? context.parsed?.x ?? context.raw ?? 0;
                return `${context.dataset.label || 'Valeur'}: ${formatNumber(raw)}`;
              },
            },
          },
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
          drawBorder: false,
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

    mountChart(id, baseConfig('doughnut', {
      data: {
        labels: [label, 'Reste'],
        datasets: [{
          data: [numeric, Math.max(0, 100 - numeric)],
          backgroundColor: [
            gaugeColor(numeric),
            theme.isDark ? 'rgba(51,65,85,0.86)' : 'rgba(226,232,240,0.96)',
          ],
          borderWidth: 0,
          hoverOffset: 0,
        }],
      },
      options: {
        rotation: 270,
        circumference: 180,
        cutout: '76%',
        plugins: {
          legend: { display: false },
          tooltip: { enabled: false },
          anbgCenterText: {
            half: true,
            lines: [
              {
                text: `${Math.round(numeric)}%`,
                color: gaugeColor(numeric),
                font: '800 25px Inter, ui-sans-serif, system-ui, sans-serif',
                offsetY: -6,
              },
              {
                text: label,
                color: theme.muted,
                font: '700 11px Inter, ui-sans-serif, system-ui, sans-serif',
                offsetY: 20,
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
          datasets: (comparison.datasets || []).map((dataset, index) => ({
            label: dataset.label || `Serie ${index + 1}`,
            data: (dataset.data || []).map((value) => finiteNumber(value, 0)),
            borderColor: dataset.color || toneForIndex(index),
            backgroundColor: chartType === 'line'
              ? alphaColor(dataset.color || toneForIndex(index), 0.16)
              : alphaColor(dataset.color || toneForIndex(index), 0.84),
            hoverBackgroundColor: dataset.color || toneForIndex(index),
            hoverBorderColor: dataset.color || toneForIndex(index),
            borderWidth: chartType === 'line' ? 3 : 1,
            borderRadius: chartType === 'bar' ? 14 : 0,
            maxBarThickness: 34,
            tension: 0.3,
            fill: chartType === 'line',
          })),
        },
        options: {
          indexAxis,
          scales: cartesianScales({
            x: {
              stacked,
            },
            y: {
              stacked,
            },
          }),
        },
      }), ({ element }) => (comparison.urls || [])[element?.index] || '');
    }

    if (Array.isArray(status.labels) && status.labels.length > 0) {
      mountChart('dashboard-role-status-chart', baseConfig('doughnut', {
        data: {
          labels: status.labels,
          datasets: [{
            data: (status.values || []).map((value) => finiteNumber(value, 0)),
            backgroundColor: (status.labels || []).map((label, index) => alphaColor(colorForStatus(label, index), 0.84)),
            borderColor: (status.labels || []).map((label, index) => colorForStatus(label, index)),
            borderWidth: 1.5,
            hoverOffset: 8,
          }],
        },
        options: {
          cutout: '68%',
        },
      }), ({ element }) => (status.urls || [])[element?.index] || '');
    }

    if (Array.isArray(trend.labels) && trend.labels.length > 0) {
      mountChart('dashboard-role-trend-chart', baseConfig('line', {
        data: {
          labels: trend.labels,
          datasets: (trend.datasets || []).map((dataset, index) => ({
            label: dataset.label || `Serie ${index + 1}`,
            data: (dataset.data || []).map((value) => finiteNumber(value, 0)),
            borderColor: dataset.color || toneForIndex(index),
            backgroundColor: alphaColor(dataset.color || toneForIndex(index), 0.12),
            pointBackgroundColor: dataset.color || toneForIndex(index),
            pointBorderColor: '#FFFFFF',
            pointBorderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5,
            tension: 0.34,
            fill: false,
            borderWidth: 3,
          })),
        },
        options: {
          scales: cartesianScales(),
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
          datasets: (support.datasets || []).map((dataset, index) => ({
            label: dataset.label || `Serie ${index + 1}`,
            data: (dataset.data || []).map((value) => finiteNumber(value, 0)),
            borderColor: dataset.color || toneForIndex(index),
            backgroundColor: chartType === 'line'
              ? alphaColor(dataset.color || toneForIndex(index), 0.16)
              : alphaColor(dataset.color || toneForIndex(index), 0.84),
            hoverBackgroundColor: dataset.color || toneForIndex(index),
            hoverBorderColor: dataset.color || toneForIndex(index),
            borderWidth: chartType === 'line' ? 3 : 1,
            borderRadius: chartType === 'bar' ? 14 : 0,
            maxBarThickness: 32,
            tension: 0.3,
            fill: chartType === 'line',
          })),
        },
        options: {
          indexAxis,
          scales: cartesianScales({
            x: {
              stacked,
              max: indexAxis === 'y' ? undefined : undefined,
            },
            y: {
              stacked,
            },
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
        const start = new Date(meta.startAccessor(row));
        let end = new Date(meta.endAccessor(row));

        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
          return null;
        }

        if (end <= start) {
          end = new Date(start.getTime() + 86400000);
        }

        return {
          label: meta.labelAccessor(row),
          subLabel: meta.subLabelAccessor ? meta.subLabelAccessor(row) : '',
          start,
          end,
          progress: Math.max(0, Math.min(100, Number(meta.progressAccessor(row) || 0))),
          color: meta.colorAccessor ? meta.colorAccessor(row) : '#3996D3',
          rightLabel: meta.rightLabelAccessor ? meta.rightLabelAccessor(row) : '',
          url: meta.urlAccessor ? meta.urlAccessor(row) : null,
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
      startAccessor: (row) => row.date_debut,
      endAccessor: (row) => row.date_fin,
      labelAccessor: (row) => row.libelle,
      subLabelAccessor: (row) => `${row.responsable || ''} | ${row.date_debut_label || ''} - ${row.date_fin_label || ''}`,
      progressAccessor: (row) => row.progression,
      colorAccessor: (row) => row.color || '#3996D3',
      rightLabelAccessor: (row) => {
        const action = actionById[row.id] || {};
        return String(Math.round(Number(action.kpi_global || 0)));
      },
      urlAccessor: (row) => row.url,
      axisTicks: window.d3 ? window.d3.timeMonth.every(1) : null,
      tickFormat: window.d3 ? window.d3.timeFormat('%b') : null,
    });

    const critical = reportingCharts.critical_gantt || { items: [] };

    renderGanttChart('dashboard-critical-gantt-chart', critical.items || [], {
      startAccessor: (row) => row.start,
      endAccessor: (row) => row.end,
      labelAccessor: (row) => row.label,
      subLabelAccessor: (row) => `${row.start} - ${row.end} | ${row.status}`,
      progressAccessor: (row) => row.progress,
      colorAccessor: (row) => gaugeColor(Number(row.progress || 0)),
      rightLabelAccessor: (row) => `S ${Number(row.score || 0).toFixed(1)}`,
      urlAccessor: (row) => row.url,
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
          backgroundColor: cards.map((item, index) => item.color || colorForStatus(item.label, index)),
          borderColor: theme.isDark ? '#0f172a' : '#ffffff',
          borderWidth: 3,
          hoverOffset: 6,
        }],
      },
      options: {
        cutout: '72%',
        plugins: {
          anbgCenterText: {
            lines: [
              {
                text: String(total || 0),
                color: theme.emphasis,
                font: '800 28px Inter, ui-sans-serif, system-ui, sans-serif',
                offsetY: -8,
              },
              {
                text: 'Actions',
                color: theme.muted,
                font: '700 11px Inter, ui-sans-serif, system-ui, sans-serif',
                offsetY: 16,
              },
            ],
          },
        },
      },
    }), ({ element }) => cards[element?.index]?.href || '');
  }

  function mountMonthlyKpiLine(hostId = 'dashboard-kpi-line-chart', rows = monthly) {
    mountChart(hostId, baseConfig('line', {
      data: {
        labels: rows.map((item) => item.label),
        datasets: [
          {
            label: 'Delai',
            data: rows.map((item) => Number(item.delai || 0)),
            borderColor: '#3996D3',
            backgroundColor: (context) => chartGradient(context.chart, '#3996D3'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Performance',
            data: rows.map((item) => Number(item.performance || 0)),
            borderColor: '#8FC043',
            backgroundColor: (context) => chartGradient(context.chart, '#8FC043'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Conformite',
            data: rows.map((item) => Number(item.conformite || 0)),
            borderColor: '#F0E509',
            backgroundColor: (context) => chartGradient(context.chart, '#F0E509'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Qualite',
            data: rows.map((item) => Number(item.qualite || 0)),
            borderColor: '#F9B13C',
            backgroundColor: (context) => chartGradient(context.chart, '#F9B13C'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Risque',
            data: rows.map((item) => Number(item.risque || 0)),
            borderColor: '#64748B',
            backgroundColor: (context) => chartGradient(context.chart, '#64748B'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Global',
            data: rows.map((item) => Number(item.global || 0)),
            borderColor: '#1C203D',
            backgroundColor: (context) => chartGradient(context.chart, '#1C203D'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: { max: 100 },
        }),
      },
    }), ({ element, chart }) => {
      const row = rows[element?.index];
      const dataset = chart?.data?.datasets?.[element?.datasetIndex];

      if (!row?.url) {
        return '';
      }

      return withQuery(row.url, { sort: metricSortKey(dataset?.label) });
    });
  }

  function mountKpiGaugeSet(prefix, scores, official = true) {
    const actionsIndexUrl = payload.actions_index_url || '/workspace/actions';
    const definitions = [
      ['delai', 'Delai'],
      ['performance', 'Performance'],
      ['conformite', 'Conformite'],
      ['qualite', 'Qualite'],
      ['risque', 'Risque'],
    ];

    definitions.forEach(([key, label]) => {
      const query = official
        ? { statut_validation: 'validee_direction', sort: metricSortKey(label) }
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
    mountChart('dashboard-unit-summary-chart', baseConfig('bar', {
      data: {
        labels: unitRows.map((item) => item.label),
        datasets: [
          {
            label: 'Indicateur moyen',
            data: unitRows.map((item) => Number(item.kpi_global || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#3996D3'),
            borderRadius: 10,
            maxBarThickness: 34,
          },
          {
            label: 'Progression',
            data: unitRows.map((item) => Number(item.progression_moyenne || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#8FC043'),
            borderRadius: 10,
            maxBarThickness: 34,
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: { max: 100 },
        }),
      },
    }), ({ element }) => unitRows[element?.index]?.url || '');
  }

  function mountMonthlyKpiGrouped() {
    mountChart('dashboard-kpi-grouped-chart', baseConfig('bar', {
      data: {
        labels: monthly.map((item) => item.label),
        datasets: [
          {
            label: 'Delai',
            data: monthly.map((item) => Number(item.delai || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#3996D3'),
            borderRadius: 8,
            maxBarThickness: 24,
          },
          {
            label: 'Perf.',
            data: monthly.map((item) => Number(item.performance || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#8FC043'),
            borderRadius: 8,
            maxBarThickness: 24,
          },
          {
            label: 'Conf.',
            data: monthly.map((item) => Number(item.conformite || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#F0E509'),
            borderRadius: 8,
            maxBarThickness: 18,
          },
          {
            label: 'Qual.',
            data: monthly.map((item) => Number(item.qualite || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#F9B13C'),
            borderRadius: 8,
            maxBarThickness: 18,
          },
          {
            label: 'Risque',
            data: monthly.map((item) => Number(item.risque || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#64748B'),
            borderRadius: 8,
            maxBarThickness: 18,
          },
          {
            label: 'Global',
            data: monthly.map((item) => Number(item.global || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#1C203D'),
            borderRadius: 8,
            maxBarThickness: 18,
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: { max: 100 },
        }),
      },
    }), ({ element, chart }) => {
      const row = monthly[element?.index];
      const dataset = chart?.data?.datasets?.[element?.datasetIndex];

      if (!row?.url) {
        return '';
      }

      return withQuery(row.url, { sort: metricSortKey(dataset?.label) });
    });
  }

  function mountInterannual() {
    mountChart('dashboard-interannual-chart', baseConfig('bar', {
      data: {
        labels: interannual.map((item) => item.annee),
        datasets: [
          {
            type: 'bar',
            label: 'Actions',
            data: interannual.map((item) => Number(item.actions_total || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#3996D3'),
            borderRadius: 10,
            maxBarThickness: 34,
            yAxisID: 'y',
          },
          {
            type: 'bar',
            label: 'Validees',
            data: interannual.map((item) => Number(item.actions_validees || 0)),
            backgroundColor: (context) => barGradient(context.chart, '#8FC043'),
            borderRadius: 10,
            maxBarThickness: 34,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Progression moyenne',
            data: interannual.map((item) => Number(item.progression_moyenne || 0)),
            borderColor: '#1C203D',
            backgroundColor: alphaColor('#1C203D', 0.18),
            borderWidth: 3,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: {
            title: { display: true, text: 'Volumes', color: dashboardTheme().muted },
          },
          y1: {
            position: 'right',
            beginAtZero: true,
            max: 100,
            grid: { drawOnChartArea: false },
            ticks: { color: dashboardTheme().muted, font: { size: 11, weight: '600' } },
            border: { display: false },
            title: { display: true, text: 'Progression %', color: dashboardTheme().muted },
          },
        }),
      },
    }), ({ element }) => interannual[element?.index]?.url || '');
  }

  function mountRadar() {
    const theme = dashboardTheme();

    mountChart('dashboard-radar-chart', baseConfig('radar', {
      data: {
        labels: ['Delai', 'Performance', 'Conformite', 'Progression'],
        datasets: radarDatasets.map((dataset, index) => {
          const color = dataset.borderColor || colorForStatus(dataset.label, index);

          return {
            label: dataset.label,
            data: dataset.data,
            borderColor: color,
            backgroundColor: alphaColor(color, 0.18),
            pointBackgroundColor: color,
            pointRadius: 3,
            pointHoverRadius: 5,
            borderWidth: 2,
          };
        }),
      },
      options: {
        scales: {
          r: {
            min: 0,
            max: 100,
            angleLines: { color: theme.grid },
            grid: { color: theme.grid },
            pointLabels: { color: theme.text, font: { size: 11, weight: '700' } },
            ticks: { display: false },
          },
        },
      },
    }), ({ element }) => radarDatasets[element?.datasetIndex]?.url || '');
  }

  function mountBubble() {
    mountChart('dashboard-scatter-chart', baseConfig('bubble', {
      data: {
        datasets: [{
          label: 'Actions',
          data: scatterPoints.map((point) => ({
            x: Number(point.x || 0),
            y: Number(point.y || 0),
            r: Number(point.r || 6),
            title: point.title,
            color: point.color || '#3996D3',
          })),
          backgroundColor: (context) => alphaColor(context.raw?.color || '#3996D3', 0.68),
          borderColor: (context) => context.raw?.color || '#3996D3',
          borderWidth: 1.5,
        }],
      },
      options: {
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title(items) {
                return items[0]?.raw?.title || 'Action';
              },
              label(context) {
                return `Performance ${context.raw.x}% | Conformite ${context.raw.y}%`;
              },
            },
          },
        },
        scales: cartesianScales({
          x: {
            min: 0,
            max: 100,
            title: { display: true, text: 'Performance', color: dashboardTheme().muted },
          },
          y: {
            min: 0,
            max: 100,
            title: { display: true, text: 'Conformite', color: dashboardTheme().muted },
          },
        }),
      },
    }), ({ element }) => scatterPoints[element?.index]?.url || '');
  }

  function mountReportingCharts() {
    const funnel = reportingCharts.funnel || { labels: [], values: [] };

    mountChart('dashboard-report-funnel-chart', baseConfig('bar', {
      data: {
        labels: funnel.labels,
        datasets: [{
          label: 'Volume',
          data: funnel.values,
          backgroundColor: funnel.labels.map((label, index) => reportingFunnelTone(label, index, String(label || '').toLowerCase().includes('pta') ? 0.28 : 0.88)),
          borderColor: funnel.labels.map((label, index) => reportingFunnelTone(label, index)),
          borderWidth: 1,
          borderRadius: 10,
          maxBarThickness: 28,
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: cartesianScales(),
      },
    }), ({ element }) => (funnel.urls || [])[element?.index] || '');

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

    mountChart('dashboard-report-progress-chart', baseConfig('line', {
      data: {
        labels: progressWeekly.labels,
        datasets: [
          {
            label: 'Reel',
            data: progressWeekly.reel,
            borderColor: ANBG.secondary,
            backgroundColor: (context) => chartGradient(context.chart, ANBG.secondary),
            fill: true,
            tension: 0.34,
            pointRadius: 3,
          },
          {
            label: 'Theorique',
            data: progressWeekly.theorique,
            borderColor: ANBG.primary,
            backgroundColor: (context) => chartGradient(context.chart, ANBG.primary),
            fill: true,
            tension: 0.34,
            borderDash: [8, 6],
            pointRadius: 3,
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: { max: 100 },
        }),
      },
    }), ({ element }) => (progressWeekly.urls || [])[element?.index] || '');

    const kpiTrend = reportingCharts.kpi_trend || { labels: [], valeurs: [], cibles: [], seuils: [] };

    mountChart('dashboard-report-kpi-trend-chart', baseConfig('bar', {
      data: {
        labels: kpiTrend.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Valeur',
            data: kpiTrend.valeurs,
            backgroundColor: (context) => barGradient(context.chart, ANBG.secondary),
            borderRadius: 9,
            maxBarThickness: 32,
          },
          {
            type: 'line',
            label: 'Cible',
            data: kpiTrend.cibles,
            borderColor: ANBG.success,
            backgroundColor: alphaColor(ANBG.success, 0.18),
            borderWidth: 3,
            tension: 0.3,
            pointRadius: 3,
          },
          {
            type: 'line',
            label: 'Seuil',
            data: kpiTrend.seuils,
            borderColor: ANBG.warning,
            backgroundColor: alphaColor(ANBG.warning, 0.18),
            borderWidth: 3,
            borderDash: [8, 6],
            tension: 0.3,
            pointRadius: 3,
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: { max: 100 },
        }),
      },
    }), ({ element }) => (kpiTrend.urls || [])[element?.index] || '');

    const interannualOverview = reportingCharts.interannual_overview || {
      labels: [],
      actions_total: [],
      actions_validees: [],
      progression_moyenne: [],
    };

    mountChart('dashboard-report-interannual-chart', baseConfig('bar', {
      data: {
        labels: interannualOverview.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Actions',
            data: interannualOverview.actions_total,
            backgroundColor: (context) => barGradient(context.chart, ANBG.primary),
            borderRadius: 10,
            maxBarThickness: 32,
            yAxisID: 'y',
          },
          {
            type: 'bar',
            label: 'Validees',
            data: interannualOverview.actions_validees,
            backgroundColor: (context) => barGradient(context.chart, ANBG.secondary),
            borderRadius: 10,
            maxBarThickness: 32,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Progression moyenne',
            data: interannualOverview.progression_moyenne,
            borderColor: ANBG.success,
            backgroundColor: alphaColor(ANBG.success, 0.18),
            tension: 0.32,
            borderWidth: 3,
            pointRadius: 3,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: {
            title: { display: true, text: 'Volumes', color: dashboardTheme().muted },
          },
          y1: {
            position: 'right',
            beginAtZero: true,
            max: 100,
            grid: { drawOnChartArea: false },
            ticks: { color: dashboardTheme().muted, font: { size: 11, weight: '600' } },
            border: { display: false },
            title: { display: true, text: 'Progression %', color: dashboardTheme().muted },
          },
        }),
      },
    }), ({ element }) => (interannualOverview.urls || [])[element?.index] || '');

    const pareto = reportingCharts.risk_pareto || { labels: [], counts: [], cumulative_pct: [] };

    mountChart('dashboard-report-risk-pareto-chart', baseConfig('bar', {
      data: {
        labels: pareto.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Occurrences',
            data: pareto.counts,
            backgroundColor: (context) => barGradient(context.chart, ANBG.warning),
            borderRadius: 10,
            maxBarThickness: 28,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Cumul %',
            data: pareto.cumulative_pct,
            borderColor: ANBG.primary,
            backgroundColor: alphaColor(ANBG.primary, 0.18),
            tension: 0.3,
            borderWidth: 3,
            pointRadius: 3,
            yAxisID: 'y1',
          },
        ],
      },
      options: {
        scales: cartesianScales({
          y: { title: { display: true, text: 'Occurrences', color: dashboardTheme().muted } },
          y1: {
            position: 'right',
            beginAtZero: true,
            max: 100,
            grid: { drawOnChartArea: false },
            ticks: { color: dashboardTheme().muted, font: { size: 11, weight: '600' } },
            border: { display: false },
            title: { display: true, text: 'Cumul %', color: dashboardTheme().muted },
          },
        }),
      },
    }), ({ element }) => (pareto.urls || [])[element?.index] || '');

    const topRisks = reportingCharts.top_risks || { labels: [], scores: [] };

    mountChart('dashboard-report-top-risks-chart', baseConfig('bar', {
      data: {
        labels: topRisks.labels,
        datasets: [{
          label: 'Score de risque',
          data: topRisks.scores,
          backgroundColor: (context) => barGradient(context.chart, ANBG.danger),
          borderRadius: 10,
          maxBarThickness: 26,
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: cartesianScales(),
      },
    }), ({ element }) => (topRisks.rows || [])[element?.index]?.url || '');

    const heatmap = reportingCharts.retard_heatmap || { weeks: [], units: [], matrix: [], max: 0 };
    const heatmapData = [];
    const heatMax = Math.max(1, Number(heatmap.max || 0));

    (heatmap.units || []).forEach((unit, unitIndex) => {
      (heatmap.weeks || []).forEach((week, weekIndex) => {
        heatmapData.push({
          x: weekIndex + 1,
          y: unitIndex + 1,
          v: Number((heatmap.matrix?.[unitIndex] || [])[weekIndex] || 0),
          week,
          unit,
          url: ((heatmap.urls?.[unitIndex] || [])[weekIndex]) || '',
        });
      });
    });

    mountChart('dashboard-report-heatmap-chart', baseConfig('matrix', {
      data: {
        datasets: [{
          label: 'Retards',
          data: heatmapData,
          borderRadius: 8,
          borderWidth: 1,
          borderColor: (context) => alphaColor(ANBG.primary, context.raw?.v ? 0.18 : 0.06),
          backgroundColor: (context) => {
            const value = Number(context.raw?.v || 0);
            const ratio = value / heatMax;
            const tone = ratio >= 0.75 ? ANBG.danger : (ratio >= 0.4 ? ANBG.warning : ANBG.secondary);
            return alphaColor(tone, Math.max(0.14, Math.min(0.92, 0.2 + (ratio * 0.6))));
          },
          width: ({ chart }) => {
            const chartArea = chart.chartArea;

            if (!chartArea) {
              return 20;
            }

            return Math.max(18, (chartArea.width / Math.max(heatmap.weeks.length, 1)) - 6);
          },
          height: ({ chart }) => {
            const chartArea = chart.chartArea;

            if (!chartArea) {
              return 18;
            }

            return Math.max(18, (chartArea.height / Math.max(heatmap.units.length, 1)) - 6);
          },
        }],
      },
      options: {
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title(items) {
                const point = items[0]?.raw;
                return point ? `${point.unit} | ${point.week}` : '';
              },
              label(context) {
                return `${context.raw.v} retard(s)`;
              },
            },
          },
        },
        scales: {
          x: {
            type: 'linear',
            position: 'top',
            offset: false,
            min: 0.5,
            max: Math.max(1, heatmap.weeks.length) + 0.5,
            grid: { display: false },
            ticks: {
              stepSize: 1,
              color: dashboardTheme().muted,
              callback(value) {
                return heatmap.weeks[Math.round(value) - 1] || '';
              },
            },
            border: { display: false },
          },
          y: {
            type: 'linear',
            reverse: true,
            min: 0.5,
            max: Math.max(1, heatmap.units.length) + 0.5,
            grid: { display: false },
            ticks: {
              stepSize: 1,
              color: dashboardTheme().muted,
              callback(value) {
                return heatmap.units[Math.round(value) - 1] || '';
              },
            },
            border: { display: false },
          },
        },
      },
    }), ({ element }) => heatmapData[element?.index]?.url || '');

    const treemap = reportingCharts.resource_treemap || { labels: [], values: [] };
    const treemapTree = (treemap.labels || [])
      .map((label, index) => ({
        label,
        value: Number((treemap.values || [])[index] || 0),
        url: (treemap.urls || [])[index] || '',
      }))
      .filter((item) => item.value > 0);

    mountChart('dashboard-report-treemap-chart', baseConfig('treemap', {
      data: {
        datasets: [{
          tree: treemapTree,
          key: 'value',
          spacing: 2,
          borderWidth: 2,
          borderColor: dashboardTheme().isDark ? 'rgba(15,23,42,0.92)' : '#ffffff',
          backgroundColor: (context) => reportingTreemapTone(context.dataIndex),
          hoverBorderColor: ANBG.secondary,
          hoverBorderWidth: 2,
          captions: { display: false },
          labels: {
            display: true,
            overflow: 'fit',
            formatter(ctx) {
              return [ctx.raw._data.label, `${ctx.raw.v}`];
            },
            color: dashboardTheme().isDark ? '#F8FAFC' : '#0F172A',
            font: [
              { size: 12, weight: '700' },
              { size: 11, weight: '600' },
            ],
          },
        }],
      },
      options: {
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label(context) {
                return `${context.raw._data.label}: ${context.raw.v}`;
              },
            },
          },
        },
      },
    }), ({ element }) => treemapTree[element?.index]?.url || '');

    const performanceGauge = reportingCharts.performance_gauge || { labels: [], values: [] };
    const gaugeLabels = Array.isArray(performanceGauge.labels) ? performanceGauge.labels : [];
    const gaugeValues = Array.isArray(performanceGauge.values) ? performanceGauge.values : [];
    const gaugeUrls = Array.isArray(performanceGauge.urls) ? performanceGauge.urls : [];
    const gaugeCount = Math.max(gaugeLabels.length, gaugeValues.length);

    Array.from({ length: gaugeCount }).forEach((_, index) => {
      mountGauge(
        `dashboard-report-gauge-${index}`,
        gaugeLabels[index] || `Performance ${index + 1}`,
        gaugeValues[index],
        gaugeUrls[index] || ''
      );
    });
  }

  function resizeCharts() {
    Object.values(chartInstances).forEach((chart) => {
      if (chart && typeof chart.resize === 'function') {
        chart.resize();
      }
    });

    renderGantts();
  }

  function render() {
    if (typeof window.Chart === 'undefined' || typeof window.d3 === 'undefined') {
      return;
    }

    ensurePlugins();

    if (typeof window.applyAnbgChartDefaults === 'function') {
      window.applyAnbgChartDefaults(window.Chart);
    }

    mountRoleCharts();

    if (payload.dashboard_role === 'dg') {
      mountKpiGaugeSet('dashboard-kpi-gauge-operational-', payload.operational_global_scores || {}, false);
      mountKpiGaugeSet('dashboard-kpi-gauge-official-', payload.global_scores || {}, true);
      mountStatusDonut(
        'dashboard-status-mix-chart-operational',
        payload.operational_status_cards || statusCards,
        (payload.totals && payload.totals.actions_total) || 0
      );
      mountStatusDonut(
        'dashboard-status-mix-chart-official',
        payload.official_status_cards || [],
        Array.isArray(payload.official_status_cards)
          ? payload.official_status_cards.reduce((sum, item) => sum + Number(item.count || 0), 0)
          : 0
      );
      mountMonthlyKpiLine('dashboard-kpi-line-chart-operational', payload.operational_monthly || monthly);
      mountMonthlyKpiLine('dashboard-kpi-line-chart-official', payload.monthly || monthly);
    } else {
      mountKpiGaugeSet('dashboard-kpi-gauge-', payload.global_scores || {}, true);
      mountStatusDonut();
      mountMonthlyKpiLine();
    }

    mountUnitSummary();
    mountMonthlyKpiGrouped();
    mountInterannual();
    mountRadar();
    mountBubble();
    mountReportingCharts();
    renderGantts();
    bindRowLinks();
  }

  function activateTab(key, syncUrl) {
    let nextKey = key;

    if (!panelKeys.includes(nextKey)) {
      nextKey = 'overview';
    }

    if (tabsRoot) {
      tabsRoot.querySelectorAll('[data-dashboard-tab]').forEach((tabButton) => {
        const active = tabButton.getAttribute('data-dashboard-tab') === nextKey;
        tabButton.classList.toggle('dashboard-tab-active', active);
        tabButton.classList.toggle('dashboard-tab-inactive', !active);
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
      render();
      resizeCharts();
      window.setTimeout(() => {
        resizeCharts();
      }, 180);
    }, 90);
  }

  const tabsRoot = document.querySelector('[data-dashboard-tabs]');

  if (tabsRoot) {
    tabsRoot.querySelectorAll('[data-dashboard-tab]').forEach((button) => {
      button.addEventListener('click', () => {
        activateTab(button.getAttribute('data-dashboard-tab'), true);
      });
    });

    let initialKey = new URLSearchParams(window.location.search).get('dashboardTab');

    if (!initialKey && window.location.hash && window.location.hash.indexOf('#dashboard-') === 0) {
      initialKey = window.location.hash.replace('#dashboard-', '');
    }

    activateTab(initialKey || 'overview', false);
  }

  document.addEventListener('anbg:dashboard-assets-ready', () => {
    render();
  }, { once: true });

  if (typeof window.Chart !== 'undefined' && typeof window.d3 !== 'undefined') {
    render();
  }

  window.addEventListener('anbg:theme-changed', () => {
    render();
  });

  window.addEventListener('resize', () => {
    window.clearTimeout(window.__anbgDashboardResizeTimer);
    window.__anbgDashboardResizeTimer = window.setTimeout(() => {
      resizeCharts();
    }, 90);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootDashboardRender, { once: true });
} else {
  bootDashboardRender();
}
