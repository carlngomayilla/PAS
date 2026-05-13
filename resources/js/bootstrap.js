import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

function handleExpiredCsrfSession() {
    if (window.__anbgCsrfExpiredHandled) {
        return;
    }

    window.__anbgCsrfExpiredHandled = true;

    const message = 'Votre session a expire. La page va etre rechargee.';
    if (typeof window.anbgToast === 'function') {
        window.anbgToast(message, 'warning', 3500);
    }

    window.setTimeout(() => {
        window.location.reload();
    }, 1200);
}

window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 419) {
            handleExpiredCsrfSession();
        }

        return Promise.reject(error);
    },
);

if (typeof window.fetch === 'function' && !window.__anbgFetchCsrfGuard) {
    const nativeFetch = window.fetch.bind(window);
    window.__anbgFetchCsrfGuard = true;

    window.fetch = (...args) => nativeFetch(...args).then((response) => {
        if (response?.status === 419) {
            handleExpiredCsrfSession();
        }

        return response;
    });
}

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
