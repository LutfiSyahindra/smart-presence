import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/notification-realtime.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const page = (id = 1, screen = 'Home') => ({
    component: `absensi/admin-web/${screen}`,
    props: {
        auth: { user: id ? { id } : null, permissions: ['notifications.view'] },
        mobileRealtime: { enabled: true, key: 'public-key', host: 'presence.test', port: 443, secure: true, path: '' },
    },
});

function runtime() {
    const clients = [];
    const document = { hidden: false, cookie: 'XSRF-TOKEN=first-token' };
    const navigator = { onLine: true };
    let changes = 0;
    let connected = false;
    class Pusher {
        constructor(key, options) {
            this.key = key;
            this.options = options;
            this.handlers = {};
            this.connectionHandlers = {};
            this.connection = { bind: (event, handler) => (this.connectionHandlers[event] = handler) };
            clients.push(this);
        }
        subscribe(channel) {
            this.channel = channel;
            return { bind: (event, handler) => (this.handlers[event] = handler) };
        }
        unbind_all() {}
        disconnect() {
            this.disconnected = true;
        }
        emit(event, value) {
            this.handlers[event](value);
        }
    }
    const exports = {};
    vm.runInNewContext(compiled, { exports, document, navigator, require: () => ({ default: Pusher }) });
    const realtime = exports.createNotificationRealtime(
        () => changes++,
        (active) => (connected = active),
    );
    return {
        realtime,
        clients,
        document,
        navigator,
        get changes() {
            return changes;
        },
        get connected() {
            return connected;
        },
    };
}

test('one private socket serves all admin pages and resynchronizes after subscribing', () => {
    const app = runtime();
    app.realtime.update(page());
    app.realtime.update(page(1, 'Account'));
    assert.equal(app.clients.length, 1);
    const socket = app.clients[0];
    assert.equal(socket.channel, 'private-notifications.1');
    assert.equal(socket.options.forceTLS, true);
    assert.deepEqual([...socket.options.enabledTransports], ['ws', 'wss']);
    assert.equal(app.connected, false);
    socket.emit('pusher:subscription_succeeded');
    assert.equal(app.connected, true);
    assert.equal(app.changes, 1);
    socket.emit('notifications.changed', { version: 'first' });
    socket.emit('notifications.changed', { version: 'first' });
    socket.emit('notifications.changed', {});
    assert.equal(app.changes, 2);
});

test('logout, account switching, and notification access revocation disconnect old sockets', () => {
    const app = runtime();
    app.realtime.update(page());
    const previous = app.clients[0];
    app.realtime.update(page(2));
    assert.equal(previous.disconnected, true);
    previous.emit('notifications.changed', { version: 'late-old-account-event' });
    assert.equal(app.changes, 0);
    const restricted = page(2);
    restricted.props.auth.permissions = [];
    app.realtime.update(restricted);
    assert.equal(app.clients[1].disconnected, true);
    app.realtime.update(page(null));
    assert.equal(app.connected, false);
    assert.equal(app.clients.length, 2);
});

test('hidden or offline apps disconnect and fetch missed changes after reconnecting', () => {
    const app = runtime();
    app.realtime.update(page());
    app.clients[0].emit('pusher:subscription_succeeded');
    app.document.hidden = true;
    app.realtime.pause();
    app.realtime.resume();
    assert.equal(app.clients.length, 1);
    assert.equal(app.connected, false);
    app.document.hidden = false;
    app.navigator.onLine = false;
    app.realtime.resume();
    assert.equal(app.clients.length, 1);
    app.navigator.onLine = true;
    app.realtime.resume();
    app.clients[1].emit('pusher:subscription_succeeded');
    assert.equal(app.changes, 2);
    assert.equal(app.connected, true);
    app.clients[1].connectionHandlers.state_change({ current: 'unavailable' });
    assert.equal(app.connected, false);
});

test('authorization uses the current CSRF cookie and only the local application endpoint', () => {
    const app = runtime();
    app.realtime.update(page());
    const authorization = app.clients[0].options.channelAuthorization;
    assert.equal(authorization.endpoint, '/broadcasting/auth');
    assert.equal(authorization.headersProvider()['X-XSRF-TOKEN'], 'first-token');
    app.document.cookie = 'XSRF-TOKEN=rotated-token';
    assert.equal(authorization.headersProvider()['X-XSRF-TOKEN'], 'rotated-token');
});

test('disabled realtime and non-admin pages never connect', () => {
    const app = runtime();
    const disabled = page();
    disabled.props.mobileRealtime.enabled = false;
    app.realtime.update(disabled);
    app.realtime.update({ ...page(), component: 'absensi/absensi-mobile/Home' });
    assert.equal(app.clients.length, 0);
});

test('failed channel authorization can recover on the next authenticated refresh', () => {
    const app = runtime();
    app.realtime.update(page());
    app.clients[0].emit('pusher:subscription_error');
    assert.equal(app.clients[0].disconnected, true);
    app.realtime.update(page());
    assert.equal(app.clients.length, 2);
    app.clients[1].emit('pusher:subscription_succeeded');
    assert.equal(app.connected, true);
});
