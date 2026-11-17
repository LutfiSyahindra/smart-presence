import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/components/NotificationFilters.vue', import.meta.url), 'utf8');
const compiled = ts.transpileModule(compileScript(parse(source).descriptor, { id: 'notification-filters' }).content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const empty = { date_from: '', date_to: '', unit: '', jabatan: '', employee_name: '' };

function panel({ mobile = false } = {}) {
    const calls = [];
    let backHandler;
    let cleanup;
    let unregistered = false;
    const props = vue.reactive({ filters: { ...empty, request_type: 'cuti' }, disabled: false, mobile, filterOptions: { units: ['IGD'], positions: ['Perawat'] } });
    const exports = {};
    vm.runInNewContext(compiled, { exports, require: () => ({
        ...vue,
        useId: () => 'filters',
        onUnmounted: (callback) => { cleanup = callback; },
        registerMobileBackHandler: (handler) => {
            backHandler = handler;
            return () => { unregistered = true; };
        },
    }) });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {}, emit: (event, filters) => calls.push({ event, filters }) }));
    return {
        state, props, calls,
        back: () => backHandler?.(),
        get unregistered() { return unregistered; },
        close: () => { cleanup?.(); scope.stop(); },
    };
}

test('filter panel blocks reversed dates, accepts one-sided ranges, and emits only filter fields', () => {
    const app = panel();
    try {
        Object.assign(app.state.draft, { date_from: '2026-11-02', date_to: '2026-11-01' });
        app.state.apply();
        assert.equal(app.calls.length, 0);
        app.state.draft.date_from = '';
        Object.assign(app.state.draft, { employee_name: '  Nadia  ', unit: 'IGD', jabatan: 'Perawat' });
        app.state.apply();
        assert.deepEqual({ ...app.calls[0].filters }, { ...empty, date_to: '2026-11-01', unit: 'IGD', jabatan: 'Perawat', employee_name: 'Nadia' });
        app.props.disabled = true;
        app.state.apply();
        assert.equal(app.calls.length, 1);
    } finally { app.close(); }
});

test('filter panel follows navigation props and reset clears every field', async () => {
    const app = panel();
    try {
        app.props.filters = { ...empty, unit: 'IGD', date_from: '2026-11-01' };
        await vue.nextTick();
        assert.equal(app.state.draft.unit, 'IGD');
        app.state.reset();
        assert.deepEqual({ ...app.calls[0].filters }, empty);
    } finally { app.close(); }
});

test('mobile filters discard cancelled edits and close only after a valid apply', () => {
    const app = panel({ mobile: true });
    try {
        app.props.filters.unit = 'IGD';
        app.state.openFilters();
        assert.equal(app.state.open.value, true);
        assert.equal(app.state.draft.unit, 'IGD');
        app.state.draft.unit = '';
        app.state.closeFilters();
        assert.equal(app.calls.length, 0);
        assert.equal(app.state.draft.unit, 'IGD');
        app.state.openFilters();
        Object.assign(app.state.draft, { date_from: '2026-11-02', date_to: '2026-11-01' });
        app.state.apply();
        assert.equal(app.state.open.value, true);
        app.state.draft.date_to = '2026-11-03';
        app.state.apply();
        assert.equal(app.state.open.value, false);
        assert.equal(app.calls.length, 1);
    } finally { app.close(); }
});

test('mobile active summary reflects applied filters and groups the date range', async () => {
    const app = panel({ mobile: true });
    try {
        app.state.draft.unit = 'IGD';
        assert.equal(app.state.activeFilters.value.length, 0);
        app.props.filters = { ...empty, date_from: '2026-11-01', date_to: '2026-11-17', unit: 'IGD', employee_name: 'Nadia' };
        await vue.nextTick();
        assert.equal(app.state.activeFilters.value.length, 3);
        assert.match(app.state.activeFilters.value[0], /01\/11\/2026.*17\/11\/2026/);
        assert.equal(app.state.activeFilters.value[1], 'Unit: IGD');
        app.props.disabled = true;
        app.state.openFilters();
        app.state.reset();
        assert.equal(app.state.open.value, false);
        assert.equal(app.state.draft.unit, 'IGD');
        assert.equal(app.calls.length, 0);
    } finally { app.close(); }
});

