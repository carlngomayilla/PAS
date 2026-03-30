const POLL_INTERVAL_MS = 4000;

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function parseIsoDate(value) {
    if (!value) {
        return null;
    }

    const timestamp = Date.parse(value);

    return Number.isNaN(timestamp) ? null : timestamp;
}

function updateSeenLabels(thread, otherLastReadAt) {
    const seenTimestamp = parseIsoDate(otherLastReadAt);
    if (!seenTimestamp) {
        return;
    }

    thread.querySelectorAll('[data-message-seen-label]').forEach((label) => {
        const message = label.closest('[data-sent-at]');
        const sentAt = parseIsoDate(message?.dataset.sentAt);

        if (sentAt && sentAt <= seenTimestamp) {
            label.textContent = 'Vu';
        }
    });
}

function renderAttachment(attachment) {
    if (!attachment) {
        return '';
    }

    return `
        <div class="mt-3">
            <a class="messaging-attachment-link" href="${attachment.download_url}">
                <span class="font-medium">${escapeHtml(attachment.name)}</span>
                <span class="text-xs opacity-80">${escapeHtml(attachment.size_label)}</span>
            </a>
        </div>
    `;
}

function renderMessage(message) {
    const article = document.createElement('article');
    article.className = `messaging-bubble ${message.is_mine ? 'is-mine' : 'is-theirs'}`;
    article.dataset.messageId = String(message.id);
    article.dataset.senderId = message.is_mine ? 'self' : 'other';
    article.dataset.sentAt = message.sent_at_iso ?? '';

    article.innerHTML = `
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold">${escapeHtml(message.sender_name)}</span>
                <span class="text-[11px] opacity-75">${escapeHtml(message.sent_at_label ?? '')}</span>
            </div>
            ${message.is_mine ? `<span class="text-[11px] font-medium opacity-80" data-message-seen-label>${message.is_seen ? 'Vu' : 'Envoye'}</span>` : ''}
        </div>
        ${message.body ? `<p class="mt-2 whitespace-pre-line text-sm leading-6">${escapeHtml(message.body)}</p>` : ''}
        ${renderAttachment(message.attachment)}
    `;

    return article;
}

function currentLastMessageId(thread) {
    const ids = [...thread.querySelectorAll('[data-message-id]')]
        .map((node) => Number.parseInt(node.dataset.messageId ?? '0', 10))
        .filter((id) => Number.isInteger(id) && id > 0);

    return ids.length > 0 ? Math.max(...ids) : 0;
}

function initMessagingRealtime() {
    const page = document.querySelector('[data-messaging-page]');
    const thread = document.querySelector('#conversation-thread[data-updates-url]');
    if (!page || !thread || !window.axios) {
        return;
    }

    let lastMessageId = currentLastMessageId(thread);
    let inflight = false;
    let timer = null;
    const currentUserId = Number.parseInt(thread.dataset.currentUserId ?? '0', 10);
    const channelName = thread.dataset.channelName ?? '';

    const schedule = (delay = POLL_INTERVAL_MS) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(run, delay);
    };

    const appendMessage = (message, shouldStickToBottom = true) => {
        if (!message || !message.id) {
            return;
        }

        if (thread.querySelector(`[data-message-id="${message.id}"]`)) {
            return;
        }

        thread.appendChild(renderMessage({
            ...message,
            is_mine: Number.parseInt(String(message.sender_id ?? 0), 10) === currentUserId,
            is_seen: Boolean(message.is_seen),
        }));

        lastMessageId = Math.max(lastMessageId, Number.parseInt(String(message.id), 10) || 0);

        if (shouldStickToBottom) {
            thread.scrollTop = thread.scrollHeight;
        }
    };

    const run = async () => {
        if (document.hidden) {
            schedule();
            return;
        }

        if (inflight) {
            schedule();
            return;
        }

        inflight = true;

        try {
            const distanceToBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight;
            const shouldStickToBottom = distanceToBottom < 96;
            const response = await window.axios.get(thread.dataset.updatesUrl, {
                params: {
                    after: lastMessageId,
                },
            });

            const payload = response.data ?? {};
            const messages = Array.isArray(payload.messages) ? payload.messages : [];

            messages.forEach((message) => {
                thread.appendChild(renderMessage(message));
            });

            if (messages.length > 0) {
                lastMessageId = Number.parseInt(String(payload.last_message_id ?? lastMessageId), 10) || lastMessageId;
            }

            updateSeenLabels(thread, payload.other_last_read_at);

            if (messages.length > 0 && shouldStickToBottom) {
                thread.scrollTop = thread.scrollHeight;
            }
        } catch (error) {
            // Swallow polling failures. The page remains functional via full refresh.
        } finally {
            inflight = false;
            schedule();
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            schedule(350);
        }
    });

    if (window.Echo && channelName) {
        window.Echo.private(channelName)
            .listen('.message.sent', (payload) => {
                const distanceToBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight;
                appendMessage(payload, distanceToBottom < 96);
            })
            .listen('.conversation.read', (payload) => {
                if (Number.parseInt(String(payload.reader_id ?? 0), 10) !== currentUserId) {
                    updateSeenLabels(thread, payload.last_read_at);
                }
            });

        return;
    }

    schedule(1200);
}

