import type { Page } from '@inertiajs/core';
import Pusher from 'pusher-js';

type RealtimeConfig = { enabled: boolean; key: string; host: string; port: number; secure: boolean; path: string };

/** Private notification invalidation for the current admin session. */
export function createNotificationRealtime(changed: () => void, status: (connected: boolean) => void) {
    let client: Pusher | undefined;
    let signature = '';
    let page: Page;
    let lastVersion: string | undefined;

    function pause() {
        const previous = client;
        client = undefined;
        previous?.unbind_all();
        previous?.disconnect();
        status(false);
    }

    function update(next: Page) {
        page = next;
        const auth = page.props.auth as { user?: { id: number }; roles?: string[]; permissions?: string[] } | undefined;
        const config = page.props.mobileRealtime as RealtimeConfig | undefined;
        const owner = auth?.user?.id;
        const allowed = auth?.roles?.includes('super-admin') || auth?.permissions?.includes('notifications.view');
        const active = page.component.startsWith('absensi/admin-web/') && owner && allowed && config?.enabled && config.key;
        const nextSignature = active ? JSON.stringify([owner, config]) : '';
        if (signature !== nextSignature || !active) {
            pause();
            signature = nextSignature;
            lastVersion = undefined;
        }
        if (!active || client || document.hidden || navigator.onLine === false) return;

        const socket = new Pusher(config.key, {
            cluster: 'mt1',
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            wsPath: config.path,
            forceTLS: config.secure,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            channelAuthorization: {
                endpoint: '/broadcasting/auth',
                transport: 'ajax',
                headersProvider: () => ({
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                            ?.slice(11) ?? '',
                    ),
                }),
            },
        });
        client = socket;
        socket.connection.bind('state_change', ({ current }: { current: string }) => {
            if (client !== socket) return;
            if (current === 'failed') pause();
            else if (current !== 'connected') status(false);
        });
        const channel = socket.subscribe(`private-notifications.${owner}`);
        channel.bind('pusher:subscription_succeeded', () => {
            if (client !== socket) return;
            status(true);
            // Recover changes missed during startup, network switches, and sleep.
            changed();
        });
        channel.bind('pusher:subscription_error', () => {
            // The next successful fallback refresh retries channel authorization.
            if (client === socket) pause();
        });
        channel.bind('notifications.changed', (event: { version?: string }) => {
            if (client !== socket || !event.version || event.version === lastVersion) return;
            lastVersion = event.version;
            changed();
        });
    }

    return { update, pause, resume: () => page && update(page) };
}
