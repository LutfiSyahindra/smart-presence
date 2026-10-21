import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const component = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/ClockIn.vue', import.meta.url), 'utf8');
const source = component.split('<script setup lang="ts">')[1].split('</script>')[0];
const { outputText } = ts.transpileModule(
    `${source}\nexport { requestLocation, submitClockIn, goBack, currentLocation, locationSamples, locating, locationError, currentStep, cameraActive, cameraVideo, cameraStream, outsideDialogOpen, checkingLocation, photoError, form };`,
    {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    },
);

function load({
    android = false,
    nativeLocation,
    clockSkew = 0,
    mode = 'in',
    reloadSucceeds = true,
    photoRejected = false,
    deferReload = false,
} = {}) {
    const exports = {};
    const callbacks = {};
    const timers = new Map();
    const watches = new Set();
    let intervalCalls = 0;
    let nativeCalls = 0;
    let reloads = 0;
    let elapsed = 0;
    const serverTime = Date.now();
    const posts = [];
    const props = {
        mode,
        office: { latitude: -6.2, longitude: 106.8166667, radius_meters: 100 },
        locationChallenge: {
            token: 'a'.repeat(64),
            server_time: serverTime,
            min_samples: 1,
            collection_seconds: 0,
            max_accuracy_meters: 100,
            max_speed_meters_per_second: 80,
        },
    };
    const vue = {
        ref: (value) => ({ value }),
        computed: (getter) => ({
            get value() {
                return getter();
            },
        }),
        onMounted: () => {},
        onUnmounted: (callback) => {
            callbacks.unmount = callback;
        },
    };
    const modules = {
        vue,
        '@inertiajs/vue3': {
            usePage: () => ({ props: { auth: { user: { name: 'Pegawai' } } } }),
            router: {
                reload: (options) => {
                    reloads += 1;
                    callbacks.reload = () => {
                        if (reloadSucceeds) {
                            props.locationChallenge = {
                                ...props.locationChallenge,
                                token: String(reloads).repeat(64),
                                server_time: serverTime + elapsed,
                            };
                            options.onSuccess();
                        }
                        options.onFinish?.();
                    };
                    if (!deferReload) callbacks.reload();
                },
            },
            useForm: (data) => ({
                ...data,
                errors: {},
                clearErrors() {
                    this.errors = {};
                },
                post(url, options) {
                    this.processing = true;
                    posts.push({ url, token: this.location_token, samples: this.location_samples.map((sample) => ({ ...sample })) });
                    options.onStart();
                    if (photoRejected && posts.length === 1) {
                        this.errors = { photo: 'Wajah tidak cocok.' };
                        // The redirect renders a new challenge while Vue preserves local evidence.
                        props.locationChallenge = { ...props.locationChallenge, token: 'b'.repeat(64), server_time: serverTime + elapsed };
                        options.onError();
                    }
                    this.processing = false;
                    options.onFinish();
                },
            }),
        },
        '@/lib/android-attendance-location': {
            isAndroidAttendance: () => android,
            getAndroidAttendanceLocation: async () => {
                nativeCalls += 1;
                return nativeLocation();
            },
        },
        '@/lib/mobile-navigation': {
            mobileGoBack: (fallback) => {
                callbacks.fallback = fallback;
            },
            registerMobileBackHandler: (handler) => {
                callbacks.back = handler;
                return () => { delete callbacks.back; };
            },
        },
    };
    vm.runInNewContext(outputText, {
        exports,
        require: (name) => modules[name] || {},
        Date: class extends Date {
            static now() {
                elapsed += 100;
                return serverTime + clockSkew + elapsed;
            }
        },
        performance: { now: () => elapsed },
        defineProps: () => props,
        route: (name) => name,
        HTMLMediaElement: { HAVE_CURRENT_DATA: 2 },
        File,
        document: { createElement: () => ({ getContext: () => ({ drawImage() {} }), toBlob: (callback) => callback(new Blob(['photo'])) }) },
        window: {
            isSecureContext: true,
            setTimeout: (callback) => {
                const id = timers.size + 1;
                timers.set(id, callback);
                return id;
            },
            clearTimeout: (id) => timers.delete(id),
            setInterval: () => {
                intervalCalls += 1;
                return 1;
            },
            clearInterval: () => {},
        },
        navigator: {
            geolocation: {
                watchPosition: (success, error, options) => {
                    Object.assign(callbacks, { success, error, options });
                    watches.add(1);
                    return 1;
                },
                clearWatch: (id) => watches.delete(id),
            },
        },
    });
    return {
        ...exports,
        callbacks,
        timers,
        watches,
        posts,
        props,
        serverTime,
        intervalCalls: () => intervalCalls,
        nativeCalls: () => nativeCalls,
        reloads: () => reloads,
        deviceNow: () => serverTime + clockSkew + elapsed,
        advance: (milliseconds) => {
            elapsed += milliseconds;
        },
        browserFix: (changes = {}) => callbacks.success({ coords: fix(changes), timestamp: serverTime + clockSkew + elapsed }),
        openCamera: () => {
            exports.currentStep.value = 2;
            exports.cameraActive.value = true;
            exports.cameraVideo.value = { readyState: 2, videoWidth: 640, videoHeight: 480 };
        },
    };
}

