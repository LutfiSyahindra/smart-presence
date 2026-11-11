import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/admin-web/master-data/shift/BulkShiftAssignment.vue', import.meta.url), 'utf8');
const script = compileScript(parse(source).descriptor, { id: 'bulk-shift-test' }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function batch() {
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        require(name) {
            if (name === 'vue') return { ...vue, useId: () => 'test-id' };
            if (name === '@inertiajs/vue3') return {
                useForm(initial) {
                    return vue.reactive({ ...initial, errors: {}, processing: false, clearErrors() {} });
                },
            };
            return {};
        },
    });
    const options = {
        month: '2026-11',
        units: [{ id: 1, nama: 'IGD' }, { id: 2, nama: 'ICU' }, { id: 3, nama: 'Poli' }],
        positions: [1, 2, 3].map((id) => ({ id: id * 10, unit_kerja_id: id, nama: 'Perawat' })),
        employees: [
            { id: 1, nama: 'Ayu', nik: '001', unit_kerja_id: 1, jabatan_id: 10 },
            { id: 2, nama: 'Budi', nik: '002', unit_kerja_id: 2, jabatan_id: 20 },
            { id: 3, nama: 'Citra', nik: null, unit_kerja_id: 3, jabatan_id: 30 },
            { id: 4, nama: 'Dewi', nik: '004', unit_kerja_id: 1, jabatan_id: null },
            { id: 5, nama: 'Eka', nik: null, unit_kerja_id: null, jabatan_id: null },
        ],
    };
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup({ open: true, options }, { expose() {}, emit() {} }));
    return { state, close: () => scope.stop() };
}

test('multiple units and positions combine their results, while search only narrows the employee picker', async () => {
    const app = batch();
    try {
        const { form } = app.state;
        assert.equal(app.state.selectedCount.value, 5);
        form.selection = 'filtered';
        assert.equal(app.state.validSelection.value, false);
        form.unit_kerja_ids = [1, 2];
        form.jabatan_ids = [10, 20];
        await vue.nextTick();
        assert.equal(app.state.selectedCount.value, 2);
        assert.equal(app.state.validSelection.value, true);
        assert.deepEqual(Array.from(app.state.positions.value, (item) => item.id), [10, 20]);
        app.state.employeeSearch.value = 'ayu';
        assert.equal(app.state.matchingEmployees.value.length, 1);
        assert.equal(app.state.selectedCount.value, 2);
        form.jabatan_ids = [];
        assert.equal(app.state.selectedCount.value, 3);
        form.unit_kerja_ids = [];
        form.jabatan_ids = [10, 30];
        await vue.nextTick();
        assert.equal(app.state.selectedCount.value, 2);
    } finally { app.close(); }
});

test('narrowing units removes incompatible positions and preserves individual employee selections', async () => {
    const app = batch();
    try {
        const { form } = app.state;
        form.selection = 'custom';
        form.pegawai_ids = [1, 3, 5];
        form.unit_kerja_ids = [1, 2];
        form.jabatan_ids = [10, 20];
        await vue.nextTick();
        form.unit_kerja_ids = [2];
        await vue.nextTick();
        assert.deepEqual([...form.jabatan_ids], [20]);
        assert.deepEqual([...form.pegawai_ids], [1, 3, 5]);
        assert.equal(app.state.selectedCount.value, 3);
        form.unit_kerja_ids = [];
        await vue.nextTick();
        assert.deepEqual([...form.jabatan_ids], [20]);
        assert.equal(app.state.positions.value.length, 3);
    } finally { app.close(); }
});

test('selecting all matching employees adds all chosen units without clearing previous selections', async () => {
    const app = batch();
    try {
        const { form } = app.state;
        form.selection = 'custom';
        form.pegawai_ids = [3];
        form.unit_kerja_ids = [1, 2];
        await vue.nextTick();
        app.state.toggleMatching();
        assert.deepEqual([...form.pegawai_ids], [3, 1, 2, 4]);
        assert.equal(app.state.allMatchingSelected.value, true);
        app.state.toggleMatching();
        assert.deepEqual([...form.pegawai_ids], [3]);
    } finally { app.close(); }
});

test('changing multi-selection filters invalidates an already prepared template and upload', async () => {
    const app = batch();
    try {
        app.state.downloaded.value = true;
        app.state.furthestStep.value = 4;
        app.state.form.file = { name: 'jadwal.xlsx' };
        app.state.form.unit_kerja_ids = [1, 2];
        await vue.nextTick();
        assert.equal(app.state.downloaded.value, false);
        assert.equal(app.state.form.file, null);
        assert.equal(app.state.furthestStep.value, 2);
    } finally { app.close(); }
});
