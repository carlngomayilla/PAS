(() => {
    if (window.__ANBG_PAGE_AUTO_REFRESH__) {
        return;
    }

    window.__ANBG_PAGE_AUTO_REFRESH__ = true;

    const seconds = Number(document.body?.dataset?.autoRefresh || 0);

    if (!Number.isFinite(seconds) || seconds < 1) {
        return;
    }

    // Plancher à 60s pour éviter tout flash perceptible sur écrans stables.
    // Le data-auto-refresh du layout reste utile pour désactiver (=0) ou pour
    // augmenter (ex: 120) mais on n'accepte plus de cadence agressive.
    const intervalMs = Math.max(seconds, 60) * 1000;

    const editableSelector = 'input, textarea, select, [contenteditable="true"]';
    const refreshRegionSelector = '[data-auto-refresh-region]';
    const skipRefreshAttribute = 'data-skip-refresh';

    let hasUnsavedInput = false;
    let refreshInFlight = false;
    let lastSnapshot = '';
    let lastScrollAt = 0;

    const markUnsavedInput = (event) => {
        if (event.target instanceof Element && event.target.matches(editableSelector)) {
            hasUnsavedInput = true;
        }
    };

    const markScrollActivity = () => {
        lastScrollAt = Date.now();
    };

    const clearUnsavedState = () => {
        hasUnsavedInput = false;
    };

    document.addEventListener('input', markUnsavedInput, true);
    document.addEventListener('change', markUnsavedInput, true);
    document.addEventListener('submit', markUnsavedInput, true);
    document.addEventListener('scroll', markScrollActivity, { passive: true, capture: true });
    window.addEventListener('scroll', markScrollActivity, { passive: true });

    const isEditing = () => {
        const activeElement = document.activeElement;

        return activeElement instanceof Element && activeElement.matches(editableSelector);
    };

    const hasOpenOverlay = () => Boolean(
        document.querySelector('#anbg-dialog:not(.hidden), #analytics-explorer:not(.hidden), dialog[open], [role="dialog"][aria-hidden="false"]'),
    );

    const hasBlockedPageContext = () => Boolean(
        document.querySelector([
            '[data-messaging-page]',
            '[data-disable-soft-refresh="1"]',
            '#anbg-dashboard-payload',
            '#analytics-explorer',
            '.dashboard-chart-host',
            '.dashboard-canvas',
            '.dashboard-gauge-card',
            '.chart-disclosure-panel',
            'canvas',
            'iframe',
            'video',
        ].join(', ')),
    );

    const isScrollingRecently = () => (Date.now() - lastScrollAt) < 1500;

    const canRefresh = () => {
        if (document.visibilityState !== 'visible') {
            return false;
        }

        if (refreshInFlight
            || hasUnsavedInput
            || isEditing()
            || hasOpenOverlay()
            || hasBlockedPageContext()
            || isScrollingRecently()) {
            return false;
        }

        return !window.location.search.includes('no_refresh=1');
    };

    const cloneNode = (node) => node.cloneNode(true);

    const nodeKey = (node) => {
        if (!(node instanceof Element)) {
            return '';
        }

        return node.getAttribute('id')
            || node.getAttribute('data-auto-refresh-key')
            || '';
    };

    const sameNodeFamily = (currentNode, nextNode) => {
        if (currentNode.nodeType !== nextNode.nodeType) {
            return false;
        }

        if (currentNode.nodeType === Node.TEXT_NODE) {
            return true;
        }

        if (!(currentNode instanceof Element) || !(nextNode instanceof Element)) {
            return false;
        }

        const currentKey = nodeKey(currentNode);
        const nextKey = nodeKey(nextNode);

        if (currentKey || nextKey) {
            return currentKey !== '' && currentKey === nextKey && currentNode.tagName === nextNode.tagName;
        }

        return currentNode.tagName === nextNode.tagName;
    };

    const syncAttributes = (currentElement, nextElement) => {
        [...currentElement.attributes].forEach((attribute) => {
            if (!nextElement.hasAttribute(attribute.name)) {
                currentElement.removeAttribute(attribute.name);
            }
        });

        [...nextElement.attributes].forEach((attribute) => {
            if (currentElement.getAttribute(attribute.name) !== attribute.value) {
                currentElement.setAttribute(attribute.name, attribute.value);
            }
        });
    };

    const syncFormState = (currentElement, nextElement) => {
        // Ne JAMAIS écraser la valeur du champ focus : l'utilisateur est en train de taper.
        if (currentElement === document.activeElement) {
            return;
        }

        if (currentElement instanceof HTMLInputElement && nextElement instanceof HTMLInputElement) {
            if (currentElement.type === 'checkbox' || currentElement.type === 'radio') {
                if (currentElement.checked !== nextElement.checked) {
                    currentElement.checked = nextElement.checked;
                }
            } else if (currentElement.value !== nextElement.value) {
                currentElement.value = nextElement.value;
            }
        }

        if (currentElement instanceof HTMLTextAreaElement && nextElement instanceof HTMLTextAreaElement) {
            if (currentElement.value !== nextElement.value) {
                currentElement.value = nextElement.value;
            }
        }

        if (currentElement instanceof HTMLSelectElement && nextElement instanceof HTMLSelectElement) {
            if (currentElement.value !== nextElement.value) {
                currentElement.value = nextElement.value;
            }
        }
    };

    const morphChildren = (currentElement, nextElement) => {
        const nextChildren = [...nextElement.childNodes];
        const length = Math.max(currentElement.childNodes.length, nextChildren.length);

        for (let index = 0; index < length; index += 1) {
            const currentChild = currentElement.childNodes[index];
            const nextChild = nextChildren[index];

            if (!currentChild && nextChild) {
                currentElement.appendChild(cloneNode(nextChild));
                continue;
            }

            if (currentChild && !nextChild) {
                currentChild.remove();
                continue;
            }

            if (!currentChild || !nextChild) {
                continue;
            }

            morphNode(currentChild, nextChild);
        }
    };

    const morphNode = (currentNode, nextNode) => {
        if (!sameNodeFamily(currentNode, nextNode)) {
            currentNode.replaceWith(cloneNode(nextNode));
            return;
        }

        if (currentNode.nodeType === Node.TEXT_NODE && nextNode.nodeType === Node.TEXT_NODE) {
            if (currentNode.textContent !== nextNode.textContent) {
                currentNode.textContent = nextNode.textContent;
            }
            return;
        }

        if (!(currentNode instanceof Element) || !(nextNode instanceof Element)) {
            return;
        }

        // Zone explicitement exclue du soft-refresh : charts, vidéos, iframes,
        // dashboards… Tout l'arbre est laissé intact.
        if (currentNode.hasAttribute(skipRefreshAttribute)) {
            return;
        }

        // Les <script type="application/json"> (ou ld+json, +json…) portent
        // des payloads de données inertes : on met à jour leur contenu sans
        // ré-évaluation. Les <script> exécutables sont laissés intacts pour
        // éviter toute réexécution silencieuse. Les <style> idem (idempotents).
        if (currentNode.tagName === 'SCRIPT') {
            const scriptType = (currentNode.getAttribute('type') || '').toLowerCase();
            const isDataScript = scriptType === 'application/json'
                || scriptType === 'application/ld+json'
                || scriptType.endsWith('+json')
                || scriptType === 'text/plain';

            if (isDataScript) {
                syncAttributes(currentNode, nextNode);
                if (currentNode.textContent !== nextNode.textContent) {
                    currentNode.textContent = nextNode.textContent;
                }
            }
            return;
        }

        if (currentNode.tagName === 'STYLE') {
            return;
        }

        syncAttributes(currentNode, nextNode);
        syncFormState(currentNode, nextNode);
        morphChildren(currentNode, nextNode);
    };

    const captureScrollState = () => ({
        windowX: window.scrollX,
        windowY: window.scrollY,
        scrollables: [...document.querySelectorAll('[data-preserve-scroll]')].map((node) => ({
            node,
            top: node.scrollTop,
            left: node.scrollLeft,
        })),
    });

    const restoreScrollState = (state) => {
        window.scrollTo(state.windowX, state.windowY);
        state.scrollables.forEach(({ node, top, left }) => {
            if (node.isConnected) {
                node.scrollTop = top;
                node.scrollLeft = left;
            }
        });
    };

    const restoreScrollStateAfterLayout = (state) => {
        restoreScrollState(state);

        window.requestAnimationFrame(() => {
            restoreScrollState(state);

            window.requestAnimationFrame(() => {
                restoreScrollState(state);
            });
        });

        window.setTimeout(() => restoreScrollState(state), 120);
    };

    const captureFocusState = () => {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement) || active === document.body) {
            return null;
        }

        const id = active.getAttribute('id');
        const name = active.getAttribute('name');
        if (!id && !name) {
            return null;
        }

        let selection = null;
        if (active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement) {
            try {
                selection = {
                    start: active.selectionStart,
                    end: active.selectionEnd,
                    direction: active.selectionDirection,
                };
            } catch (_error) {
                selection = null;
            }
        }

        return { id, name, selection };
    };

    const restoreFocusState = (state) => {
        if (!state) {
            return;
        }

        let target = null;
        if (state.id) {
            target = document.getElementById(state.id);
        }
        if (!target && state.name) {
            target = document.querySelector(`[name="${CSS.escape(state.name)}"]`);
        }

        if (!(target instanceof HTMLElement)) {
            return;
        }

        target.focus({ preventScroll: true });

        if (state.selection && (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) {
            try {
                target.setSelectionRange(state.selection.start, state.selection.end, state.selection.direction);
            } catch (_error) {
                // Type d'input non sélectionnable : ignorer.
            }
        }
    };

    const snapshotOf = (element) => element instanceof HTMLElement ? element.innerHTML : '';

    const swapMainContent = (nextDocument) => {
        const currentMain = document.querySelector(refreshRegionSelector);
        const nextMain = nextDocument.querySelector(refreshRegionSelector);

        if (!(currentMain instanceof HTMLElement) || !(nextMain instanceof HTMLElement)) {
            return { ok: false, changed: false };
        }

        const nextSnapshot = snapshotOf(nextMain);

        // Court-circuit principal : si le HTML est strictement identique au dernier
        // état morphé, on ne touche pas au DOM → aucun flash, aucun reboot de chart.
        if (nextSnapshot === lastSnapshot) {
            return { ok: true, changed: false };
        }

        const scrollState = captureScrollState();
        const focusState = captureFocusState();

        morphNode(currentMain, nextMain);

        restoreScrollStateAfterLayout(scrollState);
        restoreFocusState(focusState);

        lastSnapshot = snapshotOf(currentMain);

        if (nextDocument.title && nextDocument.title !== document.title) {
            document.title = nextDocument.title;
        }

        return { ok: true, changed: true };
    };

    const softRefresh = async () => {
        if (!canRefresh()) {
            return;
        }

        refreshInFlight = true;

        try {
            const response = await window.fetch(window.location.href, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-ANBG-Background-Refresh': '1',
                },
            });

            if (!response.ok) {
                return;
            }

            if (response.redirected && response.url) {
                // Session expirée ou redirection vers /login : on laisse le browser
                // naviguer normalement plutôt que de morpher un layout étranger.
                window.location.assign(response.url);
                return;
            }

            const html = await response.text();
            const parser = new DOMParser();
            const nextDocument = parser.parseFromString(html, 'text/html');

            const result = swapMainContent(nextDocument);

            if (!result.ok) {
                // Région manquante côté serveur : pas de fallback brutal — on retentera
                // au prochain tick. Un reload ferait flasher pour rien.
                return;
            }

            if (result.changed) {
                document.dispatchEvent(new CustomEvent('anbg:page-soft-refreshed'));
                window.dispatchEvent(new CustomEvent('anbg:theme-changed', {
                    detail: { source: 'soft-refresh' },
                }));
            }

            clearUnsavedState();
        } catch (_error) {
            // Le refresh silencieux reste opportuniste : on n'avertit pas l'utilisateur.
        } finally {
            refreshInFlight = false;
        }
    };

    // Snapshot initial : évite que le premier tick remorphe inutilement le DOM
    // alors que la page vient d'être rendue par le serveur.
    const initialMain = document.querySelector(refreshRegionSelector);
    if (initialMain instanceof HTMLElement) {
        lastSnapshot = snapshotOf(initialMain);
    }

    window.setInterval(() => {
        void softRefresh();
    }, intervalMs);
})();