const fix = (changes = {}) => ({ latitude: -6.2, longitude: 106.8166667, accuracy: 8, captured_at: Date.now(), is_mock: false, ...changes });

test('the first accurate browser location is ready immediately without a sampling timer', () => {
    const state = load();
    state.requestLocation();
    assert.equal(state.locating.value, true);
    assert.equal(state.callbacks.options.maximumAge, 0);
    state.browserFix();
    assert.equal(state.locating.value, false);
    assert.equal(state.currentLocation.value.latitude, -6.2);
    assert.equal(state.locationSamples.value.length, 1);
    assert.equal(state.watches.size, 0);
    assert.equal(state.timers.size, 0);
    assert.equal(state.intervalCalls(), 0);
});

test('Android requests only one accurate native fix without a sampling delay', async () => {
    const state = load({ android: true, nativeLocation: async () => fix() });
    state.requestLocation();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.nativeCalls(), 1);
    assert.equal(state.locating.value, false);
    assert.equal(state.locationSamples.value.length, 1);
    assert.equal(state.timers.size, 0);
    assert.equal(state.intervalCalls(), 0);
});

test('low accuracy does not make the browser location ready', () => {
    const state = load();
    state.requestLocation();
    state.browserFix({ accuracy: 101 });
    assert.equal(state.currentLocation.value, null);
    assert.equal(state.locationSamples.value.length, 0);
    assert.equal(state.locating.value, true);
    [...state.timers.values()][0]();
    assert.equal(state.locating.value, false);
    assert.match(state.locationError.value, /Akurasi GPS/);
});

test('mock locations and impossible movement still fail verification', () => {
    const mocked = load();
    mocked.requestLocation();
    mocked.browserFix({ mocked: true });
    assert.equal(mocked.currentLocation.value, null);
    assert.match(mocked.locationError.value, /Fake GPS/);

    const moving = load();
    moving.requestLocation();
    moving.browserFix({ accuracy: 101 });
    moving.browserFix({ latitude: -7.257472, longitude: 112.75209 });
    assert.equal(moving.currentLocation.value, null);
    assert.match(moving.locationError.value, /perpindahan GPS/);
});

test('an old request cannot overwrite a retried or closed location step', () => {
    const state = load();
    state.requestLocation();
    const oldSuccess = state.callbacks.success;
    state.requestLocation();
    oldSuccess({ coords: fix() });
    assert.equal(state.currentLocation.value, null);
    state.callbacks.unmount();
    state.callbacks.success({ coords: fix() });
    assert.equal(state.currentLocation.value, null);
    assert.equal(state.watches.size, 0);
    assert.equal(state.timers.size, 0);
});

test('fresh GPS uses server time when the device clock is ahead or behind', async () => {
    for (const clockSkew of [-120_000, 120_000]) {
        const browser = load({ clockSkew });
        const collected = browser.requestLocation();
        browser.browserFix();
        assert.equal(await collected, true);
        assert.ok(Math.abs(browser.locationSamples.value[0].captured_at - browser.serverTime) < 1000);

        let android;
        android = load({ android: true, clockSkew, nativeLocation: () => fix({ captured_at: android.deviceNow() }) });
        assert.equal(await android.requestLocation(), true);
        assert.ok(Math.abs(android.locationSamples.value[0].captured_at - android.serverTime) < 1000);
    }
});

test('cached or future browser fixes are ignored until a fresh fix arrives', async () => {
    for (const age of [20_000, -10_000]) {
        const state = load();
        const collected = state.requestLocation();
        state.callbacks.success({ coords: fix(), timestamp: state.deviceNow() - age });
        assert.equal(state.currentLocation.value, null);
        assert.equal(state.locating.value, true);
        state.browserFix();
        assert.equal(await collected, true);
    }
});

test('clock-in and clock-out refresh the challenge and replace old GPS before submitting', async () => {
    for (const mode of ['in', 'out']) {
        const state = load({ mode, clockSkew: -120_000 });
        state.requestLocation();
        state.browserFix();
        const oldTimestamp = state.locationSamples.value[0].captured_at;
        state.openCamera();
        state.advance(360_000);
        const submitting = state.submitClockIn();
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(state.currentStep.value, 2);
        assert.equal(state.checkingLocation.value, true);
        assert.equal(state.posts.length, 0);
        state.browserFix();
        await submitting;
        assert.equal(state.posts.length, 1);
        assert.equal(state.posts[0].url, `absensi.mobile.clock-${mode}.store`);
        assert.equal(state.posts[0].token, '1'.repeat(64));
        assert.equal(state.posts[0].samples.length, 1);
        assert.ok(state.posts[0].samples[0].captured_at > oldTimestamp + 350_000);
        assert.equal(state.checkingLocation.value, false);
    }
});

