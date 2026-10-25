import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../apps/absensi-mobile/capacitor.config.ts', import.meta.url), 'utf8');
const production = JSON.parse(await readFile(new URL('../../apps/absensi-mobile/production.json', import.meta.url), 'utf8'));
const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
});

function config(env = {}, origin = production.origin) {
    const exports = {};
    vm.runInNewContext(outputText, {
        exports,
        process: { env },
        URL,
        require: (name) => {
            assert.equal(name, './production.json');
            return { origin };
        },
    });
    return exports.default;
}

test('release embeds only the approved HTTPS domain without a live-reload URL', () => {
    const release = config({ CAPACITOR_BUILD_MODE: 'release', CAPACITOR_PWA_URL: 'http://localhost:8600' });
    assert.equal(release.server.hostname, new URL(production.origin).hostname);
    assert.equal(release.server.url, undefined);
    assert.equal(release.server.androidScheme, 'https');
    assert.equal(release.server.cleartext, false);
    assert.equal(release.android.allowMixedContent, false);
    assert.equal(release.android.webContentsDebuggingEnabled, false);
    assert.equal(release.android.useLegacyBridge, false);
    assert.equal(release.android.resolveServiceWorkerRequests, false);
    assert.equal(release.server.errorPath, 'native/offline.html');
});

test('release rejects loopback, HTTP, credentials, paths, query strings, fragments and alternate ports', () => {
    for (const origin of [
        'http://absensi.rsarsylamongan.com',
        'https://localhost',
        'http://localhost:8600',
        'https://user:secret@absensi.rsarsylamongan.com',
        'https://absensi.rsarsylamongan.com/absensi/mobile',
        'https://absensi.rsarsylamongan.com?debug=true',
        'https://absensi.rsarsylamongan.com#home',
        'https://absensi.rsarsylamongan.com:8443',
    ]) {
        assert.throws(() => config({ CAPACITOR_BUILD_MODE: 'release' }, origin), undefined, origin);
    }
});

test('development keeps its existing localhost wrapper and optional HTTPS test origin', () => {
    assert.equal(config().server.url, 'http://localhost:8600');
    assert.equal(config().server.cleartext, true);
    assert.equal(config({ CAPACITOR_PWA_URL: production.origin }).server.url, production.origin);
    assert.throws(() => config({ CAPACITOR_PWA_URL: 'http://192.168.1.10:8600' }), /HTTPS/);
});
