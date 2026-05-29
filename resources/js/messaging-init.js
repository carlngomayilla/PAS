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
            ${message.is_mine ? `<span class="text-[11px] font-medium opacity-80" data-message-seen-label>${message.is_seen ? 'Vu' : 'Envoyé'}</span>` : ''}
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
                window.dispatchEvent(new CustomEvent('anbg:message-received', {
                    detail: { count: messages.length },
                }));
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
                if (Number.parseInt(String(payload.sender_id ?? 0), 10) !== currentUserId) {
                    window.dispatchEvent(new CustomEvent('anbg:message-received', {
                        detail: { count: 1 },
                    }));
                }
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

initMessagingRealtime();
