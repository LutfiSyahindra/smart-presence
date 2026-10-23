import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../public/admin-notifications-sw.js', import.meta.url), 'utf8');
const origin = 'https://presence.example';

function worker(clients = []) {
    const handlers = new Map();
    const shown = [];
    const opened = [];
    const self = {
        location: { origin },
        addEventListener: (name, handler) => handlers.set(name, handler),
        registration: { showNotification: async (title, options) => shown.push({ title, ...options }) },
        skipWaiting: async () => {},
        clients: {
            claim: async () => {},
            matchAll: async () => clients,
            openWindow: async (url) => opened.push(url),
        },
    };
    runInNewContext(source, { self, URL });
    const dispatch = async (name, data) => {
        let pending;
        handlers.get(name)({ ...data, waitUntil: (promise) => (pending = promise) });
        await pending;
    };
    return { shown, opened, handlers, dispatch };
}

test('background push displays a notification and refreshes open inboxes', async () => {
    const messages = [];
    const app = worker([{ postMessage: (message) => messages.push(message) }]);
    await app.dispatch('push', { data: { json: () => ({ id: 'notice-1', title: 'Pengajuan baru', body: 'Cuti tahunan', url: '/absensi/admin-web/notifikasi/open/notice-1' }) } });
    assert.equal(app.shown.length, 1);
    assert.equal(app.shown[0].title, 'Pengajuan baru');
    assert.equal(app.shown[0].body, 'Cuti tahunan');
    assert.equal(app.shown[0].tag, 'notice-1');
    assert.equal(app.shown[0].icon, '/icons/arsy-presensi-192.png');
    assert.equal(app.shown[0].badge, '/icons/arsy-presensi-badge-96.png');
    assert.equal(messages[0].type, 'admin-notification');
    assert.equal(app.handlers.has('fetch'), false);
});

test('invalid push data still displays a usable notification', async () => {
    const app = worker();
    await app.dispatch('push', { data: { json: () => { throw new Error('Invalid JSON'); } } });
    assert.equal(app.shown[0].title, 'Notifikasi pengajuan');
    assert.equal(app.shown[0].icon, '/icons/arsy-presensi-192.png');
    assert.equal(app.shown[0].badge, '/icons/arsy-presensi-badge-96.png');
    assert.equal(app.shown[0].data.url, '/absensi/admin-web/notifikasi/semua');
});

test('clicking a push opens admin-web when no admin tab exists', async () => {
    const app = worker();
    let closed = false;
    await app.dispatch('notificationclick', { notification: { close: () => (closed = true), data: { url: '/absensi/admin-web/notifikasi/open/notice-1' } } });
    assert.equal(closed, true);
    assert.deepEqual(app.opened, [`${origin}/absensi/admin-web/notifikasi/open/notice-1`]);
});

test('clicking a push navigates and focuses an existing admin tab', async () => {
    let navigated;
    let focused = false;
    const app = worker([{
        url: `${origin}/absensi/admin-web/dashboard`,
        navigate: async (url) => (navigated = url),
        focus: async () => (focused = true),
    }]);
    await app.dispatch('notificationclick', { notification: { close: () => {}, data: { url: '/absensi/admin-web/notifikasi/semua' } } });
    assert.equal(navigated, `${origin}/absensi/admin-web/notifikasi/semua`);
    assert.equal(focused, true);
    assert.equal(app.opened.length, 0);
});

test('push clicks cannot navigate to another site or a non-admin page', async () => {
    for (const url of ['https://outside.example/test', '/absensi/mobile', '//outside.example/absensi/admin-web/dashboard']) {
        const app = worker();
        await app.dispatch('notificationclick', { notification: { close: () => {}, data: { url } } });
        assert.equal(app.opened.length, 0);
    }
});
