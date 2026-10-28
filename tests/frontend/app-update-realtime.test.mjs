import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/app-update-realtime.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const page = () => ({
    component: 'auth/Login',
    props: {
        mobileRealtime: { enabled: true, key: 'public-key', host: 'presensi.test', port: 443, secure: true, path: '' },
    },
});

function runtime(android = true) {
    const clients = [];
    const document = { hidden: false };
    const navigator = { onLine: true };
    let changes = 0;
    class Pusher {
        constructor(key, options) {
            this.options = options;
            this.handlers = {};
            this.connection = { bind() {} };
            clients.push(this);
        }
        subscribe(channel) {
            this.channel = channel;
            return {
                bind: (event, callback) => {
                    this.handlers[event] = callback;
                },
            };
        }
        emit(event, value) {
            this.handlers[event](value);
        }
        unbind_all() {}
        disconnect() {
            this.disconnected = true;
        }
    }
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        document,
        navigator,
        require: (name) => (name === 'pusher-js' ? { default: Pusher } : { isAndroidApp: () => android }),
    });
    return {
        realtime: exports.createAppUpdateRealtime(() => changes++),
        clients,
        document,
        navigator,
        get changes() {
            return changes;
        },
    };
}

test('Android receives public release announcements before login and recovers on subscription', () => {
    const app = runtime();
    app.realtime.update(page());
    app.realtime.update({ ...page(), component: 'absensi/absensi-mobile/Home' });
    assert.equal(app.clients.length, 1);
    const socket = app.clients[0];
    assert.equal(socket.channel, 'mobile.app-releases');
    assert.equal(socket.options.forceTLS, true);
    socket.emit('pusher:subscription_succeeded');
    socket.emit('mobile.app-release.changed', { version: 'new-release' });
    socket.emit('mobile.app-release.changed', { version: 'new-release' });
    socket.emit('mobile.app-release.changed', {});
    assert.equal(app.changes, 2);
});

test('release connections pause in the background, reconnect, and ignore obsolete sockets', () => {
    const app = runtime();
    app.realtime.update(page());
    const old = app.clients[0];
    app.document.hidden = true;
    app.realtime.pause();
    app.realtime.resume();
    assert.equal(old.disconnected, true);
    assert.equal(app.clients.length, 1);
    old.emit('mobile.app-release.changed', { version: 'obsolete' });
    assert.equal(app.changes, 0);
    app.document.hidden = false;
    app.navigator.onLine = false;
    app.realtime.resume();
    assert.equal(app.clients.length, 1);
    app.navigator.onLine = true;
    app.realtime.resume();
    app.clients[1].emit('pusher:subscription_succeeded');
    assert.equal(app.changes, 1);
    app.clients[1].emit('pusher:subscription_error');
    app.realtime.resume();
    assert.equal(app.clients.length, 3);
});

test('web/iOS and disabled configurations never subscribe to APK announcements', () => {
    const web = runtime(false);
    web.realtime.update(page());
    assert.equal(web.clients.length, 0);
    const app = runtime();
    app.realtime.update(page());
    const disabled = page();
    disabled.props.mobileRealtime.enabled = false;
    app.realtime.update(disabled);
    assert.equal(app.clients[0].disconnected, true);
    assert.equal(app.clients.length, 1);
});
