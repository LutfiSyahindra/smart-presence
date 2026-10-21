self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

function notificationUrl(value) {
    try {
        const url = new URL(value || '/absensi/mobile/notifikasi', self.location.origin);
        if (
            url.origin === self.location.origin &&
            !url.username &&
            !url.password &&
            (url.pathname === '/absensi/mobile/notifikasi' || /^\/absensi\/mobile\/notifikasi\/open\/[a-zA-Z0-9-]+$/.test(url.pathname)) &&
            !url.search &&
            !url.hash
        )
            return url;
    } catch {
        /* Ignore invalid destinations. */
    }
    return null;
}

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data?.json() ?? {};
    } catch {
        /* Display a generic notification. */
    }
    const url = notificationUrl(payload.url);
    event.waitUntil(
        Promise.all([
            self.registration.showNotification(payload.title || 'RSARSY Presence', {
                body: payload.body || 'Ada pembaruan pengajuan. Buka aplikasi untuk melihat detail.',
                icon: '/icons/absensi-192.png',
                tag: payload.id || 'mobile-notification',
                data: { url: url?.pathname || '/absensi/mobile/notifikasi' },
            }),
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
                for (const client of clients) {
                    if (new URL(client.url).pathname.startsWith('/absensi/mobile')) client.postMessage({ type: 'mobile-notification' });
                }
            }),
        ]),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = notificationUrl(event.notification.data?.url);
    if (!url) return;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
            const client = clients.find((item) => {
                const path = new URL(item.url).pathname;
                return path === '/absensi/mobile' || path.startsWith('/absensi/mobile/');
            });
            if (client) {
                await client.navigate(url.href);
                return client.focus();
            }
            return self.clients.openWindow(url.href);
        }),
    );
});