function initMessagingOrgTree() {
    const tree = document.querySelector('[data-org-tree]');
    const profileContent = document.querySelector('[data-messaging-profile-content]');
    const viewport = tree?.querySelector('[data-org-tree-viewport]');
    const quickSearchInput = tree?.querySelector('[data-org-quick-search]');
    const clearSearchButton = tree?.querySelector('[data-org-clear-search]');
    const searchCount = tree?.querySelector('[data-org-search-count]');
    const storageKey = 'anbg.messaging.orgTree.state';
    if (!tree) {
        return;
    }

    const readStoredState = () => {
        try {
            const raw = window.localStorage.getItem(storageKey);
            if (!raw) {
                return {};
            }

            const parsed = JSON.parse(raw);

            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const writeStoredState = (state) => {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(state));
        } catch (error) {
            // Ignore storage failures. The tree remains functional.
        }
    };

    const recenterNode = (node, behavior = 'smooth') => {
        if (!node || !viewport) {
            return;
        }

        node.scrollIntoView({
            block: 'center',
            inline: 'center',
            behavior,
        });
    };

    const setExpanded = (button, expanded) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        const branch = button.closest('[data-org-branch]');
        if (branch) {
            branch.classList.toggle('is-collapsed', !expanded);
        }
    };

    const activateSelectedNode = (selectedLink) => {
        tree.querySelectorAll('[data-org-user-node]').forEach((node) => {
            node.dataset.orgSelected = '0';
            node.classList.remove('is-selected');
        });

        if (selectedLink) {
            selectedLink.dataset.orgSelected = '1';
            selectedLink.classList.add('is-selected');
        }
    };

    const syncSearchControls = (rawTerm = '') => {
        const hasValue = rawTerm.trim() !== '';

        if (clearSearchButton) {
            clearSearchButton.disabled = !hasValue;
            clearSearchButton.classList.toggle('is-active', hasValue);
        }
    };

    const restoreHighlightText = (element) => {
        if (!element || element.dataset.orgHighlightOriginal === undefined) {
            return;
        }

        element.textContent = element.dataset.orgHighlightOriginal;
    };

    const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const applyHighlightText = (element, term) => {
        if (!element) {
            return;
        }

        if (element.dataset.orgHighlightOriginal === undefined) {
            element.dataset.orgHighlightOriginal = element.textContent ?? '';
        }

        const source = element.dataset.orgHighlightOriginal ?? '';
        if (!term) {
            element.textContent = source;
            return;
        }

        const pattern = new RegExp(`(${escapeRegExp(term)})`, 'ig');
        element.innerHTML = escapeHtml(source).replace(pattern, '<mark class="messaging-org-tree-highlight">$1</mark>');
    };

    tree.querySelector('[data-org-recenter]')?.addEventListener('click', () => {
        recenterNode(tree.querySelector('[data-org-selected="1"]'));
    });

    const branchButtons = [...tree.querySelectorAll('[data-org-branch-toggle]')];
    const treeItems = [...tree.querySelectorAll('[data-org-tree-item]')];
    const countVisibleProfiles = () => treeItems.filter((item) => {
        const isVisible = !item.classList.contains('is-search-hidden');
        const hasProfileNode = item.querySelector(':scope > [data-org-user-node]') !== null;

        return isVisible && hasProfileNode;
    }).length;
    const storedState = readStoredState();
    const applyStoredBranchState = () => {
        const latestState = readStoredState();

        branchButtons.forEach((button) => {
            const branchId = button.getAttribute('aria-controls') ?? '';
            const defaultExpanded = button.dataset.defaultExpanded === 'true';
            const storedExpanded = typeof latestState.branches?.[branchId] === 'boolean'
                ? latestState.branches[branchId]
                : defaultExpanded;

            setExpanded(button, storedExpanded);
        });
    };

    const persistTreeState = () => {
        const branches = {};

        branchButtons.forEach((button) => {
            const branchId = button.getAttribute('aria-controls');
            if (!branchId) {
                return;
            }

            branches[branchId] = button.getAttribute('aria-expanded') === 'true';
        });

        writeStoredState({
            branches,
            scrollTop: viewport ? viewport.scrollTop : 0,
            scrollLeft: viewport ? viewport.scrollLeft : 0,
        });
    };

    const restoreViewportPosition = () => {
        if (!viewport) {
            return false;
        }

        const nextTop = Number.parseFloat(String(storedState.scrollTop ?? ''));
        const nextLeft = Number.parseFloat(String(storedState.scrollLeft ?? ''));
        const hasStoredTop = Number.isFinite(nextTop) && nextTop > 0;
        const hasStoredLeft = Number.isFinite(nextLeft) && nextLeft > 0;

        if (!hasStoredTop && !hasStoredLeft) {
            return false;
        }

        viewport.scrollTo({
            top: hasStoredTop ? nextTop : 0,
            left: hasStoredLeft ? nextLeft : 0,
            behavior: 'auto',
        });

        return true;
    };

    branchButtons.forEach((button) => {
        const branchId = button.getAttribute('aria-controls') ?? '';
        const defaultExpanded = button.getAttribute('aria-expanded') === 'true';
        button.dataset.defaultExpanded = defaultExpanded ? 'true' : 'false';
        const storedExpanded = typeof storedState.branches?.[branchId] === 'boolean'
            ? storedState.branches[branchId]
            : defaultExpanded;

        setExpanded(button, storedExpanded);

        button.addEventListener('click', () => {
            setExpanded(button, button.getAttribute('aria-expanded') !== 'true');
            persistTreeState();
        });
    });

    const setItemVisibility = (item, visible) => {
        item.classList.toggle('is-search-hidden', !visible);
    };

    const clearTreeSearch = () => {
        treeItems.forEach((item) => {
            item.classList.remove('is-search-match');
            setItemVisibility(item, true);
            item.querySelectorAll('[data-org-highlight-source]').forEach((element) => {
                restoreHighlightText(element);
            });
        });

        applyStoredBranchState();

        if (searchCount) {
            searchCount.textContent = `${countVisibleProfiles()} profil(s) visible(s)`;
        }

        syncSearchControls('');
    };

    const applyTreeSearch = (rawTerm) => {
        const term = rawTerm.trim().toLowerCase();

        if (!term) {
            clearTreeSearch();
            return;
        }

        syncSearchControls(rawTerm);

        const recurse = (item, ancestorMatched = false) => {
            const searchText = item.dataset.orgSearchText ?? '';
            const selfMatches = searchText.includes(term);
            const keepSubtree = ancestorMatched || selfMatches;
            const childItems = [...item.querySelectorAll(':scope > .messaging-org-tree-children > .messaging-org-tree-list > .messaging-org-tree-item')];
            let descendantVisible = false;

            childItems.forEach((child) => {
                const childVisible = recurse(child, keepSubtree);
                descendantVisible = descendantVisible || childVisible;
            });

            const visible = keepSubtree || descendantVisible;
            setItemVisibility(item, visible);
            item.classList.toggle('is-search-match', selfMatches);

            item.querySelectorAll('[data-org-highlight-source]').forEach((element) => {
                applyHighlightText(element, selfMatches ? rawTerm.trim() : '');
            });

            const toggle = item.querySelector(':scope > .messaging-org-tree-branch [data-org-branch-toggle]');
            if (toggle) {
                setExpanded(toggle, visible && (keepSubtree || descendantVisible));
            }

            return visible;
        };

        const rootList = tree.querySelector('[data-org-tree-stage] > .messaging-org-tree-list');
        const rootItems = rootList ? [...rootList.children] : [];
        rootItems.forEach((item) => {
            recurse(item, false);
        });

        if (searchCount) {
            searchCount.textContent = `${countVisibleProfiles()} profil(s) visible(s)`;
        }

        const firstMatch = tree.querySelector('.messaging-org-tree-item.is-search-match [data-org-user-node], .messaging-org-tree-item.is-search-match .messaging-org-tree-toggle');
        if (firstMatch) {
            recenterNode(firstMatch, 'smooth');
        }
    };

    tree.querySelector('[data-org-expand-all]')?.addEventListener('click', () => {
        branchButtons.forEach((button) => setExpanded(button, true));
        persistTreeState();
    });

    tree.querySelector('[data-org-collapse-all]')?.addEventListener('click', () => {
        branchButtons.forEach((button, index) => setExpanded(button, index === 0));
        persistTreeState();
    });

    tree.querySelector('[data-org-reset-state]')?.addEventListener('click', () => {
        try {
            window.localStorage.removeItem(storageKey);
        } catch (error) {
            // Ignore storage failures. The tree remains functional.
        }

        branchButtons.forEach((button) => {
            setExpanded(button, button.dataset.defaultExpanded === 'true');
        });

        if (viewport) {
            viewport.scrollTo({
                top: 0,
                left: 0,
                behavior: 'smooth',
            });
        }

        const selected = tree.querySelector('[data-org-selected="1"]');
        if (selected) {
            expandParents(selected);
            window.setTimeout(() => recenterNode(selected), 120);
        }

        if (quickSearchInput) {
            quickSearchInput.value = '';
            clearTreeSearch();
        }
    });

    quickSearchInput?.addEventListener('input', (event) => {
        applyTreeSearch(event.currentTarget.value ?? '');
    });

    quickSearchInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        event.preventDefault();
        quickSearchInput.value = '';
        clearTreeSearch();
        quickSearchInput.focus();
    });

    clearSearchButton?.addEventListener('click', () => {
        if (!quickSearchInput) {
            return;
        }

        quickSearchInput.value = '';
        clearTreeSearch();
        quickSearchInput.focus();
    });

    let scrollPersistTimer = null;
    viewport?.addEventListener('scroll', () => {
        window.clearTimeout(scrollPersistTimer);
        scrollPersistTimer = window.setTimeout(() => {
            persistTreeState();
        }, 120);
    });

    window.addEventListener('pagehide', () => {
        persistTreeState();
    });

    const expandParents = (selected) => {
        let current = selected?.parentElement?.closest('[data-org-branch]') ?? null;
        while (current) {
            const toggle = current.querySelector(':scope > .messaging-org-tree-branch [data-org-branch-toggle]');
            if (toggle) {
                setExpanded(toggle, true);
            }

            current = current.parentElement?.closest('[data-org-branch]') ?? null;
        }
    };

    const loadProfileCard = async (link) => {
        const url = link?.dataset.profileCardUrl;
        if (!url || !profileContent || !window.axios) {
            return false;
        }

        profileContent.setAttribute('aria-busy', 'true');
        profileContent.classList.add('is-loading');

        try {
            const response = await window.axios.get(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            profileContent.innerHTML = response.data?.html ?? '';
            profileContent.removeAttribute('aria-busy');
            profileContent.classList.remove('is-loading');
            return true;
        } catch (error) {
            profileContent.removeAttribute('aria-busy');
            profileContent.classList.remove('is-loading');
            return false;
        }
    };

    tree.querySelectorAll('[data-profile-card-link]').forEach((link) => {
        link.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();

            activateSelectedNode(link);
            expandParents(link);
            recenterNode(link);

            const loaded = await loadProfileCard(link);
            if (!loaded) {
                window.location.assign(link.href);
                return;
            }

            window.history.replaceState({}, '', link.href);
            document.getElementById('messaging-profile-card')?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    });

    const selected = tree.querySelector('[data-org-selected="1"]');
    if (selected) {
        expandParents(selected);
        window.setTimeout(() => {
            const restored = restoreViewportPosition();
            if (!restored) {
                recenterNode(selected, 'auto');
            }

            persistTreeState();
        }, 60);
    } else {
        window.setTimeout(() => {
            restoreViewportPosition();
        }, 60);
    }

    clearTreeSearch();
}

initMessagingOrgTree();
initMessagingRealtime();
