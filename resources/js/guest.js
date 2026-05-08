function scheduleIdleTask(callback, timeout = 900) {
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(callback, { timeout });

        return;
    }

    window.setTimeout(callback, Math.min(timeout, 400));
}

function shouldSkipLoginVideo() {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    const effectiveType = String(connection?.effectiveType || '').toLowerCase();

    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        return true;
    }

    if (connection?.saveData) {
        return true;
    }

    return effectiveType.includes('2g') || effectiveType.includes('slow-2g') || effectiveType.includes('3g');
}

function bootGuestLoginVideo() {
    const video = document.querySelector('[data-login-video]');

    if (!(video instanceof HTMLVideoElement) || shouldSkipLoginVideo()) {
        return;
    }

    const source = video.querySelector('source[data-src]');

    if (!(source instanceof HTMLSourceElement)) {
        return;
    }

    let activated = false;

    const activate = () => {
        if (activated) {
            return;
        }

        activated = true;
        source.src = source.dataset.src || '';
        video.load();

        const playPromise = video.play();
        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(() => {});
        }
    };

    window.addEventListener('load', () => {
        window.setTimeout(activate, 120);
    }, { once: true });

    scheduleIdleTask(activate, 1200);
}

bootGuestLoginVideo();
