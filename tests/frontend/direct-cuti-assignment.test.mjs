import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/admin-web/presensi/Index.vue', import.meta.url), 'utf8');
const script = compileScript(parse(source).descriptor, { id: 'direct-cuti-test' }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

const annual = { id: 10, nama: 'Cuti Tahunan', is_tahunan: true, tahun: 2026, jumlah_hari: 12, sisa: 9 };
const ordinary = { id: 20, nama: 'Cuti Khusus', is_tahunan: false, tahun: 0, jumlah_hari: 3, sisa: 3 };
const flush = async () => { await new Promise(setImmediate); await vue.nextTick(); };

function runtime() {
    const requests = [];
    const submissions = [];
    const exports = {};
    vm.runInNewContext(compiled, {
        exports, AbortController, setTimeout, clearTimeout,
        fetch(url, options) {
            return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
        },
        require(name) {
            if (name === 'vue') return { ...vue, onBeforeUnmount: vue.onScopeDispose };
            if (name === '@inertiajs/vue3') return {
                useForm(initial) {
                    let defaults = { ...initial };
                    const form = vue.reactive({
                        ...initial, errors: {}, processing: false,
                        defaults(value) { defaults = { ...value }; },
                        reset() { Object.assign(form, defaults); },
                        clearErrors() { form.errors = {}; },
                        post(url, options) { submissions.push({ method: 'post', url, options }); },
                        put(url, options) { submissions.push({ method: 'put', url, options }); },
                    });
                    return form;
                },
            };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup({
        manage: true, filters: { date_from: '2026-11-23', date_to: '2026-11-23' },
        records: { data: [], total: 0 }, overview: {},
        options: { pegawai: [{ id: 1, nama: 'Ayu' }, { id: 2, nama: 'Budi' }], shifts: [] },
    }, { expose() {} }));
    return { state, requests, submissions, close: () => scope.stop() };
}

function respond(request, options) {
    request.resolve({ ok: true, json: async () => ({ options }) });
}

test('direct leave defaults to the allocated annual type, previews inclusive days, and uses its own endpoint', async () => {
    const app = runtime();
    try {
        app.state.openCreate(1, '2026-11-23');
        app.state.form.entry_type = 'cuti';
        await flush();
        assert.match(app.requests[0].url, /cuti-options\?pegawai_id=1$/);
        assert.equal(app.state.cutiLoading.value, true);
        respond(app.requests[0], [ordinary, annual, { ...annual, tahun: 2025 }]);
        await flush();
        assert.equal(app.state.form.master_cuti_id, annual.id);
        assert.equal(app.state.availableCuti.value.length, 2);
        app.state.form.tanggal_selesai = '2026-11-25';
        app.state.form.alasan = 'Keperluan keluarga';
        assert.equal(app.state.cutiDays.value, 3);
        assert.equal(app.state.selectedCuti.value.sisa, 9);
        app.state.submitForm();
        assert.equal(app.submissions[0].method, 'post');
        assert.equal(app.submissions[0].url, '/absensi/admin-web/presensi/set-pegawai/cuti');
        app.state.form.tanggal_mulai = '2025-11-23';
        await flush();
        assert.equal(app.state.selectedCuti.value.tahun, 2025);
    } finally { app.close(); }
});

test('switching employees clears entitlements immediately and ignores a stale balance response', async () => {
    const app = runtime();
    try {
        app.state.openCreate(1);
        app.state.form.entry_type = 'cuti';
        await flush();
        app.state.form.pegawai_id = 2;
        await flush();
        assert.equal(app.requests[0].options.signal.aborted, true);
        assert.equal(app.state.selectedCuti.value, null);
        respond(app.requests[1], [{ ...annual, sisa: 2 }]);
        await flush();
        respond(app.requests[0], [annual]);
        await flush();
        assert.equal(app.state.selectedCuti.value.sisa, 2);
        app.state.closeForm();
        await flush();
        assert.equal(app.requests[1].options.signal.aborted, true);
        assert.equal(app.state.availableCuti.value.length, 0);
    } finally { app.close(); }
});

test('failed entitlement loading exposes an error and can be retried without leaving stale choices', async () => {
    const app = runtime();
    try {
        app.state.openCreate(1);
        app.state.form.entry_type = 'cuti';
        await flush();
        app.requests[0].reject(new Error('offline'));
        await flush();
        assert.equal(app.state.cutiLoading.value, false);
        assert.match(app.state.cutiLoadError.value, /Gagal memuat/);
        assert.equal(app.state.selectedCuti.value, null);
        app.state.form.entry_type = 'shift';
        await flush();
        app.state.form.entry_type = 'cuti';
        await flush();
        respond(app.requests[1], [annual]);
        await flush();
        assert.equal(app.state.cutiLoadError.value, '');
        assert.equal(app.state.selectedCuti.value.id, annual.id);
    } finally { app.close(); }
});

test('editing attendance can grant leave through POST while shift corrections still use PUT', async () => {
    const app = runtime();
    try {
        app.state.openEdit({ id: 42, pegawai_id: 1, tanggal: '2026-11-23', clock_in: {}, clock_out: {}, is_day_off: false });
        app.state.form.entry_type = 'cuti';
        await flush();
        respond(app.requests[0], [annual]);
        await flush();
        app.state.submitForm();
        assert.equal(app.submissions[0].method, 'post');
        assert.match(app.submissions[0].url, /set-pegawai\/cuti$/);
        app.state.form.processing = true;
        app.state.submitForm();
        assert.equal(app.submissions.length, 1);
        app.state.form.processing = false;
        app.state.form.entry_type = 'shift';
        app.state.submitForm();
        assert.equal(app.submissions[1].method, 'put');
        assert.match(app.submissions[1].url, /set-pegawai\/42$/);
    } finally { app.close(); }
});
