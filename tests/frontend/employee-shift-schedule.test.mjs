import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(
    new URL('../../resources/js/pages/absensi/admin-web/master-data/shift/EmployeeShiftSchedule.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(source).descriptor, { id: 'employee-shift-schedule-test' });
const compiled = ts.transpileModule(script.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const morning = { id: 1, nama: 'Pagi', is_day_off: false, jam_masuk: '07:00', jam_keluar: '15:00' };
const night = { id: 2, nama: 'Malam', is_day_off: false, jam_masuk: '22:00', jam_keluar: '06:00' };
const off = { id: 3, nama: 'DAYOFF', is_day_off: true, jam_masuk: '', jam_keluar: '' };

function fixture(overrides = {}) {
    return {
        month: '2026-12',
        today: '2026-11-18',
        default_shift: morning,
        days: [1, 2, 3].map((day) => ({
            date: `2026-12-0${day}`,
            day,
            source: 'default',
            shift: morning,
            is_day_off: false,
            day_off_reason: null,
            locked_reason: null,
        })),
        ...overrides,
    };
}

const response = (data, status = 200) => ({
    ok: status === 200,
    status,
    headers: { get: () => 'application/json' },
    json: async () => data,
});

function editor(fetcher = async () => response(fixture()), overrides = {}) {
    const exports = {};
    const mounted = [];
    const cleanup = [];
    const requests = [];
    const events = [];
    const props = {
        employee: { id: 42, nama: 'Ayu', nik: 'EMP-42' },
        canEdit: true,
        shifts: [morning, night, off],
        initialDate: '2026-12-02',
        ...overrides,
    };
    vm.runInNewContext(compiled, {
        exports,
        AbortController,
        document: { cookie: 'XSRF-TOKEN=test%20token' },
        fetch: (...args) => {
            requests.push(args);
            return fetcher(...args);
        },
        require(name) {
            if (name === 'vue') return { ...vue, onMounted: (handler) => mounted.push(handler), onBeforeUnmount: (handler) => cleanup.push(handler) };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {}, emit: (...event) => events.push(event) }));
    return {
        state,
        requests,
        events,
        mount: () => mounted[0](),
        close() {
            cleanup.forEach((handler) => handler());
            scope.stop();
        },
    };
}

test('opening a roster date loads its month and selects that date and its current shift', async () => {
    const app = editor();
    try {
        await app.mount();
        assert.equal(app.requests[0][0], '/absensi/admin-web/master-data/shift/assign/42/schedule?month=2026-12');
        assert.equal(app.state.selectedDate.value, '2026-12-02');
        assert.equal(app.state.selectedShift.value, morning.id);
        assert.equal(app.state.selectedDay.value.date, '2026-12-02');
        assert.equal(app.state.loading.value, false);
        await app.state.load('2027-01');
        assert.equal(app.state.selectedDate.value, '');
    } finally {
        app.close();
    }
});

test('saving sends only the selected employee/date and shift, then announces a successful refresh', async () => {
    const updated = fixture();
    updated.days[1] = { ...updated.days[1], shift: night, source: 'scheduled' };
    const app = editor(async (url, options) =>
        response(options.method === 'PUT' ? { schedule: updated, message: 'Jadwal berhasil diubah.' } : fixture()),
    );
    try {
        await app.mount();
        app.state.selectedShift.value = night.id;
        await app.state.save();
        const [url, options] = app.requests[1];
        assert.equal(url, '/absensi/admin-web/master-data/shift/assign/42/schedule');
        assert.equal(options.method, 'PUT');
        assert.deepEqual(JSON.parse(options.body), { date: '2026-12-02', shift_id: night.id });
        assert.equal(options.headers['X-XSRF-TOKEN'], 'test token');
        assert.equal(app.state.schedule.value.default_shift.id, morning.id);
        assert.equal(app.state.schedule.value.days[0].shift.id, morning.id);
        assert.equal(app.state.schedule.value.days[1].shift.id, night.id);
        assert.equal(app.state.selectedDate.value, '');
        assert.deepEqual(app.events, [['saved']]);
    } finally {
        app.close();
    }
});

test('a locked roster date displays its reason and cannot save', async () => {
    const data = fixture();
    data.days[1].locked_reason = 'Tanggal ini sudah memiliki clock in/out.';
    const app = editor(async () => response(data));
    try {
        await app.mount();
        assert.equal(app.state.selectedDate.value, '2026-12-02');
        assert.match(app.state.selectedDay.value.locked_reason, /clock in\/out/);
        app.state.selectedShift.value = night.id;
        assert.equal(app.state.canSave.value, false);
        await app.state.save();
        assert.equal(app.requests.length, 1);
        assert.deepEqual(app.events, []);
    } finally {
        app.close();
    }
});

test('approved leave is counted separately from scheduled shifts, defaults, and holidays and remains locked', async () => {
    const data = fixture();
    Object.assign(data.days[1], {
        leave_name: 'Cuti Tahunan',
        source: 'scheduled',
        is_day_off: true,
        locked_reason: 'Tanggal ini memiliki cuti yang disetujui.',
    });
    const app = editor(async () => response(data));
    try {
        await app.mount();
        assert.equal(app.state.selectedDay.value.leave_name, 'Cuti Tahunan');
        assert.equal(app.state.leaveCount.value, 1);
        assert.equal(app.state.scheduledCount.value, 0);
        assert.equal(app.state.defaultCount.value, 2);
        assert.equal(app.state.dayOffCount.value, 0);
        app.state.selectedShift.value = night.id;
        await app.state.save();
        assert.equal(app.requests.length, 1);
        assert.equal(app.state.canSave.value, false);
    } finally {
        app.close();
    }
});

test('validation errors keep the selected date open and do not announce a save', async () => {
    const app = editor(async (url, options) =>
        response(
            options.method === 'PUT' ? { errors: { date: ['Tanggal ini memiliki cuti yang disetujui.'] } } : fixture(),
            options.method === 'PUT' ? 422 : 200,
        ),
    );
    try {
        await app.mount();
        app.state.selectedShift.value = off.id;
        await app.state.save();
        assert.match(app.state.saveError.value, /cuti yang disetujui/);
        assert.equal(app.state.selectedDate.value, '2026-12-02');
        assert.equal(app.state.saving.value, false);
        assert.deepEqual(app.events, []);
    } finally {
        app.close();
    }
});

test('viewers and the directory entry point keep their existing read-only and unselected behavior', async () => {
    const viewer = editor(undefined, { canEdit: false });
    const directory = editor(undefined, { initialDate: '' });
    try {
        await viewer.mount();
        viewer.state.selectedShift.value = night.id;
        await viewer.state.save();
        assert.equal(viewer.state.selectedDate.value, '');
        assert.equal(viewer.requests.length, 1);
        await directory.mount();
        assert.equal(directory.requests[0][0], '/absensi/admin-web/master-data/shift/assign/42/schedule');
        assert.equal(directory.state.selectedDate.value, '');
    } finally {
        viewer.close();
        directory.close();
    }
});