test('a face verification retry sends GPS collected after the new challenge', async () => {
    const state = load({ photoRejected: true });
    state.requestLocation();
    state.browserFix();
    state.openCamera();
    for (let attempt = 0; attempt < 2; attempt += 1) {
        state.advance(60_000);
        const submitting = state.submitClockIn();
        await new Promise((resolve) => setImmediate(resolve));
        state.browserFix();
        await submitting;
        assert.equal(state.currentStep.value, 2);
    }
    assert.equal(state.posts.length, 2);
    assert.equal(state.posts[1].token, '2'.repeat(64));
    assert.ok(state.posts[1].samples[0].captured_at > state.posts[0].samples[0].captured_at + 59_000);
});

test('Android submission replaces the old native fix and retains its mock check', async () => {
    let state;
    state = load({ android: true, nativeLocation: () => fix({ captured_at: state.deviceNow() }) });
    await state.requestLocation();
    state.openCamera();
    state.advance(360_000);
    await state.submitClockIn();
    assert.equal(state.nativeCalls(), 2);
    assert.equal(state.posts.length, 1);
    assert.equal(state.posts[0].samples.length, 1);
    assert.equal(state.posts[0].samples[0].is_mock, false);
});

test('a failed challenge refresh keeps the camera step available without submitting', async () => {
    const state = load({ reloadSucceeds: false });
    state.requestLocation();
    state.browserFix();
    state.openCamera();
    await state.submitClockIn();
    assert.equal(state.posts.length, 0);
    assert.equal(state.currentStep.value, 2);
    assert.equal(state.checkingLocation.value, false);
    assert.match(state.photoError.value, /koneksi/);
});

test('mock GPS or a real location change during face verification prevents submission', async () => {
    for (const changes of [{ mocked: true }, { latitude: -7.257472, longitude: 112.75209 }]) {
        const state = load();
        state.requestLocation();
        state.browserFix();
        state.openCamera();
        const submitting = state.submitClockIn();
        await new Promise((resolve) => setImmediate(resolve));
        state.browserFix(changes);
        await submitting;
        assert.equal(state.posts.length, 0);
        assert.equal(state.currentStep.value, 1);
    }
});

test('unmounting while refreshing GPS cancels the pending submission', async () => {
    const state = load();
    state.requestLocation();
    state.browserFix();
    state.openCamera();
    const submitting = state.submitClockIn();
    await new Promise((resolve) => setImmediate(resolve));
    state.callbacks.unmount();
    await submitting;
    assert.equal(state.posts.length, 0);
    assert.equal(state.checkingLocation.value, false);
    assert.equal(state.watches.size, 0);
});

test('leaving during a challenge refresh cannot restart GPS collection', async () => {
    const state = load({ deferReload: true });
    state.requestLocation();
    state.browserFix();
    state.openCamera();
    const submitting = state.submitClockIn();
    state.callbacks.unmount();
    state.callbacks.reload();
    await submitting;
    assert.equal(state.posts.length, 0);
    assert.equal(state.watches.size, 0);
    assert.equal(state.timers.size, 0);
    assert.equal(state.checkingLocation.value, false);
});

test('Android back leaves the camera step and stops camera tracks for both clock modes', () => {
    for (const mode of ['in', 'out']) {
        const state = load({ mode });
        let stopped = false;
        state.openCamera();
        state.cameraStream.value = { getTracks: () => [{ stop: () => { stopped = true; } }] };
        assert.equal(state.callbacks.back(), true);
        assert.equal(state.currentStep.value, 1);
        assert.equal(state.cameraActive.value, false);
        assert.equal(stopped, true);
        assert.equal(state.callbacks.back(), false);
        state.goBack();
        assert.equal(state.callbacks.fallback, 'absensi.mobile.home');
    }
});

test('Android back closes the outside-location dialog before leaving the attendance page', () => {
    const state = load();
    state.outsideDialogOpen.value = true;
    assert.equal(state.callbacks.back(), true);
    assert.equal(state.outsideDialogOpen.value, false);
    assert.equal(state.callbacks.back(), false);
});

test('Android back waits while attendance is checking GPS or submitting', () => {
    const state = load();
    state.openCamera();
    state.checkingLocation.value = true;
    assert.equal(state.callbacks.back(), true);
    assert.equal(state.currentStep.value, 2);
    state.checkingLocation.value = false;
    state.form.processing = true;
    assert.equal(state.callbacks.back(), true);
    assert.equal(state.currentStep.value, 2);
});

test('leaving attendance removes its screen-specific Android back handler', () => {
    const state = load();
    assert.equal(typeof state.callbacks.back, 'function');
    state.callbacks.unmount();
    assert.equal(state.callbacks.back, undefined);
});
