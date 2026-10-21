import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/mobile-navigation.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
const home = '/absensi/mobile';
const stateKey = 'rsarsy.mobile.navigation';

function page(url, owner = 1, extra = {}) {
    return { url, props: { auth: { user: owner === null ? null : { id: owner } } }, rememberedState: {}, ...extra };
}

function browser(initial = page(home), { earlier = [], storage = new Map(), blockedStorage = false } = {}) {
    const window = new EventTarget();
    const entries = [...earlier, initial];
    const events = new Map();
    const visits = [];
    let index = entries.length - 1;
    let backCalls = 0;
    let queuedBack = false;
    Object.defineProperty(window, 'location', { get: () => new URL(entries[index].url, 'https://presence.test') });
    window.sessionStorage = {
        getItem(key) {
            if (blockedStorage) throw new Error('Storage disabled');
            return storage.get(key) ?? null;
        },
        setItem(key, value) {
            if (blockedStorage) throw new Error('Storage disabled');
            storage.set(key, value);
        },
        removeItem: (key) => storage.delete(key),
    };
    window.history = {
        get length() {
            return entries.length;
        },
        back() {
            backCalls++;
            queuedBack = true;
        },
    };
    const emit = (name, currentPage) => events.get(name)?.forEach((listener) => listener({ detail: { page: currentPage } }));
    const router = {
        on(name, listener) {
            const listeners = events.get(name) ?? [];
            events.set(name, [...listeners, listener]);
            return () => events.set(name, listeners.filter((item) => item !== listener));
        },
        remember(value, key) {
            entries[index].rememberedState[key] = value;
        },
        visit(url, options = {}) {
            visits.push({ url, options });
            const next = page(url);
            if (options.replace) entries[index] = next;
            else {
                entries.splice(index + 1, entries.length, next);
                index++;
                emit('navigate', next);
            }
            emit('success', next);
            emit('finish', next);
        },
    };
    function load(initialPage) {
        const exports = {};
        vm.runInNewContext(outputText, { exports, require: () => ({ router }), window, URL });
        exports.startMobileNavigation(initialPage);
        return exports;
    }
    const navigation = load(initial);
    return {
        navigation,
        router,
        visits,
        storage,
        get backCalls() {
            return backCalls;
        },
        get url() {
            return entries[index].url;
        },
        traverse(delta) {
            index += delta;
            window.dispatchEvent(new Event('popstate'));
            emit('navigate', entries[index]);
        },
        flushBack() {
            if (!queuedBack) return;
            queuedBack = false;
            this.traverse(-1);
        },
        reload() {
            // A reload resets the JS runtime, and Inertia clears remembered state.
            return browser(page(this.url), { earlier: entries.slice(0, index), storage });
        },
        replacePage(next) {
            entries[index] = next;
            emit('success', next);
        },
        nativeBack() {
            const event = new Event('rsarsy:back', { cancelable: true });
            window.dispatchEvent(event);
            return event.defaultPrevented;
        },
    };
}

test('Android back returns through multiple mobile pages without creating new entries', () => {
    const app = browser();
    app.router.visit(`${home}/presensi`);
    app.router.visit(`${home}/clock-in`);
    assert.equal(app.nativeBack(), true);
    app.flushBack();
    assert.equal(app.url, `${home}/presensi`);
    assert.equal(app.nativeBack(), true);
    app.flushBack();
    assert.equal(app.url, home);
    assert.equal(app.nativeBack(), false);
    assert.equal(app.visits.length, 2);
});

test('the PWA/iOS back link follows history and preserves the previous query string', () => {
    const app = browser();
    app.router.visit(`${home}/notifikasi?tab=approvals&page=2`);
    app.router.visit(`${home}/notifikasi/cuti`);
    assert.equal(app.navigation.mobileGoBack(`${home}/notifikasi?tab=approvals`), true);
    app.flushBack();
    assert.equal(app.url, `${home}/notifikasi?tab=approvals&page=2`);
});

test('direct links fall back to home without traversing login or external browser history', () => {
    for (const earlier of [[], [page('/login')], [page('https://external.test/')]]) {
        const app = browser(page(`${home}/cuti`), { earlier });
        assert.equal(app.navigation.mobileGoBack(), true);
        assert.equal(app.backCalls, 0);
        assert.equal(app.url, home);
        assert.equal(app.visits[0].options.replace, true);
        assert.equal(app.navigation.mobileGoBack(), false);
    }
});

