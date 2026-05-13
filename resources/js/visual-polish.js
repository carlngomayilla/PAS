const PATTERN_CARD_SELECTOR = [
  '.showcase-panel',
  '.showcase-toolbar',
  '.form-section',
  '.dashboard-card',
  '.dashboard-widget',
  '.dashboard-advanced-card',
  '.dashboard-advanced-kpi',
  '.dashboard-summary-card',
  '.showcase-kpi-card',
  '.showcase-inline-stat',
  '.stat-card',
  '.stat-card-link',
  '.eas-stat-card',
  '.workspace-card',
  '.report-card',
  '.reporting-hub-kpi',
  '.data-table-shell',
  '.table-container',
  '.app-table-wrapper',
  '.app-card',
  '.eas-section-card',
  '.pattern-form',
  '.tracking-entry-form',
  '.panel',
  '.card',
].join(',');

const PATTERN_SKIP_SELECTOR = [
  '.no-pattern',
  '.dashboard-canvas',
  '.dashboard-chart-host',
  'input',
  'select',
  'textarea',
  'button',
  'table',
  'thead',
  'tbody',
  'tfoot',
  'tr',
  'td',
  'th',
].join(',');

const CHART_BODY_SELECTOR = [
  '.dashboard-canvas',
  '.dashboard-gauge-grid-4',
  '.dashboard-gauge-grid',
  '.dashboard-chart-host',
].join(',');

const CHART_PANEL_SELECTOR = [
  'article',
  '.showcase-panel',
  '.dashboard-advanced-card',
  '.dashboard-card',
  '.app-card',
  '.eas-section-card',
  '.panel',
  '.card',
].join(',');

const CHART_HEADER_SELECTOR = [
  '.chart-panel-head',
  '.dashboard-advanced-head',
  '.app-card-header',
  '.mb-4.flex',
  '.mb-3.flex',
  '[class*="justify-between"]',
].join(',');

function applyPatternCards() {
  const root = document.querySelector('body.admin-theme-scope') || document.body;

  if (!root) {
    return;
  }

  root.querySelectorAll(PATTERN_CARD_SELECTOR).forEach((node) => {
    if (!(node instanceof HTMLElement) || node.matches(PATTERN_SKIP_SELECTOR)) {
      return;
    }

    node.classList.add('pattern-card');
  });
}

function visibleHeaderForPanel(panel, body) {
  const directPrevious = body.previousElementSibling;

  if (directPrevious instanceof HTMLElement && directPrevious.matches(CHART_HEADER_SELECTOR)) {
    return directPrevious;
  }

  const header = panel.querySelector(CHART_HEADER_SELECTOR);

  if (header instanceof HTMLElement && !header.contains(body)) {
    return header;
  }

  return null;
}

function addToggleButton(panel, header, body) {
  const existing = header.querySelector(':scope > .chart-disclosure-toggle');

  if (existing instanceof HTMLButtonElement) {
    return existing;
  }

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
        window.dispatchEvent(new Event('resize'));
      }, 240);

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

  return button;
}

function bindChartAccordions() {
  const panels = new Set();

  document.querySelectorAll(CHART_BODY_SELECTOR).forEach((body) => {
    if (!(body instanceof HTMLElement)) {
      return;
    }

    const panel = body.closest(CHART_PANEL_SELECTOR);

    if (panel instanceof HTMLElement) {
      panels.add(panel);
    }
  });

  panels.forEach((panel) => {
    if (!(panel instanceof HTMLElement)) {
      return;
    }

    const body = panel.querySelector(CHART_BODY_SELECTOR);

    if (!(body instanceof HTMLElement)) {
      return;
    }

    const header = visibleHeaderForPanel(panel, body);

    if (!(header instanceof HTMLElement)) {
      return;
    }

    panel.dataset.chartDisclosureBound = '1';
    panel.classList.add('chart-disclosure-panel', 'pattern-card');
    header.classList.add('chart-disclosure-head');
    body.classList.add('chart-disclosure-body');

    addToggleButton(panel, header, body);
  });
}

function initVisualPolish() {
  applyPatternCards();
  bindChartAccordions();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initVisualPolish, { once: true });
} else {
  initVisualPolish();
}

document.addEventListener('anbg:page-soft-refreshed', initVisualPolish);
document.addEventListener('anbg:dashboard-payload-ready', initVisualPolish);
window.addEventListener('load', initVisualPolish, { once: true });
