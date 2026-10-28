import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as Vue from 'vue';

const source = await readFile(new URL('../../resources/js/lib/mobile-app-update.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });

function runtime({ platform = 'android', nativePromise, response = {}, ok = true } = {}) {
    const requests = [];
    const opened = [];
    const exports = {};
    vm.runInNewContext(outputText, {
        exports, URLSearchParams, setTimeout, clearTimeout, AbortSignal,
        window: { Capacitor: { getPlatform: () => platform, nativePromise }, open: (...args) => opened.push(args) },
        fetch: async (...args) => { requests.push(args); return { ok, json: async () => response }; },
    });
    return { ...exports, requests, opened };
}

test('Android reads installed version and OS from native plugin; web and iOS are excluded', async () => {
    const expected = { version_code: 2, version_name: '1.1.0', sdk_int: 36 };
    const app = runtime({ nativePromise: async (plugin, method) => { assert.equal(plugin, 'AppUpdates'); assert.equal(method, 'getInfo'); return expected; } });
    assert.equal(app.isAndroidApp(), true);
    assert.deepEqual(await app.installedApp(), expected);
    assert.equal(runtime({ platform: 'web' }).isAndroidApp(), false);
    assert.equal(runtime({ platform: 'ios' }).isAndroidApp(), false);
});

test('legacy or broken bridge uses unknown version without guessing the installed APK', async () => {
    const app = runtime({ nativePromise: async () => { throw new Error('UNIMPLEMENTED'); } });
    assert.equal((await app.installedApp()).version_code, 0);
    assert.equal((await runtime().installedApp()).version_code, 0);
});

test('checks the public API with actual version and SDK and avoids stale cached policies', async () => {
    const app = runtime({ response: { update_available: true } });
    assert.equal((await app.checkAppUpdate({ version_code: 2, sdk_int: 36 })).update_available, true);
    assert.equal(app.requests[0][0], '/mobile-app/android/release?version_code=2&sdk_int=36');
    assert.equal(app.requests[0][1].cache, 'no-store');
    await assert.rejects(runtime({ ok: false }).checkAppUpdate({ version_code: 2 }));
});

test('opens a release in native browser and never accepts a download URL supplied by the API', async () => {
    const calls = [];
    const app = runtime({ nativePromise: async (...args) => { calls.push(args); } });
    assert.equal(await app.openAppDownload({ id: 7, download_url: 'https://attacker.test/app.apk' }), true);
    assert.equal(calls[0][0], 'AppUpdates');
    assert.equal(calls[0][1], 'openDownload');
    assert.equal(calls[0][2].releaseId, 7);
    assert.equal(app.opened.length, 0);
    await assert.rejects(app.openAppDownload({ id: -1 }));
});

test('legacy APK requests manual browser update and prompts wait for attendance and forms to finish', async () => {
    const app = runtime({ nativePromise: async () => { throw new Error('UNIMPLEMENTED'); } });
    assert.equal(await app.openAppDownload({ id: 7 }), false);
    assert.equal(app.opened.length, 0);
    assert.equal(app.canPresentAppUpdate('/login'), true);
    assert.equal(app.canPresentAppUpdate('/absensi/mobile/'), true);
    assert.equal(app.canPresentAppUpdate('/absensi/mobile/akun'), true);
    for (const path of ['/absensi/mobile/clock-in', '/absensi/mobile/clock-out', '/absensi/mobile/presensi/proses/2', '/absensi/mobile/lembur/ajukan', '/absensi/mobile/cuti']) {
        assert.equal(app.canPresentAppUpdate(path), false);
    }
});

test('in-app download and installation pass only a release ID to native code', async () => {
    const calls = [];
    const app = runtime({ nativePromise: async (...args) => { calls.push(args); } });
    const release = { id: 9, download_url: 'https://attacker.test/app.apk', sha256: 'untrusted', path: '/sdcard/other.apk' };
    await app.downloadAppUpdate(release);
    await app.installAppUpdate(release);
    assert.equal(calls[0][0], 'AppUpdates');
    assert.equal(calls[0][1], 'downloadUpdate');
    assert.equal(calls[1][1], 'installUpdate');
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0][2])), { releaseId: 9 });
    assert.deepEqual(JSON.parse(JSON.stringify(calls[1][2])), { releaseId: 9 });
    assert.equal(app.opened.length, 0);
    for (const id of [-1, 0, 1.5, NaN, '9']) {
        await assert.rejects(app.downloadAppUpdate({ id }));
        await assert.rejects(app.installAppUpdate({ id }));
    }
});

test('download errors are surfaced and never silently redirected to a browser', async () => {
    const app = runtime({ nativePromise: async () => { throw new Error('Koneksi terputus'); } });
    await assert.rejects(app.downloadAppUpdate({ id: 7 }), /Koneksi terputus/);
    await assert.rejects(app.installAppUpdate({ id: 7 }), /Koneksi terputus/);
    assert.equal(app.opened.length, 0);
    await assert.rejects(runtime({ platform: 'web' }).downloadAppUpdate({ id: 7 }));
    await assert.rejects(runtime({ platform: 'ios' }).installAppUpdate({ id: 7 }));
});

