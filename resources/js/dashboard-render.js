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
        ctx.font = line.font || '700 14px Instrument Sans, ui-sans-serif, system-ui, sans-serif';
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
    return ['#3996D3', '#8FC043', '#F0E509', '#F9B13C', '#1C203D', '#7DD3FC', '#A3E635', '#FB7185'];
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
      return '#F0E509';
    }

    if (status.includes('retard')) {
      return '#F9B13C';
    }

    if (status.includes('avance') || status.includes('valide') || status.includes('acheve')) {
      return '#8FC043';
    }

    if (status.includes('non') || status.includes('rejet')) {
      return '#94A3B8';
    }

    if (status.includes('soumis') || status.includes('cours')) {
      return '#3996D3';
    }

    return toneForIndex(index);
  }

  function gaugeColor(value) {
    if (value >= 80) {
      return '#8FC043';
    }

    if (value >= 60) {
      return '#3996D3';
    }

    return '#F9B13C';
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

  function mountChart(id, config) {
    const host = document.getElementById(id);

    if (!host || typeof window.Chart === 'undefined') {
      return;
    }

    destroyChart(id);
    host.innerHTML = '';

    const canvas = document.createElement('canvas');
    host.appendChild(canvas);

    chartInstances[id] = new window.Chart(canvas.getContext('2d'), config);
  }

  window.anbgDashboardRuntime = {
    getChart(id) {
      return chartInstances[id] || null;
    },
    getChartIds() {
      return Object.keys(chartInstances);
    },
  };

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

  function mountGauge(id, label, value) {
    const theme = dashboardTheme();
    const numeric = Math.max(0, Math.min(100, Number(value || 0)));

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
                font: '800 25px Instrument Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: -6,
              },
              {
                text: label,
                color: theme.muted,
                font: '700 11px Instrument Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: 20,
              },
            ],
          },
        },
      },
    }));
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
      axisTicks: window.d3 ? window.d3.timeWeek.every(1) : null,
      tickFormat: window.d3 ? window.d3.timeFormat('%d %b') : null,
    });
  }

  function mountStatusDonut() {
    const theme = dashboardTheme();

    mountChart('dashboard-status-mix-chart', baseConfig('doughnut', {
      data: {
        labels: statusCards.map((item) => item.label),
        datasets: [{
          data: statusCards.map((item) => Number(item.count || 0)),
          backgroundColor: statusCards.map((item, index) => item.color || colorForStatus(item.label, index)),
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
                text: String((payload.totals && payload.totals.actions_total) || 0),
                color: theme.emphasis,
                font: '800 28px Instrument Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: -8,
              },
              {
                text: 'Actions',
                color: theme.muted,
                font: '700 11px Instrument Sans, ui-sans-serif, system-ui, sans-serif',
                offsetY: 16,
              },
            ],
          },
        },
      },
    }));
  }

  function mountMonthlyKpiLine() {
    mountChart('dashboard-kpi-line-chart', baseConfig('line', {
      data: {
        labels: monthly.map((item) => item.label),
        datasets: [
          {
            label: 'Delai',
            data: monthly.map((item) => Number(item.delai || 0)),
            borderColor: '#3996D3',
            backgroundColor: (context) => chartGradient(context.chart, '#3996D3'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Performance',
            data: monthly.map((item) => Number(item.performance || 0)),
            borderColor: '#8FC043',
            backgroundColor: (context) => chartGradient(context.chart, '#8FC043'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Conformite',
            data: monthly.map((item) => Number(item.conformite || 0)),
            borderColor: '#F0E509',
            backgroundColor: (context) => chartGradient(context.chart, '#F0E509'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Qualite',
            data: monthly.map((item) => Number(item.qualite || 0)),
            borderColor: '#F9B13C',
            backgroundColor: (context) => chartGradient(context.chart, '#F9B13C'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Risque',
            data: monthly.map((item) => Number(item.risque || 0)),
            borderColor: '#64748B',
            backgroundColor: (context) => chartGradient(context.chart, '#64748B'),
            fill: true,
            tension: 0.36,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: 'Global',
            data: monthly.map((item) => Number(item.global || 0)),
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
    }));
  }

  function mountUnitSummary() {
    mountChart('dashboard-unit-summary-chart', baseConfig('bar', {
      data: {
        labels: unitRows.map((item) => item.label),
        datasets: [
          {
            label: 'KPI moyen',
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
    }));
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
    }));
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
    }));
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
    }));
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
    }));
  }

  function mountReportingCharts() {
    const funnel = reportingCharts.funnel || { labels: [], values: [] };

    mountChart('dashboard-report-funnel-chart', baseConfig('bar', {
      data: {
        labels: funnel.labels,
        datasets: [{
          label: 'Volume',
          data: funnel.values,
          backgroundColor: funnel.labels.map((_label, index) => alphaColor(toneForIndex(index), 0.88)),
          borderColor: funnel.labels.map((_label, index) => toneForIndex(index)),
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
    }));

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
    }));

    const progressWeekly = reportingCharts.progress_weekly || { labels: [], reel: [], theorique: [] };

    mountChart('dashboard-report-progress-chart', baseConfig('line', {
      data: {
        labels: progressWeekly.labels,
        datasets: [
          {
            label: 'Reel',
            data: progressWeekly.reel,
            borderColor: '#3996D3',
            backgroundColor: (context) => chartGradient(context.chart, '#3996D3'),
            fill: true,
            tension: 0.34,
            pointRadius: 3,
          },
          {
            label: 'Theorique',
            data: progressWeekly.theorique,
            borderColor: '#F9B13C',
            backgroundColor: (context) => chartGradient(context.chart, '#F9B13C'),
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
    }));

    const kpiTrend = reportingCharts.kpi_trend || { labels: [], valeurs: [], cibles: [], seuils: [] };

    mountChart('dashboard-report-kpi-trend-chart', baseConfig('bar', {
      data: {
        labels: kpiTrend.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Valeur',
            data: kpiTrend.valeurs,
            backgroundColor: (context) => barGradient(context.chart, '#3996D3'),
            borderRadius: 9,
            maxBarThickness: 32,
          },
          {
            type: 'line',
            label: 'Cible',
            data: kpiTrend.cibles,
            borderColor: '#8FC043',
            backgroundColor: alphaColor('#8FC043', 0.18),
            borderWidth: 3,
            tension: 0.3,
            pointRadius: 3,
          },
          {
            type: 'line',
            label: 'Seuil',
            data: kpiTrend.seuils,
            borderColor: '#F9B13C',
            backgroundColor: alphaColor('#F9B13C', 0.18),
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
    }));

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
            backgroundColor: (context) => barGradient(context.chart, '#1C203D'),
            borderRadius: 10,
            maxBarThickness: 32,
            yAxisID: 'y',
          },
          {
            type: 'bar',
            label: 'Validees',
            data: interannualOverview.actions_validees,
            backgroundColor: (context) => barGradient(context.chart, '#8FC043'),
            borderRadius: 10,
            maxBarThickness: 32,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Progression moyenne',
            data: interannualOverview.progression_moyenne,
            borderColor: '#3996D3',
            backgroundColor: alphaColor('#3996D3', 0.18),
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
    }));

    const pareto = reportingCharts.risk_pareto || { labels: [], counts: [], cumulative_pct: [] };

    mountChart('dashboard-report-risk-pareto-chart', baseConfig('bar', {
      data: {
        labels: pareto.labels,
        datasets: [
          {
            type: 'bar',
            label: 'Occurrences',
            data: pareto.counts,
            backgroundColor: (context) => barGradient(context.chart, '#F9B13C'),
            borderRadius: 10,
            maxBarThickness: 28,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Cumul %',
            data: pareto.cumulative_pct,
            borderColor: '#1C203D',
            backgroundColor: alphaColor('#1C203D', 0.18),
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
    }));

    const topRisks = reportingCharts.top_risks || { labels: [], scores: [] };

    mountChart('dashboard-report-top-risks-chart', baseConfig('bar', {
      data: {
        labels: topRisks.labels,
        datasets: [{
          label: 'Score de risque',
          data: topRisks.scores,
          backgroundColor: (context) => barGradient(context.chart, '#F9B13C'),
          borderRadius: 10,
          maxBarThickness: 26,
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: cartesianScales(),
      },
    }));

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
          borderColor: (context) => alphaColor('#1C203D', context.raw?.v ? 0.18 : 0.06),
          backgroundColor: (context) => {
            const value = Number(context.raw?.v || 0);
            const ratio = value / heatMax;
            return alphaColor('#F9B13C', Math.max(0.08, Math.min(0.94, 0.14 + (ratio * 0.8))));
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
    }));

    const treemap = reportingCharts.resource_treemap || { labels: [], values: [] };
    const treemapTree = (treemap.labels || [])
      .map((label, index) => ({
        label,
        value: Number((treemap.values || [])[index] || 0),
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
          backgroundColor: (context) => alphaColor(toneForIndex(context.dataIndex), 0.84),
          hoverBorderColor: '#F8E932',
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
    }));

    const performanceGauge = reportingCharts.performance_gauge || { labels: [], values: [] };

    (performanceGauge.values || []).forEach((value, index) => {
      mountGauge(`dashboard-report-gauge-${index}`, performanceGauge.labels[index] || 'Performance', value);
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

    mountGauge('dashboard-kpi-gauge-delai', 'Delai', payload.global_scores && payload.global_scores.delai);
    mountGauge('dashboard-kpi-gauge-performance', 'Performance', payload.global_scores && payload.global_scores.performance);
    mountGauge('dashboard-kpi-gauge-conformite', 'Conformite', payload.global_scores && payload.global_scores.conformite);
    mountGauge('dashboard-kpi-gauge-qualite', 'Qualite', payload.global_scores && payload.global_scores.qualite);
    mountGauge('dashboard-kpi-gauge-risque', 'Risque', payload.global_scores && payload.global_scores.risque);

    mountStatusDonut();
    mountMonthlyKpiLine();
    mountUnitSummary();
    mountMonthlyKpiGrouped();
    mountInterannual();
    mountRadar();
    mountBubble();
    mountReportingCharts();
    renderGantts();
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
