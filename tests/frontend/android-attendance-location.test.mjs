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
        android(async (plugin, method) => {
            assert.equal(plugin, 'AttendanceLocation');
            assert.equal(method, 'getCurrentPosition');
            return position;
        }),
    );
    assert.equal(location.isAndroidAttendance(), true);
    assert.equal(await location.getAndroidAttendanceLocation(), position);
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
