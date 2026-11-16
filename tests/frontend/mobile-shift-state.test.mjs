import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/PerubahanShift.vue', import.meta.url), 'utf8');
const compiled = ts.transpileModule(compileScript(parse(source).descriptor, { id: 'mobile-shift-state-test' }).content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function runtime(overrides = {}) {
    const props = vue.reactive({
        today: '2026-11-02',
        month: '2026-11',
        status: '',
        scope: 'mine',
        initialCreate: true,
        initialRequest: null,
        requests: { data: [] },
        currentShift: { id: 7 },
        scheduledShiftIds: {},
        allowPastDates: false,
        shifts: [],
        ...overrides,
    });
    const exports = {};
    const cleanup = [];
    let back;
    vm.runInNewContext(compiled, {
        exports,
        require(name) {
            if (name === 'vue') return { ...vue, onMounted: (handler) => handler(), onUnmounted: (handler) => cleanup.push(handler) };
            if (name === '@/lib/mobile-navigation')
                return {
                    registerMobileBackHandler(handler) {
                        back = handler;
                        return () => {};
                    },
                };
            if (name === '@inertiajs/vue3')
                return {
                    usePage: () => ({ props: { flash: {} } }),
                    useForm(initial) {
                        let defaults = { ...initial };
                        return vue.reactive({
                            ...initial,
                            errors: {},
                            processing: false,
                            defaults(values) {
                                defaults = { ...values };
                            },
                            reset() {
                                Object.assign(this, defaults);
                            },
                            clearErrors() {
                                this.errors = {};
                            },
                        });
                    },
                };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {} }));
    return {
        props,
        state,
        back: () => back(),
        close() {
            cleanup.forEach((handler) => handler());
            scope.stop();
        },
    };
}

test('instant shift entry opens the choice before the form and keeps drafts across refreshes', async () => {
    const app = runtime();
    try {
        assert.equal(app.state.sheet.value, 'type');
        app.props.requests = { data: [] };
        await vue.nextTick();
        assert.equal(app.state.sheet.value, 'type');
        app.state.openForm();
        assert.equal(app.state.sheet.value, 'create');
        assert.equal(app.state.form.tanggal_perubahan, '2026-11-02');
        assert.equal(app.state.form.original_shift_id, 7);
        app.state.form.requested_shift_id = '8';
        app.state.form.alasan = 'Menyesuaikan jadwal keluarga';
        app.props.requests = { data: [] };
        app.props.currentShift = { id: 7 };
        await vue.nextTick();
        assert.equal(app.state.form.requested_shift_id, '8');
        assert.equal(app.state.form.alasan, 'Menyesuaikan jadwal keluarga');
        app.state.sheet.value = null;
        app.props.initialCreate = false;
        await vue.nextTick();
        app.props.initialCreate = true;
        await vue.nextTick();
        assert.equal(app.state.sheet.value, null);
    } finally {
        app.close();
    }
});

test('the apply button opens the choice and mobile back closes it before leaving the page', () => {
    const app = runtime({ initialCreate: false });
    try {
        assert.equal(app.back(), false);
        app.state.openSubmissionOptions();
        assert.equal(app.state.sheet.value, 'type');
        assert.equal(app.back(), true);
        assert.equal(app.state.sheet.value, null);
        assert.equal(app.back(), false);
        app.state.openSubmissionOptions();
        app.state.closeSheet();
        assert.equal(app.state.sheet.value, null);
    } finally {
        app.close();
    }
});

test('ordinary shift entry and request details never open a new submission', () => {
    for (const app of [runtime({ initialCreate: false }), runtime({ initialRequest: { id: 42 } })]) {
        try {
            assert.equal(app.state.sheet.value, null);
        } finally {
            app.close();
        }
    }
});

test('a request without a default shift is ready only on a date with its own schedule', async () => {
    const app = runtime({
        currentShift: null,
        unavailableReason: null,
        shifts: [{ id: 7 }, { id: 8 }],
        scheduledShiftIds: { '2026-11-03': 7 },
    });
    try {
        app.state.form.requested_shift_id = '8';
        app.state.form.alasan = 'Menyesuaikan jadwal';
        assert.equal(app.state.form.original_shift_id, null);
        assert.ok(!app.state.ready.value);
        app.state.form.tanggal_perubahan = '2026-11-03';
        await vue.nextTick();
        assert.equal(app.state.form.original_shift_id, 7);
        assert.ok(app.state.ready.value);
        app.state.form.tanggal_perubahan = '2026-11-04';
        await vue.nextTick();
        assert.equal(app.state.form.original_shift_id, null);
        assert.ok(!app.state.ready.value);
        assert.equal(app.state.form.alasan, 'Menyesuaikan jadwal');
    } finally {
        app.close();
    }
});

test('the form uses the dated shift and updates it when the chosen date changes', async () => {
    const app = runtime({
        shifts: [{ id: 7 }, { id: 8 }, { id: 9 }],
        scheduledShiftIds: { '2026-10-31': 9, '2026-11-02': 8, '2026-11-03': 9 },
        allowPastDates: true,
    });
    try {
        assert.equal(app.state.originalShift.value.id, 8);
        assert.equal(app.state.form.original_shift_id, 8);
        assert.deepEqual(
            Array.from(app.state.targetShifts.value, (shift) => shift.id),
            [7, 9],
        );
        app.state.form.requested_shift_id = '9';
        app.state.form.alasan = 'Menyesuaikan jadwal';
        app.state.form.tanggal_perubahan = '2026-11-03';
        await vue.nextTick();
        assert.equal(app.state.originalShift.value.id, 9);
        assert.equal(app.state.form.original_shift_id, 9);
        assert.equal(app.state.form.requested_shift_id, '');
        assert.equal(app.state.form.alasan, 'Menyesuaikan jadwal');
        app.state.form.tanggal_perubahan = '2026-10-31';
        await vue.nextTick();
        assert.equal(app.state.originalShift.value.id, 9);
        assert.equal(app.state.form.original_shift_id, 9);
        app.state.form.tanggal_perubahan = '2026-11-04';
        await vue.nextTick();
        assert.equal(app.state.form.original_shift_id, 7);
        app.props.scheduledShiftIds = { '2026-11-04': 8 };
        await vue.nextTick();
        assert.equal(app.state.form.original_shift_id, 8);
    } finally {
        app.close();
    }
});
