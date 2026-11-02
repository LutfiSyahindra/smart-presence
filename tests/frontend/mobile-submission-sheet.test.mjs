import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(
    new URL('../../resources/js/pages/absensi/absensi-mobile/components/MobileSubmissionSheet.vue', import.meta.url),
    'utf8',
);
const compiled = ts.transpileModule(compileScript(parse(source).descriptor, { id: 'submission-sheet-test' }).content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function runtime(initialPermissions = ['mobile.cuti.view', 'mobile.cuti.create', 'mobile.attendance', 'mobile.clock-in', 'mobile.clock-out']) {
    const props = vue.reactive({ open: false });
    const permissions = vue.ref(initialPermissions);
    const events = [];
    const cleanup = [];
    let back;
    let removed = false;
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        route: (name, params) => name + (params ? `?${new URLSearchParams(params)}` : ''),
        require(name) {
            if (name === 'vue') return { ...vue, onUnmounted: (handler) => cleanup.push(handler) };
            if (name === '@/composables/usePermission')
                return { usePermission: () => ({ can: (permission) => permissions.value.includes(permission) }) };
            if (name === '@/lib/mobile-navigation')
                return {
                    registerMobileBackHandler(handler) {
                        back = handler;
                        return () => {
                            removed = true;
                        };
                    },
                };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {}, emit: (event) => events.push(event) }));
    return {
        props,
        permissions,
        state,
        events,
        back: () => back(),
        removed: () => removed,
        close() {
            cleanup.forEach((handler) => handler());
            scope.stop();
        },
    };
}

test('available services open a new submission directly and unfinished services cannot navigate', () => {
    const app = runtime();
    try {
        const options = app.state.options.value;
        assert.equal(options.length, 6);
        assert.equal(options.find((item) => item.label === 'Cuti').href, 'absensi.mobile.cuti.index?action=create');
        assert.equal(options.find((item) => item.label === 'Absensi').href, 'absensi.mobile.attendance?action=create');
        assert.equal(options.find((item) => item.label === 'Perubahan Shift').href, 'absensi.mobile.perubahan-shift.index?action=create');
        assert.equal(options.find((item) => item.label === 'Lembur').href, 'absensi.mobile.lembur.create');
        assert.equal(options.find((item) => item.label === 'Reimbursement').href, undefined);
        assert.equal(options.find((item) => item.label === 'Perubahan Data').href, undefined);
    } finally {
        app.close();
    }
});

test('permission changes hide restricted services without removing other options', () => {
    const app = runtime([]);
    try {
        assert.equal(
            app.state.options.value.some((item) => ['Cuti', 'Absensi'].includes(item.label)),
            false,
        );
        assert.equal(app.state.options.value.length, 4);
        app.permissions.value = ['mobile.cuti.view'];
        assert.equal(
            app.state.options.value.some((item) => item.label === 'Cuti'),
            false,
        );
        app.permissions.value = ['mobile.cuti.view', 'mobile.cuti.create'];
        assert.equal(
            app.state.options.value.some((item) => item.label === 'Cuti'),
            true,
        );
        assert.equal(
            app.state.options.value.some((item) => item.label === 'Absensi'),
            false,
        );
        app.permissions.value = ['mobile.attendance'];
        assert.equal(
            app.state.options.value.some((item) => item.label === 'Absensi'),
            false,
        );
        app.permissions.value = ['mobile.attendance', 'mobile.clock-out'];
        assert.equal(
            app.state.options.value.some((item) => item.label === 'Absensi'),
            true,
        );
    } finally {
        app.close();
    }
});

test('Android back closes an open sheet before navigation and leaves a closed sheet alone', () => {
    const app = runtime();
    try {
        assert.equal(app.back(), false);
        assert.deepEqual(app.events, []);
        app.props.open = true;
        assert.equal(app.back(), true);
        assert.deepEqual(app.events, ['close']);
        app.props.open = false;
        assert.equal(app.back(), false);
    } finally {
        app.close();
    }
    assert.equal(app.removed(), true);
});
