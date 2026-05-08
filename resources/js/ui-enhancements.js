/**
 * UI Enhancements — spinner, flash dismiss, search icons, pagination summary
 */
(function () {
    'use strict';

    // ── FORM SUBMIT SPINNER ──────────────────────────────────────────────
    // Finds the submit button, marks it as loading, disables form resubmit
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        // Skip forms handled by the confirm/prompt dialog
        if (form.dataset.confirmMessage || form.dataset.promptMessage) return;
        // Skip GET search forms
        if ((form.method || 'get').toLowerCase() === 'get') return;

        var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!submitBtn || submitBtn.dataset.loading) return;

        submitBtn.dataset.loading = '1';
        submitBtn.disabled = true;

        // Safety: restore after 10s in case of network error / redirect cancel
        setTimeout(function () {
            if (submitBtn.dataset.loading) {
                delete submitBtn.dataset.loading;
                submitBtn.disabled = false;
            }
        }, 10000);
    });

    // ── FLASH MESSAGE DISMISS ────────────────────────────────────────────
    function fadeRemove(el) {
        el.style.transition = 'opacity .2s ease, transform .2s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-4px)';
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
    }

    function initFlash() {
        document.querySelectorAll('.flash-dismiss').forEach(function (btn) {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', function () {
                var msg = btn.closest('.flash-success, .flash-error');
                if (msg) fadeRemove(msg);
            });
        });

        // Auto-dismiss success flashes after 5s
        document.querySelectorAll('.flash-success').forEach(function (msg) {
            if (msg.dataset.autoDismiss) return;
            msg.dataset.autoDismiss = '1';
            setTimeout(function () {
                if (document.contains(msg)) fadeRemove(msg);
            }, 5000);
        });
    }

    // ── SEARCH INPUT ICON ────────────────────────────────────────────────
    var searchSvg = '<svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';

    function initSearchIcons() {
        var selectors = [
            'input[type="search"]',
            'input[name="q"]',
            'input[id$="_q"]',
            'input[placeholder*="Recherch"]',
            'input[placeholder*="echerch"]',
            'input[placeholder*="Titre ou"]',
        ].join(', ');

        document.querySelectorAll(selectors).forEach(function (input) {
            if (input.closest('.search-field-wrapper')) return;
            if (input.closest('.admin-navbar-search')) return; // navbar search already has icon
            if (input.closest('.app-sidebar-search')) return; // sidebar search already has icon

            var wrapper = document.createElement('div');
            wrapper.className = 'search-field-wrapper';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            wrapper.insertAdjacentHTML('afterbegin', searchSvg);
        });
    }

    function initDeadLinks() {
        document.querySelectorAll('a[href="#"]').forEach(function (link) {
            if (link.dataset.deadLinkBound) return;
            link.dataset.deadLinkBound = '1';
            link.addEventListener('click', function (event) {
                event.preventDefault();
            });
        });
    }

    // ── PAGINATION SUMMARY ───────────────────────────────────────────────
    function initPaginationSummary() {
        document.querySelectorAll('section').forEach(function (section) {
            var pagination = section.querySelector('.pagination');
            if (!pagination) return;
            if (section.querySelector('.pagination-summary')) return;

            var rows = section.querySelectorAll('tbody tr');
            if (rows.length === 0) return;

            var summary = document.createElement('span');
            summary.className = 'pagination-summary';
            summary.textContent = rows.length + ' ligne(s) sur cette page';
            pagination.insertBefore(summary, pagination.firstChild);
        });
    }

    // ── TABLE ROW KEYBOARD NAV ───────────────────────────────────────────
    // Allows clicking a row to follow its first link (accessibility improvement)
    function initTableRowClick() {
        document.querySelectorAll('tbody tr').forEach(function (row) {
            if (row.dataset.rowClickBound) return;
            row.dataset.rowClickBound = '1';
            row.style.cursor = 'default';
        });
    }

    function initSidebarCollapse() {
        var sidebar = document.getElementById('admin-sidebar');
        var toggles = document.querySelectorAll('[data-sidebar-collapse-toggle]');
        if (!sidebar || toggles.length === 0) return;

        var storageKey = 'anbg:sidebar:collapsed';

        function isCollapsed() {
            return document.body.classList.contains('sidebar-collapsed');
        }

        function setCollapsed(collapsed) {
            document.body.classList.toggle('sidebar-collapsed', collapsed);
            toggles.forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                toggle.setAttribute('title', collapsed ? 'Agrandir le menu' : 'Reduire le menu');
            });

            try {
                window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
            } catch (error) {
                // Preference persistence is a progressive enhancement.
            }
        }

        if (!sidebar.dataset.collapseBound) {
            sidebar.dataset.collapseBound = '1';
            try {
                setCollapsed(window.localStorage.getItem(storageKey) === '1');
            } catch (error) {
                setCollapsed(false);
            }
        }

        toggles.forEach(function (toggle) {
            if (toggle.dataset.bound) return;
            toggle.dataset.bound = '1';
            toggle.addEventListener('click', function () {
                setCollapsed(!isCollapsed());
            });
        });
    }

    // ── INIT ─────────────────────────────────────────────────────────────
    function init() {
        initFlash();
        initSearchIcons();
        initPaginationSummary();
        initTableRowClick();
        initSidebarCollapse();
        initDeadLinks();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-run on Livewire/Turbo updates if ever added
    window.addEventListener('anbg:page-updated', init);
})();
