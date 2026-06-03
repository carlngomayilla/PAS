function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function fileKind(mime, title) {
    var normalizedMime = String(mime || '').toLowerCase();
    var extension = String(title || '').split('.').pop().toLowerCase();

    if (normalizedMime.includes('pdf') || extension === 'pdf') return 'pdf';
    if (normalizedMime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(extension)) return 'image';
    if (normalizedMime.startsWith('text/') || ['txt', 'csv', 'log'].includes(extension)) return 'text';
    if (['xls', 'xlsx', 'ods'].includes(extension) || normalizedMime.includes('spreadsheet') || normalizedMime.includes('excel')) return 'spreadsheet';
    if (['doc', 'docx', 'odt'].includes(extension) || normalizedMime.includes('word')) return 'document';

    return 'unknown';
}

function collectTableRows(table) {
    return Array.from(table.querySelectorAll('tr')).map(function (row) {
        return Array.from(row.children).map(function (cell) {
            return String(cell.textContent || '').replace(/\s+/g, ' ').trim();
        });
    });
}

function rowsToHtmlTable(rows) {
    return [
        '<div class="preview-table-wrapper"><table class="app-table data-table preview-table">',
        rows.map(function (row, rowIndex) {
            var tag = rowIndex === 0 ? 'th' : 'td';
            return '<tr>' + row.map(function (cell) {
                return '<' + tag + '>' + escapeHtml(cell) + '</' + tag + '>';
            }).join('') + '</tr>';
        }).join(''),
        '</table></div>',
    ].join('');
}

function safeFilename(title, fallback) {
    return String(title || fallback || 'export')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9_.-]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .toLowerCase() || fallback || 'export';
}

function downloadTableAsExcel(title, rows) {
    var safeTitle = safeFilename(title, 'tableau');
    var html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' + rowsToHtmlTable(rows) + '</body></html>';
    var blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = safeTitle + '.xls';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
}

function canvasToPngDataUrl(canvas) {
    var output = document.createElement('canvas');
    var width = canvas.width || Math.max(1, canvas.clientWidth || 1200);
    var height = canvas.height || Math.max(1, canvas.clientHeight || 680);

    output.width = width;
    output.height = height;

    var context = output.getContext('2d');

    if (!context) {
        return canvas.toDataURL('image/png');
    }

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);
    context.drawImage(canvas, 0, 0, width, height);

    return output.toDataURL('image/png');
}

