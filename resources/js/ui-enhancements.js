import { gsap } from 'gsap';

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

    function tableHasRealData(table) {
        if (!table) return false;
        var bodyRows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        if (bodyRows.length === 0) return false;
        if (bodyRows.length === 1
            && bodyRows[0].querySelector('td[colspan]')
            && /aucun|aucune|vide|no data|pas de/i.test(bodyRows[0].textContent || '')) {
            return false;
        }
        return true;
    }

    var actionAccordionStoragePrefix = 'anbg:action-accordion:';

    function actionAccordionStorageKey(section) {
        if (!section || !section.id) return '';
        return actionAccordionStoragePrefix + window.location.pathname + ':' + section.id;
    }

    function readActionAccordionOpen(section, fallback) {
        var key = actionAccordionStorageKey(section);
        if (!key) return fallback;

        try {
            var value = window.localStorage.getItem(key);
            if (value === 'open') return true;
            if (value === 'closed') return false;
        } catch (_error) {
            return fallback;
        }

        return fallback;
    }

    function writeActionAccordionOpen(section, isOpen) {
        var key = actionAccordionStorageKey(section);
        if (!key) return;

        try {
            window.localStorage.setItem(key, isOpen ? 'open' : 'closed');
        } catch (_error) {
            // Optional UI preference only.
        }
    }

    function initEmptyTableSections() {
        document.querySelectorAll('.showcase-panel, .ui-card, .form-section, .data-table-shell').forEach(function (section) {
            if (section.dataset.keepEmpty === '1') return;
            var table = section.querySelector('table');
            if (!table) return;
            if (!tableHasRealData(table)) {
                section.classList.add('hidden');
            }
        });
    }

    function applyAccordion(section, startOpen) {
        if (section.dataset.accordionReady === '1') return;
        var initialOpen = readActionAccordionOpen(section, startOpen);

        var title = section.querySelector('.showcase-panel-title, .data-table-title, .showcase-kpi-card-title');
        if (!title) {
            var h = section.querySelector('h2, h3');
            if (!h) return;
            title = h;
        }

        // Find the direct child of section that contains the title
        var headerChild = title;
        while (headerChild.parentNode !== section) {
            headerChild = headerChild.parentNode;
            if (!headerChild || headerChild === document.body) return;
        }

        var content = document.createElement('div');
        content.className = 'action-accordion-content';
        while (headerChild.nextSibling) {
            content.appendChild(headerChild.nextSibling);
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'action-accordion-toggle';
        button.setAttribute('aria-expanded', initialOpen ? 'true' : 'false');
        button.innerHTML = '<span>' + title.textContent.trim() + '</span><span aria-hidden="true">▾</span>';

        headerChild.replaceWith(button);
        section.appendChild(content);
        section.classList.add('action-accordion-panel');
        section.classList.toggle('is-collapsed', !initialOpen);
        content.hidden = !initialOpen;

        button.addEventListener('click', function () {
            var isNowCollapsed = !section.classList.contains('is-collapsed');
            section.classList.toggle('is-collapsed', isNowCollapsed);
            content.hidden = isNowCollapsed;
            button.setAttribute('aria-expanded', isNowCollapsed ? 'false' : 'true');
            writeActionAccordionOpen(section, !isNowCollapsed);
        });

        section.dataset.accordionReady = '1';
    }

    function initActionAccordions() {
        var openByDefault = { 'action-validation': true, 'action-status': true };

        var selectors = [
            '#action-validation',
            '#action-fiche',
            '#action-financement',
            '#action-status',
            '#action-weeks',
            '#action-cloture',
            '#action-review-chef',
            '#action-review-direction',
            '#action-discussion',
            '#action-justificatifs',
            '#action-logs'
        ].join(', ');

        document.querySelectorAll(selectors).forEach(function (section) {
            var startOpen = openByDefault[section.id] === true;
            applyAccordion(section, startOpen);
        });
    }

    function initTableAccordions() {
        document.querySelectorAll('.showcase-panel, article.showcase-panel').forEach(function (section) {
            if (section.dataset.accordionReady === '1') return;
            if (section.classList.contains('hidden')) return;
            if (section.dataset.keepAccordion === '0') return;

            var table = section.querySelector('table');
            if (!table) return;

            var title = section.querySelector('.showcase-panel-title');
            if (!title) return;

            applyAccordion(section, true);
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

    // ── CLIENT-SIDE FORM VALIDATION ──────────────────────────────────────
    // Runs inline validation as the user types/blurs — server validation remains authoritative
    var VALIDATION_RULES = {
        required:  { test: function (v) { return v.trim() !== ''; },           msg: 'Ce champ est obligatoire.' },
        minlength: { test: function (v, p) { return v.trim().length >= parseInt(p, 10); }, msg: function (p) { return 'Minimum ' + p + ' caractères.'; } },
        maxlength: { test: function (v, p) { return v.trim().length <= parseInt(p, 10); }, msg: function (p) { return 'Maximum ' + p + ' caractères.'; } },
        min:       { test: function (v, p) { return parseFloat(v) >= parseFloat(p); }, msg: function (p) { return 'Valeur minimale : ' + p + '.'; } },
        max:       { test: function (v, p) { return parseFloat(v) <= parseFloat(p); }, msg: function (p) { return 'Valeur maximale : ' + p + '.'; } },
        pattern:   { test: function (v, p) { return new RegExp(p).test(v); },   msg: 'Format invalide.' },
    };

    function getFieldError(field) {
        var id = field.id || field.name;
        if (!id) return null;
        return field.form ? field.form.querySelector('.field-error[data-for="' + id + '"]') : null;
    }

    function showFieldError(field, msg) {
        var id = field.id || field.name;
        if (!id) return;
        var err = getFieldError(field);
        if (!err) {
            err = document.createElement('span');
            err.className = 'field-error';
            err.setAttribute('data-for', id);
            err.setAttribute('role', 'alert');
            err.setAttribute('aria-live', 'polite');
            field.parentNode.appendChild(err);
        }
        err.textContent = msg;
        field.setAttribute('aria-invalid', 'true');
        field.classList.add('field-invalid');
    }

    function clearFieldError(field) {
        var err = getFieldError(field);
        if (err) err.textContent = '';
        field.removeAttribute('aria-invalid');
        field.classList.remove('field-invalid');
        field.classList.toggle('field-valid', field.value.trim() !== '');
    }

    function validateField(field) {
        var value = field.value;
        if (field.required && !VALIDATION_RULES.required.test(value)) {
            showFieldError(field, VALIDATION_RULES.required.msg); return false;
        }
        if (field.hasAttribute('minlength')) {
            var min = field.getAttribute('minlength');
            if (value && !VALIDATION_RULES.minlength.test(value, min)) {
                showFieldError(field, VALIDATION_RULES.minlength.msg(min)); return false;
            }
        }
        if (field.hasAttribute('maxlength')) {
            var max = field.getAttribute('maxlength');
            if (value && !VALIDATION_RULES.maxlength.test(value, max)) {
                showFieldError(field, VALIDATION_RULES.maxlength.msg(max)); return false;
            }
        }
        if (field.type === 'number' && field.hasAttribute('min')) {
            var minN = field.getAttribute('min');
            if (value !== '' && !VALIDATION_RULES.min.test(value, minN)) {
                showFieldError(field, VALIDATION_RULES.min.msg(minN)); return false;
            }
        }
        if (field.type === 'number' && field.hasAttribute('max')) {
            var maxN = field.getAttribute('max');
            if (value !== '' && !VALIDATION_RULES.max.test(value, maxN)) {
                showFieldError(field, VALIDATION_RULES.max.msg(maxN)); return false;
            }
        }
        if (field.hasAttribute('pattern')) {
            var pat = field.getAttribute('pattern');
            if (value && !VALIDATION_RULES.pattern.test(value, pat)) {
                var customMsg = field.title || VALIDATION_RULES.pattern.msg;
                showFieldError(field, customMsg); return false;
            }
        }
        clearFieldError(field);
        return true;
    }

    function initClientValidation() {
        // Only apply to forms that don't already use server-only validation
        document.querySelectorAll('form[method]:not([data-no-client-validation])').forEach(function (form) {
            if (form.dataset.clientValidationBound) return;
            form.dataset.clientValidationBound = '1';

            var fields = form.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select');
            fields.forEach(function (field) {
                field.addEventListener('blur', function () { validateField(field); }, { passive: true });
                field.addEventListener('input', function () {
                    // Only re-validate if field already has an error shown
                    if (field.classList.contains('field-invalid')) validateField(field);
                }, { passive: true });
            });
        });
    }

    // ── TOAST SYSTEM ─────────────────────────────────────────────────────
    var TOAST_ICONS = {
        success: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        warning: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info:    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    };

    function getToastRegion() {
        var region = document.getElementById('anbg-toast-region');
        if (!region) {
            region = document.createElement('div');
            region.id = 'anbg-toast-region';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'false');
            document.body.appendChild(region);
        }
        return region;
    }

    function showToast(options, legacyTone, legacyDuration) {
        if (typeof options === 'string') {
            options = {
                message: options,
                tone: legacyTone,
                duration: legacyDuration,
            };
        }

        options = options || {};

        var tone    = options.tone    || 'info';
        var title   = options.title   || '';
        var message = options.message || '';
        var duration = options.duration !== undefined ? options.duration : 5000;

        var region = getToastRegion();

        var toast = document.createElement('div');
        toast.className = 'anbg-toast anbg-toast-' + tone + ' toast-entering';
        toast.setAttribute('role', 'alert');

        toast.innerHTML =
            '<div class="anbg-toast-icon">' + (TOAST_ICONS[tone] || TOAST_ICONS.info) + '</div>' +
            '<div class="anbg-toast-body">' +
                (title   ? '<p class="anbg-toast-title">'   + title   + '</p>' : '') +
                (message ? '<p class="anbg-toast-message">' + message + '</p>' : '') +
            '</div>' +
            '<button type="button" class="anbg-toast-close" aria-label="Fermer">✕</button>' +
            (duration > 0 ? '<div class="anbg-toast-progress" style="animation-duration:' + duration + 'ms;"></div>' : '');

        region.appendChild(toast);

        // Mirror to browser notification when tab is hidden
        sendBrowserNotif(
            title || (tone === 'error' ? 'Erreur' : tone === 'warning' ? 'Alerte' : 'ANBG PAS'),
            message,
            'anbg-toast-' + tone,
            null
        );

        // Animate in
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.classList.remove('toast-entering');
            });
        });

        function dismiss() {
            toast.classList.add('toast-leaving');
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 280);
        }

        toast.querySelector('.anbg-toast-close').addEventListener('click', dismiss);
        if (duration > 0) setTimeout(dismiss, duration);

        return { dismiss: dismiss };
    }

    // Convert existing flash-success / flash-error into toasts on load
    function flashToToast() {
        document.querySelectorAll('.flash-success, .flash-error').forEach(function (flash) {
            if (flash.dataset.toastMigrated) return;
            flash.dataset.toastMigrated = '1';

            var tone = flash.classList.contains('flash-success') ? 'success' : 'error';
            var text = flash.textContent.trim().replace(/\s+/g, ' ');
            if (!text) return;

            showToast({ tone: tone, message: text, duration: tone === 'success' ? 5000 : 8000 });
            // Hide original flash so both don't show
            flash.style.display = 'none';
        });
    }

    // ── MOBILE SIDEBAR TOGGLE ─────────────────────────────────────────────
    function initMobileSidebar() {
        var sidebar = document.getElementById('admin-sidebar');
        if (!sidebar) return;

        // Create hamburger button if viewport is mobile
        function checkMobile() {
            return window.innerWidth < 640;
        }

        // Close sidebar when clicking overlay (body::before)
        document.addEventListener('click', function (e) {
            if (!checkMobile()) return;
            if (!document.body.classList.contains('sidebar-mobile-open')) return;
            if (sidebar.contains(e.target)) return;
            document.body.classList.remove('sidebar-mobile-open');
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-mobile-open')) {
                document.body.classList.remove('sidebar-mobile-open');
            }
        });

        // Wire existing sidebar collapse toggles to also open on mobile
        document.querySelectorAll('[data-sidebar-collapse-toggle]').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                if (checkMobile()) {
                    document.body.classList.toggle('sidebar-mobile-open');
                }
            });
        });
    }

    // ── TABLE MOBILE DATA-LABELS ──────────────────────────────────────────
    // Stamp data-label attributes onto td elements so CSS can show them
    function initTableDataLabels() {
        document.querySelectorAll('.data-table-shell table, .showcase-panel table').forEach(function (table) {
            if (table.dataset.mobileLabeled) return;
            table.dataset.mobileLabeled = '1';

            var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
            var labels  = headers.map(function (th) { return th.textContent.trim(); });
            if (labels.length === 0) return;

            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.querySelectorAll('td').forEach(function (td, i) {
                    if (labels[i]) td.setAttribute('data-label', labels[i]);
                });
            });
        });
    }

    // ── BROWSER PUSH NOTIFICATIONS ──────────────────────────────────────
    var _notifGranted = false;

    function updateNotifButton() {
        var btn  = document.getElementById('admin-notif-toggle');
        var bell = document.getElementById('notif-icon-bell');
        var deny = document.getElementById('notif-icon-denied');
        if (!btn) return;
        var perm = ('Notification' in window) ? Notification.permission : 'denied';
        if (perm === 'denied') {
            if (bell) bell.classList.add('hidden');
            if (deny) deny.classList.remove('hidden');
            btn.title = 'Notifications bloquées par le navigateur';
        } else if (perm === 'granted') {
            _notifGranted = true;
            if (deny) deny.classList.add('hidden');
            if (bell) bell.classList.remove('hidden');
            btn.title = 'Notifications activées — cliquer pour désactiver';
            btn.style.color = 'var(--app-blue, #3996d3)';
        } else {
            _notifGranted = false;
            if (deny) deny.classList.add('hidden');
            if (bell) bell.classList.remove('hidden');
            btn.title = 'Activer les notifications navigateur';
            btn.style.color = '';
        }
    }

    function sendBrowserNotif(title, body, tag, url) {
        if (!_notifGranted || !('Notification' in window)) return;
        if (document.visibilityState === 'visible') return; // only when tab hidden
        var n = new Notification(title, {
            body: body || '',
            tag: tag || 'anbg-notif',
            icon: '/favicon.ico',
            badge: '/favicon.ico',
        });
        if (url) {
            n.onclick = function () { window.focus(); window.location.href = url; n.close(); };
        }
    }
    window.anbgNotify = sendBrowserNotif;

    function initBrowserNotifications() {
        if (!('Notification' in window)) return;
        updateNotifButton();

        var btn = document.getElementById('admin-notif-toggle');
        if (!btn) return;

        btn.addEventListener('click', function () {
            if (Notification.permission === 'denied') {
                if (window.anbgToast) window.anbgToast('Les notifications ont été bloquées. Modifiez les paramètres du navigateur pour les réactiver.', 'warning', 7000);
                return;
            }
            if (Notification.permission === 'granted') {
                // Toggle off: use sessionStorage flag so user can hide the button feedback
                _notifGranted = false;
                btn.style.color = '';
                btn.title = 'Activer les notifications navigateur';
                if (window.anbgToast) window.anbgToast('Notifications navigateur désactivées pour cette session.', 'info', 3000);
                return;
            }
            Notification.requestPermission().then(function (perm) {
                updateNotifButton();
                if (perm === 'granted') {
                    _notifGranted = true;
                    if (window.anbgToast) window.anbgToast('Notifications navigateur activées !', 'success', 3000);
                    new Notification('ANBG PAS', { body: 'Vous recevrez des alertes même quand cet onglet est en arrière-plan.', icon: '/favicon.ico' });
                } else {
                    if (window.anbgToast) window.anbgToast('Permission refusée — les notifications restent désactivées.', 'warning', 5000);
                }
            });
        });
    }

    // ── SPOTLIGHT SEARCH ─────────────────────────────────────────────────
    var _spotlightOpen = false;
    var _spotlightDebounce = null;
    var _spotlightActiveIdx = -1;

    function openSpotlight() {
        var backdrop = document.getElementById('spotlight-backdrop');
        var input    = document.getElementById('spotlight-input');
        if (!backdrop || _spotlightOpen) return;
        _spotlightOpen = true;
        backdrop.classList.add('spotlight-open');
        backdrop.setAttribute('aria-hidden', 'false');
        if (input) { input.value = ''; input.focus(); }
        renderSpotlightResults([]);
    }

    function closeSpotlight() {
        var backdrop = document.getElementById('spotlight-backdrop');
        if (!backdrop || !_spotlightOpen) return;
        _spotlightOpen = false;
        _spotlightActiveIdx = -1;
        backdrop.classList.remove('spotlight-open');
        backdrop.setAttribute('aria-hidden', 'true');
    }

    function renderSpotlightResults(groups) {
        var box = document.getElementById('spotlight-results');
        if (!box) return;
        var html = '';
        var hasAny = false;
        groups.forEach(function (group) {
            var items = group.items || [];
            if (!items.length) return;
            hasAny = true;
            html += '<div class="spotlight-group-label">' + group.title + '</div>';
            items.forEach(function (item) {
                html += '<a href="' + item.href + '" class="spotlight-item" role="option" tabindex="-1">'
                    + '<span class="spotlight-item-dot"></span>'
                    + '<span class="spotlight-item-body">'
                    +   '<span class="spotlight-item-title">' + item.title + '</span>'
                    +   '<span class="spotlight-item-sub">' + (item.subtitle || '') + (item.meta ? ' · ' + item.meta : '') + '</span>'
                    + '</span>'
                    + '<span class="spotlight-item-arrow">›</span>'
                    + '</a>';
            });
        });
        if (!hasAny) {
            html = '<div class="spotlight-empty">Aucun résultat trouvé.</div>';
        }
        box.innerHTML = html;
        _spotlightActiveIdx = -1;
    }

    function spotlightNavigate(dir) {
        var box = document.getElementById('spotlight-results');
        if (!box) return;
        var items = Array.from(box.querySelectorAll('.spotlight-item'));
        if (!items.length) return;
        items.forEach(function (el) { el.classList.remove('spotlight-active'); });
        _spotlightActiveIdx = Math.max(0, Math.min(items.length - 1, _spotlightActiveIdx + dir));
        items[_spotlightActiveIdx].classList.add('spotlight-active');
        items[_spotlightActiveIdx].scrollIntoView({ block: 'nearest' });
    }

    function initSpotlight() {
        var backdrop = document.getElementById('spotlight-backdrop');
        var input    = document.getElementById('spotlight-input');
        if (!backdrop || !input) return;

        // Close on backdrop click (outside panel)
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closeSpotlight();
        });

        // Keyboard navigation inside modal
        backdrop.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeSpotlight(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); spotlightNavigate(1); return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); spotlightNavigate(-1); return; }
            if (e.key === 'Enter') {
                var box = document.getElementById('spotlight-results');
                if (!box) return;
                var active = box.querySelector('.spotlight-active');
                if (active) { active.click(); closeSpotlight(); }
            }
        });

        // Debounced fetch on input
        input.addEventListener('input', function () {
            clearTimeout(_spotlightDebounce);
            var q = input.value.trim();
            if (q.length < 2) { renderSpotlightResults([]); return; }
            _spotlightDebounce = setTimeout(function () {
                fetch('/workspace/recherche?q=' + encodeURIComponent(q) + '&format=json', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                }).then(function (r) { return r.ok ? r.json() : null; })
                .then(function (json) {
                    if (json && json.groups) renderSpotlightResults(json.groups);
                }).catch(function () {});
            }, 280);
        });
    }

    // ── KEYBOARD SHORTCUTS ──────────────────────────────────────────────
    function initKeyboardShortcuts() {
        var gBuffer = '';
        var gTimer = null;

        document.addEventListener('keydown', function (e) {
            // Never fire inside inputs, textareas, selects, or contentEditable
            // Exception: allow Escape from inside the spotlight input
            var tag = (e.target.tagName || '').toUpperCase();
            var inInput = ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(tag) !== -1 || e.target.isContentEditable;

            // Ctrl+K / Cmd+K — open spotlight modal
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                if (_spotlightOpen) { closeSpotlight(); } else { openSpotlight(); }
                return;
            }

            // Escape closes spotlight from anywhere
            if (e.key === 'Escape' && _spotlightOpen) {
                closeSpotlight();
                return;
            }

            if (inInput) return;

            // Escape — close open dialogs / panels
            if (e.key === 'Escape') {
                var dialog = document.getElementById('anbg-dialog');
                if (dialog && !dialog.classList.contains('hidden')) {
                    var cancelBtn = dialog.querySelector('[data-dialog-cancel], .btn-cancel, button[type="button"]');
                    if (cancelBtn) { cancelBtn.click(); return; }
                }
                var explorer = document.getElementById('analytics-explorer');
                if (explorer && !explorer.classList.contains('hidden')) {
                    var closeBtn = explorer.querySelector('[data-close], .explorer-close');
                    if (closeBtn) { closeBtn.click(); return; }
                }
                return;
            }

            // G-key sequences (G then N = go create action, G then L = go to list)
            if (!e.ctrlKey && !e.metaKey && !e.altKey && e.key === 'g') {
                gBuffer = 'g';
                clearTimeout(gTimer);
                gTimer = setTimeout(function () { gBuffer = ''; }, 1500);
                return;
            }
            if (gBuffer === 'g' && !e.ctrlKey && !e.metaKey) {
                gBuffer = '';
                clearTimeout(gTimer);
                if (e.key === 'n') {
                    // Go to new action
                    var createLink = document.querySelector('a[href*="actions/create"]');
                    if (createLink) { window.location.href = createLink.href; }
                } else if (e.key === 'l') {
                    // Go to action list
                    var listLink = document.querySelector('a[href*="actions"][href*="index"], a[data-sidebar-link="actions"]');
                    if (listLink) { window.location.href = listLink.href; }
                } else if (e.key === 'd') {
                    // Go to dashboard
                    var dashLink = document.querySelector('a[href*="dashboard"]');
                    if (dashLink) { window.location.href = dashLink.href; }
                }
            }
        });
    }

    // ── MICRO-ANIMATIONS (GSAP) ──────────────────────────────────────────
    function initMicroAnimations() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        // Panels slide-up entrance
        var panels = Array.from(document.querySelectorAll(
            '.showcase-panel, .dashboard-card, .kanban-column'
        )).filter(function (el) { return !el.closest('[data-no-anim]'); });
        if (panels.length && panels.length <= 16) {
            gsap.from(panels, {
                opacity: 0,
                y: 16,
                duration: 0.42,
                stagger: 0.055,
                ease: 'power2.out',
                clearProps: 'all',
            });
        }

        // Progress bars wipe from left
        var bars = document.querySelectorAll('.showcase-progress-bar, .kanban-card-progress-bar');
        bars.forEach(function (bar) {
            var target = bar.style.width || '0%';
            bar.style.width = '0%';
            gsap.to(bar, { width: target, duration: 0.75, delay: 0.25, ease: 'power1.out' });
        });

        // Kanban cards stagger (in case they're not covered by the column animation)
        var kcards = document.querySelectorAll('.kanban-card');
        if (kcards.length && kcards.length <= 40) {
            gsap.from(kcards, {
                opacity: 0,
                scale: 0.96,
                duration: 0.28,
                stagger: 0.035,
                ease: 'power2.out',
                clearProps: 'all',
                delay: 0.1,
            });
        }

        // Stat cards pop-in
        var statCards = document.querySelectorAll('.stat-card-link, .showcase-inline-stat');
        if (statCards.length && statCards.length <= 12) {
            gsap.from(statCards, {
                opacity: 0,
                y: 10,
                scale: 0.97,
                duration: 0.32,
                stagger: 0.05,
                ease: 'back.out(1.4)',
                clearProps: 'all',
            });
        }
    }

    // ── INIT ─────────────────────────────────────────────────────────────
    function initDynamicDom() {
        initFlash();
        flashToToast();
        initClientValidation();
        initSearchIcons();
        initPaginationSummary();
        initTableRowClick();
        initEmptyTableSections();
        initActionAccordions();
        initTableAccordions();
        initTableDataLabels();
        initDeadLinks();
    }

    function init() {
        initDynamicDom();
        initSidebarCollapse();
        initMobileSidebar();
        initBrowserNotifications();
        initSpotlight();
        initKeyboardShortcuts();
        initMicroAnimations();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-run on Livewire/Turbo updates if ever added
    window.addEventListener('anbg:page-updated', initDynamicDom);
    document.addEventListener('anbg:page-soft-refreshed', initDynamicDom);

    // Expose toast API globally
    window.anbgToast = showToast;
})();
