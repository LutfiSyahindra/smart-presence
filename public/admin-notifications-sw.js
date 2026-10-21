self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};
    try { payload = event.data?.json() ?? {}; } catch { /* Show a generic notification for an invalid payload. */ }
    event.waitUntil(Promise.all([
        self.registration.showNotification(payload.title || 'Notifikasi pengajuan', {
            body: payload.body || 'Ada pembaruan pengajuan di admin-web.',
            icon: '/assets/images/favicon.png',
            tag: payload.id || 'admin-notification',
            data: { url: payload.url || '/absensi/admin-web/notifikasi/semua' },
        }),
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) client.postMessage({ type: 'admin-notification' });
        }),
    ]));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/absensi/admin-web/notifikasi/semua', self.location.origin);
    if (url.origin !== self.location.origin || !url.pathname.startsWith('/absensi/admin-web/')) return;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
        const client = clients.find((item) => new URL(item.url).pathname.startsWith('/absensi/admin-web/'));
        if (client) {
            await client.navigate(url.href);
            return client.focus();
        }
        return self.clients.openWindow(url.href);
    }));
});
