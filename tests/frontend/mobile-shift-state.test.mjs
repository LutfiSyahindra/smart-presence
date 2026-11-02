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
        shifts: [],
        ...overrides,
    });
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        require(name) {
            if (name === 'vue') return { ...vue, onMounted() {}, onUnmounted() {} };
            if (name === '@inertiajs/vue3')
                return {
                    usePage: () => ({ props: { flash: {} } }),
                    useForm: (initial) => vue.reactive({ ...initial, errors: {}, processing: false }),
                };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {} }));
    return { props, state, close: () => scope.stop() };
}

test('instant shift entry opens the form with the current date and shift and keeps drafts across refreshes', async () => {
    const app = runtime();
    try {
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

test('ordinary shift entry and request details never open a new submission', () => {
    for (const app of [runtime({ initialCreate: false }), runtime({ initialRequest: { id: 42 } })]) {
        try {
            assert.equal(app.state.sheet.value, null);
        } finally {
            app.close();
        }
    }
});