function svgToPngDataUrl(svgElement) {
    return new Promise(function (resolve, reject) {
        var serializer = new XMLSerializer();
        var clone = svgElement.cloneNode(true);
        var originalNodes = [svgElement].concat(Array.from(svgElement.querySelectorAll('*')));
        var cloneNodes = [clone].concat(Array.from(clone.querySelectorAll('*')));

        cloneNodes.forEach(function (node, index) {
            var sourceNode = originalNodes[index];
            var styles = window.getComputedStyle(sourceNode);
            node.setAttribute('style', [
                'fill:' + styles.fill,
                'stroke:' + styles.stroke,
                'stroke-width:' + styles.strokeWidth,
                'opacity:' + styles.opacity,
                'font-size:' + styles.fontSize,
                'font-family:' + styles.fontFamily,
                'font-weight:' + styles.fontWeight,
            ].join(';'));
        });

        var blob = new Blob([serializer.serializeToString(clone)], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var image = new Image();

        image.onload = function () {
            var viewBox = svgElement.viewBox ? svgElement.viewBox.baseVal : null;
            var width = Math.max(1200, svgElement.clientWidth || (viewBox ? viewBox.width : 0) || 1200);
            var height = Math.max(680, svgElement.clientHeight || (viewBox ? viewBox.height : 0) || 680);
            var canvas = document.createElement('canvas');

            canvas.width = width * 2;
            canvas.height = height * 2;

            var context = canvas.getContext('2d');

            if (!context) {
                URL.revokeObjectURL(url);
                reject(new Error('Canvas indisponible.'));
                return;
            }

            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.scale(2, 2);
            context.drawImage(image, 0, 0, width, height);
            URL.revokeObjectURL(url);
            resolve(canvas.toDataURL('image/png'));
        };

        image.onerror = function () {
            URL.revokeObjectURL(url);
            reject(new Error('Conversion SVG impossible.'));
        };

        image.src = url;
    });
}

(function () {
    'use strict';

    var interactiveSelector = 'a, button, input, select, textarea, label, summary, details, form, [role="button"]';
    var modal = null;
    var titleEl = null;
    var eyebrowEl = null;
    var subtitleEl = null;
    var bodyEl = null;
    var downloadBtn = null;
    var excelBtn = null;
    var pdfBtn = null;
    var printBtn = null;
    var lastFocus = null;
    var activeTableRows = [];
    var activeTableTitle = 'Apercu tableau';
    var activeChartUrl = '';
    var activeChartTitle = 'Graphique';
    var previewId = 0;
    var refreshTimer = null;
    var previewInitialized = false;

    function ensureElements() {
        modal = modal || document.getElementById('preview-modal');
        if (!modal) return false;

        if (document.body && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        titleEl = titleEl || document.getElementById('preview-modal-title');
        eyebrowEl = eyebrowEl || document.getElementById('preview-modal-eyebrow');
        subtitleEl = subtitleEl || document.getElementById('preview-modal-subtitle');
        bodyEl = bodyEl || document.getElementById('preview-modal-body');
        downloadBtn = downloadBtn || document.getElementById('preview-modal-download');
        excelBtn = excelBtn || document.getElementById('preview-modal-excel');
        pdfBtn = pdfBtn || document.getElementById('preview-modal-pdf');
        printBtn = printBtn || document.getElementById('preview-modal-print');

        return Boolean(titleEl && eyebrowEl && subtitleEl && bodyEl && downloadBtn && excelBtn && pdfBtn && printBtn);
    }

    function hideActions() {
        downloadBtn.classList.add('hidden');
        excelBtn.classList.add('hidden');
        pdfBtn.classList.add('hidden');
        printBtn.classList.add('hidden');
        downloadBtn.removeAttribute('href');
        downloadBtn.removeAttribute('download');
        downloadBtn.textContent = 'Telecharger';
        downloadBtn.onclick = null;
    }

    function openBase(options) {
        if (!ensureElements()) return;

        lastFocus = document.activeElement;
        hideActions();
        eyebrowEl.textContent = options.eyebrow || 'Previsualisation';
        titleEl.textContent = options.title || 'Apercu';
        subtitleEl.textContent = options.subtitle || '';
        bodyEl.innerHTML = options.body || '';
        modal.classList.remove('hidden');
        modal.classList.add('is-open');
        modal.style.position = 'fixed';
        modal.style.inset = '0';
        modal.style.zIndex = '9998';
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('preview-modal-open');
        setTimeout(function () { bodyEl.focus({ preventScroll: true }); }, 0);
    }

    function closeModal() {
        if (!ensureElements()) return;

        modal.classList.add('hidden');
        modal.classList.remove('is-open');
        modal.style.display = '';
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('preview-modal-open');
        bodyEl.innerHTML = '';
        activeTableRows = [];
        activeChartUrl = '';

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    function openFilePreview(trigger) {
        var title = trigger.dataset.previewTitle || trigger.textContent.trim() || 'Document';
        var subtitle = trigger.dataset.previewSubtitle || trigger.dataset.previewMime || '';
        var previewUrl = trigger.dataset.previewUrl || trigger.getAttribute('href') || '';
        var downloadUrl = trigger.dataset.downloadUrl || '';
        var mime = trigger.dataset.previewMime || '';
        var kind = trigger.dataset.previewKind || fileKind(mime, title);
        var body = '';

        if (kind === 'pdf') {
            body = '<iframe class="preview-file-frame" src="' + escapeHtml(previewUrl) + '" title="' + escapeHtml(title) + '"></iframe>';
        } else if (kind === 'image') {
            body = '<div class="preview-image-shell"><img class="preview-image" src="' + escapeHtml(previewUrl) + '" alt="' + escapeHtml(title) + '"></div>';
        } else if (kind === 'text') {
            body = '<iframe class="preview-file-frame" src="' + escapeHtml(previewUrl) + '" title="' + escapeHtml(title) + '"></iframe>';
        } else {
            body = [
                '<div class="preview-empty-state">',
                '<p class="preview-empty-title">Previsualisation non disponible</p>',
                '<p>Ce type de fichier ne peut pas etre lu directement dans PAS. Vous pouvez le telecharger.</p>',
                '</div>',
            ].join('');
        }

        openBase({
            eyebrow: 'Document',
            title: title,
            subtitle: subtitle,
            body: body,
        });

        if (downloadUrl) {
            downloadBtn.href = downloadUrl;
            downloadBtn.classList.remove('hidden');
        }
    }

    function nearestTitle(table) {
        var current = table;

        while (current && current !== document.body) {
            var heading = current.querySelector
                ? current.querySelector('.data-table-title, .showcase-panel-title, .showcase-title, .chart-title, h1, h2, h3')
                : null;

            if (heading && heading.textContent.trim()) {
                return heading.textContent.trim();
            }

            current = current.parentElement;
        }

        return 'Apercu';
    }

    function nearestSubtitle(node) {
        var current = node;

        while (current && current !== document.body) {
            var subtitle = current.querySelector
                ? current.querySelector('.data-table-subtitle, .showcase-panel-subtitle, .showcase-subtitle')
                : null;

            if (subtitle && subtitle.textContent.trim()) {
                return subtitle.textContent.trim();
            }

            current = current.parentElement;
        }

        return '';
    }

    function openTablePreview(table) {
        activeTableRows = collectTableRows(table);
        activeTableTitle = table.dataset.previewTitle || nearestTitle(table);

        openBase({
            eyebrow: 'Tableau',
            title: activeTableTitle,
            subtitle: nearestSubtitle(table) || 'Apercu grand format du tableau affiche',
            body: rowsToHtmlTable(activeTableRows),
        });

        excelBtn.classList.remove('hidden');
    }

    function resolveChartNode(source) {
        if (!source) return null;
        if (source.matches && (source.matches('canvas') || source.matches('svg'))) return source;
        return source.querySelector ? source.querySelector('canvas, svg') : null;
    }

    function openChartPreview(source) {
        var chartNode = resolveChartNode(source);
        var title = (source && source.dataset ? source.dataset.previewTitle : '') || nearestTitle(source || chartNode);
        var subtitle = (source && source.dataset ? source.dataset.previewSubtitle : '') || nearestSubtitle(source || chartNode) || 'Apercu grand format du graphique';

        if (!chartNode) {
            openBase({
                eyebrow: 'Graphique',
                title: title || 'Graphique',
                subtitle: subtitle,
                body: [
                    '<div class="preview-empty-state">',
                    '<p class="preview-empty-title">Graphique indisponible</p>',
                    '<p>Le graphique doit etre charge avant la previsualisation.</p>',
                    '</div>',
                ].join(''),
            });
            return Promise.resolve();
        }

        activeChartTitle = title || 'Graphique';

        if (chartNode.matches('canvas')) {
            activeChartUrl = canvasToPngDataUrl(chartNode);
            openBase({
                eyebrow: 'Graphique',
                title: activeChartTitle,
                subtitle: subtitle,
                body: '<div class="preview-image-shell"><img class="preview-image preview-chart-image" src="' + escapeHtml(activeChartUrl) + '" alt="' + escapeHtml(activeChartTitle) + '"></div>',
            });

            downloadBtn.href = activeChartUrl;
            downloadBtn.setAttribute('download', safeFilename(activeChartTitle, 'graphique') + '.png');
            downloadBtn.textContent = 'Telecharger PNG';
            downloadBtn.classList.remove('hidden');
            return Promise.resolve();
        }

        openBase({
            eyebrow: 'Graphique',
            title: activeChartTitle,
            subtitle: subtitle,
            body: '<div class="preview-empty-state"><p class="preview-empty-title">Conversion du graphique...</p></div>',
        });

        return svgToPngDataUrl(chartNode).then(function (url) {
            activeChartUrl = url;
            bodyEl.innerHTML = '<div class="preview-image-shell"><img class="preview-image preview-chart-image" src="' + escapeHtml(activeChartUrl) + '" alt="' + escapeHtml(activeChartTitle) + '"></div>';
            downloadBtn.href = activeChartUrl;
            downloadBtn.setAttribute('download', safeFilename(activeChartTitle, 'graphique') + '.png');
            downloadBtn.textContent = 'Telecharger PNG';
            downloadBtn.classList.remove('hidden');
        }).catch(function () {
            bodyEl.innerHTML = [
                '<div class="preview-empty-state">',
                '<p class="preview-empty-title">Conversion impossible</p>',
                '<p>Ce graphique ne peut pas etre exporte en PNG pour le moment.</p>',
                '</div>',
            ].join('');
        });
    }

    function nextPreviewId(prefix) {
        previewId += 1;
        return prefix + '-' + previewId;
    }

    function findTableByPreviewId(id) {
        return Array.from(document.querySelectorAll('table.app-table')).find(function (table) {
            return table.dataset.previewTableId === id;
        });
    }

    function findChartByPreviewId(id) {
        return Array.from(document.querySelectorAll('.dashboard-chart-host, .dashboard-canvas, .dashboard-gauge-card')).find(function (node) {
            return node.dataset.previewChartId === id;
        });
    }

    function injectTablePreviewButtons() {
        document.querySelectorAll('table.app-table').forEach(function (table) {
            if (table.closest('#preview-modal')) return;
            if (table.dataset.previewReady === '1') return;
            table.dataset.previewReady = '1';
            table.dataset.previewTableId = table.dataset.previewTableId || nextPreviewId('preview-table');
            table.classList.add('preview-table-clickable');
            table.setAttribute('title', 'Agrandir le tableau');

            var wrapper = table.closest('.app-table-wrapper') || table.parentElement;
            if (!wrapper || wrapper.querySelector('[data-preview-table-trigger]')) return;

            var toolbar = document.createElement('div');
            toolbar.className = 'preview-table-toolbar';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary btn-sm';
            button.dataset.previewTableTrigger = '1';
            button.dataset.previewTableId = table.dataset.previewTableId;
            button.textContent = 'Apercu';
            toolbar.appendChild(button);
            wrapper.parentNode.insertBefore(toolbar, wrapper);
        });
    }

    function injectChartPreviewButtons() {
        document.querySelectorAll('.dashboard-chart-host, .dashboard-canvas, .dashboard-gauge-card').forEach(function (node) {
            if (node.closest('#preview-modal')) return;
            if (node.matches('.dashboard-canvas, .dashboard-gauge-card') && node.querySelector('.dashboard-chart-host')) return;
            if (!resolveChartNode(node)) return;
            if (node.dataset.previewChartReady === '1') return;

            node.dataset.previewChartReady = '1';
            node.dataset.previewChartId = node.dataset.previewChartId || node.id || nextPreviewId('preview-chart');
            node.classList.add('preview-chart-clickable');
            node.setAttribute('title', 'Agrandir le graphique');

            if (node.querySelector('[data-preview-chart-trigger]')) return;

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'preview-chart-inline-btn';
            button.dataset.previewChartTrigger = '1';
            button.dataset.previewChartId = node.dataset.previewChartId;
            button.textContent = 'Apercu';
            node.appendChild(button);
        });
    }

    function refreshPreviewTargets() {
        injectTablePreviewButtons();
        injectChartPreviewButtons();
    }

    function scheduleRefreshPreviewTargets() {
        if (refreshTimer) {
            window.clearTimeout(refreshTimer);
        }

        refreshTimer = window.setTimeout(function () {
            refreshTimer = null;
            refreshPreviewTargets();
        }, 120);
    }

    function printPreview() {
        if (!ensureElements()) return;
        window.print();
    }

    document.addEventListener('click', function (event) {
        var closeTrigger = event.target.closest('[data-preview-close]');
        if (closeTrigger) {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.defaultPrevented) {
            return;
        }

        var fileTrigger = event.target.closest('[data-preview-file]');
        if (fileTrigger) {
            event.preventDefault();
            openFilePreview(fileTrigger);
            return;
        }

        var tableTrigger = event.target.closest('[data-preview-table-trigger]');
        if (tableTrigger) {
            event.preventDefault();
            var table = tableTrigger.dataset.previewTableId
                ? findTableByPreviewId(tableTrigger.dataset.previewTableId)
                : null;
            if (table) openTablePreview(table);
            return;
        }

        var chartTrigger = event.target.closest('[data-preview-chart-trigger]');
        if (chartTrigger) {
            event.preventDefault();
            var chart = chartTrigger.dataset.previewChartId
                ? findChartByPreviewId(chartTrigger.dataset.previewChartId)
                : chartTrigger.closest('.dashboard-chart-host, .dashboard-canvas, .dashboard-gauge-card');
            if (chart) openChartPreview(chart);
            return;
        }

        var clickedTable = event.target.closest('table.app-table.preview-table-clickable');
        if (clickedTable && !clickedTable.closest('#preview-modal') && !event.target.closest(interactiveSelector)) {
            event.preventDefault();
            openTablePreview(clickedTable);
            return;
        }

        var clickedChart = event.target.closest('.dashboard-chart-host.preview-chart-clickable, .dashboard-canvas.preview-chart-clickable, .dashboard-gauge-card.preview-chart-clickable');
        if (clickedChart && !clickedChart.closest('#preview-modal') && !event.target.closest(interactiveSelector)) {
            event.preventDefault();
            openChartPreview(clickedChart);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && ensureElements() && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    function initPreviewModal() {
        if (previewInitialized) return;
        previewInitialized = true;
        if (!ensureElements()) return;

        excelBtn.addEventListener('click', function () {
            if (activeTableRows.length > 0) {
                downloadTableAsExcel(activeTableTitle, activeTableRows);
            }
        });
        pdfBtn.addEventListener('click', printPreview);
        printBtn.addEventListener('click', printPreview);
        refreshPreviewTargets();

        if (window.MutationObserver) {
            new MutationObserver(scheduleRefreshPreviewTargets).observe(document.body, {
                childList: true,
                subtree: true,
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPreviewModal, { once: true });
    } else {
        initPreviewModal();
    }

    document.addEventListener('anbg:dashboard-assets-ready', scheduleRefreshPreviewTargets);
    document.addEventListener('anbg:dashboard-payload-ready', scheduleRefreshPreviewTargets);
    document.addEventListener('anbg:page-soft-refreshed', scheduleRefreshPreviewTargets);

    window.PasPreviewModal = {
        close: closeModal,
        openTable: openTablePreview,
        openChart: openChartPreview,
        refresh: refreshPreviewTargets,
        refreshTables: injectTablePreviewButtons,
        refreshCharts: injectChartPreviewButtons,
    };
})();
