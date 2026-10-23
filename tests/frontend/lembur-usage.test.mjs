import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/LemburUsageCreate.vue', import.meta.url), 'utf8');
const script = source.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
const { outputText } = ts.transpileModule(`${script}\nglobalThis.usage = { form, range, remaining, submit, leave, discard, discardOpen };`, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});

function runtime(overrides = {}) {
    const posts = [];
    const backs = [];
    let backHandler;
    let guard;
    const props = {
        today: '2026-10-23',
        balance: { earned_minutes: 180, available_minutes: 120, reserved_minutes: 60, used_minutes: 0 },
        employee: { name: 'Nadia' },
        shift: { id: 1, start_time: '08:00', end_time: '16:00' },
        unavailableReason: null,
        ...overrides,
    };
    const context = {
        exports: {},
        defineProps: () => props,
        route: (name) => name,
        require(name) {
            if (name === 'vue')
                return {
                    computed: (get) => ({
                        get value() {
                            return get();
                        },
                    }),
                    ref: (value) => ({ value }),
                    onMounted: (fn) => fn(),
                    onUnmounted: () => {},
                };
            if (name === '@/lib/mobile-navigation')
                return {
                    mobileGoBack: (fallback) => backs.push(fallback),
                    registerMobileBackHandler: (fn) => {
                        backHandler = fn;
                    },
                };
            if (name === './lembur/types') return { lemburDuration: (minutes) => `${Math.floor(minutes / 60)}j ${minutes % 60}m` };
            if (name === '@inertiajs/vue3')
                return {
                    router: {
                        on: (_event, fn) => {
                            guard = fn;
                        },
                    },
                    useForm: (data) => ({
                        ...data,
                        errors: {},
                        processing: false,
                        isDirty: false,
                        get hasErrors() {
                            return Object.keys(this.errors).length > 0;
                        },
                        clearErrors() {
                            this.errors = {};
                        },
                        setError(field, message) {
                            this.errors[field] = message;
                        },
                        transform(fn) {
                            this.transformer = fn;
                            return this;
                        },
                        post(url, options) {
                            posts.push({ url, data: this.transformer(this), options });
                        },
                    }),
                };
            return {};
        },
    };
    vm.createContext(context);
    vm.runInContext(outputText, context);
    return { ...context.usage, posts, backs, back: () => backHandler(), navigate: () => guard({ detail: { visit: { method: 'get' } } }) };
}

test('one hour previews the approved arrival or departure boundary in WIB', () => {
    const app = runtime();
    assert.match(app.range.value.time, /09[.:]00/);
    assert.equal(app.remaining.value, 60);
    app.form.usage_type = 'early_departure';
    assert.match(app.range.value.time, /15[.:]00/);
    app.form.tanggal_lembur = '';
    assert.equal(app.range.value, null);
});

test('overnight departure previews the following calendar day', () => {
    const app = runtime({ shift: { id: 2, start_time: '22:00', end_time: '06:00' } });
    app.form.usage_type = 'early_departure';
    assert.match(app.range.value.time, /24 Okt/);
    assert.match(app.range.value.time, /05[.:]00/);
});

test('overdrawn, fractional, empty and full shift requests stay on the form', () => {
    for (const minutes of [0, -1, 1.5, 121]) {
        const app = runtime();
        app.form.duration_minutes = minutes;
        app.form.alasan = 'Keperluan keluarga';
        app.submit();
        assert.ok(app.form.errors.duration_minutes);
        assert.equal(app.posts.length, 0);
    }
    const app = runtime({ balance: { available_minutes: 600 } });
    app.form.duration_minutes = 480;
    app.submit();
    assert.ok(app.form.errors.duration_minutes);
    assert.ok(app.form.errors.alasan);
    assert.equal(app.posts.length, 0);
});

test('valid usage submits the selected purpose and trimmed reason once', () => {
    const app = runtime();
    app.form.usage_type = 'early_departure';
    app.form.alasan = '  Keperluan keluarga  ';
    app.submit();
    assert.equal(app.posts[0].url, 'absensi.mobile.lembur.usage.store');
    assert.equal(app.posts[0].data.duration_minutes, 60);
    assert.equal(app.posts[0].data.usage_type, 'early_departure');
    assert.equal(app.posts[0].data.alasan, 'Keperluan keluarga');
    app.form.processing = true;
    app.submit();
    assert.equal(app.posts.length, 1);
});

test('Android back and in-app navigation protect unsent inputs and in-flight requests', () => {
    const app = runtime();
    app.form.processing = true;
    assert.equal(app.back(), true);
    assert.equal(app.navigate(), false);
    app.leave();
    assert.equal(app.backs.length, 0);
    app.form.processing = false;
    app.form.isDirty = true;
    assert.equal(app.back(), true);
    assert.equal(app.discardOpen.value, true);
    assert.equal(app.back(), true);
    assert.equal(app.discardOpen.value, false);
    assert.equal(app.navigate(), false);
    app.discard();
    assert.equal(app.backs[0], 'absensi.mobile.lembur.index');
});
