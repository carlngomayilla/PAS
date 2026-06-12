(function () {
    var root = document.documentElement;
    var adminHeader = document.getElementById('admin-shell-header');
    var localClock = document.getElementById('admin-local-clock');
    var globalSearchInput = document.getElementById('dashboard-tabs-search');
    var themeToggle = document.getElementById('admin-theme-toggle');

    window.anbgToggleTheme = function () {
        var isDark = root.classList.contains('dark');
        if (isDark) {
            root.classList.remove('dark');
            root.setAttribute('data-theme', 'light');
            try { window.localStorage.setItem('theme', 'light'); } catch (e) {}
        } else {
            root.classList.add('dark');
            root.setAttribute('data-theme', 'dark');
            try { window.localStorage.setItem('theme', 'dark'); } catch (e) {}
        }
    };

    if (themeToggle) {
        themeToggle.addEventListener('click', window.anbgToggleTheme);
    }

    var backButton = document.getElementById('admin-back-button');
    if (backButton) {
        backButton.addEventListener('click', function () {
            var fallback = backButton.getAttribute('data-fallback-url') || '/';
            var sameOriginReferrer = false;
            try {
                sameOriginReferrer = !!document.referrer
                    && new URL(document.referrer).origin === window.location.origin;
            } catch (e) {
                sameOriginReferrer = false;
            }

            if (window.history.length > 1 && sameOriginReferrer) {
                window.history.back();
            } else {
                window.location.href = fallback;
            }
        });
    }

    function updateLocalClock() {
        if (!localClock) {
            return;
        }

        var now = new Date();
        localClock.textContent = new Intl.DateTimeFormat('fr-FR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).format(now);
    }

    updateLocalClock();
    if (localClock) {
        window.setInterval(updateLocalClock, 1000);
    }

    function unlockNotificationSound() {
        notificationSoundUnlocked = true;
        try {
            var AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass && !notificationAudioContext) {
                notificationAudioContext = new AudioContextClass();
            }
            if (notificationAudioContext && notificationAudioContext.state === 'suspended') {
                notificationAudioContext.resume().catch(function () {});
            }
        } catch (error) {
            notificationAudioContext = null;
        }
    }

    function playNotificationSound(kind) {
        if (!notificationSoundUnlocked) {
            return;
        }

        try {
            var AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }

            notificationAudioContext = notificationAudioContext || new AudioContextClass();
            if (notificationAudioContext.state === 'suspended') {
                notificationAudioContext.resume().catch(function () {});
            }

            var now = notificationAudioContext.currentTime;
            var frequencies = kind === 'message' ? [660, 880] : [520, 740, 980];

            frequencies.forEach(function (frequency, index) {
                var oscillator = notificationAudioContext.createOscillator();
                var gainNode = notificationAudioContext.createGain();
                var start = now + (index * 0.09);
                var end = start + 0.075;

                oscillator.type = kind === 'message' ? 'sine' : 'triangle';
                oscillator.frequency.setValueAtTime(frequency, start);
                gainNode.gain.setValueAtTime(0.0001, start);
                gainNode.gain.exponentialRampToValueAtTime(kind === 'message' ? 0.045 : 0.055, start + 0.018);
                gainNode.gain.exponentialRampToValueAtTime(0.0001, end);

                oscillator.connect(gainNode);
                gainNode.connect(notificationAudioContext.destination);
                oscillator.start(start);
                oscillator.stop(end + 0.02);
            });
        } catch (error) {
            // progressive enhancement: audio unavailable
        }
    }

    window.anbgPlayNotificationSound = playNotificationSound;
    window.addEventListener('pointerdown', unlockNotificationSound, { once: true, passive: true });
    window.addEventListener('keydown', unlockNotificationSound, { once: true });
    window.addEventListener('anbg:alert-received', function () {
        playNotificationSound('alert');
    });

    var lastScrollY = window.scrollY || 0;
    var headerScrollTicking = false;

    function syncHeaderVisibility() {
        headerScrollTicking = false;

        if (!adminHeader) {
            return;
        }

        var currentScrollY = window.scrollY || 0;
        var isScrollingDown = currentScrollY > lastScrollY;
        var shouldHide = isScrollingDown && currentScrollY > 120;

        adminHeader.classList.toggle('admin-navbar-hidden', shouldHide);
        lastScrollY = Math.max(currentScrollY, 0);
    }

    window.addEventListener('scroll', function () {
        if (headerScrollTicking) {
            return;
        }

        headerScrollTicking = true;
        window.requestAnimationFrame(syncHeaderVisibility);
    }, { passive: true });

    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || !globalSearchInput) {
            return;
        }

        var target = event.target;
        var isTyping = target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement
            || target instanceof HTMLSelectElement
            || (target && target.isContentEditable);

        if (isTyping) {
            return;
        }

        event.preventDefault();
        globalSearchInput.focus();
        globalSearchInput.select();
    });

    var sidebar = document.getElementById('admin-sidebar');
    var openButton = document.getElementById('admin-sidebar-open');
    var closeButton = document.getElementById('admin-sidebar-close');
    var overlay = document.getElementById('admin-overlay');
    var notificationsWrapper = document.getElementById('header-notifications');
    var notificationsToggle = document.getElementById('header-notifications-toggle');
    var notificationsMenu = document.getElementById('header-notifications-menu');
    var notificationsBadge = document.getElementById('header-notifications-badge');
    var notificationsAlertsEndpoint = notificationsMenu ? notificationsMenu.getAttribute('data-alerts-endpoint') : null;
    var notificationsAlertsSummary = document.getElementById('header-alerts-summary');
    var notificationsAlertsKpis = document.getElementById('header-alerts-kpi-summary');
    var notificationsAlertsItems = document.getElementById('header-alerts-items');
    var notificationsAlertsLoadedAt = 0;
    var notificationsAlertsPending = null;
    var previousAlertUnreadCount = Number(document.body.dataset.alertUnread || 0);
    var previousNotificationUnreadCount = Number(document.body.dataset.notificationUnread || 0);
    var notificationAudioContext = null;
    var notificationSoundUnlocked = false;
    var dialogRoot = document.getElementById('anbg-dialog');
    var dialogBackdrop = dialogRoot ? dialogRoot.querySelector('[data-dialog-dismiss]') : null;
    var dialogClose = document.getElementById('anbg-dialog-close');
    var dialogTitle = document.getElementById('anbg-dialog-title');
    var dialogEyebrow = document.getElementById('anbg-dialog-eyebrow');
    var dialogMessage = document.getElementById('anbg-dialog-message');
    var dialogInputWrap = document.getElementById('anbg-dialog-input-wrap');
    var dialogInputLabel = document.getElementById('anbg-dialog-input-label');
    var dialogInput = document.getElementById('anbg-dialog-input');
    var dialogError = document.getElementById('anbg-dialog-error');
    var dialogCancel = document.getElementById('anbg-dialog-cancel');
    var dialogConfirm = document.getElementById('anbg-dialog-confirm');
    var dialogResolver = null;
    var dialogLastFocused = null;
    var dialogState = null;

    function openSidebar() {
        if (!sidebar || !overlay) {
            return;
        }
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    }

    function closeSidebar() {
        if (!sidebar || !overlay) {
            return;
        }
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }

    if (openButton) {
        openButton.addEventListener('click', openSidebar);
    }
    if (closeButton) {
        closeButton.addEventListener('click', closeSidebar);
    }
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function notificationAlertTone(level) {
        if (level === 'urgence') {
            return 'anbg-badge anbg-badge-danger';
        }
        if (level === 'critical') {
            return 'anbg-badge anbg-badge-danger';
        }

        if (level === 'warning') {
            return 'anbg-badge anbg-badge-warning';
        }

        return 'anbg-badge anbg-badge-info';
    }

    function updateNotificationsBadge(notificationUnread, alertUnread) {
        previousNotificationUnreadCount = Math.max(0, Number(notificationUnread || 0));
        previousAlertUnreadCount = Math.max(0, Number(alertUnread || 0));

        if (!notificationsBadge) {
            return;
        }

        var total = previousNotificationUnreadCount + previousAlertUnreadCount;
        var kind = 'none';

        if (previousNotificationUnreadCount > 0 && previousAlertUnreadCount > 0) {
            kind = 'both';
        } else if (previousAlertUnreadCount > 0) {
            kind = 'alert';
        } else if (previousNotificationUnreadCount > 0) {
            kind = 'notification';
        }

        notificationsBadge.textContent = total > 99 ? '99+' : String(total);
        notificationsBadge.classList.toggle('hidden', total <= 0);
        notificationsBadge.classList.remove('bg-[#3996d3]', 'bg-[#f59e0b]', 'bg-[#7c3aed]');
        notificationsBadge.classList.add(kind === 'both' ? 'bg-[#7c3aed]' : (kind === 'alert' ? 'bg-[#f59e0b]' : 'bg-[#3996d3]'));
        notificationsBadge.dataset.notificationUnread = String(previousNotificationUnreadCount);
        notificationsBadge.dataset.alertUnread = String(previousAlertUnreadCount);
        notificationsBadge.dataset.badgeKind = kind;
        notificationsBadge.title = previousNotificationUnreadCount + ' notification(s), ' + previousAlertUnreadCount + ' alerte(s)';
    }

    function renderNavbarAlertSummary(payload) {
        if (!notificationsAlertsSummary) {
            return;
        }

        var summary = payload && payload.summary ? payload.summary : {};
        notificationsAlertsSummary.textContent = (summary.unread || 0) + ' non lue(s) sur ' + (summary.total || 0) + ' alerte(s).';

        if (notificationsBadge) {
            var unread = Number(summary.unread || 0);
            if (unread > previousAlertUnreadCount) {
                window.dispatchEvent(new CustomEvent('anbg:alert-received', {
                    detail: { unread: unread }
                }));
            }
            updateNotificationsBadge(previousNotificationUnreadCount, unread);
        }
    }

    function renderNavbarAlertKpis(payload) {
        if (!notificationsAlertsKpis) {
            return;
        }

        var summary = payload && payload.kpi_summary ? payload.kpi_summary : {};
        var cards = [
            ['Global', summary.global],
            ['Progression', summary.progression],
        ].filter(function (row) {
            return typeof row[1] !== 'undefined';
        });

        if (!cards.length) {
            notificationsAlertsKpis.classList.add('hidden');
            notificationsAlertsKpis.innerHTML = '';
            return;
        }

        notificationsAlertsKpis.classList.remove('hidden');
        notificationsAlertsKpis.innerHTML = cards.map(function (row) {
            var value = Number(row[1] || 0);
            var tone = value >= 80 ? 'success' : (value >= 60 ? 'warning' : 'danger');

            return '<span class="anbg-badge anbg-badge-' + tone + ' px-3 py-1 text-[11px]">' + escapeHtml(row[0]) + ' ' + escapeHtml(value.toFixed(0)) + '</span>';
        }).join('');
    }

    function renderNavbarAlertItems(payload) {
        if (!notificationsAlertsItems) {
            return;
        }

        var items = payload && Array.isArray(payload.items) ? payload.items : [];
        if (!items.length) {
            notificationsAlertsItems.innerHTML = '<div class="admin-dropdown-empty px-3 py-4 text-xs text-[#667085]">Aucune alerte recente.</div>';
            return;
        }

        notificationsAlertsItems.innerHTML = items.map(function (item) {
            var metrics = item.metrics || {};
            // KPI "Conformité" retire (2026-05-28) des chips metric inline.
            var metricChips = [
                ['Global', metrics.kpi_global],
                ['Performance', metrics.kpi_performance],
            ].filter(function (row) {
                return typeof row[1] !== 'undefined';
            }).map(function (row) {
                return '<span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-[10px]">' + escapeHtml(row[0]) + ' ' + escapeHtml(Number(row[1] || 0).toFixed(0)) + '</span>';
            }).join('');

            var escalation = item.escalation_label
                ? '<span class="anbg-badge anbg-badge-info px-2 py-0.5 text-[10px]">Escalade ' + escapeHtml(item.escalation_label) + '</span>'
                : '';

            return '<a href="' + escapeHtml(item.read_url || item.target_url || '#') + '" class="block border-b border-[#d8ecf8] px-3 py-2 transition last:border-b-0 hover:bg-[#eaf6fd] ' + (item.is_unread ? 'bg-[#eaf6fd]' : '') + '">' +
                '<div class="mb-1 flex items-start justify-between gap-2">' +
                    '<p class="text-sm font-semibold text-[#17324a]">' + escapeHtml(item.titre || 'Alerte') + '</p>' +
                    '<span class="' + notificationAlertTone(String(item.niveau || 'info')) + ' px-2 py-0.5 text-[10px]">' + escapeHtml(item.niveau_label || item.niveau || 'Info') + '</span>' +
                '</div>' +
                '<p class="text-xs text-[#667085]">' + escapeHtml(item.message || '') + '</p>' +
                '<div class="mt-2 flex flex-wrap items-center gap-2">' + metricChips + escalation + '</div>' +
                '<p class="mt-1 text-[11px] text-[#667085]">' + escapeHtml(item.date_label || '') + '</p>' +
            '</a>';
        }).join('');
    }

    function loadNavbarAlerts(forceRefresh) {
        if (!notificationsAlertsEndpoint || !notificationsAlertsItems) {
            return Promise.resolve();
        }

        var isFresh = notificationsAlertsLoadedAt > 0 && (Date.now() - notificationsAlertsLoadedAt) < 60000;
        if (!forceRefresh && isFresh) {
            return Promise.resolve();
        }

        if (notificationsAlertsPending) {
            return notificationsAlertsPending;
        }

        notificationsAlertsPending = window.fetch(notificationsAlertsEndpoint, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('alerts_dropdown_failed');
                }

                return response.json();
            })
            .then(function (payload) {
                notificationsAlertsLoadedAt = Date.now();
                renderNavbarAlertSummary(payload);
                renderNavbarAlertKpis(payload);
                renderNavbarAlertItems(payload);
            })
            .catch(function () {
                if (notificationsAlertsSummary) {
                    notificationsAlertsSummary.textContent = 'Impossible de charger les alertes pour le moment.';
                }
                if (notificationsAlertsKpis) {
                    notificationsAlertsKpis.classList.add('hidden');
                    notificationsAlertsKpis.innerHTML = '';
                }
                if (notificationsAlertsItems) {
                    notificationsAlertsItems.innerHTML = '<div class="admin-dropdown-empty px-3 py-4 text-xs text-[#667085]">Ouvrir le centre d\'alertes pour consulter le detail.</div>';
                }
            })
            .finally(function () {
                notificationsAlertsPending = null;
            });

        return notificationsAlertsPending;
    }

    if (notificationsAlertsEndpoint) {
        window.setInterval(function () {
            loadNavbarAlerts(true);
        }, 60000);
    }

    function openNotificationsMenu() {
        if (!notificationsMenu) {
            return;
        }
        notificationsMenu.classList.remove('hidden');
        loadNavbarAlerts(false);
    }

    function closeNotificationsMenu() {
        if (!notificationsMenu) {
            return;
        }
        notificationsMenu.classList.add('hidden');
    }

    function resetDialog() {
        if (!dialogRoot) {
            return;
        }

        dialogRoot.classList.add('hidden');
        dialogRoot.setAttribute('aria-hidden', 'true');

        if (dialogInputWrap) {
            dialogInputWrap.classList.add('hidden');
        }

        if (dialogInput) {
            dialogInput.value = '';
        }

        if (dialogError) {
            dialogError.textContent = '';
            dialogError.classList.add('hidden');
        }

        dialogState = null;

        if (dialogLastFocused && typeof dialogLastFocused.focus === 'function') {
            dialogLastFocused.focus();
        }
        dialogLastFocused = null;
    }

    function resolveDialog(payload) {
        var resolver = dialogResolver;
        dialogResolver = null;
        resetDialog();
        if (typeof resolver === 'function') {
            resolver(payload);
        }
    }

    function openDialog(options) {
        if (!dialogRoot || !dialogTitle || !dialogMessage || !dialogCancel || !dialogConfirm) {
            return Promise.resolve({ confirmed: false, value: null });
        }

        dialogLastFocused = document.activeElement;
        dialogState = options || {};

        dialogRoot.classList.remove('hidden');
        dialogRoot.setAttribute('aria-hidden', 'false');

        if (dialogEyebrow) {
            dialogEyebrow.textContent = dialogState.eyebrow || 'Confirmation';
        }

        dialogTitle.textContent = dialogState.title || 'Confirmer l\'action';
        dialogMessage.textContent = dialogState.message || '';
        dialogCancel.textContent = dialogState.cancelLabel || 'Annuler';
        dialogConfirm.textContent = dialogState.confirmLabel || 'Confirmer';
        dialogConfirm.dataset.tone = dialogState.tone || 'primary';

        if (dialogInputWrap && dialogInput && dialogInputLabel) {
            var hasPrompt = dialogState.mode === 'prompt';
            dialogInputWrap.classList.toggle('hidden', !hasPrompt);
            dialogInputLabel.textContent = dialogState.inputLabel || 'Valeur';
            dialogInput.placeholder = dialogState.inputPlaceholder || '';
            dialogInput.value = dialogState.initialValue || '';
        }

        if (dialogError) {
            dialogError.textContent = '';
            dialogError.classList.add('hidden');
        }

        window.requestAnimationFrame(function () {
            if (dialogState && dialogState.mode === 'prompt' && dialogInput) {
                dialogInput.focus();
                dialogInput.select();
            } else {
                dialogConfirm.focus();
            }
        });

        return new Promise(function (resolve) {
            dialogResolver = resolve;
        });
    }

    function submitDialogConfirm() {
        if (!dialogState) {
            resolveDialog({ confirmed: false, value: null });
            return;
        }

        if (dialogState.mode === 'prompt') {
            var value = dialogInput ? dialogInput.value.trim() : '';
            var minLength = Number(dialogState.minLength || 0);

            if (value.length < minLength) {
                if (dialogError) {
                    dialogError.textContent = 'Veuillez saisir au moins ' + minLength + ' caractères.';
                    dialogError.classList.remove('hidden');
                }
                if (dialogInput) {
                    dialogInput.focus();
                }
                return;
            }

            resolveDialog({ confirmed: true, value: value });
            return;
        }

        resolveDialog({ confirmed: true, value: null });
    }

    if (notificationsToggle) {
        notificationsToggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (!notificationsMenu) {
                return;
            }
            if (notificationsMenu.classList.contains('hidden')) {
                openNotificationsMenu();
            } else {
                closeNotificationsMenu();
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (!notificationsWrapper || !notificationsMenu) {
            return;
        }
        if (notificationsWrapper.contains(event.target)) {
            return;
        }
        closeNotificationsMenu();
    });

    if (dialogBackdrop) {
        dialogBackdrop.addEventListener('click', function () {
            resolveDialog({ confirmed: false, value: null });
        });
    }

    if (dialogClose) {
        dialogClose.addEventListener('click', function () {
            resolveDialog({ confirmed: false, value: null });
        });
    }

    if (dialogCancel) {
        dialogCancel.addEventListener('click', function () {
            resolveDialog({ confirmed: false, value: null });
        });
    }

    if (dialogConfirm) {
        dialogConfirm.addEventListener('click', submitDialogConfirm);
    }

    if (dialogInput) {
        dialogInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitDialogConfirm();
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var confirmMessage = form.dataset.confirmMessage;
        var promptMessage = form.dataset.promptMessage;

        if (!confirmMessage && !promptMessage) {
            return;
        }

        event.preventDefault();

        if (promptMessage) {
            openDialog({
                mode: 'prompt',
                eyebrow: form.dataset.promptTitle || 'Retour brouillon',
                title: form.dataset.promptTitle || 'Retour brouillon',
                message: promptMessage,
                tone: 'primary',
                confirmLabel: form.dataset.promptConfirm || 'Confirmer',
                cancelLabel: form.dataset.promptCancel || 'Annuler',
                inputLabel: form.dataset.promptLabel || 'Motif',
                inputPlaceholder: form.dataset.promptPlaceholder || '',
                minLength: Number(form.dataset.promptMinlength || 0),
            }).then(function (result) {
                if (!result.confirmed) {
                    return;
                }

                var targetName = form.dataset.promptTarget || 'motif_retour';
                var targetInput = form.querySelector('[name="' + targetName + '"]');
                if (targetInput) {
                    targetInput.value = result.value || '';
                }

                HTMLFormElement.prototype.submit.call(form);
            });

            return;
        }

        openDialog({
            mode: 'confirm',
            eyebrow: form.dataset.confirmTone === 'danger' ? 'Action sensible' : 'Confirmation',
            title: form.dataset.confirmTitle || 'Confirmer l\'action',
            message: confirmMessage,
            tone: form.dataset.confirmTone || 'primary',
            confirmLabel: form.dataset.confirmLabel || 'Confirmer',
            cancelLabel: form.dataset.confirmCancel || 'Annuler',
        }).then(function (result) {
            if (!result.confirmed) {
                return;
            }

            HTMLFormElement.prototype.submit.call(form);
        });
    }, true);

    window.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (dialogResolver) {
                resolveDialog({ confirmed: false, value: null });
            }
            closeSidebar();
            closeNotificationsMenu();
        }
    });

    function syncSidebarForViewport() {
        if (!sidebar || !overlay) {
            return;
        }

        if (window.matchMedia('(min-width: 1024px)').matches) {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.add('hidden');
            return;
        }

        if (!sidebar.classList.contains('-translate-x-full')) {
            overlay.classList.remove('hidden');
        }
    }

    syncSidebarForViewport();
    window.addEventListener('resize', syncSidebarForViewport);

})();