test('mobile back dismisses the filter before navigation and unregisters on unmount', () => {
    const app = panel({ mobile: true });
    try {
        assert.equal(app.back(), false);
        app.state.openFilters();
        app.state.draft.employee_name = 'Nadia';
        assert.equal(app.back(), true);
        assert.equal(app.state.open.value, false);
        assert.equal(app.state.draft.employee_name, '');
        assert.equal(app.calls.length, 0);
        assert.equal(app.back(), false);
    } finally { app.close(); }
    assert.equal(app.unregistered, true);
});

const selectSource = await readFile(new URL('../../resources/js/components/NotificationFilterSelect.vue', import.meta.url), 'utf8');
const selectCompiled = ts.transpileModule(compileScript(parse(selectSource).descriptor, { id: 'notification-filter-select' }).content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function searchableSelect(options) {
    const calls = [];
    const props = vue.reactive({ modelValue: '', label: 'Unit', allLabel: 'Semua unit', options, disabled: false, mobile: true });
    const exports = {};
    vm.runInNewContext(selectCompiled, { exports, require: () => vue });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {}, emit: (event, value) => calls.push({ event, value }) }));
    return { state, props, calls, close: () => scope.stop() };
}

test('unit and position searches match partial labels regardless of case and surrounding spaces', () => {
    for (const [options, query, expected] of [
        [['IGD', 'Rawat Inap', 'Rawat Jalan'], '  rAWat i  ', ['Rawat Inap']],
        [['Perawat', 'Dokter Spesialis', 'Dokter Umum'], 'DOKTER', ['Dokter Spesialis', 'Dokter Umum']],
    ]) {
        const app = searchableSelect(options);
        try {
            app.state.searchOptions({ target: { value: query } });
            assert.deepEqual([...app.state.filteredOptions.value], expected);
            assert.equal(app.props.modelValue, '');
            assert.equal(app.calls.length, 0);
            app.state.searchOptions({ target: { value: 'tidak ada' } });
            assert.equal(app.state.filteredOptions.value.length, 0);
            app.state.searchOptions({ target: { value: '' } });
            assert.deepEqual([...app.state.filteredOptions.value], options);
        } finally { app.close(); }
    }
});

test('searchable choices emit the selected value or all option and stay locked while disabled', () => {
    const app = searchableSelect(['IGD', 'Rawat Inap']);
    try {
        app.state.selectValue('IGD');
        assert.deepEqual({ ...app.calls[0] }, { event: 'update:modelValue', value: 'IGD' });
        app.props.modelValue = 'IGD';
        app.state.searchOptions({ target: { value: 'rawat' } });
        assert.equal(app.props.modelValue, 'IGD');
        app.state.selectValue('');
        assert.deepEqual({ ...app.calls[1] }, { event: 'update:modelValue', value: '' });
        app.props.disabled = true;
        app.state.selectValue('Rawat Inap');
        assert.equal(app.calls.length, 2);
    } finally { app.close(); }
});

test('tapping a closed search field opens the choices without toggling an open or disabled list', () => {
    const app = searchableSelect(['IGD', 'Rawat Inap']);
    let clicks = 0;
    app.state.optionsButton.value = { el: { click: () => clicks++ } };
    try {
        app.state.openOptions(false);
        assert.equal(clicks, 1);
        app.state.openOptions(true);
        assert.equal(clicks, 1);
        app.props.disabled = true;
        app.state.openOptions(false);
        assert.equal(clicks, 1);
        assert.equal(app.calls.length, 0);
    } finally { app.close(); }
});
