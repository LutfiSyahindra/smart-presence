import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/android-attendance-location.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });

function load(capacitor) {
    const exports = {};
    vm.runInNewContext(outputText, { exports, window: { Capacitor: capacitor }, Date });
    return exports;
}

const fix = (changes = {}) => ({ latitude: -6.2, longitude: 106.8166667, accuracy: 8, captured_at: Date.now(), is_mock: false, ...changes });
const android = (nativePromise) => ({ getPlatform: () => 'android', nativePromise });

test('ordinary browsers do not use the Android bridge', () => {
    assert.equal(load().isAndroidAttendance(), false);
    assert.equal(load({ getPlatform: () => 'ios' }).isAndroidAttendance(), false);
});

test('server rendering does not require a browser window', () => {
    const exports = {};
    vm.runInNewContext(outputText, { exports, Date });
    assert.equal(exports.isAndroidAttendance(), false);
});

test('Android requests a native fix with an explicit non-mock result', async () => {
    const position = fix();
    const location = load(
        android(async (plugin, method, options) => {
            assert.equal(plugin, 'AttendanceLocation');
            assert.equal(method, 'getCurrentPosition');
            assert.equal(options.maxAccuracyMeters, 100);
            assert.equal(typeof options.requestId, 'string');
            return position;
        }),
    );
    assert.equal(location.isAndroidAttendance(), true);
    assert.equal(await location.getAndroidAttendanceLocation(), position);
});

test('the native provider receives the configured attendance accuracy threshold', async () => {
    const location = load(android(async (plugin, method, options) => {
        assert.equal(options.maxAccuracyMeters, 45);
        return fix();
    }));
    await location.getAndroidAttendanceLocation(45);
});

test('cancel only targets the in-flight native request and permits a new request', async () => {
    let finish;
    let firstId;
    let nativeCalls = 0;
    const location = load(android(async (plugin, method, options) => {
        if (method === 'cancelCurrentPosition') {
            assert.equal(options.requestId, firstId);
            return {};
        }
        if (++nativeCalls === 1) {
            firstId = options.requestId;
            return new Promise((resolve) => { finish = resolve; });
        }
        assert.notEqual(options.requestId, firstId);
        return fix();
    }));
    const pending = location.getAndroidAttendanceLocation();
    await location.cancelAndroidAttendanceLocation();
    await location.getAndroidAttendanceLocation();
    finish(fix());
    await pending;
    await location.cancelAndroidAttendanceLocation();
    assert.equal(nativeCalls, 2);
});

test('older APKs without native cancellation still ignore cancellation errors', async () => {
    let finish;
    const location = load(android(async (plugin, method) => {
        if (method === 'cancelCurrentPosition') throw new Error('UNIMPLEMENTED');
        return new Promise((resolve) => { finish = resolve; });
    }));
    const pending = location.getAndroidAttendanceLocation();
    await location.cancelAndroidAttendanceLocation();
    finish(fix());
    await pending;
});

test('mock GPS is rejected even when its coordinates and accuracy look normal', async () => {
    const location = load(android(async () => fix({ is_mock: true })));
    await assert.rejects(location.getAndroidAttendanceLocation(), /Fake GPS terdeteksi/);
});

test('absent or ambiguous mock flags fail closed', async () => {
    for (const flag of [undefined, null, 0, 'false']) {
        const location = load(android(async () => fix({ is_mock: flag })));
        await assert.rejects(location.getAndroidAttendanceLocation(), /Keaslian lokasi/);
    }
});

test('invalid, stale, or future fixes cannot be accepted as fresh evidence', async () => {
    for (const changes of [
        { captured_at: Date.now() - 16_000 },
        { captured_at: Date.now() + 10_000 },
        { latitude: NaN },
        { latitude: 91 },
        { longitude: 181 },
        { accuracy: -1 },
        { accuracy: Infinity },
    ]) {
        await assert.rejects(load(android(async () => fix(changes))).getAndroidAttendanceLocation(), /Keaslian lokasi/);
    }
    await assert.rejects(load(android(async () => null)).getAndroidAttendanceLocation(), /Keaslian lokasi/);
});

test('missing or failing native checks block attendance without browser fallback', async () => {
    await assert.rejects(load(android()).getAndroidAttendanceLocation(), /Perbarui aplikasi Android/);
    for (const code of [
        'MOCK_LOCATION',
        'LOCATION_PERMISSION_DENIED',
        'LOCATION_DISABLED',
        'LOCATION_TIMEOUT',
        'LOCATION_INTERRUPTED',
        'UNIMPLEMENTED',
    ]) {
        const location = load(
            android(async () => {
                throw new Error(code);
            }),
        );
        await assert.rejects(location.getAndroidAttendanceLocation(), new RegExp(code));
    }
});

test('a fresh check detects Fake GPS enabled after the initial verification', async () => {
    let calls = 0;
    const location = load(android(async () => fix({ is_mock: ++calls > 1 })));
    await location.getAndroidAttendanceLocation();
    await assert.rejects(location.getAndroidAttendanceLocation(), /Fake GPS terdeteksi/);
});