test('direct approval links can fall back to their inbox', () => {
    const app = browser(page(`${home}/notifikasi/cuti`));
    app.navigation.mobileGoBack(`${home}/notifikasi?tab=approvals`);
    assert.equal(app.url, `${home}/notifikasi?tab=approvals`);
    assert.equal(app.visits[0].options.replace, true);
});

test('replace visits for filters do not introduce phantom back entries', () => {
    const app = browser();
    app.router.visit(`${home}/cuti`);
    app.router.visit(`${home}/cuti?status=pending`, { replace: true });
    app.navigation.mobileGoBack();
    app.flushBack();
    assert.equal(app.url, home);
    assert.equal(app.navigation.mobileGoBack(), false);
});

test('browser back and iOS swipe traversal restore each entry before the next back action', () => {
    const app = browser();
    app.router.visit(`${home}/cuti`);
    app.router.visit(`${home}/notifikasi`);
    app.traverse(-1);
    assert.equal(app.url, `${home}/cuti`);
    app.traverse(1);
    assert.equal(app.url, `${home}/notifikasi`);
    app.navigation.mobileGoBack();
    app.flushBack();
    app.navigation.mobileGoBack();
    app.flushBack();
    assert.equal(app.url, home);
});

test('a full reload retains a known previous mobile page', () => {
    const app = browser();
    app.router.visit(`${home}/presensi`);
    app.router.visit(`${home}/clock-in`);
    const reloaded = app.reload();
    reloaded.navigation.mobileGoBack();
    reloaded.flushBack();
    assert.equal(reloaded.url, `${home}/presensi`);
});

test('stale saved history from another page, account, or history length is ignored', () => {
    for (const changes of [{ current: `${home}/cuti` }, { owner: 2 }, { length: 20 }, { previous: 'https://external.test/' }]) {
        const storage = new Map([
            [stateKey, JSON.stringify({ current: `${home}/clock-in`, previous: `${home}/presensi`, owner: 1, length: 2, ...changes })],
        ]);
        const app = browser(page(`${home}/clock-in`), { earlier: [page('/login')], storage });
        app.navigation.mobileGoBack();
        assert.equal(app.backCalls, 0);
        assert.equal(app.url, home);
    }
});

test('logout, account changes, and clearHistory reset the mobile back destination', () => {
    for (const next of [page('/login', null), page(`${home}/cuti`, 2), page(`${home}/cuti`, 1, { clearHistory: true })]) {
        const app = browser();
        app.router.visit(`${home}/presensi`);
        app.replacePage(next);
        app.navigation.mobileGoBack();
        assert.equal(app.backCalls, 0);
    }
});

test('ordinary login/admin pages do not consume Android back', () => {
    for (const url of ['/login', '/absensi/admin', '/absensi/mobile-other']) {
        const app = browser(page(url, url === '/login' ? null : 1));
        assert.equal(app.nativeBack(), false);
        assert.equal(app.navigation.mobileGoBack(), false);
        assert.equal(app.visits.length, 0);
    }
});

test('rapid back presses cannot skip a page while history is restoring', () => {
    const app = browser();
    app.router.visit(`${home}/presensi`);
    app.router.visit(`${home}/clock-in`);
    app.nativeBack();
    app.nativeBack();
    assert.equal(app.backCalls, 1);
    app.flushBack();
    assert.equal(app.url, `${home}/presensi`);
});

test('active screens handle nested dialogs or camera steps first and unregister on leaving', () => {
    const app = browser(page(`${home}/clock-in`));
    const handled = [];
    const removeScreen = app.navigation.registerMobileBackHandler(() => {
        handled.push('camera');
        return true;
    });
    const removeDialog = app.navigation.registerMobileBackHandler(() => {
        handled.push('dialog');
        return true;
    });
    app.nativeBack();
    assert.deepEqual(handled, ['dialog']);
    removeDialog();
    app.nativeBack();
    assert.deepEqual(handled, ['dialog', 'camera']);
    removeScreen();
    app.nativeBack();
    assert.equal(app.url, home);
});

test('disabled browser storage does not prevent navigation', () => {
    const app = browser(page(home), { blockedStorage: true });
    app.router.visit(`${home}/presensi`);
    app.navigation.mobileGoBack();
    app.flushBack();
    assert.equal(app.url, home);
});

test('server rendering does not require window', () => {
    const exports = {};
    vm.runInNewContext(outputText, { exports, require: () => ({ router: {} }), URL });
    exports.startMobileNavigation(page(home));
    assert.equal(exports.mobileGoBack(), false);
});
