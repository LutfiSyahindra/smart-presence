import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/composables/useMobileNotifications.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
const flush = async () => {
    for (let i = 0; i < 6; i++) await new Promise((resolve) => setImmediate(resolve));
};
const page = (id = 1, url = '/absensi/mobile') => ({ url, props: { auth: { user: id ? { id } : null } } });

function runtime({ native = true, permission = 'granted', pluginAvailable = true } = {}) {
    const listeners = {};
    const visited = [];
    const requests = [];
    let onNavigate;
    let prompts = 0;
    const notification = {
        get permission() {
            return permission;
        },
        requestPermission: async () => {
            prompts++;
            permission = 'granted';
            return permission;
        },
    };
    const plugin = {
        addListener: async (event, callback) => {
            listeners[event] = callback;
            return { remove: async () => {} };
        },
        checkPermissions: async () => ({ receive: permission }),
        requestPermissions: async () => {
            prompts++;
            permission = 'granted';
            return { receive: permission };
        },
        register: async () => {
            await listeners.registration({ value: 'test-device-token' });
        },
        createChannel: async () => {},
    };
    const exports = {};
    vm.runInNewContext(outputText, {
        exports,
        URL,
        AbortController,
        setTimeout,
        clearTimeout,
        setInterval: () => 1,
        clearInterval() {},
        document: { hidden: false, cookie: 'XSRF-TOKEN=test-csrf', addEventListener() {} },
        navigator: { serviceWorker: { addEventListener() {} } },
        Notification: notification,
        window: {
            isSecureContext: true,
            Notification: notification,
            PushManager: {},
            location: { origin: 'https://absensi.example' },
            addEventListener() {},
        },
        fetch: async (url, options = {}) => {
            requests.push({ url, options });
            return {
                ok: true,
                status: 200,
                json: async () => ({ items: [], unread_count: 3, push_public_key: 'test-public-key', native_push: { android: true, ios: true } }),
            };
        },
        require: (name) => {
            if (name === 'vue')
                return {
                    ref: (value) => ({ value }),
                    computed: (getter) => ({
                        get value() {
                            return getter();
                        },
                    }),
                };
            if (name === '@inertiajs/vue3')
                return {
                    router: {
                        on: (_, callback) => {
                            onNavigate = callback;
                        },
                        visit: (url) => visited.push(url),
                    },
                };
            if (name === '@/lib/mobile-push')
                return {
                    nativePushPlatform: () => (native ? 'android' : null),
                    nativePushPlugin: () => (native && pluginAvailable ? plugin : undefined),
                    mobileNotificationUrl: (value) =>
                        typeof value === 'string' && value.startsWith('/absensi/mobile/notifikasi/open/') ? value : null,
                    webPushSubscription: async () => {
                        assert.equal(permission, 'granted');
                        return { toJSON: () => ({ endpoint: 'test-endpoint', keys: {} }) };
                    },
                };
            throw new Error(`Unexpected import ${name}`);
        },
    });
    return {
        exports,
        state: exports.useMobileNotifications(),
        requests,
        visited,
        listeners,
        get prompts() {
            return prompts;
        },
        navigate: (next) => onNavigate({ detail: { page: next } }),
    };
}

test('granted native permission registers silently and a tap from the admin portal opens mobile', async () => {
    const app = runtime();
    app.exports.startMobileNotifications(page());
    await flush();
    assert.equal(app.prompts, 0);
    assert.equal(app.state.pushActive.value, true);
    const saved = app.requests.find((request) => request.url.endsWith('/devices'));
    assert.deepEqual(JSON.parse(saved.options.body), { platform: 'android', token: 'test-device-token' });
    app.navigate(page(1, '/absensi/admin-web/dashboard'));
    await flush();
    app.listeners.pushNotificationActionPerformed({ notification: { data: { url: '/absensi/mobile/notifikasi/open/notice-1' } } });
    assert.deepEqual(app.visited, ['/absensi/mobile/notifikasi/open/notice-1']);
});

test('native permission is requested only after activation and the saved token enables push', async () => {
    const app = runtime({ permission: 'prompt' });
    app.exports.startMobileNotifications(page());
    await flush();
    assert.equal(app.prompts, 0);
    assert.equal(app.state.pushActive.value, false);
    await app.state.enablePush();
    assert.equal(app.prompts, 1);
    assert.equal(app.state.pushActive.value, true);
    assert.equal(app.state.busy.value, false);
});

test('PWA activation prompts from the button and binds the browser subscription to mobile', async () => {
    const app = runtime({ native: false, permission: 'default' });
    app.exports.startMobileNotifications(page());
    await flush();
    assert.equal(app.prompts, 0);
    await app.state.enablePush();
    assert.equal(app.prompts, 1);
    assert.equal(app.state.pushActive.value, true);
    const saved = app.requests.find((request) => request.url.endsWith('/push-subscriptions'));
    assert.equal(saved.options.headers['X-XSRF-TOKEN'], 'test-csrf');
    assert.equal(JSON.parse(saved.options.body).contentEncoding, 'aes128gcm');
});

test('logout clears account state and a cold-start tap waits for login', async () => {
    const app = runtime();
    app.exports.startMobileNotifications(page(null, '/login'));
    await flush();
    app.listeners.pushNotificationActionPerformed({ notification: { data: { url: '/absensi/mobile/notifikasi/open/notice-1' } } });
    assert.equal(app.visited.length, 0);
    app.navigate(page());
    await flush();
    assert.deepEqual(app.visited, ['/absensi/mobile/notifikasi/open/notice-1']);
    assert.equal(app.state.feed.value.unread_count, 3);
    app.navigate(page(null, '/login'));
    await flush();
    assert.equal(app.state.feed.value.unread_count, 0);
    assert.equal(app.state.pushActive.value, false);
});

test('an unavailable native plugin gives an app update message instead of blaming notification settings', async () => {
    const app = runtime({ pluginAvailable: false });
    app.exports.startMobileNotifications(page());
    await flush();
    await app.state.enablePush();
    assert.match(app.state.feedback.value, /Perbarui aplikasi/);
    assert.equal(app.prompts, 0);
    assert.equal(
        app.requests.some((request) => request.url.endsWith('/devices')),
        false,
    );
});
