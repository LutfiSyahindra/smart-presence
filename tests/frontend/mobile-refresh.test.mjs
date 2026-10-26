import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/mobile-refresh.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const home = '/absensi/mobile';
const page = (screen = 'Home', url = home, user = 1) => ({
    url,
    component: `absensi/absensi-mobile/${screen}`,
    props: {
        auth: { user: user ? { id: user } : null },
        todaySchedule: {},
        todayAttendance: {},
        notifications: {},
        unreadCount: 0,
        balance: {},
        leaveOptions: [],
        filters: {},
        errors: {},
        flash: {},
        locationChallenge: { token: 'do-not-rotate' },
    },
});

function runtime(initial = page()) {
    const window = new EventTarget();
    window.location = new URL(initial.url, 'https://presence.test');
    const document = new EventTarget();
    document.hidden = false;
    const navigator = { onLine: true };
    const listeners = new Map();
    const timers = new Map();
    const intervals = [];
    const requests = [];
    let nextTimer = 0;
    let current = initial;
    let now = 0;
    let socketChanged;
    let socketStatus;
    let blockNext = false;
    const emit = (name, detail) => listeners.get(name)?.forEach((handler) => handler({ detail }));
    const router = {
        on(name, handler) {
            listeners.set(name, [...(listeners.get(name) ?? []), handler]);
        },
        reload(options) {
            if (blockNext) {
                blockNext = false;
                return;
            }
            const visit = { headers: options.headers, method: 'get' };
            const request = { options, visit, completed: false, url: window.location.href };
            requests.push(request);
            options.onBefore?.(visit);
            emit('before', { visit });
            options.onCancelToken({ cancel: () => finish(request, true) });
            emit('start', { visit });
        },
    };
    function finish(request, cancelled = false, props = {}) {
        if (request.completed) return;
        request.completed = true;
        request.cancelled = cancelled;
        if (!cancelled) {
            current = { ...current, props: { ...current.props, ...props } };
            emit('success', { page: current });
        }
        emit('finish', { visit: request.visit });
        request.options.onFinish();
    }
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        window,
        document,
        navigator,
        URL,
        Event,
        Date: { now: () => now },
        setTimeout: (callback) => {
            timers.set(++nextTimer, callback);
            return nextTimer;
        },
        setInterval: (callback, delay) => {
            intervals.push({ callback, delay });
            return intervals.length;
        },
        require: (name) =>
            name === './mobile-realtime'
                ? {
                      createMobileRealtime(changed, status) {
                          socketChanged = changed;
                          socketStatus = status;
                          return {
                              update() {},
                              pause() {
                                  status(false);
                              },
                              resume() {},
                          };
                      },
                  }
                : { router },
    });
    exports.startMobileRefresh(initial);
    return {
        exports,
        window,
        document,
        navigator,
        requests,
        intervals,
        blockNext: () => (blockNext = true),
        socket: {
            subscribe() {
                socketStatus(true);
                socketChanged();
            },
            change() {
                socketChanged();
            },
            disconnect() {
                socketStatus(false);
            },
        },
        get current() {
            return current;
        },
        flush() {
            const pending = [...timers.values()];
            timers.clear();
            pending.forEach((callback) => callback());
        },
        poll() {
            now += 10000;
            intervals[0].callback();
            this.flush();
        },
        complete(props = {}) {
            finish(requests.at(-1), false, props);
        },
        startVisit() {
            const visit = { headers: {}, method: 'post' };
            emit('before', { visit });
            emit('start', { visit });
            return visit;
        },
        finishVisit(visit) {
            emit('finish', { visit });
        },
        navigate(next, { history = false, replace = false } = {}) {
            const visit = { headers: {}, method: 'get' };
            if (!history) emit('before', { visit });
            window.location = new URL(next.url, window.location.origin);
            if (history) window.dispatchEvent(new Event('popstate'));
            current = next;
            emit(replace ? 'success' : 'navigate', { page: next });
        },
    };
}

test('visible mobile pages periodically update approved leave and schedules without a new history entry', () => {
    const app = runtime();
    assert.equal(app.intervals[0].delay, 10000);
    assert.equal(app.requests.length, 0);
    app.poll();
    const { options } = app.requests[0];
    assert.ok(options.only.includes('todaySchedule'));
    assert.ok(options.only.includes('todayAttendance'));
    assert.ok(options.only.includes('auth'));
    assert.equal(options.replace, true);
    assert.equal(options.showProgress, false);
    app.complete({ todayAttendance: { is_on_leave: true } });
    assert.equal(app.current.props.todayAttendance.is_on_leave, true);
    app.flush();
    assert.equal(app.requests.length, 1);
});

test('returning to cached Inbox through Android back, browser back, or iOS swipe reloads read state', () => {
    const app = runtime(page('Cuti', `${home}/cuti/42`));
    app.navigate(page('Notifications', `${home}/notifikasi?page=2`), { history: true });
    app.flush();
    assert.equal(app.requests[0].url, 'https://presence.test/absensi/mobile/notifikasi?page=2');
    assert.ok(app.requests[0].options.only.includes('notifications'));
    assert.ok(app.requests[0].options.only.includes('unreadCount'));
    app.complete({ notifications: { data: [{ id: 'notice-1', read_at: '2026-10-26T09:00:00+07:00' }] }, unreadCount: 0 });
    assert.equal(app.current.props.unreadCount, 0);
    assert.ok(app.current.props.notifications.data[0].read_at);
});