test('native progress can be restored after resuming the WebView', async () => {
    const progress = { stage: 'ready', release_id: 9, downloaded_bytes: 100, total_bytes: 100, error: '' };
    const app = runtime({ nativePromise: async (plugin, method) => {
        assert.equal(plugin, 'AppUpdates');
        assert.equal(method, 'getUpdateState');
        return progress;
    } });
    assert.deepEqual(await app.appUpdateProgress(), progress);
});

const component = await readFile(new URL('../../resources/js/components/MobileAppUpdate.vue', import.meta.url), 'utf8');
const componentScript = component.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
const componentCode = ts.transpileModule(`${componentScript}\nexport const api = {
    check, download, install, readProgress, dismiss, offline, visibility, status, busy, error, presenting, packageReady, required, path
};`, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

function updateModal({ nativePromise, mandatory = false } = {}) {
    const release = { id: 9, version_code: 4, version_name: '1.3.0', size_bytes: 100, release_notes: 'Perbaikan', required_at: null };
    const app = runtime({ nativePromise, response: { release, update_available: true, compatible: true, update_required: mandatory } });
    const exports = {};
    const document = { hidden: false };
    vm.runInNewContext(componentCode, {
        exports, Error, setInterval, clearInterval, navigator: { onLine: true }, document,
        window: { location: { pathname: '/absensi/mobile', origin: 'https://presensi.test' } },
        require(name) {
            if (name === '@/lib/mobile-app-update') return app;
            if (name === 'vue') return { ...Vue, onMounted() {}, onBeforeUnmount() {} };
            if (name === '@inertiajs/vue3') return { router: {} };
            if (name === 'lucide-vue-next') return {};
            throw new Error(`Unexpected import: ${name}`);
        },
    });
    return { ...exports.api, app, document };
}

test('modal downloads inside the app, automatically opens installer, and can retry after cancellation', async () => {
    const calls = [];
    let stage = 'idle';
    const modal = updateModal({ nativePromise: async (plugin, method) => {
        calls.push(method);
        if (method === 'getInfo') return { version_code: 3, sdk_int: 36, in_app_updates: true };
        if (method === 'downloadUpdate') { stage = 'downloading'; return; }
        if (method === 'getUpdateState') return { stage, release_id: 9, downloaded_bytes: 100, total_bytes: 100, error: '' };
        if (method === 'installUpdate') { stage = 'ready'; return; }
        throw new Error(`Unexpected native method: ${method}`);
    } });
    await modal.check(true);
    await Vue.nextTick();
    assert.equal(Boolean(modal.presenting.value), true);
    await modal.download();
    assert.equal(modal.busy.value, true);
    await modal.check(true);
    assert.equal(modal.app.requests.length, 1, 'policy checks wait for download completion');
    stage = 'ready';
    modal.document.hidden = true;
    await modal.readProgress();
    assert.equal(calls.includes('installUpdate'), false, 'installer does not open while backgrounded');
    modal.document.hidden = false;
    await modal.readProgress();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(calls.filter(method => method === 'installUpdate').length, 1);
    assert.equal(modal.packageReady.value, true);
    assert.equal(modal.busy.value, false);
    await modal.install();
    assert.equal(calls.filter(method => method === 'downloadUpdate').length, 1);
    assert.equal(calls.filter(method => method === 'installUpdate').length, 2);
    assert.equal(calls.includes('openDownload'), false);
});

test('failed native download stays retryable and reports the error', async () => {
    const modal = updateModal({ nativePromise: async (plugin, method) => {
        if (method === 'getInfo') return { version_code: 3, in_app_updates: true };
        if (method === 'downloadUpdate') throw new Error('Tidak cukup ruang penyimpanan');
    } });
    await modal.check(true);
    await Vue.nextTick();
    await modal.download();
    assert.equal(modal.busy.value, false);
    assert.equal(modal.error.value, 'Tidak cukup ruang penyimpanan');
    assert.equal(Boolean(modal.presenting.value), true);
});

test('mandatory prompts wait for safe screens and offline users can continue attendance', async () => {
    const modal = updateModal({ mandatory: true, nativePromise: async () => ({ version_code: 3, in_app_updates: true }) });
    await modal.check(true);
    await Vue.nextTick();
    modal.path.value = '/absensi/mobile/clock-in';
    assert.equal(Boolean(modal.presenting.value), false);
    modal.path.value = '/absensi/mobile';
    modal.dismiss();
    assert.equal(Boolean(modal.presenting.value), true);
    modal.offline();
    assert.equal(Boolean(modal.required.value), false);
    assert.equal(Boolean(modal.presenting.value), false);
});

test('build 2 uses its browser fallback until the native updater has been installed once', async () => {
    const calls = [];
    const modal = updateModal({ nativePromise: async (plugin, method) => {
        calls.push(method);
        if (method === 'getInfo') return { version_code: 2, sdk_int: 36 };
    } });
    await modal.check(true);
    await Vue.nextTick();
    await modal.download();
    assert.equal(calls.includes('openDownload'), true);
    assert.equal(calls.includes('downloadUpdate'), false);
});
