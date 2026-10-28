import type { Page } from '@inertiajs/core';
import Pusher from 'pusher-js';
import { isAndroidApp } from './mobile-app-update';

type RealtimeConfig = { enabled: boolean; key: string; host: string; port: number; secure: boolean; path: string };

/** Release announcements also reach Android users on the login screen. */
export function createAppUpdateRealtime(changed: () => void) {
    let client: Pusher | undefined;
    let config: RealtimeConfig | undefined;
    let signature = '';
    let lastVersion: string | undefined;

    function pause() {
        const previous = client;
        client = undefined;
        previous?.unbind_all();
        previous?.disconnect();
    }

    function resume() {
        if (!isAndroidApp() || !config?.enabled || !config.key || client || document.hidden || navigator.onLine === false) return;
        const socket = new Pusher(config.key, {
            cluster: 'mt1',
            wsHost: config.host,
            wsPort: config.port,
            wssPort: config.port,
            wsPath: config.path,
            forceTLS: config.secure,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
        });
        client = socket;
        socket.connection.bind('state_change', ({ current }: { current: string }) => {
            if (client === socket && current === 'failed') pause();
        });
        const channel = socket.subscribe('mobile.app-releases');
        channel.bind('pusher:subscription_succeeded', () => {
            if (client === socket) changed(); // Recover releases missed while disconnected.
        });
        channel.bind('pusher:subscription_error', () => {
            if (client === socket) pause();
        });
        channel.bind('mobile.app-release.changed', (event: { version?: string }) => {
            if (client !== socket || !event.version || event.version === lastVersion) return;
            lastVersion = event.version;
            changed();
        });
    }

    function update(page: Page) {
        config = page.props.mobileRealtime as RealtimeConfig | undefined;
        const nextSignature = JSON.stringify(config) ?? '';
        if (signature !== nextSignature) {
            pause();
            signature = nextSignature;
            lastVersion = undefined;
        }
        resume();
    }

    return { update, pause, resume };
}
