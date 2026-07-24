function initActionDetailTabs() {
    var tabsList = document.querySelector('[data-action-detail-tabs]');
    var panels = Array.from(document.querySelectorAll('[data-action-tab-panel]'));

    if (!tabsList || panels.length === 0) {
        return;
    }

    var tabs = Array.from(tabsList.querySelectorAll('[role="tab"]'));
    var panelIds = new Set(panels.map(function (panel) {
        return panel.id;
    }));
    var aliases = {
        'action-suivi': 'action-validation',
        'action-status': 'action-validation',
        'action-controle': 'action-validation',
    };

    function resolvePanelId(hash) {
        var requestedId = String(hash || '').replace(/^#/, '');
        var resolvedId = aliases[requestedId] || requestedId;

        return panelIds.has(resolvedId) ? resolvedId : null;
    }

    function activatePanel(panelId, options) {
        var resolvedId = resolvePanelId(panelId);

        if (!resolvedId) {
            return;
        }

        tabs.forEach(function (tab) {
            var isActive = tab.getAttribute('aria-controls') === resolvedId;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        panels.forEach(function (panel) {
            var isActive = panel.id === resolvedId;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });

        if (options && options.focusPanel) {
            document.getElementById(resolvedId)?.focus({ preventScroll: true });
        }

        if (options && options.focusTab) {
            tabs.find(function (tab) {
                return tab.getAttribute('aria-controls') === resolvedId;
            })?.focus({ preventScroll: true });
        }
    }

    function activateFromLocation() {
        var errorPanel = panels.find(function (panel) {
            return panel.dataset.hasErrors === 'true'
                || panel.querySelector('.field-error, [aria-invalid="true"]');
        });
        var initialPanelId = errorPanel?.id
            || resolvePanelId(window.location.hash)
            || tabs[0]?.getAttribute('aria-controls');

        activatePanel(initialPanelId);
    }

    tabsList.addEventListener('click', function (event) {
        var tab = event.target.closest('[role="tab"]');

        if (!tab) {
            return;
        }

        event.preventDefault();
        var panelId = tab.getAttribute('aria-controls');
        activatePanel(panelId, { focusPanel: true });
        window.history.pushState({ actionTab: panelId }, '', '#'+panelId);
    });

    tabsList.addEventListener('keydown', function (event) {
        var currentIndex = tabs.indexOf(document.activeElement);

        if (currentIndex < 0) {
            return;
        }

        var nextIndex = currentIndex;
        if (event.key === 'ArrowRight') {
            nextIndex = (currentIndex + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        } else {
            return;
        }

        event.preventDefault();
        var panelId = tabs[nextIndex].getAttribute('aria-controls');
        activatePanel(panelId, { focusTab: true });
        window.history.pushState({ actionTab: panelId }, '', '#'+panelId);
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href^="#"]');

        if (!link || link.closest('[data-action-detail-tabs]')) {
            return;
        }

        var panelId = resolvePanelId(link.getAttribute('href'));
        if (panelId) {
            activatePanel(panelId);
        }
    });

    window.addEventListener('hashchange', activateFromLocation);
    window.addEventListener('popstate', activateFromLocation);
    activateFromLocation();
    document.body.setAttribute('data-action-tabs-ready', 'true');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initActionDetailTabs, { once: true });
} else {
    initActionDetailTabs();
}
