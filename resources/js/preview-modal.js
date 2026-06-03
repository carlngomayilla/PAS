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

function downloadTableAsExcel(title, rows) {
    var safeTitle = String(title || 'tableau').replace(/[^\w.-]+/g, '_').replace(/^_+|_+$/g, '') || 'tableau';
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

(function () {
    'use strict';

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

    function ensureElements() {
        modal = modal || document.getElementById('preview-modal');
        if (!modal) return false;

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
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('preview-modal-open');
        setTimeout(function () { bodyEl.focus({ preventScroll: true }); }, 0);
    }

    function closeModal() {
        if (!ensureElements()) return;

        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('preview-modal-open');
        bodyEl.innerHTML = '';
        activeTableRows = [];

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
        var section = table.closest('section, article, .showcase-panel, .ui-card');
        var heading = section ? section.querySelector('h1, h2, h3') : null;
        return heading ? heading.textContent.trim() : 'Apercu du tableau';
    }

    function openTablePreview(table) {
        activeTableRows = collectTableRows(table);
        activeTableTitle = table.dataset.previewTitle || nearestTitle(table);

        openBase({
            eyebrow: 'Tableau',
            title: activeTableTitle,
            subtitle: 'Apercu grand format du tableau affiche',
            body: rowsToHtmlTable(activeTableRows),
        });

        excelBtn.classList.remove('hidden');
        pdfBtn.classList.remove('hidden');
        printBtn.classList.remove('hidden');
    }

    function injectTablePreviewButtons() {
        document.querySelectorAll('table.app-table').forEach(function (table) {
            if (table.closest('#preview-modal')) return;
            if (table.dataset.previewReady === '1') return;
            table.dataset.previewReady = '1';

            var wrapper = table.closest('.app-table-wrapper') || table.parentElement;
            if (!wrapper || wrapper.querySelector('[data-preview-table-trigger]')) return;

            var toolbar = document.createElement('div');
            toolbar.className = 'preview-table-toolbar';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary btn-sm';
            button.dataset.previewTableTrigger = '1';
            button.textContent = 'Apercu';
            toolbar.appendChild(button);
            wrapper.parentNode.insertBefore(toolbar, wrapper);
        });
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

        var fileTrigger = event.target.closest('[data-preview-file]');
        if (fileTrigger) {
            event.preventDefault();
            openFilePreview(fileTrigger);
            return;
        }

        var tableTrigger = event.target.closest('[data-preview-table-trigger]');
        if (tableTrigger) {
            event.preventDefault();
            var wrapper = tableTrigger.closest('.preview-table-toolbar')?.nextElementSibling;
            var table = wrapper ? wrapper.querySelector('table.app-table') : null;
            if (table) openTablePreview(table);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && ensureElements() && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (!ensureElements()) return;

        excelBtn.addEventListener('click', function () {
            if (activeTableRows.length > 0) {
                downloadTableAsExcel(activeTableTitle, activeTableRows);
            }
        });
        pdfBtn.addEventListener('click', printPreview);
        printBtn.addEventListener('click', printPreview);
        injectTablePreviewButtons();
    });

    window.PasPreviewModal = {
        close: closeModal,
        refreshTables: injectTablePreviewButtons,
    };
})();
