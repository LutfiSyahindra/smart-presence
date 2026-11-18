import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/admin-web/master-data/shift/ShiftCalendar.vue', import.meta.url), 'utf8');
const descriptor = parse(source).descriptor;
const script = compileScript(descriptor, { id: 'shift-calendar-test' });
const template = compileTemplate({
    source: descriptor.template.content,
    filename: 'ShiftCalendar.vue',
    id: 'shift-calendar-test',
    compilerOptions: { bindingMetadata: script.bindings },
});
assert.deepEqual(template.errors, []);
const transpile = (code) =>
    ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

const morning = { id: 1, nama: 'Pagi', jam_masuk: '07:00', jam_keluar: '15:00' };
const night = { id: 2, nama: 'Malam', jam_masuk: '22:00', jam_keluar: '06:00' };
const off = { id: 3, nama: 'DAYOFF', jam_masuk: '', jam_keluar: '' };
function fixture(overrides = {}) {
    const days = Array.from({ length: 30 }, (_, index) => ({
        date: `2026-11-${String(index + 1).padStart(2, '0')}`,
        day: index + 1,
        working: 2,
        day_off: 1,
        unassigned: 1,
    }));
    const employee = (id, nama, unitId, unit, shift) => ({
        id,
        nama,
        nik: `EMP-${id}`,
        unit_kerja_id: unitId,
        unit,
        jabatan: 'Perawat',
        shift,
        source: 'default',
        is_day_off: false,
        day_off_reason: null,
        schedule: days.map((day) => ({ date: day.date, source: 'default', shift_id: shift?.id ?? null, is_day_off: false, day_off_reason: null })),
    });
    const employees = [
        employee(1, 'Ayu', 2, 'IGD', morning),
        employee(2, 'Budi', 1, 'ICU', night),
        employee(3, 'Citra', null, null, null),
        employee(4, 'Dewi', 2, 'IGD', morning),
    ];
    employees[0].schedule[10] = { date: '2026-11-11', source: 'scheduled', shift_id: off.id, is_day_off: true, day_off_reason: 'DAYOFF' };
    return {
        month: '2026-11',
        today: '2026-11-10',
        selected_date: '2026-11-10',
        view: 'monthly',
        unit_kerja_id: null,
        units: [
            { id: 1, nama: 'ICU' },
            { id: 2, nama: 'IGD' },
        ],
        total: 4,
        days,
        employees,
        shifts: [morning, night, off],
        ...overrides,
    };
}

function calendar(fetcher = async () => response(fixture())) {
    const exports = {};
    const cleanup = [];
    const mounted = [];
    const requests = [];
    const context = {
        exports,
        URLSearchParams,
        AbortController,
        fetch: (...args) => {
            requests.push(args);
            return fetcher(...args);
        },
        require(name) {
            if (name === 'vue') return { ...vue, onMounted: (handler) => mounted.push(handler), onBeforeUnmount: (handler) => cleanup.push(handler) };
            if (name === 'lucide-vue-next') return new Proxy({}, { get: () => () => null });
            return {};
        },
    };
    vm.runInNewContext(transpile(script.content), context);
    const component = exports.default;
    const scope = vue.effectScope();
    const state = scope.run(() => component.setup({}, { expose() {} }));
    const rendered = {};
    vm.runInNewContext(transpile(template.code), { exports: rendered, require: () => vue });
    return {
        state,
        requests,
        mounted,
        render: () => renderToString(vue.createSSRApp({ setup: () => state, render: rendered.render })),
        close() {
            cleanup.forEach((handler) => handler());
            scope.stop();
        },
    };
}
function response(data, status = 200) {
    return { ok: status === 200, status, redirected: false, headers: { get: () => 'application/json' }, json: async () => data };
}

