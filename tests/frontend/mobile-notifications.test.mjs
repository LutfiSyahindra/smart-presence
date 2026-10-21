import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const origin = 'https://absensi.example';
const workerSource = await readFile(new URL('../../public/mobile-notifications-sw.js', import.meta.url), 'utf8');
function worker(clients = []) {
    const handlers = {};
    const shown = [];
    const opened = [];
    vm.runInNewContext(workerSource, {
        URL,
        self: {
            location: { origin },
            addEventListener: (event, handler) => {
                handlers[event] = handler;
            },
            registration: { showNotification: async (title, options) => shown.push({ title, ...options }) },
            clients: { matchAll: async () => clients, openWindow: async (url) => opened.push(url) },
        },
    });
    return {
        shown,
        opened,
        dispatch: async (event, data) => {
            let waiting;
            handlers[event]({
                ...data,
                waitUntil: (promise) => {
                    waiting = promise;
                },
            });
            await waiting;
        },
    };
}

test('mobile push displays while closed and informs only mobile windows', async () => {
    const messages = [];
    const app = worker([
        { url: `${origin}/absensi/mobile`, postMessage: (data) => messages.push(data) },
        { url: `${origin}/absensi/admin-web/dashboard`, postMessage: () => assert.fail('Must not refresh admin') },
    ]);
    await app.dispatch('push', {
        data: { json: () => ({ id: 'notice-1', title: 'Disetujui', body: 'Ada pembaruan', url: '/absensi/mobile/notifikasi/open/notice-1' }) },
    });
    assert.equal(app.shown[0].tag, 'notice-1');
    assert.equal(app.shown[0].data.url, '/absensi/mobile/notifikasi/open/notice-1');
    assert.equal(messages[0].type, 'mobile-notification');
});

test('invalid payload still displays a generic notification', async () => {
    const app = worker();
    await app.dispatch('push', {
        data: {
            json: () => {
                throw new Error();
            },
        },
    });
    assert.equal(app.shown[0].title, 'RSARSY Presence');
    assert.equal(app.shown[0].data.url, '/absensi/mobile/notifikasi');
});

test('tap opens mobile when closed and focuses an existing mobile window', async () => {
    const closedApp = worker();
    const notification = { close() {}, data: { url: '/absensi/mobile/notifikasi/open/notice-1' } };
    await closedApp.dispatch('notificationclick', { notification });
    assert.equal(closedApp.opened[0], `${origin}/absensi/mobile/notifikasi/open/notice-1`);
    let navigated;
    let focused = false;
    const runningApp = worker([
        {
            url: `${origin}/absensi/mobile`,
            navigate: async (url) => {
                navigated = url;
            },
            focus: async () => {
                focused = true;
            },
        },
    ]);
    await runningApp.dispatch('notificationclick', { notification });
    assert.equal(navigated, `${origin}/absensi/mobile/notifikasi/open/notice-1`);
    assert.equal(focused, true);
    assert.equal(runningApp.opened.length, 0);
});

test('push taps cannot leave the mobile notification routes', async () => {
    for (const url of [
        'https://outside.example/absensi/mobile/notifikasi',
        '//outside.example/a',
        '/absensi/admin-web/dashboard',
        '/absensi/mobileevil',
        '/logout',
        '/absensi/mobile/notifikasi?url=outside',
        'http://[invalid',
    ]) {
        const app = worker();
        await app.dispatch('notificationclick', { notification: { close() {}, data: { url } } });
        assert.equal(app.opened.length, 0, url);
    }
});

const source = await readFile(new URL('../../resources/js/lib/mobile-push.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
function bridge(capacitor, extra = {}) {
    const exports = {};
    vm.runInNewContext(outputText, { exports, window: { Capacitor: capacitor }, URL, ...extra });
    return exports;
}

test('browser and native platforms choose the correct push transport', () => {
    assert.equal(bridge().nativePushPlatform(), null);
    const plugin = {};
    for (const platform of ['android', 'ios']) {
        const app = bridge({
            getPlatform: () => platform,
            registerPlugin: (name) => {
                assert.equal(name, 'PushNotifications');
                return plugin;
            },
        });
        assert.equal(app.nativePushPlatform(), platform);
        assert.equal(app.nativePushPlugin(), plugin);
    }
    assert.equal(bridge({ getPlatform: () => 'android', isPluginAvailable: () => false }).nativePushPlugin(), undefined);
});

test('native tap validation accepts only same-origin notification IDs', () => {
    const app = bridge();
    assert.equal(app.mobileNotificationUrl('/absensi/mobile/notifikasi/open/notice-1', origin), '/absensi/mobile/notifikasi/open/notice-1');
    for (const url of [
        undefined,
        '/absensi/admin-web',
        '/logout',
        'https://outside.example/absensi/mobile/notifikasi/open/id',
        '/absensi/mobile/notifikasi/open/id?redirect=x',
        'http://[bad',
    ]) {
        assert.equal(app.mobileNotificationUrl(url, origin), null);
    }
});

test('the injected wrapper exposes its native push plugin without the bundled Capacitor runtime', () => {
    const plugin = { requestPermissions() {}, register() {} };
    const app = bridge({
        getPlatform: () => 'android',
        isPluginAvailable: (name) => name === 'PushNotifications',
        Plugins: { PushNotifications: plugin },
    });
    assert.equal(app.nativePushPlugin(), plugin);
});

test('PWA subscription uses the returned mobile registration without waiting on an unrelated scope', async () => {
    const expected = {};
    const app = bridge(undefined, {
        navigator: {
            serviceWorker: {
                register: async (url, options) => {
                    assert.equal(url, '/mobile-notifications-sw.js');
                    assert.equal(options.scope, '/absensi/mobile');
                    return { active: true, pushManager: { getSubscription: async () => expected } };
                },
                get ready() {
                    assert.fail('Must not wait on serviceWorker.ready');
                },
            },
        },
    });
    assert.equal(await app.webPushSubscription('key'), expected);
});

test('PWA creates a user-visible subscription with the server VAPID key', async () => {
    let subscribed = false;
    const app = bridge(undefined, {
        atob,
        navigator: {
            serviceWorker: {
                register: async () => ({
                    active: true,
                    pushManager: {
                        getSubscription: async () => null,
                        subscribe: async (options) => {
                            assert.equal(options.userVisibleOnly, true);
                            assert.equal(options.applicationServerKey[0], 4);
                            subscribed = true;
                            return {};
                        },
                    },
                }),
            },
        },
    });
    await app.webPushSubscription(Buffer.from([4, 1, 2]).toString('base64url'));
    assert.equal(subscribed, true);
});
