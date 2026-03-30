function slugify(value) {
  return String(value || 'export')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .toLowerCase() || 'export';
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 800);
}

function downloadDataUrl(dataUrl, filename) {
  const link = document.createElement('a');
  link.href = dataUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
}

function closestPanelTitle(node) {
  let current = node;

  while (current && current !== document.body) {
    const title = current.querySelector?.('.showcase-panel-title, .showcase-title, h1, h2, h3');
    if (title) {
      return title.textContent?.trim() || 'Visualisation';
    }

    current = current.parentElement;
  }

  return 'Visualisation';
}

function closestPanelSubtitle(node) {
  let current = node;

  while (current && current !== document.body) {
    const subtitle = current.querySelector?.('.showcase-panel-subtitle, .showcase-subtitle');
    if (subtitle) {
      return subtitle.textContent?.trim() || '';
    }

    current = current.parentElement;
  }

  return '';
}

function escapeCsv(value) {
  const normalized = String(value ?? '').replace(/\r?\n/g, ' ').trim();
  return `"${normalized.replace(/"/g, '""')}"`;
}

function tableToCsv(table) {
  const rows = [...table.querySelectorAll('tr')];
  return rows
    .map((row) => [...row.querySelectorAll('th,td')].map((cell) => escapeCsv(cell.textContent)).join(';'))
    .join('\n');
}

function svgToPngDataUrl(svgElement) {
  return new Promise((resolve, reject) => {
    const serializer = new XMLSerializer();
    const clone = svgElement.cloneNode(true);
    const originalNodes = [svgElement, ...svgElement.querySelectorAll('*')];
    const cloneNodes = [clone, ...clone.querySelectorAll('*')];

    cloneNodes.forEach((node, index) => {
      const sourceNode = originalNodes[index];
      const styles = window.getComputedStyle(sourceNode);
      const styleText = [
        `fill:${styles.fill}`,
        `stroke:${styles.stroke}`,
        `stroke-width:${styles.strokeWidth}`,
        `opacity:${styles.opacity}`,
        `font-size:${styles.fontSize}`,
        `font-family:${styles.fontFamily}`,
        `font-weight:${styles.fontWeight}`,
      ].join(';');

      node.setAttribute('style', styleText);
    });

    const source = serializer.serializeToString(clone);
    const svgBlob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(svgBlob);
    const image = new Image();

    image.onload = () => {
      const viewBox = svgElement.viewBox?.baseVal;
      const width = Math.max(1200, svgElement.clientWidth || viewBox?.width || 1200);
      const height = Math.max(680, svgElement.clientHeight || viewBox?.height || 680);
      const canvas = document.createElement('canvas');
      canvas.width = width * 2;
      canvas.height = height * 2;
      const context = canvas.getContext('2d');

      if (!context) {
        URL.revokeObjectURL(url);
        reject(new Error('Canvas indisponible.'));
        return;
      }

      context.scale(2, 2);
      context.drawImage(image, 0, 0, width, height);
      URL.revokeObjectURL(url);
      resolve(canvas.toDataURL('image/png'));
    };

    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('Conversion SVG impossible.'));
    };

    image.src = url;
  });
}

function shouldIgnoreClick(target) {
  return !!target.closest('a, button, input, select, textarea, label, form');
}

function createZoomableBadge(target) {
  if (target.tagName === 'TABLE') {
    return;
  }

  if (target.querySelector('.analytics-zoom-badge')) {
    return;
  }

  const badge = document.createElement('span');
  badge.className = 'analytics-zoom-badge';
  badge.textContent = 'Plein ecran';
  target.appendChild(badge);
}

function isWorkspaceTable(table) {
  if (!table || table.closest('#analytics-explorer')) {
    return false;
  }

  if (table.matches('.dashboard-table')) {
    return true;
  }

  if (!table.closest('main')) {
    return false;
  }

  if (table.closest('form') && !table.closest('.table-wrap, .overflow-auto, .overflow-x-auto, .overflow-y-auto, .showcase-panel')) {
    return false;
  }

  return true;
}

function decorateTarget(target) {
  if (!target || target.dataset.analyticsZoomBound === '1') {
    return;
  }

  target.dataset.analyticsZoomBound = '1';
  target.dataset.analyticsZoomable = '1';
  target.tabIndex = 0;
  target.setAttribute('role', 'button');
  target.setAttribute('title', 'Ouvrir en plein ecran');

  createZoomableBadge(target);
}

