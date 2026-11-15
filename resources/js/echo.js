import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

/*
 * Reconnect handling. The socket is never the source of truth: when the connection comes back
 * after dropping (laptop sleep, Wi-Fi blip), tell Livewire pages to re-read their figures,
 * because events sent while offline were missed.
 */
let hasConnectedBefore = false;

window.Echo.connector.pusher.connection.bind('state_change', ({ current }) => {
    if (current !== 'connected') {
        return;
    }

    if (hasConnectedBefore) {
        window.Livewire?.dispatch('echo-reconnected');
    }

    hasConnectedBefore = true;
});
