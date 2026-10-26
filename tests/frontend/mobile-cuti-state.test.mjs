import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/Cuti.vue', import.meta.url), 'utf8');
const script = compileScript(parse(source).descriptor, { id: 'mobile-cuti-state-test' }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function runtime() {
    const request = { id: 42, status: 'pending', can_cancel: true, can_decide: false };
    const props = vue.reactive({
        today: '2026-10-26',
        filters: { scope: 'mine', month: '2026-10', status: '' },
        requests: { data: [request] },
        initialRequest: request,
        leaveOptions: [{ id: 1, is_tahunan: true, tahun: 2026, sisa: 10 }],
    });
    const posts = [];
    let back;
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        route: (name) => name,
        require(name) {
            if (name === 'vue') return { ...vue, onUnmounted() {} };
            if (name === '@/lib/mobile-navigation')
                return {
                    registerMobileBackHandler(handler) {
                        back = handler;
                        return () => {};
                    },
                };
            if (name === '@/composables/usePermission') return { usePermission: () => ({ can: () => true }) };
            if (name === '@inertiajs/vue3')
                return {
                    usePage: () => ({ props: { flash: {} } }),
                    useForm(initial) {
                        const form = vue.reactive({
                            ...initial,
                            errors: {},
                            processing: false,
                            clearErrors() {
                                form.errors = {};
                            },
                            reset() {
                                Object.assign(form, initial);
                            },
                            post(url, options) {
                                posts.push({ url, options });
                            },
                        });
                        return form;
                    },
                };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {} }));
    return { props, state, posts, back: () => back(), close: () => scope.stop() };
}

test('leave submission replaces its history entry and closes and resets the submitted form', () => {
    const app = runtime();
    try {
        app.state.create();
        app.state.form.alasan = 'Cuti keluarga';
        app.state.submit();
        assert.equal(app.posts[0].options.replace, true);
        app.posts[0].options.onSuccess();
        assert.equal(app.state.sheet.value, null);
        assert.equal(app.state.form.alasan, '');
    } finally {
        app.close();
    }
});

test('approval updates refresh the open detail without erasing drafts or reopening a closed detail', async () => {
    const app = runtime();
    try {
        app.state.decision.note = 'Catatan yang sedang ditulis';
        app.state.form.alasan = 'Draf cuti berikutnya';
        app.props.initialRequest = { ...app.props.initialRequest, status: 'approved', can_cancel: false };
        app.props.requests.data = [app.props.initialRequest];
        await vue.nextTick();
        assert.equal(app.state.selected.value.status, 'approved');
        assert.equal(app.state.selected.value.can_cancel, false);
        assert.equal(app.state.decision.note, 'Catatan yang sedang ditulis');
        assert.equal(app.state.form.alasan, 'Draf cuti berikutnya');
        app.state.selected.value = null;
        app.props.initialRequest = { ...app.props.initialRequest };
        await vue.nextTick();
        assert.equal(app.state.selected.value, null);
    } finally {
        app.close();
    }
});

test('a list detail also follows the refreshed request status', async () => {
    const app = runtime();
    try {
        app.props.initialRequest = null;
        app.state.openDetail(app.props.requests.data[0]);
        app.props.requests.data = [{ ...app.props.requests.data[0], status: 'rejected', can_cancel: false }];
        await vue.nextTick();
        assert.equal(app.state.selected.value.status, 'rejected');
    } finally {
        app.close();
    }
});

test('Android back closes the confirmation, detail, and request sheet before leaving and waits for submission', () => {
    const app = runtime();
    try {
        app.state.confirmingCancellation.value = true;
        assert.equal(app.back(), true);
        assert.equal(app.state.confirmingCancellation.value, false);
        assert.ok(app.state.selected.value);
        assert.equal(app.back(), true);
        assert.equal(app.state.selected.value, null);
        app.state.create();
        app.state.form.processing = true;
        assert.equal(app.back(), true);
        assert.equal(app.state.sheet.value, 'create');
        app.state.form.processing = false;
        assert.equal(app.back(), true);
        assert.equal(app.state.sheet.value, null);
        assert.equal(app.back(), false);
    } finally {
        app.close();
    }
});