function initAnalyticsExplorer() {
  const root = document.getElementById('analytics-explorer');
  if (!root) {
    return;
  }

  const titleNode = document.getElementById('analytics-explorer-title');
  const subtitleNode = document.getElementById('analytics-explorer-subtitle');
  const bodyNode = document.getElementById('analytics-explorer-body');
  const downloadButton = document.getElementById('analytics-explorer-download');
  const closeButton = document.getElementById('analytics-explorer-close');
  const backdrop = root.querySelector('[data-analytics-explorer-dismiss]');

  let downloadHandler = null;
  let lastFocused = null;

  const decorateAll = () => {
    document.querySelectorAll('.dashboard-canvas, .dashboard-gauge-card').forEach((node) => {
      decorateTarget(node);
    });

    document.querySelectorAll('table').forEach((node) => {
      if (!isWorkspaceTable(node)) {
        return;
      }

      decorateTarget(node);
    });
  };

  const close = () => {
    root.classList.add('hidden');
    root.setAttribute('aria-hidden', 'true');
    bodyNode.innerHTML = '';
    subtitleNode.textContent = '';
    downloadButton.classList.add('hidden');
    downloadButton.textContent = 'Telecharger';
    downloadHandler = null;

    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  };

  const open = ({ title, subtitle, content, onDownload, downloadLabel }) => {
    lastFocused = document.activeElement;
    titleNode.textContent = title;
    subtitleNode.textContent = subtitle || '';
    bodyNode.innerHTML = '';
    bodyNode.appendChild(content);

    if (typeof onDownload === 'function') {
      downloadHandler = onDownload;
      downloadButton.textContent = downloadLabel || 'Telecharger';
      downloadButton.classList.remove('hidden');
    } else {
      downloadHandler = null;
      downloadButton.classList.add('hidden');
    }

    root.classList.remove('hidden');
    root.setAttribute('aria-hidden', 'false');
    window.requestAnimationFrame(() => closeButton.focus());
  };

  const openTable = (table) => {
    const title = closestPanelTitle(table);
    const subtitle = closestPanelSubtitle(table);
    const wrapper = document.createElement('div');
    wrapper.className = 'analytics-explorer-table-wrap';
    wrapper.innerHTML = table.outerHTML;

    open({
      title,
      subtitle,
      content: wrapper,
      onDownload: () => {
        const csv = tableToCsv(table);
        downloadBlob(new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' }), `${slugify(title)}.csv`);
      },
      downloadLabel: 'Telecharger CSV',
    });
  };

  const openChart = async (target) => {
    const title = target.classList.contains('dashboard-gauge-card')
      ? `${closestPanelTitle(target)} - ${(target.querySelector('strong')?.textContent || 'Jauge').trim()}`
      : closestPanelTitle(target);
    const subtitle = closestPanelSubtitle(target);
    const host = target.querySelector('.dashboard-chart-host') || target;
    const canvas = host.querySelector('canvas');
    const svg = host.querySelector('svg');

    if (canvas) {
      const image = document.createElement('img');
      image.className = 'analytics-explorer-image';
      image.src = canvas.toDataURL('image/png');
      image.alt = title;

      open({
        title,
        subtitle,
        content: image,
        onDownload: () => downloadDataUrl(canvas.toDataURL('image/png'), `${slugify(title)}.png`),
        downloadLabel: 'Telecharger PNG',
      });
      return;
    }

    if (svg) {
      const wrapper = document.createElement('div');
      wrapper.className = 'analytics-explorer-svg-wrap';
      wrapper.innerHTML = svg.outerHTML;

      open({
        title,
        subtitle,
        content: wrapper,
        onDownload: async () => {
          const dataUrl = await svgToPngDataUrl(svg);
          downloadDataUrl(dataUrl, `${slugify(title)}.png`);
        },
        downloadLabel: 'Telecharger PNG',
      });
    }
  };

  const resolveZoomTarget = (node) => {
    const gauge = node.closest('.dashboard-gauge-card[data-analytics-zoomable]');
    if (gauge) {
      return gauge;
    }

    const canvas = node.closest('.dashboard-canvas[data-analytics-zoomable]');
    if (canvas) {
      return canvas;
    }

    const table = node.closest('table[data-analytics-zoomable]');
    if (table) {
      return table;
    }

    return null;
  };

  decorateAll();
  window.addEventListener('anbg:dashboard-assets-ready', decorateAll);
  window.addEventListener('anbg:theme-changed', decorateAll);

  document.addEventListener('click', async (event) => {
    if (event.target.closest('#analytics-explorer')) {
      return;
    }

    const target = resolveZoomTarget(event.target);
    if (!target || shouldIgnoreClick(event.target)) {
      return;
    }

    event.preventDefault();

    if (target.matches('table[data-analytics-zoomable]')) {
      openTable(target);
      return;
    }

    await openChart(target);
  });

  document.addEventListener('keydown', async (event) => {
    if (event.target.closest('#analytics-explorer')) {
      return;
    }

    const target = resolveZoomTarget(event.target);
    if (!target || event.key !== 'Enter' && event.key !== ' ') {
      return;
    }

    if (shouldIgnoreClick(event.target)) {
      return;
    }

    event.preventDefault();

    if (target.matches('table[data-analytics-zoomable]')) {
      openTable(target);
      return;
    }

    await openChart(target);
  });

  if (downloadButton) {
    downloadButton.addEventListener('click', async () => {
      if (typeof downloadHandler === 'function') {
        await downloadHandler();
      }
    });
  }

  [closeButton, backdrop].forEach((node) => {
    if (node) {
      node.addEventListener('click', close);
    }
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !root.classList.contains('hidden')) {
      close();
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAnalyticsExplorer, { once: true });
} else {
  initAnalyticsExplorer();
}
