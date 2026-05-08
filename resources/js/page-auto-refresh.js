(() => {
    if (window.__ANBG_PAGE_AUTO_REFRESH__) {
        return;
    }

    window.__ANBG_PAGE_AUTO_REFRESH__ = true;

    const seconds = Number(document.body?.dataset?.autoRefresh || 0);

    if (!Number.isFinite(seconds) || seconds < 1) {
        return;
    }

    const editableSelector = 'input, textarea, select, [contenteditable="true"]';
    const refreshRegionSelector = '[data-auto-refresh-region]';
    let hasUnsavedInput = false;
    let refreshInFlight = false;

    const markUnsavedInput = (event) => {
        if (event.target instanceof Element && event.target.matches(editableSelector)) {
            hasUnsavedInput = true;
        }
    };

    const clearUnsavedState = () => {
        hasUnsavedInput = false;
    };

    document.addEventListener('input', markUnsavedInput, true);
    document.addEventListener('change', markUnsavedInput, true);
    document.addEventListener('submit', markUnsavedInput, true);

    const isEditing = () => {
        const activeElement = document.activeElement;

        return activeElement instanceof Element && activeElement.matches(editableSelector);
    };

    const hasOpenOverlay = () => Boolean(
        document.querySelector('#anbg-dialog:not(.hidden), #analytics-explorer:not(.hidden), dialog[open], [role="dialog"][aria-hidden="false"]'),
    );

    const hasBlockedPageContext = () => Boolean(
        document.querySelector('[data-messaging-page], [data-disable-soft-refresh="1"]'),
    );

    const canRefresh = () => {
        if (document.visibilityState !== 'visible') {
            return false;
        }

        if (refreshInFlight || hasUnsavedInput || isEditing() || hasOpenOverlay() || hasBlockedPageContext()) {
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
        if (currentElement instanceof HTMLInputElement && nextElement instanceof HTMLInputElement) {
            if (currentElement.type === 'checkbox' || currentElement.type === 'radio') {
                currentElement.checked = nextElement.checked;
            } else {
                currentElement.value = nextElement.value;
            }
        }

        if (currentElement instanceof HTMLTextAreaElement && nextElement instanceof HTMLTextAreaElement) {
            currentElement.value = nextElement.value;
        }

        if (currentElement instanceof HTMLSelectElement && nextElement instanceof HTMLSelectElement) {
            currentElement.value = nextElement.value;
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

        if (currentNode.tagName === 'SCRIPT') {
            if (currentNode.textContent !== nextNode.textContent) {
                currentNode.textContent = nextNode.textContent;
            }

            syncAttributes(currentNode, nextNode);
            return;
        }

        syncAttributes(currentNode, nextNode);
        syncFormState(currentNode, nextNode);
        morphChildren(currentNode, nextNode);
    };

    const swapMainContent = (nextDocument) => {
        const currentMain = document.querySelector(refreshRegionSelector);
        const nextMain = nextDocument.querySelector(refreshRegionSelector);

        if (!(currentMain instanceof HTMLElement) || !(nextMain instanceof HTMLElement)) {
            return false;
        }

        morphNode(currentMain, nextMain);
        document.title = nextDocument.title || document.title;
        document.dispatchEvent(new CustomEvent('anbg:page-soft-refreshed'));
        window.dispatchEvent(new CustomEvent('anbg:theme-changed', {
            detail: { source: 'soft-refresh' },
        }));

        return true;
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
                window.location.assign(response.url);
                return;
            }

            const html = await response.text();
            const parser = new DOMParser();
            const nextDocument = parser.parseFromString(html, 'text/html');

            if (!swapMainContent(nextDocument)) {
                window.location.reload();
                return;
            }

            clearUnsavedState();
        } catch (_error) {
            // Le refresh silencieux reste opportuniste.
        } finally {
            refreshInFlight = false;
        }
    };

    window.setInterval(() => {
        void softRefresh();
    }, seconds * 1000);
})();
