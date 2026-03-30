import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const reverbHost = import.meta.env.VITE_REVERB_HOST;

window.Echo = null;

if (reverbKey && reverbHost) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: csrfToken ? {
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        } : undefined,
    });
}