test('monthly roster renders every date and groups all matching employees by unit', async () => {
    const app = calendar();
    try {
        await app.state.load();
        assert.match(app.requests[0][0], /view=monthly/);
        assert.deepEqual(
            Array.from(app.state.unitGroups.value, (group) => [group.nama, group.employees.length]),
            [
                ['ICU', 1],
                ['IGD', 2],
                ['Tanpa unit kerja', 1],
            ],
        );
        const html = await app.render();
        assert.equal((html.match(/class="schedule-cell/g) ?? []).length, 120);
        assert.match(html, /2026: OFF \/ Libur, DAYOFF\. Per tanggal/);
        assert.match(html, /07:00–15:00/);
        assert.match(html, /22:00–06:00/);
        assert.match(html, /Belum diatur/);
        app.state.search.value = 'dayoff';
        assert.deepEqual(
            Array.from(app.state.matchingEmployees.value, (employee) => employee.nama),
            ['Ayu'],
        );
        app.state.search.value = 'emp-3';
        assert.equal(app.state.unitGroups.value[0].nama, 'Tanpa unit kerja');
        app.state.search.value = 'tidak ditemukan';
        assert.match(await app.render(), /Tidak ada pegawai yang sesuai/);
    } finally {
        app.close();
    }
});

test('unit selection, month navigation, refresh, and date drilldown keep the selected unit', async () => {
    const app = calendar(async (url) => {
        const params = new URL(url, 'http://localhost').searchParams;
        return response(
            fixture({
                view: params.get('view'),
                month: params.get('month') ?? '2026-11',
                selected_date: params.get('date') ?? '2026-11-10',
                unit_kerja_id: params.get('unit_kerja_id') === 'unassigned' ? 'unassigned' : Number(params.get('unit_kerja_id')) || null,
            }),
        );
    });
    try {
        await app.state.load();
        app.state.unitFilter.value = '2';
        await app.state.load();
        assert.equal(app.state.unitLabel.value, 'IGD');
        app.state.moveMonth(1);
        assert.match(app.requests.at(-1)[0], /month=2026-12&date=2026-12-01&view=monthly&unit_kerja_id=2/);
        await vue.nextTick();
        app.state.openDate('2026-12-05');
        assert.match(app.requests.at(-1)[0], /date=2026-12-05&view=daily&unit_kerja_id=2/);
        assert.equal(app.state.view.value, 'daily');
        await vue.nextTick();
        app.state.refresh();
        assert.match(app.requests.at(-1)[0], /unit_kerja_id=2/);
        await vue.nextTick();
        app.state.unitFilter.value = 'unassigned';
        await app.state.load();
        assert.equal(app.state.unitLabel.value, 'Tanpa unit kerja');
    } finally {
        app.close();
    }
});

test('superseded responses cannot replace the selected unit and unmount cancels pending requests', async () => {
    const pending = [];
    const app = calendar((url, options) => new Promise((resolve) => pending.push({ resolve, options })));
    try {
        const first = app.state.load();
        app.state.unitFilter.value = '1';
        const second = app.state.load();
        assert.equal(pending[0].options.signal.aborted, true);
        pending[1].resolve(response(fixture({ unit_kerja_id: 1, employees: [], total: 0 })));
        await second;
        pending[0].resolve(response(fixture()));
        await first;
        assert.equal(app.state.data.value.unit_kerja_id, 1);
        assert.equal(app.state.loading.value, false);
        assert.match(await app.render(), /Belum ada pegawai berakun pada unit ini/);
        const canceled = app.state.load();
        app.close();
        assert.equal(pending[2].options.signal.aborted, true);
        pending[2].resolve(response(fixture()));
        await canceled;
    } finally {
        app.close();
    }
});

test('failed unit loads show an error and hide schedules from the previous unit until retry succeeds', async () => {
    let fail = false;
    const app = calendar(async () => (fail ? response(null, 403) : response(fixture())));
    try {
        await app.state.load();
        fail = true;
        app.state.unitFilter.value = '2';
        await app.state.load();
        const html = await app.render();
        assert.match(html, /Anda tidak memiliki izin melihat kalender shift/);
        assert.doesNotMatch(html, /class="monthly-table/);
        fail = false;
        app.state.unitFilter.value = '';
        await app.state.load();
        assert.equal(app.state.error.value, '');
        assert.match(await app.render(), /class="monthly-table/);
    } finally {
        app.close();
    }
});
