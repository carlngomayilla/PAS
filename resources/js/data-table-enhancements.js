(() => {
    'use strict';

    if (window.__ANBG_DATA_TABLE_ENHANCEMENTS__) {
        return;
    }

    window.__ANBG_DATA_TABLE_ENHANCEMENTS__ = true;

    const TABLE_SELECTOR = 'table[data-table-enhanced]';
    const PAGE_SIZES = [10, 25, 50, 100];
    const INTERACTIVE_SELECTOR = 'a, button, input, select, textarea, summary, [role="button"]';
    const controllers = new WeakMap();
    let generatedId = 0;

    const collator = new Intl.Collator(document.documentElement.lang || 'fr', {
        numeric: true,
        sensitivity: 'base',
    });

    const normalizeText = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLocaleLowerCase(document.documentElement.lang || 'fr');

    const parseNumber = (value) => {
        const rawValue = String(value ?? '').trim();

        if (rawValue === '' || !/^[+\-()]?[\d\s\u00a0\u202f.,]+\s*%?\)?$/.test(rawValue)) {
            return null;
        }

        const isNegative = rawValue.startsWith('(') && rawValue.endsWith(')');
        let normalizedValue = rawValue
            .replace(/[\s\u00a0\u202f%()]/g, '');
        const commaIndex = normalizedValue.lastIndexOf(',');
        const dotIndex = normalizedValue.lastIndexOf('.');

        if (commaIndex >= 0 && dotIndex >= 0) {
            const decimalSeparator = commaIndex > dotIndex ? ',' : '.';
            const thousandsSeparator = decimalSeparator === ',' ? /\./g : /,/g;
            normalizedValue = normalizedValue
                .replace(thousandsSeparator, '')
                .replace(decimalSeparator, '.');
        } else if (commaIndex >= 0) {
            normalizedValue = normalizedValue.replace(/,/g, '.');
        }

        const parsedValue = Number(normalizedValue);

        if (!Number.isFinite(parsedValue)) {
            return null;
        }

        return isNegative ? -parsedValue : parsedValue;
    };

    const parseDate = (value) => {
        const rawValue = String(value ?? '').trim();

        if (rawValue === '') {
            return null;
        }

        const frenchDate = rawValue.match(/^(\d{1,2})[\s/.-](\d{1,2})[\s/.-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/);

        if (frenchDate) {
            const [, day, month, year, hour = '0', minute = '0', second = '0'] = frenchDate;
            const timestamp = Date.UTC(
                Number(year),
                Number(month) - 1,
                Number(day),
                Number(hour),
                Number(minute),
                Number(second),
            );
            const parsedDate = new Date(timestamp);

            if (parsedDate.getUTCFullYear() === Number(year)
                && parsedDate.getUTCMonth() === Number(month) - 1
                && parsedDate.getUTCDate() === Number(day)) {
                return timestamp;
            }

            return null;
        }

        const timestamp = Date.parse(rawValue);

        return Number.isNaN(timestamp) ? null : timestamp;
    };

    const nextId = (prefix) => {
        let candidate;

        do {
            generatedId += 1;
            candidate = `${prefix}-${generatedId}`;
        } while (document.getElementById(candidate));

        return candidate;
    };

    const createElement = (tagName, className, textContent) => {
        const element = document.createElement(tagName);

        if (className) {
            element.className = className;
        }

        if (textContent !== undefined) {
            element.textContent = textContent;
        }

        return element;
    };

    class DataTableController {
        constructor(table) {
            this.table = table;
            this.tbody = null;
            this.rows = [];
            this.rowSlots = [];
            this.rowState = new WeakMap();
            this.sortHeaders = [];
            this.sortHeaderSignature = '';
            this.sortColumn = null;
            this.sortDirection = 'asc';
            this.query = '';
            this.currentPage = 1;
            this.pageSize = this.resolveInitialPageSize();
            this.emptyRow = null;
            this.refreshFrame = null;
            this.observer = null;
            this.headerCells = [];
            this.columnHeaders = [];
            this.columnDefinitions = [];
            this.columnFeatureSignature = '';
            this.columnCellState = new Map();
            this.spanningCellState = new Map();
            this.columnMenuRoot = null;
            this.columnMenuButton = null;
            this.columnMenuPanel = null;
            this.columnMenuDocumentHandler = null;
            this.activeResize = null;

            this.ensureTableId();

            if (!this.resolveStructure()) {
                return;
            }

            this.captureRows();
            this.configureSortHeaders();
            this.createControls();
            this.configureColumnFeatures();
            this.ensureEmptyRow();
            this.render();
            this.startObserving();

            table.dataset.tableEnhancedReady = 'true';
        }

        get isReady() {
            return Boolean(this.tbody && this.toolbar && this.pagination);
        }

        ensureTableId() {
            if (!this.table.id) {
                this.table.id = nextId('anbg-data-table');
            }
        }

        resolveInitialPageSize() {
            const requestedSize = Number.parseInt(this.table.dataset.tablePageSize || '', 10);

            return PAGE_SIZES.includes(requestedSize) ? requestedSize : PAGE_SIZES[0];
        }

        resolveStructure() {
            const tbody = this.table.tBodies.item(0);

            if (!(tbody instanceof HTMLTableSectionElement)) {
                return false;
            }

            if (this.tbody !== tbody) {
                this.tbody = tbody;
                this.emptyRow = null;
            }

            return true;
        }

        isStaticRow(row) {
            if (row.matches('[data-table-static], [data-table-empty], [data-table-row="static"]')) {
                return true;
            }

            if (row.matches('[data-table-row="data"]')) {
                return false;
            }

            const cells = Array.from(row.cells);

            return cells.length === 0
                || (cells.length === 1 && cells[0].colSpan > 1);
        }

        captureRows() {
            const bodyRows = Array.from(this.tbody.rows)
                .filter((row) => row !== this.emptyRow && !row.matches('[data-table-generated-row]'));

            this.rows = [];
            this.rowSlots = bodyRows.map((row) => {
                if (this.isStaticRow(row)) {
                    return row;
                }

                if (!this.rowState.has(row)) {
                    this.rowState.set(row, { initiallyHidden: row.hidden });
                }

                this.rows.push(row);

                return null;
            });
        }

        getTableName() {
            return this.table.dataset.tableName
                || this.table.getAttribute('aria-label')
                || this.table.caption?.textContent?.trim()
                || 'ce tableau';
        }

        createControls() {
            const tableName = this.getTableName();
            const toolbar = createElement('div', 'data-table-controls');
            toolbar.dataset.tableControlsFor = this.table.id;
            toolbar.setAttribute('aria-label', `Outils pour ${tableName}`);

            const searchGroup = createElement('div', 'data-table-controls__search');
            const searchId = nextId('data-table-search');
            const searchLabel = createElement('label', 'data-table-controls__label', 'Rechercher');
            searchLabel.htmlFor = searchId;

            this.searchInput = createElement('input', 'app-form-control data-table-search-input');
            this.searchInput.id = searchId;
            this.searchInput.type = 'search';
            this.searchInput.autocomplete = 'off';
            this.searchInput.spellcheck = false;
            this.searchInput.placeholder = this.table.dataset.tableSearchPlaceholder || 'Rechercher…';
            this.searchInput.setAttribute('aria-controls', this.table.id);
            this.searchInput.setAttribute('aria-label', `Rechercher dans ${tableName}`);
            this.searchInput.dataset.tableSearch = '';
            searchGroup.append(searchLabel, this.searchInput);

            const sizeGroup = createElement('label', 'data-table-controls__page-size');
            const sizeLabel = createElement('span', 'data-table-controls__label', 'Lignes par page');
            this.pageSizeSelect = createElement('select', 'app-form-control data-table-page-size-select');
            this.pageSizeSelect.setAttribute('aria-controls', this.table.id);
            this.pageSizeSelect.dataset.tablePageSizeSelect = '';

            PAGE_SIZES.forEach((pageSize) => {
                const option = createElement('option', '', String(pageSize));
                option.value = String(pageSize);
                option.selected = pageSize === this.pageSize;
                this.pageSizeSelect.appendChild(option);
            });

            sizeGroup.append(sizeLabel, this.pageSizeSelect);

            const resultsId = nextId('data-table-results');
            this.results = createElement('p', 'data-table-results-count');
            this.results.id = resultsId;
            this.results.dataset.tableResultsCount = '';
            this.results.setAttribute('role', 'status');
            this.results.setAttribute('aria-live', 'polite');
            this.results.setAttribute('aria-atomic', 'true');

            const describedBy = new Set((this.table.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            describedBy.add(resultsId);
            this.table.setAttribute('aria-describedby', Array.from(describedBy).join(' '));

            toolbar.append(searchGroup, sizeGroup, this.results);

            this.pagination = createElement('nav', 'data-table-pagination');
            this.pagination.dataset.tablePaginationFor = this.table.id;
            this.pagination.setAttribute('aria-label', `Pagination de ${tableName}`);

            const anchor = this.resolveInsertionAnchor();
            anchor.before(toolbar);
            anchor.after(this.pagination);
            this.toolbar = toolbar;

            let pendingSearchFrame = null;
            this.searchInput.addEventListener('input', () => {
                if (pendingSearchFrame !== null) {
                    window.cancelAnimationFrame(pendingSearchFrame);
                }

                pendingSearchFrame = window.requestAnimationFrame(() => {
                    pendingSearchFrame = null;
                    this.query = this.searchInput.value.trim();
                    this.currentPage = 1;
                    this.render();
                });
            });

            this.searchInput.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || this.searchInput.value === '') {
                    return;
                }

                event.preventDefault();
                this.searchInput.value = '';
                this.query = '';
                this.currentPage = 1;
                this.render();
            });

            this.pageSizeSelect.addEventListener('change', () => {
                const requestedSize = Number.parseInt(this.pageSizeSelect.value, 10);
                this.pageSize = PAGE_SIZES.includes(requestedSize) ? requestedSize : PAGE_SIZES[0];
                this.currentPage = 1;
                this.render();
            });

            this.pagination.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-table-page]');

                if (!(button instanceof HTMLButtonElement) || button.disabled) {
                    return;
                }

                const requestedPage = Number.parseInt(button.dataset.tablePage || '', 10);

                if (!Number.isFinite(requestedPage)) {
                    return;
                }

                this.currentPage = requestedPage;
                this.render();
                this.table.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        }

        resolveInsertionAnchor() {
            const wrapper = this.table.closest('[data-table-scroll], .app-table-wrapper, .table-wrap');

            if (wrapper && wrapper.querySelectorAll(TABLE_SELECTOR).length === 1) {
                return wrapper;
            }

            return this.table;
        }

        getHeaderRow() {
            const head = this.table.tHead;

            return head && head.rows.length > 0 ? head.rows.item(head.rows.length - 1) : null;
        }

        getHeaderLabel(header, fallbackIndex = 0) {
            if (header.dataset.columnLabel || header.dataset.sortLabel) {
                return header.dataset.columnLabel || header.dataset.sortLabel;
            }

            const clone = header.cloneNode(true);
            clone.querySelectorAll('[data-table-sort-button], [data-table-resize-handle]')
                .forEach((control) => control.remove());

            return clone.textContent.replace(/\s+/g, ' ').trim() || `Colonne ${fallbackIndex + 1}`;
        }

        getSimpleColumnStructure() {
            const head = this.table.tHead;

            if (!head || head.rows.length !== 1) {
                return null;
            }

            const headerRow = head.rows.item(0);
            const headers = Array.from(headerRow.cells);

            if (headers.length === 0 || headers.some((header) => (
                header.tagName !== 'TH'
                || header.colSpan !== 1
                || header.rowSpan !== 1
                || (header.hidden && !this.columnCellState.has(header))
            ))) {
                return null;
            }

            const columnCount = headers.length;
            const rows = [
                ...Array.from(this.table.tBodies).flatMap((tbody) => Array.from(tbody.rows)),
                ...(this.table.tFoot ? Array.from(this.table.tFoot.rows) : []),
            ];

            const hasComplexRow = rows.some((row) => {
                if (row === this.emptyRow || row.matches('[data-table-generated-row]')) {
                    return false;
                }

                const cells = Array.from(row.cells);

                if (cells.length === 0) {
                    return false;
                }

                if (cells.length === 1
                    && (cells[0].colSpan === columnCount || this.spanningCellState.get(cells[0]) === columnCount)
                    && cells[0].rowSpan === 1) {
                    return false;
                }

                return cells.length !== columnCount
                    || cells.some((cell) => cell.colSpan !== 1 || cell.rowSpan !== 1);
            });

            return hasComplexRow ? null : { columnCount, headers };
        }

        getColumnFeatureSignature(headers) {
            const tableSettings = [
                this.table.dataset.tableColumnVisibility || '',
                this.table.dataset.tableColumnResize || '',
                this.table.dataset.tableColumnMinWidth || '',
                this.table.dataset.tableColumnMaxWidth || '',
            ];
            const headerSettings = headers.map((header) => [
                header.dataset.columnHideable || '',
                header.dataset.columnResizable || '',
                header.dataset.columnMinWidth || '',
                header.dataset.columnMaxWidth || '',
                header.dataset.columnLabel || '',
            ].join(':'));

            return [...tableSettings, ...headerSettings].join('|');
        }

        configureColumnFeatures() {
            const structure = this.getSimpleColumnStructure();
            const visibilityEnabled = this.table.dataset.tableColumnVisibility !== 'false';
            const resizeEnabled = this.table.dataset.tableColumnResize !== 'false';

            if (!structure || (!visibilityEnabled && !resizeEnabled)) {
                this.clearColumnFeatures();
                return;
            }

            const signature = this.getColumnFeatureSignature(structure.headers);
            const sameHeaders = structure.headers.length === this.columnHeaders.length
                && structure.headers.every((header, index) => header === this.columnHeaders[index]);
            const menuExpected = visibilityEnabled
                && structure.headers.some((header, index) => (
                    index > 0 && header.dataset.columnHideable !== 'false'
                ));
            const menuIsIntact = !menuExpected || Boolean(this.columnMenuRoot?.isConnected);
            const resizeHandlesAreIntact = !resizeEnabled || this.columnDefinitions
                .filter((definition) => definition.resizable)
                .every((definition) => definition.resizeHandle?.isConnected);

            if (sameHeaders
                && signature === this.columnFeatureSignature
                && menuIsIntact
                && resizeHandlesAreIntact) {
                this.applyColumnVisibility();
                this.applyColumnWidths();
                return;
            }

            this.clearColumnFeatures();
            this.columnHeaders = structure.headers;
            this.columnFeatureSignature = signature;
            this.columnDefinitions = structure.headers.map((header, index) => {
                const minWidth = this.resolveColumnMinWidth(header);

                return {
                    header,
                    hideable: visibilityEnabled
                        && index > 0
                        && header.dataset.columnHideable !== 'false',
                    index,
                    label: this.getHeaderLabel(header, index),
                    maxWidth: this.resolveColumnMaxWidth(header, minWidth),
                    minWidth,
                    resizable: resizeEnabled && header.dataset.columnResizable !== 'false',
                    resizeHandle: null,
                    visible: true,
                    width: null,
                };
            });

            if (menuExpected) {
                this.createColumnMenu();
            }

            if (resizeEnabled) {
                this.columnDefinitions
                    .filter((definition) => definition.resizable)
                    .forEach((definition) => this.createResizeHandle(definition));
            }

            this.applyColumnVisibility();
            this.applyColumnWidths();
        }

        clearColumnFeatures() {
            this.endColumnResize();
            this.closeColumnMenu(false);
            this.columnMenuRoot?.remove();
            this.columnMenuRoot = null;
            this.columnMenuButton = null;
            this.columnMenuPanel = null;

            this.columnDefinitions.forEach((definition) => {
                definition.resizeHandle?.remove();
                definition.header.classList.remove('data-table-column-resizable');
            });

            this.columnCellState.forEach((state, cell) => {
                cell.hidden = state.hidden;
                cell.classList.toggle('data-table-column-hidden', state.hadHiddenClass);
                cell.style.width = state.width;
                cell.style.minWidth = state.minWidth;
            });

            this.spanningCellState.forEach((colSpan, cell) => {
                cell.colSpan = colSpan;
            });

            this.columnCellState.clear();
            this.spanningCellState.clear();
            this.columnHeaders = [];
            this.columnDefinitions = [];
            this.columnFeatureSignature = '';
            this.table.classList.remove('is-resizing-columns');
        }

        rememberColumnCellState(cell) {
            if (this.columnCellState.has(cell)) {
                return;
            }

            this.columnCellState.set(cell, {
                hadHiddenClass: cell.classList.contains('data-table-column-hidden'),
                hidden: cell.hidden,
                minWidth: cell.style.minWidth,
                width: cell.style.width,
            });
        }

        getColumnRows() {
            return [
                ...(this.table.tHead ? Array.from(this.table.tHead.rows) : []),
                ...Array.from(this.table.tBodies).flatMap((tbody) => Array.from(tbody.rows)),
                ...(this.table.tFoot ? Array.from(this.table.tFoot.rows) : []),
            ];
        }

        getVisibleColumnCount() {
            if (this.columnDefinitions.length === 0) {
                return this.getColumnCount();
            }

            return Math.max(1, this.columnDefinitions.filter((definition) => definition.visible).length);
        }

        applyColumnVisibility() {
            if (this.columnDefinitions.length === 0) {
                return;
            }

            this.columnCellState.forEach((_state, cell) => {
                if (!this.table.contains(cell)) {
                    this.columnCellState.delete(cell);
                }
            });
            this.spanningCellState.forEach((_colSpan, cell) => {
                if (!this.table.contains(cell)) {
                    this.spanningCellState.delete(cell);
                }
            });

            const visibleColumnCount = this.getVisibleColumnCount();

            this.getColumnRows().forEach((row) => {
                const cells = Array.from(row.cells);

                if (cells.length === 1
                    && (cells[0].colSpan > 1 || this.spanningCellState.has(cells[0]))) {
                    const cell = cells[0];

                    if (!this.spanningCellState.has(cell)) {
                        this.spanningCellState.set(cell, cell.colSpan);
                    }

                    cell.colSpan = visibleColumnCount;
                    return;
                }

                if (cells.length !== this.columnDefinitions.length) {
                    return;
                }

                this.columnDefinitions.forEach((definition) => {
                    const cell = cells[definition.index];
                    this.rememberColumnCellState(cell);
                    const originalState = this.columnCellState.get(cell);
                    cell.hidden = originalState.hidden || !definition.visible;
                    cell.classList.toggle(
                        'data-table-column-hidden',
                        originalState.hadHiddenClass || !definition.visible,
                    );
                });
            });
        }

        createColumnMenu() {
            const root = createElement('div', 'data-table-column-menu');
            const button = createElement('button', 'btn btn-secondary btn-sm data-table-column-menu-button', 'Colonnes');
            const panel = createElement('div', 'data-table-column-menu-panel');
            const panelId = nextId('data-table-column-menu');
            const headingId = nextId('data-table-column-menu-title');
            const heading = createElement('p', 'data-table-column-menu-title', 'Afficher les colonnes');

            root.dataset.tableColumnMenu = '';
            button.type = 'button';
            button.dataset.tableColumnMenuButton = '';
            button.setAttribute('aria-controls', panelId);
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-haspopup', 'true');
            panel.id = panelId;
            panel.hidden = true;
            panel.dataset.tableColumnMenuPanel = '';
            panel.setAttribute('role', 'group');
            panel.setAttribute('aria-labelledby', headingId);
            heading.id = headingId;
            panel.appendChild(heading);

            this.columnDefinitions.forEach((definition) => {
                const label = createElement('label', 'data-table-column-option');
                const checkbox = createElement('input', 'data-table-column-checkbox');
                const text = createElement('span', 'data-table-column-option-label', definition.label);
                checkbox.type = 'checkbox';
                checkbox.checked = definition.visible;
                checkbox.disabled = !definition.hideable;
                checkbox.dataset.tableColumnIndex = String(definition.index);
                checkbox.setAttribute('aria-controls', this.table.id);
                label.append(checkbox, text);

                if (!definition.hideable) {
                    const note = createElement(
                        'small',
                        'data-table-column-option-note',
                        definition.index === 0 ? 'Toujours visible' : 'Colonne fixe',
                    );
                    label.appendChild(note);
                }

                panel.appendChild(label);
            });

            root.append(button, panel);
            this.toolbar.insertBefore(root, this.results);
            this.columnMenuRoot = root;
            this.columnMenuButton = button;
            this.columnMenuPanel = panel;

            button.addEventListener('click', () => {
                this.toggleColumnMenu(panel.hidden);
            });

            button.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !panel.hidden) {
                    event.preventDefault();
                    this.closeColumnMenu(true);
                    return;
                }

                if (event.key !== 'ArrowDown') {
                    return;
                }

                event.preventDefault();
                this.toggleColumnMenu(true, true);
            });

            panel.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') {
                    return;
                }

                event.preventDefault();
                this.closeColumnMenu(true);
            });

            panel.addEventListener('change', (event) => {
                const checkbox = event.target.closest('input[data-table-column-index]');

                if (!(checkbox instanceof HTMLInputElement)) {
                    return;
                }

                const index = Number.parseInt(checkbox.dataset.tableColumnIndex || '', 10);
                const definition = this.columnDefinitions[index];

                if (!definition || !definition.hideable) {
                    checkbox.checked = Boolean(definition?.visible);
                    return;
                }

                const nextVisibleCount = this.columnDefinitions.filter((column) => (
                    column === definition ? checkbox.checked : column.visible
                )).length;

                if (nextVisibleCount < 1 || (definition.index === 0 && !checkbox.checked)) {
                    checkbox.checked = true;
                    return;
                }

                definition.visible = checkbox.checked;

                if (!definition.visible && this.sortColumn === definition.index) {
                    this.sortColumn = null;
                    this.sortDirection = 'asc';
                }

                this.applyColumnVisibility();
                this.render();
                this.dispatchColumnVisibilityChanged();
            });
        }

        toggleColumnMenu(open, focusFirst = false) {
            if (!this.columnMenuButton || !this.columnMenuPanel || !this.columnMenuRoot) {
                return;
            }

            if (!open) {
                this.closeColumnMenu(false);
                return;
            }

            if (!this.columnMenuPanel.hidden) {
                if (focusFirst) {
                    this.columnMenuPanel.querySelector('input:not(:disabled)')?.focus();
                }
                return;
            }

            this.columnMenuPanel.hidden = false;
            this.columnMenuButton.setAttribute('aria-expanded', 'true');
            this.columnMenuDocumentHandler = (event) => {
                if (!this.columnMenuRoot.contains(event.target)) {
                    this.closeColumnMenu(false);
                }
            };
            document.addEventListener('pointerdown', this.columnMenuDocumentHandler);

            if (focusFirst) {
                this.columnMenuPanel.querySelector('input:not(:disabled)')?.focus();
            }
        }

        closeColumnMenu(restoreFocus) {
            if (this.columnMenuDocumentHandler) {
                document.removeEventListener('pointerdown', this.columnMenuDocumentHandler);
                this.columnMenuDocumentHandler = null;
            }

            if (!this.columnMenuButton || !this.columnMenuPanel) {
                return;
            }

            const wasOpen = !this.columnMenuPanel.hidden;
            this.columnMenuPanel.hidden = true;
            this.columnMenuButton.setAttribute('aria-expanded', 'false');

            if (restoreFocus && wasOpen) {
                this.columnMenuButton.focus({ preventScroll: true });
            }
        }

        dispatchColumnVisibilityChanged() {
            const visibleColumns = this.columnDefinitions
                .filter((definition) => definition.visible)
                .map((definition) => ({ index: definition.index, label: definition.label }));
            const hiddenColumns = this.columnDefinitions
                .filter((definition) => !definition.visible)
                .map((definition) => ({ index: definition.index, label: definition.label }));

            this.table.dispatchEvent(new CustomEvent('anbg:data-table-columns-changed', {
                bubbles: true,
                detail: { hiddenColumns, visibleColumns },
            }));
        }

        resolveColumnMinWidth(header) {
            const requestedWidth = Number.parseFloat(
                header.dataset.columnMinWidth
                || this.table.dataset.tableColumnMinWidth
                || '',
            );

            return Number.isFinite(requestedWidth) ? Math.max(48, requestedWidth) : 96;
        }

        resolveColumnMaxWidth(header, minWidth) {
            const requestedWidth = Number.parseFloat(
                header.dataset.columnMaxWidth
                || this.table.dataset.tableColumnMaxWidth
                || '',
            );

            return Number.isFinite(requestedWidth)
                ? Math.max(minWidth, requestedWidth)
                : Math.max(minWidth, 4096);
        }

        resolveColumnWidth(definition) {
            if (Number.isFinite(definition.width)) {
                return definition.width;
            }

            const renderedWidth = definition.header.getBoundingClientRect().width;

            return Math.min(
                definition.maxWidth,
                Math.max(definition.minWidth, Math.round(renderedWidth || definition.minWidth)),
            );
        }

        createResizeHandle(definition) {
            const handle = createElement('span', 'data-table-resize-handle', '⋮');
            handle.tabIndex = 0;
            handle.dataset.tableResizeHandle = '';
            handle.dataset.tableColumnIndex = String(definition.index);
            handle.setAttribute('role', 'separator');
            handle.setAttribute('aria-orientation', 'vertical');
            handle.setAttribute('aria-controls', this.table.id);
            handle.setAttribute('aria-label', `Redimensionner la colonne « ${definition.label} »`);
            handle.setAttribute('aria-valuemin', String(definition.minWidth));
            handle.setAttribute('aria-valuemax', String(definition.maxWidth));
            definition.resizeHandle = handle;
            definition.header.classList.add('data-table-column-resizable');
            definition.header.appendChild(handle);
            this.updateResizeHandleValue(definition, this.resolveColumnWidth(definition));

            handle.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) {
                    return;
                }

                event.preventDefault();
                const startWidth = this.resolveColumnWidth(definition);
                this.activeResize = {
                    definition,
                    pointerId: event.pointerId,
                    startWidth,
                    startX: event.clientX,
                };
                this.table.classList.add('is-resizing-columns');
                handle.setPointerCapture?.(event.pointerId);
            });

            handle.addEventListener('pointermove', (event) => {
                if (!this.activeResize || this.activeResize.pointerId !== event.pointerId) {
                    return;
                }

                const width = this.activeResize.startWidth + event.clientX - this.activeResize.startX;
                this.setColumnWidth(definition, width, false);
            });

            const finishPointerResize = (event) => {
                if (!this.activeResize || this.activeResize.pointerId !== event.pointerId) {
                    return;
                }

                const resizedDefinition = this.activeResize.definition;
                this.endColumnResize();
                this.dispatchColumnResize(resizedDefinition);
            };

            handle.addEventListener('pointerup', finishPointerResize);
            handle.addEventListener('pointercancel', finishPointerResize);

            handle.addEventListener('keydown', (event) => {
                const step = event.shiftKey ? 32 : 8;
                let requestedWidth = null;

                if (event.key === 'ArrowLeft') {
                    requestedWidth = this.resolveColumnWidth(definition) - step;
                } else if (event.key === 'ArrowRight') {
                    requestedWidth = this.resolveColumnWidth(definition) + step;
                } else if (event.key === 'Home') {
                    requestedWidth = definition.minWidth;
                }

                if (requestedWidth === null) {
                    return;
                }

                event.preventDefault();
                this.setColumnWidth(definition, requestedWidth);
            });
        }

        getCellsForColumn(definition) {
            return this.getColumnRows().flatMap((row) => {
                const cells = Array.from(row.cells);

                return cells.length === this.columnDefinitions.length
                    ? [cells[definition.index]]
                    : [];
            });
        }

        setColumnWidth(definition, requestedWidth, dispatchEvent = true) {
            if (!Number.isFinite(requestedWidth)) {
                return;
            }

            const width = Math.min(
                definition.maxWidth,
                Math.max(definition.minWidth, Math.round(requestedWidth)),
            );
            definition.width = width;
            this.getCellsForColumn(definition).forEach((cell) => {
                this.rememberColumnCellState(cell);
                cell.style.width = `${width}px`;
                cell.style.minWidth = `${definition.minWidth}px`;
            });
            this.updateResizeHandleValue(definition, width);

            if (dispatchEvent) {
                this.dispatchColumnResize(definition);
            }
        }

        applyColumnWidths() {
            this.columnDefinitions.forEach((definition) => {
                if (Number.isFinite(definition.width)) {
                    this.setColumnWidth(definition, definition.width, false);
                }
            });
        }

        updateResizeHandleValue(definition, width) {
            if (!definition.resizeHandle) {
                return;
            }

            definition.resizeHandle.setAttribute('aria-valuenow', String(width));
            definition.resizeHandle.setAttribute('aria-valuetext', `${width} pixels`);
        }

        endColumnResize() {
            if (this.activeResize?.definition?.resizeHandle && this.activeResize.pointerId !== undefined) {
                const handle = this.activeResize.definition.resizeHandle;

                if (handle.hasPointerCapture?.(this.activeResize.pointerId)) {
                    handle.releasePointerCapture(this.activeResize.pointerId);
                }
            }

            this.activeResize = null;
            this.table.classList.remove('is-resizing-columns');
        }

        dispatchColumnResize(definition) {
            this.table.dispatchEvent(new CustomEvent('anbg:data-table-column-resized', {
                bubbles: true,
                detail: {
                    index: definition.index,
                    label: definition.label,
                    width: this.resolveColumnWidth(definition),
                },
            }));
        }

        clearSortHeaders() {
            this.sortHeaders.forEach((definition) => {
                definition.spacer.remove();
                definition.button.remove();
                definition.header.classList.remove('data-table-sortable');

                if (definition.originalAriaSort === null) {
                    definition.header.removeAttribute('aria-sort');
                } else {
                    definition.header.setAttribute('aria-sort', definition.originalAriaSort);
                }
            });

            this.sortHeaders = [];
        }

        configureSortHeaders() {
            const headerRow = this.getHeaderRow();
            const headerCells = headerRow ? Array.from(headerRow.cells) : [];
            const signature = [
                this.table.dataset.tableSort || '',
                ...headerCells.map((header, index) => [
                    header.dataset.sortable || '',
                    header.dataset.sortType || '',
                    header.dataset.sortColumn || '',
                    this.getHeaderLabel(header, index),
                ].join(':')),
            ].join('|');
            const sortButtonsAreIntact = this.sortHeaders.every((definition) => (
                definition.button.isConnected
                && definition.header.contains(definition.button)
            ));

            if (headerCells.length > 0
                && headerCells.length === this.headerCells.length
                && headerCells.every((header, index) => header === this.headerCells[index])
                && signature === this.sortHeaderSignature
                && sortButtonsAreIntact) {
                return;
            }

            this.clearSortHeaders();
            this.headerCells = headerCells;
            this.sortHeaderSignature = signature;

            if (!headerRow || this.table.dataset.tableSort === 'false') {
                this.sortColumn = null;
                return;
            }

            const hasComplexHeader = this.table.tHead.rows.length > 1;
            let columnCursor = 0;

            headerCells.forEach((header) => {
                const hasExplicitColumn = /^\d+$/.test(header.dataset.sortColumn || '');
                const columnIndex = hasExplicitColumn
                    ? Number(header.dataset.sortColumn)
                    : columnCursor;
                columnCursor += header.colSpan;

                const label = this.getHeaderLabel(header, columnIndex);
                const explicitlySortable = header.dataset.sortable === 'true';
                const normalizedLabel = normalizeText(label);
                const isActionsColumn = /^(action|actions|option|options|menu)$/.test(normalizedLabel);
                const hasForeignInteractiveControl = Array.from(header.querySelectorAll(INTERACTIVE_SELECTOR))
                    .some((control) => !control.matches('[data-table-sort-button], [data-table-resize-handle]'));
                const isSimple = header instanceof HTMLTableCellElement
                    && header.tagName === 'TH'
                    && header.colSpan === 1
                    && !header.matches('[aria-disabled="true"]')
                    && !hasForeignInteractiveControl
                    && label !== '';

                if (!isSimple
                    || header.dataset.sortable === 'false'
                    || (hasComplexHeader && !explicitlySortable)
                    || (isActionsColumn && !explicitlySortable)) {
                    return;
                }

                const spacer = document.createTextNode(' ');
                const button = createElement('button', 'data-table-sort-button');
                const indicator = createElement('span', 'data-table-sort-indicator', '↕');
                button.type = 'button';
                button.dataset.tableSortButton = '';
                button.setAttribute('aria-controls', this.table.id);
                indicator.setAttribute('aria-hidden', 'true');
                button.appendChild(indicator);

                const definition = {
                    button,
                    columnIndex,
                    header,
                    indicator,
                    label,
                    originalAriaSort: header.getAttribute('aria-sort'),
                    sortType: header.dataset.sortType || 'auto',
                    spacer,
                };

                header.classList.add('data-table-sortable');
                header.setAttribute('aria-sort', 'none');
                header.append(spacer, button);
                button.addEventListener('click', () => this.toggleSort(definition));
                this.sortHeaders.push(definition);
            });

            if (this.sortColumn !== null
                && !this.sortHeaders.some((definition) => definition.columnIndex === this.sortColumn)) {
                this.sortColumn = null;
                this.sortDirection = 'asc';
            }

            this.updateSortHeaders();
        }

        toggleSort(definition) {
            if (this.sortColumn === definition.columnIndex) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = definition.columnIndex;
                this.sortDirection = 'asc';
            }

            this.currentPage = 1;
            this.render();
        }

        updateSortHeaders() {
            this.sortHeaders.forEach((definition) => {
                const isCurrent = definition.columnIndex === this.sortColumn;
                const direction = isCurrent ? this.sortDirection : null;
                let buttonLabel = `Trier « ${definition.label} » par ordre croissant`;
                let indicator = '↕';

                if (direction === 'asc') {
                    buttonLabel = `Tri croissant sur « ${definition.label} ». Trier par ordre décroissant`;
                    indicator = '↑';
                } else if (direction === 'desc') {
                    buttonLabel = `Tri décroissant sur « ${definition.label} ». Trier par ordre croissant`;
                    indicator = '↓';
                }

                definition.header.setAttribute('aria-sort', direction === 'asc'
                    ? 'ascending'
                    : direction === 'desc' ? 'descending' : 'none');
                definition.button.setAttribute('aria-label', buttonLabel);
                definition.button.title = buttonLabel;
                definition.indicator.textContent = indicator;
            });
        }

        getCellAtColumn(row, columnIndex) {
            let cursor = 0;

            for (const cell of Array.from(row.cells)) {
                const nextCursor = cursor + cell.colSpan;

                if (columnIndex >= cursor && columnIndex < nextCursor) {
                    return cell;
                }

                cursor = nextCursor;
            }

            return null;
        }

        getSortValue(row, columnIndex) {
            const cell = this.getCellAtColumn(row, columnIndex);

            return cell ? (cell.dataset.sortValue ?? cell.textContent ?? '') : '';
        }

        resolveSortType(definition, rows) {
            const requestedType = definition.sortType.toLocaleLowerCase();

            if (requestedType === 'number' || requestedType === 'numeric') {
                return 'number';
            }

            if (requestedType === 'date' || requestedType === 'datetime') {
                return 'date';
            }

            if (requestedType === 'string' || requestedType === 'text') {
                return 'string';
            }

            const values = rows
                .map((row) => this.getSortValue(row, definition.columnIndex))
                .filter((value) => String(value).trim() !== '');

            return values.length > 0 && values.every((value) => parseNumber(value) !== null)
                ? 'number'
                : 'string';
        }

        sortRows(rows) {
            if (this.sortColumn === null) {
                return [...rows];
            }

            const definition = this.sortHeaders.find((header) => header.columnIndex === this.sortColumn);

            if (!definition) {
                return [...rows];
            }

            const sortType = this.resolveSortType(definition, rows);
            const indexedRows = rows.map((row, index) => ({ index, row }));

            indexedRows.sort((first, second) => {
                const firstRawValue = this.getSortValue(first.row, definition.columnIndex);
                const secondRawValue = this.getSortValue(second.row, definition.columnIndex);
                const firstIsEmpty = String(firstRawValue).trim() === '';
                const secondIsEmpty = String(secondRawValue).trim() === '';

                if (firstIsEmpty !== secondIsEmpty) {
                    return firstIsEmpty ? 1 : -1;
                }

                let comparison = 0;

                if (sortType === 'number') {
                    const firstNumber = parseNumber(firstRawValue);
                    const secondNumber = parseNumber(secondRawValue);

                    if (firstNumber === null || secondNumber === null) {
                        if (firstNumber === null && secondNumber !== null) {
                            return 1;
                        }

                        if (firstNumber !== null && secondNumber === null) {
                            return -1;
                        }

                        comparison = collator.compare(String(firstRawValue), String(secondRawValue));
                    } else {
                        comparison = firstNumber - secondNumber;
                    }
                } else if (sortType === 'date') {
                    const firstDate = parseDate(firstRawValue);
                    const secondDate = parseDate(secondRawValue);

                    if (firstDate === null || secondDate === null) {
                        if (firstDate === null && secondDate !== null) {
                            return 1;
                        }

                        if (firstDate !== null && secondDate === null) {
                            return -1;
                        }

                        comparison = collator.compare(String(firstRawValue), String(secondRawValue));
                    } else {
                        comparison = firstDate - secondDate;
                    }
                } else {
                    comparison = collator.compare(String(firstRawValue), String(secondRawValue));
                }

                if (comparison === 0) {
                    return first.index - second.index;
                }

                return this.sortDirection === 'desc' ? -comparison : comparison;
            });

            return indexedRows.map(({ row }) => row);
        }

        getSearchText(row) {
            if (row.dataset.searchValue !== undefined) {
                return normalizeText(row.dataset.searchValue);
            }

            return normalizeText(Array.from(row.cells)
                .filter((cell) => !cell.matches('[data-search-exclude], [data-table-search-exclude]'))
                .map((cell) => cell.dataset.searchValue ?? cell.textContent ?? '')
                .join(' '));
        }

        matchesQuery(row, queryTokens) {
            if (queryTokens.length === 0) {
                return true;
            }

            const searchText = this.getSearchText(row);

            return queryTokens.every((token) => searchText.includes(token));
        }

        ensureEmptyRow() {
            if (this.emptyRow?.isConnected) {
                return;
            }

            const row = document.createElement('tr');
            const cell = document.createElement('td');
            row.dataset.tableGeneratedRow = 'empty';
            row.className = 'data-table-no-results-row';
            row.hidden = true;
            cell.colSpan = this.getVisibleColumnCount();
            cell.className = 'data-table-no-results-cell';
            cell.textContent = this.table.dataset.tableNoResults
                || 'Aucun résultat ne correspond à votre recherche.';
            row.appendChild(cell);
            this.tbody.appendChild(row);
            this.emptyRow = row;
        }

        getColumnCount() {
            const headerRows = this.table.tHead ? Array.from(this.table.tHead.rows) : [];

            if (headerRows.length > 0) {
                return Math.max(1, ...headerRows.map((row) => Array.from(row.cells)
                    .reduce((total, cell) => total + cell.colSpan, 0)));
            }

            return Math.max(1, ...this.rows.map((row) => Array.from(row.cells)
                .reduce((total, cell) => total + cell.colSpan, 0)));
        }

        getLabels(count) {
            const singular = this.table.dataset.tableLabelSingular || 'résultat';
            const plural = this.table.dataset.tableLabelPlural || 'résultats';

            return count === 1 ? singular : plural;
        }

        updateResultsCount(totalCount, filteredCount, firstVisible, lastVisible) {
            let message;

            if (totalCount === 0) {
                message = `Aucun ${this.getLabels(1)}`;
            } else if (filteredCount === 0) {
                message = `Aucun ${this.getLabels(1)} sur ${totalCount}`;
            } else if (this.query !== '' && filteredCount !== totalCount) {
                const filteredSuffix = filteredCount === 1 ? 'filtré' : 'filtrés';
                message = `${firstVisible}–${lastVisible} sur ${filteredCount} ${this.getLabels(filteredCount)} ${filteredSuffix} (${totalCount} au total)`;
            } else {
                message = `${firstVisible}–${lastVisible} sur ${totalCount} ${this.getLabels(totalCount)}`;
            }

            if (this.results.textContent !== message) {
                this.results.textContent = message;
            }
        }

        getPageTokens(totalPages) {
            if (totalPages <= 7) {
                return Array.from({ length: totalPages }, (_, index) => index + 1);
            }

            const pages = new Set([
                1,
                totalPages,
                this.currentPage - 1,
                this.currentPage,
                this.currentPage + 1,
            ].filter((page) => page >= 1 && page <= totalPages));
            const sortedPages = Array.from(pages).sort((first, second) => first - second);
            const tokens = [];

            sortedPages.forEach((page, index) => {
                if (index > 0 && page - sortedPages[index - 1] > 1) {
                    tokens.push('ellipsis');
                }

                tokens.push(page);
            });

            return tokens;
        }

        createPageButton(label, page, options = {}) {
            const button = createElement('button', 'btn btn-secondary btn-sm data-table-page-button', label);
            button.type = 'button';
            button.dataset.tablePage = String(page);
            button.disabled = Boolean(options.disabled);
            button.setAttribute('aria-controls', this.table.id);
            button.setAttribute('aria-label', options.ariaLabel || label);

            if (options.current) {
                button.classList.add('is-current');
                button.setAttribute('aria-current', 'page');
            }

            return button;
        }

        updatePagination(totalPages) {
            this.pagination.replaceChildren();
            this.pagination.hidden = totalPages <= 1;

            if (totalPages <= 1) {
                return;
            }

            this.pagination.appendChild(this.createPageButton('Précédent', this.currentPage - 1, {
                ariaLabel: 'Page précédente',
                disabled: this.currentPage === 1,
            }));

            this.getPageTokens(totalPages).forEach((token) => {
                if (token === 'ellipsis') {
                    const ellipsis = createElement('span', 'data-table-page-ellipsis', '…');
                    ellipsis.setAttribute('aria-hidden', 'true');
                    this.pagination.appendChild(ellipsis);
                    return;
                }

                this.pagination.appendChild(this.createPageButton(String(token), token, {
                    ariaLabel: `Page ${token}`,
                    current: token === this.currentPage,
                }));
            });

            this.pagination.appendChild(this.createPageButton('Suivant', this.currentPage + 1, {
                ariaLabel: 'Page suivante',
                disabled: this.currentPage === totalPages,
            }));
        }

        render() {
            if (!this.isReady) {
                return;
            }

            let updateDetail = null;
            this.stopObserving();

            try {
                const activeRows = this.rows.filter((row) => !this.rowState.get(row)?.initiallyHidden);
                const queryTokens = normalizeText(this.query).split(' ').filter(Boolean);
                const orderedRows = this.sortRows(this.rows);
                const activeRowSet = new Set(activeRows);
                const matchingRows = orderedRows
                    .filter((row) => activeRowSet.has(row) && this.matchesQuery(row, queryTokens));
                const matchingRowSet = new Set(matchingRows);
                const totalPages = Math.ceil(matchingRows.length / this.pageSize);
                const maximumPage = Math.max(1, totalPages);
                this.currentPage = Math.min(Math.max(1, this.currentPage), maximumPage);

                const firstIndex = (this.currentPage - 1) * this.pageSize;
                const visibleRows = new Set(matchingRows.slice(firstIndex, firstIndex + this.pageSize));
                const fragment = document.createDocumentFragment();
                let dataRowIndex = 0;

                this.rowSlots.forEach((staticRow) => {
                    fragment.appendChild(staticRow || orderedRows[dataRowIndex++]);
                });

                this.tbody.appendChild(fragment);
                this.rows.forEach((row) => {
                    const state = this.rowState.get(row);
                    row.hidden = Boolean(state?.initiallyHidden) || !visibleRows.has(row);
                    row.dataset.tableFilterMatch = matchingRowSet.has(row) ? 'true' : 'false';
                    row.dataset.tablePageVisible = visibleRows.has(row) ? 'true' : 'false';
                });

                this.ensureEmptyRow();
                this.emptyRow.hidden = !(this.query !== '' && matchingRows.length === 0 && activeRows.length > 0);
                this.emptyRow.cells[0].colSpan = this.getVisibleColumnCount();
                this.tbody.appendChild(this.emptyRow);

                const firstVisible = matchingRows.length === 0 ? 0 : firstIndex + 1;
                const lastVisible = Math.min(firstIndex + this.pageSize, matchingRows.length);
                this.updateResultsCount(activeRows.length, matchingRows.length, firstVisible, lastVisible);
                this.updatePagination(totalPages);
                this.updateSortHeaders();
                updateDetail = {
                    filteredCount: matchingRows.length,
                    page: this.currentPage,
                    pageSize: this.pageSize,
                    query: this.query,
                    sort: this.sortColumn === null ? null : {
                        column: this.sortColumn,
                        direction: this.sortDirection,
                    },
                    totalCount: activeRows.length,
                    visibleCount: visibleRows.size,
                };
            } finally {
                this.startObserving();
            }

            if (updateDetail) {
                this.table.dispatchEvent(new CustomEvent('anbg:data-table-updated', {
                    bubbles: true,
                    detail: updateDetail,
                }));
            }
        }

        refresh() {
            this.stopObserving();

            try {
                if (!this.resolveStructure()) {
                    return;
                }

                this.captureRows();
                this.configureSortHeaders();
                this.configureColumnFeatures();
                this.ensureEmptyRow();
            } finally {
                this.startObserving();
            }

            this.render();
        }

        scheduleRefresh() {
            if (this.refreshFrame !== null) {
                return;
            }

            this.refreshFrame = window.requestAnimationFrame(() => {
                this.refreshFrame = null;

                if (this.table.isConnected) {
                    this.refresh();
                }
            });
        }

        startObserving() {
            if (!this.observer) {
                this.observer = new MutationObserver(() => this.scheduleRefresh());
            }

            this.observer.observe(this.table, {
                childList: true,
                characterData: true,
                subtree: true,
            });
        }

        stopObserving() {
            this.observer?.disconnect();
        }
    }

    const initializeTable = (table) => {
        if (!(table instanceof HTMLTableElement) || !table.matches(TABLE_SELECTOR) || controllers.has(table)) {
            return controllers.get(table) || null;
        }

        const controller = new DataTableController(table);

        if (!controller.isReady) {
            return null;
        }

        controllers.set(table, controller);

        return controller;
    };

    const initializeTables = (root = document) => {
        const tables = [];

        if (root instanceof Element && root.matches(TABLE_SELECTOR)) {
            tables.push(root);
        }

        if (root instanceof Document || root instanceof DocumentFragment || root instanceof Element) {
            tables.push(...root.querySelectorAll(TABLE_SELECTOR));
        }

        tables.forEach(initializeTable);

        return tables.length;
    };

    const resolveTables = (target) => {
        if (target instanceof HTMLTableElement) {
            return [target];
        }

        if (typeof target === 'string') {
            try {
                return Array.from(document.querySelectorAll(target)).filter((node) => node.matches(TABLE_SELECTOR));
            } catch (_error) {
                return [];
            }
        }

        return Array.from(document.querySelectorAll(TABLE_SELECTOR));
    };

    const start = () => {
        initializeTables(document);

        const documentObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node instanceof Element) {
                        initializeTables(node);

                        const ownerTable = node.closest(TABLE_SELECTOR);

                        if (ownerTable) {
                            initializeTable(ownerTable);
                        }
                    }
                });
            });
        });

        documentObserver.observe(document.documentElement, {
            childList: true,
            subtree: true,
        });
    };

    window.anbgDataTables = Object.freeze({
        init: (root = document) => initializeTables(root),
        refresh: (target) => resolveTables(target).reduce((count, table) => {
            const controller = initializeTable(table);

            if (!controller) {
                return count;
            }

            controller.refresh();

            return count + 1;
        }, 0),
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
