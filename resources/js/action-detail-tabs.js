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
    var groups = panels.reduce(function (result, panel) {
        var groupId = panel.dataset.actionTabGroup || panel.id;
        result[groupId] = result[groupId] || [];
        result[groupId].push(panel);

        return result;
    }, {});
    var aliases = {
        'action-suivi': 'action-validation',
        'action-status': 'action-validation',
        'action-controle': 'action-validation',
    };

    function resolveTarget(hash) {
        var requestedId = String(hash || '').replace(/^#/, '');
        var resolvedId = aliases[requestedId] || requestedId;

        if (groups[resolvedId]) {
            return { groupId: resolvedId, panelId: groups[resolvedId][0].id };
        }

        if (!panelIds.has(resolvedId)) {
            return null;
        }

        var panel = document.getElementById(resolvedId);
        var groupId = panel?.dataset.actionTabGroup || resolvedId;

        return { groupId: groupId, panelId: resolvedId };
    }

    function activatePanel(target, options) {
        var resolvedTarget = typeof target === 'string' ? resolveTarget(target) : target;

        if (!resolvedTarget) {
            return;
        }

        tabs.forEach(function (tab) {
            var isActive = tab.getAttribute('aria-controls') === resolvedTarget.groupId;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        panels.forEach(function (panel) {
            var isInActiveGroup = (panel.dataset.actionTabGroup || panel.id) === resolvedTarget.groupId;
            var isFocusedPanel = panel.id === resolvedTarget.panelId;
            panel.classList.toggle('is-active', isFocusedPanel);
            panel.hidden = !isInActiveGroup;
            panel.setAttribute('aria-hidden', isInActiveGroup ? 'false' : 'true');
        });

        if (options?.focusPanel) {
            document.getElementById(resolvedTarget.panelId)?.focus({ preventScroll: true });
        }

        if (options?.focusTab) {
            tabs.find(function (tab) {
                return tab.getAttribute('aria-controls') === resolvedTarget.groupId;
            })?.focus({ preventScroll: true });
        }
    }

    function activateFromLocation() {
        var errorPanel = panels.find(function (panel) {
            return panel.dataset.hasErrors === 'true'
                || panel.querySelector('.field-error, [aria-invalid="true"]');
        });
        var initialTarget = errorPanel
            ? resolveTarget(errorPanel.id)
            : resolveTarget(window.location.hash)
                || resolveTarget(tabs[0]?.getAttribute('aria-controls'));

        activatePanel(initialTarget);
    }

    tabsList.addEventListener('click', function (event) {
        var tab = event.target.closest('[role="tab"]');

        if (!tab) {
            return;
        }

        event.preventDefault();
        var target = resolveTarget(tab.getAttribute('aria-controls'));
        activatePanel(target, { focusPanel: true });
        document.getElementById(target?.panelId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.history.pushState({ actionTab: tab.getAttribute('aria-controls') }, '', '#'+tab.getAttribute('aria-controls'));
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
        var target = resolveTarget(tabs[nextIndex].getAttribute('aria-controls'));
        activatePanel(target, { focusTab: true });
        window.history.pushState({ actionTab: tabs[nextIndex].getAttribute('aria-controls') }, '', '#'+tabs[nextIndex].getAttribute('aria-controls'));
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href^="#"]');

        if (!link || link.closest('[data-action-detail-tabs]')) {
            return;
        }

        var target = resolveTarget(link.getAttribute('href'));
        if (target) {
            activatePanel(target);
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