test('foreground, reconnect, push, and restored browser sessions coalesce into a single update', () => {
    const app = runtime(page('Cuti', `${home}/cuti?month=2026-12`));
    app.document.dispatchEvent(new Event('visibilitychange'));
    app.window.dispatchEvent(new Event('focus'));
    app.window.dispatchEvent(new Event('online'));
    const restored = new Event('pageshow');
    restored.persisted = true;
    app.window.dispatchEvent(restored);
    app.exports.refreshMobilePage();
    app.flush();
    assert.equal(app.requests.length, 1);
    assert.equal(app.requests[0].url, 'https://presence.test/absensi/mobile/cuti?month=2026-12');
    assert.ok(app.requests[0].options.only.includes('balance'));
    assert.ok(!app.requests[0].options.only.includes('filters'));
    assert.ok(!app.requests[0].options.only.includes('errors'));
    assert.ok(!app.requests[0].options.only.includes('flash'));
});

test('background work waits for submissions and is cancelled before navigation or account changes', () => {
    const app = runtime();
    const mutation = app.startVisit();
    app.poll();
    assert.equal(app.requests.length, 0);
    app.finishVisit(mutation);
    app.flush();
    assert.equal(app.requests.length, 1);
    app.navigate(page('Cuti', `${home}/cuti`, 2));
    assert.equal(app.requests[0].cancelled, true);
    app.poll();
    assert.equal(app.requests.length, 2);
    app.navigate(page('Home', '/login', null));
    assert.equal(app.requests[1].cancelled, true);
    app.poll();
    assert.equal(app.requests.length, 2);
});

test('hidden and offline pages pause refresh and retry on returning', () => {
    const app = runtime();
    app.document.hidden = true;
    app.poll();
    app.document.hidden = false;
    app.navigator.onLine = false;
    app.poll();
    assert.equal(app.requests.length, 0);
    app.navigator.onLine = true;
    app.window.dispatchEvent(new Event('online'));
    app.flush();
    assert.equal(app.requests.length, 1);
    app.document.hidden = true;
    app.document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(app.requests[0].cancelled, true);
});

test('polls cannot overlap and a pending event is retried after completion', () => {
    const app = runtime();
    app.poll();
    app.poll();
    assert.equal(app.requests.length, 1);
    app.complete();
    app.flush();
    assert.equal(app.requests.length, 2);
});

test('every mobile screen refreshes server data while retaining location challenges and draft state', () => {
    for (const screen of ['ClockIn', 'ClockOut', 'LemburCreate', 'LemburUsageCreate', 'Account']) {
        const app = runtime(page(screen, `${home}/screen`));
        app.poll();
        assert.equal(app.requests.length, 1, screen);
        assert.ok(!app.requests[0].options.only.includes('locationChallenge'));
    }
    const app = runtime({ ...page(), component: 'absensi/admin-web/Home' });
    app.poll();
    assert.equal(app.requests.length, 0);
});

test('a camera GPS/verification guard defers an invalidation and resumes after the operation', () => {
    const app = runtime(page('ClockIn', `${home}/clock-in`));
    let ready = false;
    const remove = app.exports.registerMobileRefreshGuard(() => ready);
    app.socket.change();
    app.flush();
    assert.equal(app.requests.length, 0);
    ready = true;
    app.exports.resumeMobileRefresh();
    app.flush();
    assert.equal(app.requests.length, 1);
    remove();
});

test('subscribed sockets update immediately with a 60-second safety poll and a 10-second disconnected fallback', () => {
    const app = runtime();
    app.socket.subscribe();
    app.flush();
    assert.equal(app.requests.length, 1);
    app.complete();
    assert.equal(app.exports.mobilePollingInterval(), 60000);
    for (let i = 0; i < 5; i++) app.poll();
    assert.equal(app.requests.length, 1);
    app.poll();
    assert.equal(app.requests.length, 2);
    app.complete();
    app.socket.disconnect();
    assert.equal(app.exports.mobilePollingInterval(), 10000);
    app.poll();
    assert.equal(app.requests.length, 3);
});

test('future mobile data properties are automatically included in realtime refreshes', () => {
    const initial = page('FutureModule', `${home}/future`);
    initial.props.futureData = { value: 'old' };
    const app = runtime(initial);
    app.socket.change();
    app.flush();
    assert.ok(app.requests[0].options.only.includes('futureData'));
    app.complete({ futureData: { value: 'new' } });
    assert.equal(app.current.props.futureData.value, 'new');
});

test('a rejected before-visit guard cannot permanently block future refreshes', () => {
    const app = runtime();
    app.blockNext();
    app.poll();
    assert.equal(app.requests.length, 0);
    app.poll();
    assert.equal(app.requests.length, 1);
});

test('a browser fragment does not prevent live data from updating', () => {
    const initial = page();
    const app = runtime(initial);
    app.window.location.hash = '#schedule';
    app.socket.change();
    app.flush();
    assert.equal(app.requests.length, 1);
});

test('replace visits update the refresh target without causing a reload loop', () => {
    const app = runtime();
    app.navigate(page('Cuti', `${home}/cuti?month=2026-12`), { replace: true });
    app.flush();
    assert.equal(app.requests.length, 0);
    app.poll();
    assert.ok(app.requests[0].options.only.includes('leaveOptions'));
    app.complete();
    app.flush();
    assert.equal(app.requests.length, 1);
});
