import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/PresensiPegawai.vue', import.meta.url), 'utf8');
const script = compileScript(parse(source).descriptor, { id: 'mobile-presensi-pegawai-test', inlineTemplate: true }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const wrapper = (_, { slots }) => vue.h('span', slots.default?.());
const exports = {};
vm.runInNewContext(compiled, {
    exports,
    require(name) {
        if (name === 'vue') return vue;
        if (name === '@inertiajs/vue3') return { Head: () => null, Link: wrapper, router: {} };
        if (name === '@/components/ui/avatar') return { Avatar: wrapper, AvatarImage: () => null, AvatarFallback: wrapper };
        if (name === '@/composables/useInitials') return { getInitials: () => 'AS' };
        if (name === 'lucide-vue-next') return new Proxy({}, { get: () => () => null });
        if (name === './components/MobileBackLink.vue') return { default: wrapper };
        throw new Error(`Unexpected import: ${name}`);
    },
});

async function renderRecord(overrides = {}) {
    const record = {
        id: 1, pegawai_id: 1, has_presensi: true, cuti: null,
        pegawai: { nama: 'A Siti', nik: '001', unit: 'IGD', jabatan: 'Perawat', profile_photo_url: null },
        tanggal_label: '16/11/2026', status: 'incomplete',
        shift: { nama: 'Shift Pagi', jam_masuk: '07:00', jam_keluar: '15:00', note: null },
        clock_in: {}, clock_out: {}, late_minutes: 0, early_departure_minutes: 0, overtime_minutes: 0, notes: null,
        ...overrides,
    };
    const html = await renderToString(vue.createSSRApp(exports.default, {
        filters: { search: null, date_from: '2026-11-16', date_to: '2026-11-16', status: null },
        records: { data: [record], total: 1, current_page: 1, last_page: 1 },
        overview: { total: 1, present: 0, late: 0, day_off: 0 },
        unitName: 'IGD', scopeMode: 'unit-kerja', approvalTypeLabel: null, today: '2026-11-16',
    }));
    return { html, card: html.match(/<article\b[\s\S]*?<\/article>/)?.[0] ?? '' };
}

for (const hasPresensi of [true, false]) {
    test(`approved leave displays its status, type and dates with has_presensi=${hasPresensi}`, async () => {
        const { html, card } = await renderRecord({
            id: hasPresensi ? 1 : null, has_presensi: hasPresensi, status: 'cuti',
            cuti: { nama: 'Cuti Tahunan', tanggal_mulai: '2026-11-16', tanggal_selesai: '2026-11-18' },
        });
        assert.match(card, /status-badge cuti[^>]*>Cuti</);
        assert.match(card, /CUTI · Cuti Tahunan/);
        assert.match(card, /16\/11\/2026 – 18\/11\/2026/);
        assert.match(card, /Cuti disetujui/);
        assert.doesNotMatch(card, /Shift Pagi|Belum lengkap|Belum ada catatan|--:--|Jadwal:/);
        assert.match(html, /<option value="cuti">Cuti<\/option>/);
    });
}

test('ordinary incomplete attendance retains its shift, clocks and status', async () => {
    const { card } = await renderRecord();
    assert.match(card, /status-badge incomplete[^>]*>Belum lengkap</);
    assert.match(card, /Shift Pagi/);
    assert.match(card, /Jadwal: 07:00–15:00 WIB/);
    assert.match(card, /--:--/);
    assert.doesNotMatch(card, /Cuti disetujui|cuti-details/);
});

test('employees without leave or attendance keep the unrecorded message', async () => {
    const { card } = await renderRecord({ id: null, has_presensi: false, status: null, shift: null });
    assert.match(card, /status-badge unrecorded[^>]*>Belum ada presensi</);
    assert.match(card, /Belum ada catatan presensi pada periode ini/);
    assert.doesNotMatch(card, /Cuti disetujui|cuti-details/);
});

test('older approved leave without a named type still has a readable leave label', async () => {
    const { card } = await renderRecord({
        status: 'cuti', cuti: { nama: null, tanggal_mulai: '2026-11-16', tanggal_selesai: '2026-11-16' },
    });
    assert.match(card, /CUTI · Cuti/);
    assert.match(card, /16\/11\/2026 – 16\/11\/2026/);
});
