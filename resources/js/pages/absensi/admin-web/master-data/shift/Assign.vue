<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    Check,
    Clock3,
    Filter,
    LoaderCircle,
    Save,
    Search,
    TimerOff,
    UserRoundCheck,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import SearchableSelect from '../../presensi/components/SearchableSelect.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import Pagination from '../../setting/components/Pagination.vue';
import type { Paginated } from '../../setting/types';

interface ShiftOption {
    id: number;
    nama: string;
    jam_masuk: string;
    jam_keluar: string;
}

interface SearchableSelectOption {
    value: string | number;
    label: string;
    description?: string;
    searchText?: string;
}

interface AssignmentRow {
    id: number;
    nik: string | null;
    nama: string;
    unit: string | null;
    jabatan: string | null;
    user: { name: string | null; email: string | null; active: boolean };
    shift_id: number | null;
    shift: ShiftOption | null;
    assigned_at: string | null;
    assigned_by: string | null;
}

const props = defineProps<{
    assignments: Paginated<AssignmentRow>;
    overview: { total: number; assigned: number; unassigned: number };
    shifts: ShiftOption[];
    filters: { search?: string | null; status?: string | null; shift_id?: number | null };
    can: { assign: boolean };
}>();

const baseUrl = '/absensi/admin-web/master-data/shift/assign';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Master Data', href: '/absensi/admin-web/master-data/shift' },
    { title: 'Shift', href: '/absensi/admin-web/master-data/shift' },
    { title: 'Assign Shift', href: baseUrl },
];

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const shiftFilter = ref<number | string>(props.filters.shift_id ?? '');
const selectedIds = ref<number[]>([]);
const bulkShift = ref<number | string>('');
const bulkError = ref('');
const savingRow = ref<number | null>(null);
const savingBulk = ref(false);
const rowShifts = reactive<Record<number, number | string>>({});
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const syncRows = () => {
    props.assignments.data.forEach((pegawai) => {
        rowShifts[pegawai.id] = pegawai.shift_id ?? 0;
    });
    selectedIds.value = selectedIds.value.filter((id) => props.assignments.data.some((pegawai) => pegawai.id === id));
};

syncRows();
watch(() => props.assignments.data, syncRows);

const allVisibleSelected = computed(
    () => props.assignments.data.length > 0 && props.assignments.data.every((pegawai) => selectedIds.value.includes(pegawai.id)),
);
const resultSummary = computed(() => {
    if (!props.assignments.total) return 'Belum ada pegawai berakun';
    return `${props.assignments.from}-${props.assignments.to} dari ${props.assignments.total} pegawai`;
});
const assignmentRate = computed(() => (props.overview.total ? Math.round((props.overview.assigned / props.overview.total) * 100) : 0));
const searchableShiftOptions = computed<SearchableSelectOption[]>(() =>
    props.shifts.map((shift) => ({
        value: shift.id,
        label: shift.nama,
        description: `${shift.jam_masuk}–${shift.jam_keluar}`,
        searchText: `${shift.nama} ${shift.jam_masuk} ${shift.jam_keluar}`,
    })),
);
const bulkShiftOptions = computed<SearchableSelectOption[]>(() => [
    ...searchableShiftOptions.value,
    {
        value: 'clear',
        label: 'Kosongkan shift',
        description: 'Hapus shift aktif pegawai terpilih',
        searchText: 'kosongkan hapus tanpa shift',
    },
]);

const toggleAll = () => {
    const visibleIds = props.assignments.data.map((pegawai) => pegawai.id);
    selectedIds.value = allVisibleSelected.value
        ? selectedIds.value.filter((id) => !visibleIds.includes(id))
        : [...new Set([...selectedIds.value, ...visibleIds])];
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        baseUrl,
        {
            search: search.value.trim() || undefined,
            status: status.value || undefined,
            shift_id: shiftFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const resetFilters = () => {
    search.value = '';
    status.value = '';
    shiftFilter.value = '';
    applyFilters();
};

watch(search, (value, previousValue) => {
    if (value === previousValue) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 450);
});

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});

const saveRow = (pegawai: AssignmentRow) => {
    if (!props.can.assign || savingRow.value !== null) return;
    savingRow.value = pegawai.id;
    router.put(
        baseUrl,
        { pegawai_ids: [pegawai.id], shift_id: Number(rowShifts[pegawai.id]) || null },
        {
            preserveScroll: true,
            onFinish: () => (savingRow.value = null),
        },
    );
};

const saveBulk = () => {
    bulkError.value = '';
    if (!selectedIds.value.length) {
        bulkError.value = 'Pilih minimal satu pegawai.';
        return;
    }
    if (bulkShift.value === '') {
        bulkError.value = 'Pilih shift atau aksi kosongkan shift.';
        return;
    }

    savingBulk.value = true;
    router.put(
        baseUrl,
        {
            pegawai_ids: selectedIds.value,
            shift_id: bulkShift.value === 'clear' ? null : Number(bulkShift.value),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = [];
                bulkShift.value = '';
            },
            onError: (errors) => {
                bulkError.value = String(errors.pegawai_ids ?? errors.shift_id ?? 'Assignment shift gagal disimpan.');
            },
            onFinish: () => (savingBulk.value = false),
        },
    );
};
</script>

<template>
    <Head title="Assign Shift" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="assign-page">
            <FlashMessage />

            <section class="assign-hero" aria-labelledby="assign-title">
                <div class="assign-hero-copy">
                    <span class="assign-eyebrow"><UserRoundCheck /> Penempatan waktu kerja</span>
                    <h1 id="assign-title">Assign shift ke <span>pegawai berakun.</span></h1>
                    <p>
                        Tetapkan shift aktif untuk pegawai yang sudah terhubung dengan user. Gunakan aksi per pegawai atau pilih beberapa pegawai
                        sekaligus.
                    </p>
                    <div class="assign-hero-notes">
                        <span><Check /> Hanya pegawai berakun</span>
                        <span><Check /> Mendukung assignment massal</span>
                    </div>
                </div>
                <div class="assign-progress-card">
                    <div class="assign-progress-head">
                        <span><Clock3 /></span>
                        <div>
                            <small>Cakupan assignment</small><strong>{{ assignmentRate }}%</strong>
                        </div>
                    </div>
                    <div class="assign-progress-track"><i :style="{ width: `${assignmentRate}%` }"></i></div>
                    <p>{{ overview.assigned }} dari {{ overview.total }} pegawai sudah memiliki shift.</p>
                </div>
            </section>

            <section class="assign-overview" aria-label="Ringkasan assignment shift">
                <article>
                    <span class="tone-indigo"><UsersRound /></span>
                    <div>
                        <small>Pegawai berakun</small><strong>{{ overview.total.toLocaleString('id-ID') }}</strong>
                        <p>Siap menerima shift</p>
                    </div>
                </article>
                <article>
                    <span class="tone-green"><BadgeCheck /></span>
                    <div>
                        <small>Sudah di-assign</small><strong>{{ overview.assigned.toLocaleString('id-ID') }}</strong>
                        <p>Memiliki shift aktif</p>
                    </div>
                </article>
                <article>
                    <span class="tone-amber"><TimerOff /></span>
                    <div>
                        <small>Belum di-assign</small><strong>{{ overview.unassigned.toLocaleString('id-ID') }}</strong>
                        <p>Perlu ditentukan</p>
                    </div>
                </article>
            </section>

            <section class="assign-directory" aria-labelledby="assign-directory-title">
                <header class="assign-directory-header">
                    <div>
                        <span>Direktori pegawai</span>
                        <h2 id="assign-directory-title">Assignment shift</h2>
                        <p>Temukan pegawai lalu tentukan shift aktifnya.</p>
                    </div>
                    <strong>{{ resultSummary }}</strong>
                </header>

                <form class="assign-filters" role="search" @submit.prevent="applyFilters">
                    <label class="assign-search">
                        <Search />
                        <span class="visually-hidden">Cari pegawai</span>
                        <input v-model="search" type="search" placeholder="Cari nama, NIK, email, unit..." autocomplete="off" />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="search = ''"><X /></button>
                    </label>
                    <label>
                        <span class="visually-hidden">Filter status assignment</span>
                        <select v-model="status" @change="applyFilters">
                            <option value="">Semua status</option>
                            <option value="assigned">Sudah di-assign</option>
                            <option value="unassigned">Belum di-assign</option>
                        </select>
                    </label>
                    <div class="filter-shift-select">
                        <span class="visually-hidden">Filter shift</span>
                        <SearchableSelect
                            v-model="shiftFilter"
                            :options="searchableShiftOptions"
                            placeholder="Semua shift"
                            placeholder-value=""
                            search-placeholder="Cari nama atau jam shift..."
                            :search-threshold="0"
                            @change="applyFilters"
                        />
                    </div>
                    <button type="submit"><Filter /> Terapkan</button>
                    <button v-if="search || status || shiftFilter" type="button" class="reset-filter" @click="resetFilters"><X /></button>
                </form>

                <div v-if="can.assign && assignments.data.length" class="assign-bulk-bar">
                    <div class="assign-bulk-copy">
                        <span><UserRoundCheck /></span>
                        <p>
                            <strong>{{ selectedIds.length }} pegawai dipilih</strong><small>Pilih shift untuk assignment massal</small>
                        </p>
                    </div>
                    <SearchableSelect
                        v-model="bulkShift"
                        class="bulk-shift-select"
                        :options="bulkShiftOptions"
                        placeholder="Pilih aksi shift"
                        placeholder-value=""
                        placeholder-disabled
                        search-placeholder="Cari nama atau jam shift..."
                        :search-threshold="0"
                    />
                    <button type="button" :disabled="savingBulk || !selectedIds.length || bulkShift === ''" @click="saveBulk">
                        <LoaderCircle v-if="savingBulk" class="spinner" /><Save v-else /> Terapkan
                    </button>
                    <p v-if="bulkError" class="assign-error">{{ bulkError }}</p>
                </div>

                <template v-if="assignments.data.length">
                    <div class="assign-table-wrap">
                        <table class="assign-table">
                            <thead>
                                <tr>
                                    <th v-if="can.assign" class="check-column">
                                        <input
                                            type="checkbox"
                                            :checked="allVisibleSelected"
                                            aria-label="Pilih semua pegawai di halaman ini"
                                            @change="toggleAll"
                                        />
                                    </th>
                                    <th>Pegawai</th>
                                    <th>Akun user</th>
                                    <th>Unit / jabatan</th>
                                    <th>Shift aktif</th>
                                    <th>Riwayat assignment</th>
                                    <th v-if="can.assign"><span class="visually-hidden">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="pegawai in assignments.data" :key="pegawai.id" :class="{ selected: selectedIds.includes(pegawai.id) }">
                                    <td v-if="can.assign" class="check-column">
                                        <input v-model="selectedIds" type="checkbox" :value="pegawai.id" :aria-label="`Pilih ${pegawai.nama}`" />
                                    </td>
                                    <td>
                                        <div class="employee-cell">
                                            <span>{{ pegawai.nama.charAt(0).toUpperCase() }}</span>
                                            <div>
                                                <strong>{{ pegawai.nama }}</strong
                                                ><small>{{ pegawai.nik || 'NIK belum tersedia' }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="account-cell">
                                            <strong>{{ pegawai.user.email || pegawai.user.name }}</strong>
                                            <span :class="pegawai.user.active ? 'active' : 'pending'">
                                                <i></i>{{ pegawai.user.active ? 'Akun aktif' : 'Menunggu aktivasi' }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="organization-cell">
                                            <span><BriefcaseBusiness />{{ pegawai.unit || 'Tanpa unit' }}</span>
                                            <small>{{ pegawai.jabatan || 'Jabatan belum diatur' }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <SearchableSelect
                                            v-if="can.assign"
                                            v-model="rowShifts[pegawai.id]"
                                            class="row-shift-select"
                                            :options="searchableShiftOptions"
                                            placeholder="Tanpa shift"
                                            :placeholder-value="0"
                                            search-placeholder="Cari nama atau jam shift..."
                                            :search-threshold="0"
                                        />
                                        <div v-else-if="pegawai.shift" class="shift-badge">
                                            <strong>{{ pegawai.shift.nama }}</strong
                                            ><small>{{ pegawai.shift.jam_masuk }}–{{ pegawai.shift.jam_keluar }}</small>
                                        </div>
                                        <span v-else class="no-shift"><TimerOff /> Belum ada shift</span>
                                    </td>
                                    <td>
                                        <div v-if="pegawai.assigned_at" class="history-cell">
                                            <strong>{{ pegawai.assigned_at }}</strong
                                            ><small>oleh {{ pegawai.assigned_by || 'sistem' }}</small>
                                        </div>
                                        <span v-else class="history-empty">Belum pernah di-assign</span>
                                    </td>
                                    <td v-if="can.assign">
                                        <button
                                            type="button"
                                            class="save-row"
                                            :disabled="savingRow !== null || Number(rowShifts[pegawai.id]) === (pegawai.shift_id ?? 0)"
                                            @click="saveRow(pegawai)"
                                        >
                                            <LoaderCircle v-if="savingRow === pegawai.id" class="spinner" /><Save v-else /> Simpan
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="assign-mobile-list">
                        <article v-for="pegawai in assignments.data" :key="pegawai.id" :class="{ selected: selectedIds.includes(pegawai.id) }">
                            <header>
                                <label v-if="can.assign">
                                    <input v-model="selectedIds" type="checkbox" :value="pegawai.id" />
                                    <span class="visually-hidden">Pilih {{ pegawai.nama }}</span>
                                </label>
                                <div class="employee-cell">
                                    <span>{{ pegawai.nama.charAt(0).toUpperCase() }}</span>
                                    <div>
                                        <strong>{{ pegawai.nama }}</strong
                                        ><small>{{ pegawai.nik || 'NIK belum tersedia' }}</small>
                                    </div>
                                </div>
                                <span class="mobile-account" :class="pegawai.user.active ? 'active' : 'pending'">
                                    {{ pegawai.user.active ? 'Aktif' : 'Pending' }}
                                </span>
                            </header>
                            <div class="mobile-detail">
                                <span><BriefcaseBusiness /> {{ pegawai.unit || 'Tanpa unit' }}</span>
                                <small>{{ pegawai.jabatan || 'Jabatan belum diatur' }} · {{ pegawai.user.email }}</small>
                            </div>
                            <div class="mobile-assignment">
                                <label>Shift aktif</label>
                                <SearchableSelect
                                    v-if="can.assign"
                                    v-model="rowShifts[pegawai.id]"
                                    class="mobile-shift-select"
                                    :options="searchableShiftOptions"
                                    placeholder="Tanpa shift"
                                    :placeholder-value="0"
                                    search-placeholder="Cari nama atau jam shift..."
                                    :search-threshold="0"
                                />
                                <div v-else-if="pegawai.shift" class="shift-badge">
                                    <strong>{{ pegawai.shift.nama }}</strong
                                    ><small>{{ pegawai.shift.jam_masuk }}–{{ pegawai.shift.jam_keluar }}</small>
                                </div>
                                <span v-else class="no-shift"><TimerOff /> Belum ada shift</span>
                                <button
                                    v-if="can.assign"
                                    type="button"
                                    :disabled="savingRow !== null || Number(rowShifts[pegawai.id]) === (pegawai.shift_id ?? 0)"
                                    @click="saveRow(pegawai)"
                                >
                                    <LoaderCircle v-if="savingRow === pegawai.id" class="spinner" /><Save v-else /> Simpan perubahan
                                </button>
                            </div>
                            <footer>
                                {{
                                    pegawai.assigned_at
                                        ? `Terakhir ${pegawai.assigned_at} oleh ${pegawai.assigned_by || 'sistem'}`
                                        : 'Belum pernah di-assign'
                                }}
                            </footer>
                        </article>
                    </div>
                </template>

                <div v-else class="assign-empty">
                    <span><UserRoundCheck /></span>
                    <small>{{ search || status || shiftFilter ? 'Filter selesai' : 'Belum ada kandidat' }}</small>
                    <h3>{{ search || status || shiftFilter ? 'Pegawai tidak ditemukan' : 'Belum ada pegawai berakun' }}</h3>
                    <p>
                        {{
                            search || status || shiftFilter
                                ? 'Ubah kata kunci atau filter untuk menemukan pegawai lain.'
                                : 'Buat akun user dari menu Pegawai agar pegawai dapat menerima assignment shift.'
                        }}
                    </p>
                    <button v-if="search || status || shiftFilter" type="button" @click="resetFilters"><X /> Hapus filter</button>
                </div>

                <Pagination :links="assignments.links" :from="assignments.from" :to="assignments.to" :total="assignments.total" />
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.assign-page {
    --assign-primary: var(--rs-primary);
    --assign-ink: var(--rs-primary-deep);
    --assign-muted: var(--rs-text-soft);
    --assign-line: var(--rs-border);
    display: grid;
    gap: 20px;
    padding-bottom: 28px;
}
.assign-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.65fr);
    gap: 40px;
    overflow: hidden;
    padding: 38px 42px;
    border-radius: 21px;
    color: white;
    background:
        radial-gradient(circle at 82% 5%, rgba(var(--rs-primary-pale-rgb), 0.34), transparent 30%),
        linear-gradient(125deg, var(--rs-primary-deep) 0%, var(--rs-primary-hover) 58%, var(--rs-primary) 100%);
    box-shadow: 0 18px 45px rgba(var(--rs-primary-deep-rgb), 0.16);
}
.assign-hero-copy {
    position: relative;
    z-index: 1;
}
.assign-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 999px;
    color: var(--rs-primary-soft);
    background: rgba(255, 255, 255, 0.08);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.assign-eyebrow svg,
.assign-hero-notes svg {
    width: 14px;
}
.assign-hero h1 {
    max-width: 650px;
    margin: 14px 0 10px;
    font-size: clamp(30px, 4vw, 47px);
    font-weight: 800;
    line-height: 1.07;
    letter-spacing: -0.04em;
}
.assign-hero h1 span {
    color: var(--rs-primary-border);
}
.assign-hero-copy > p {
    max-width: 690px;
    margin: 0;
    color: var(--rs-primary-border);
    font-size: 13px;
    line-height: 1.7;
}
.assign-hero-notes {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
}
.assign-hero-notes span {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--rs-primary-soft);
    font-size: 10px;
    font-weight: 650;
}
.assign-progress-card {
    z-index: 1;
    align-self: center;
    padding: 20px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 17px;
    background: rgba(var(--rs-primary-deep-rgb), 0.3);
    backdrop-filter: blur(12px);
}
.assign-progress-head {
    display: flex;
    align-items: center;
    gap: 12px;
}
.assign-progress-head > span {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border-radius: 12px;
    color: var(--rs-primary-soft);
    background: rgba(255, 255, 255, 0.12);
}
.assign-progress-head svg {
    width: 20px;
}
.assign-progress-head div {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    flex: 1;
}
.assign-progress-head small {
    color: var(--rs-primary-pale);
    font-size: 10px;
}
.assign-progress-head strong {
    font-size: 25px;
}
.assign-progress-track {
    overflow: hidden;
    height: 7px;
    margin: 17px 0 10px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
}
.assign-progress-track i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--rs-primary-pale), var(--rs-primary-soft));
    transition: width 0.25s ease;
}
.assign-progress-card p {
    margin: 0;
    color: var(--rs-primary-border);
    font-size: 10px;
}
.assign-overview {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.assign-overview article {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 17px;
    border: 1px solid var(--assign-line);
    border-radius: 15px;
    background: white;
    box-shadow: 0 7px 20px rgba(var(--rs-primary-deep-rgb), 0.04);
}
.assign-overview article > span {
    display: grid;
    width: 43px;
    height: 43px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 13px;
}
.assign-overview svg {
    width: 20px;
}
.assign-overview .tone-indigo {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.assign-overview .tone-green {
    color: #158568;
    background: #e7f8f2;
}
.assign-overview .tone-amber {
    color: #ae700f;
    background: #fff5df;
}
.assign-overview article div {
    display: grid;
}
.assign-overview small {
    color: var(--assign-muted);
    font-size: 9px;
    font-weight: 750;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.assign-overview strong {
    color: var(--assign-ink);
    font-size: 22px;
    line-height: 1.25;
}
.assign-overview p {
    margin: 0;
    color: var(--rs-muted);
    font-size: 9px;
}
.assign-directory {
    overflow: hidden;
    border: 1px solid var(--assign-line);
    border-radius: 18px;
    background: white;
    box-shadow: 0 10px 28px rgba(var(--rs-ink-rgb), 0.05);
}
.assign-directory-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 23px 25px 17px;
}
.assign-directory-header span {
    color: var(--assign-primary);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.assign-directory-header h2 {
    margin: 3px 0;
    color: var(--assign-ink);
    font-size: 20px;
}
.assign-directory-header p {
    margin: 0;
    color: var(--assign-muted);
    font-size: 11px;
}
.assign-directory-header > strong {
    padding: 7px 10px;
    border-radius: 9px;
    color: var(--rs-text-soft);
    background: var(--rs-canvas);
    font-size: 10px;
}
.assign-filters {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 170px minmax(190px, 0.65fr) auto auto;
    gap: 9px;
    padding: 0 25px 18px;
}
.assign-filters label {
    position: relative;
    display: flex;
    align-items: center;
}
.assign-filters select,
.assign-filters input {
    width: 100%;
    height: 42px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    outline: none;
    color: var(--rs-ink);
    background: var(--rs-canvas);
    font-size: 11px;
}
.assign-filters select {
    padding: 0 10px;
}
.assign-search > svg {
    position: absolute;
    left: 12px;
    width: 15px;
    color: var(--rs-muted);
}
.assign-search input {
    padding: 0 39px 0 36px;
}
.assign-search button {
    position: absolute;
    right: 7px;
    display: grid;
    width: 28px;
    height: 28px;
    place-items: center;
    border: 0;
    border-radius: 7px;
    color: var(--rs-muted);
    background: transparent;
}
.assign-search button svg {
    width: 14px;
}
.assign-filters input:focus,
.assign-filters select:focus {
    border-color: var(--rs-primary-pale);
    box-shadow: 0 0 0 3px rgba(var(--rs-primary-rgb), 0.1);
    background: white;
}
.assign-filters > button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 90px;
    padding: 0 14px;
    border: 0;
    border-radius: 10px;
    color: white;
    background: var(--assign-primary);
    font-size: 11px;
    font-weight: 750;
}
.assign-filters > button svg {
    width: 14px;
}
.assign-filters > .reset-filter {
    min-width: 42px;
    padding: 0;
    color: var(--rs-text-soft);
    border: 1px solid var(--rs-border);
    background: white;
}
.assign-bulk-bar {
    position: relative;
    display: grid;
    grid-template-columns: minmax(190px, 1fr) minmax(220px, 0.8fr) auto;
    align-items: center;
    gap: 12px;
    margin: 0 25px 18px;
    padding: 12px;
    border: 1px solid var(--rs-primary-soft);
    border-radius: 13px;
    background: var(--rs-canvas);
}
.assign-bulk-copy {
    display: flex;
    align-items: center;
    gap: 9px;
}
.assign-bulk-copy > span {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border-radius: 10px;
    color: var(--assign-primary);
    background: var(--rs-primary-soft);
}
.assign-bulk-bar svg {
    width: 16px;
}
.assign-bulk-bar p {
    display: grid;
    margin: 0;
}
.assign-bulk-bar strong {
    color: var(--assign-ink);
    font-size: 11px;
}
.assign-bulk-bar small {
    color: var(--rs-muted);
    font-size: 9px;
}
.filter-shift-select,
.bulk-shift-select,
.row-shift-select,
.mobile-shift-select {
    min-width: 0;
}
:deep(.filter-shift-select .searchable-select-trigger),
:deep(.bulk-shift-select .searchable-select-trigger),
:deep(.filter-shift-select .searchable-select-native),
:deep(.bulk-shift-select .searchable-select-native) {
    height: 42px;
    border-radius: 10px;
    background: var(--rs-canvas);
    font-size: 11px;
}
:deep(.bulk-shift-select .searchable-select-trigger),
:deep(.bulk-shift-select .searchable-select-native) {
    background: white;
}
:deep(.searchable-select-panel) {
    min-width: 230px;
}
.assign-bulk-bar > button,
.save-row,
.mobile-assignment button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 38px;
    padding: 0 13px;
    border: 0;
    border-radius: 9px;
    color: white;
    background: var(--assign-primary);
    font-size: 10px;
    font-weight: 750;
}
.assign-bulk-bar > button:disabled,
.save-row:disabled,
.mobile-assignment button:disabled {
    cursor: not-allowed;
    opacity: 0.48;
}
.assign-error {
    grid-column: 1 / -1;
    color: #cf3e54;
    font-size: 10px;
}
.assign-table-wrap {
    overflow-x: auto;
    border-top: 1px solid var(--assign-line);
}
.assign-table {
    width: 100%;
    min-width: 1120px;
    border-collapse: collapse;
}
.assign-table th {
    padding: 12px 14px;
    color: var(--rs-muted);
    background: var(--rs-canvas);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}
.assign-table td {
    padding: 14px;
    border-top: 1px solid var(--rs-surface-muted);
    color: var(--assign-ink);
    font-size: 11px;
    vertical-align: middle;
}
.assign-table tbody tr {
    transition: background 0.15s;
}
.assign-table tbody tr:hover,
.assign-table tbody tr.selected {
    background: var(--rs-canvas);
}
.check-column {
    width: 38px;
    padding-right: 5px !important;
    text-align: center !important;
}
.check-column input,
.assign-mobile-list input {
    width: 15px;
    height: 15px;
    accent-color: var(--assign-primary);
}
.employee-cell {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 155px;
}
.employee-cell > span {
    display: grid;
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 11px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-size: 14px;
    font-weight: 800;
}
.employee-cell > div,
.account-cell,
.organization-cell,
.history-cell,
.shift-badge {
    display: grid;
}
.employee-cell strong,
.account-cell strong,
.history-cell strong,
.shift-badge strong {
    font-size: 11px;
}
.employee-cell small,
.organization-cell small,
.history-cell small,
.shift-badge small {
    margin-top: 2px;
    color: var(--rs-muted);
    font-size: 9px;
}
.account-cell {
    min-width: 170px;
}
.account-cell > span {
    display: flex;
    align-items: center;
    gap: 5px;
    width: max-content;
    margin-top: 4px;
    padding: 3px 6px;
    border-radius: 999px;
    font-size: 8px;
    font-weight: 750;
}
.account-cell > span i {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.account-cell .active,
.mobile-account.active {
    color: #137b61;
    background: #e8f7f1;
}
.account-cell .pending,
.mobile-account.pending {
    color: #a96c10;
    background: #fff4dd;
}
.organization-cell {
    min-width: 135px;
}
.organization-cell span {
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 650;
}
.organization-cell svg {
    width: 13px;
    color: var(--rs-muted);
}
.row-shift-select {
    min-width: 190px;
}
:deep(.row-shift-select .searchable-select-trigger),
:deep(.row-shift-select .searchable-select-native),
:deep(.mobile-shift-select .searchable-select-trigger),
:deep(.mobile-shift-select .searchable-select-native) {
    height: 38px;
    border-radius: 9px;
    background: white;
    font-size: 10px;
}
.shift-badge strong {
    color: var(--rs-primary);
}
.no-shift {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #a16b18;
    font-size: 10px;
}
.no-shift svg {
    width: 14px;
}
.history-cell,
.history-empty {
    min-width: 130px;
}
.history-empty {
    color: var(--rs-muted);
    font-size: 9px;
}
.save-row {
    height: 34px;
    white-space: nowrap;
}
.save-row svg,
.mobile-assignment button svg {
    width: 13px;
}
.assign-mobile-list {
    display: none;
}
.assign-empty {
    display: grid;
    justify-items: center;
    padding: 58px 20px;
    border-top: 1px solid var(--assign-line);
    text-align: center;
}
.assign-empty > span {
    display: grid;
    width: 60px;
    height: 60px;
    margin-bottom: 13px;
    place-items: center;
    border-radius: 18px;
    color: var(--assign-primary);
    background: var(--rs-primary-soft);
}
.assign-empty > span svg {
    width: 27px;
}
.assign-empty small {
    color: var(--assign-primary);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}
.assign-empty h3 {
    margin: 5px 0;
    color: var(--assign-ink);
    font-size: 18px;
}
.assign-empty p {
    max-width: 460px;
    margin: 0 0 14px;
    color: var(--assign-muted);
    font-size: 11px;
}
.assign-empty button {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border: 0;
    border-radius: 9px;
    color: white;
    background: var(--assign-primary);
    font-size: 10px;
    font-weight: 750;
}
.assign-empty button svg {
    width: 14px;
}
.spinner {
    animation: assign-spin 0.75s linear infinite;
}

@media (max-width: 1050px) {
    .assign-filters {
        grid-template-columns: 1fr 1fr;
    }
    .assign-search {
        grid-column: 1 / -1;
    }
    .assign-filters > button {
        min-height: 40px;
    }
}

@media (max-width: 760px) {
    .assign-page {
        gap: 14px;
    }
    .assign-hero {
        grid-template-columns: 1fr;
        gap: 24px;
        padding: 28px 23px;
        border-radius: 17px;
    }
    .assign-hero h1 {
        font-size: 32px;
    }
    .assign-overview {
        grid-template-columns: 1fr;
        gap: 9px;
    }
    .assign-overview article {
        padding: 13px;
    }
    .assign-directory-header {
        align-items: flex-start;
        padding: 19px;
    }
    .assign-directory-header > strong {
        display: none;
    }
    .assign-filters {
        grid-template-columns: 1fr;
        padding: 0 19px 16px;
    }
    .assign-search {
        grid-column: auto;
    }
    .assign-bulk-bar {
        grid-template-columns: 1fr;
        margin: 0 14px 14px;
    }
    .assign-table-wrap {
        display: none;
    }
    .assign-mobile-list {
        display: grid;
        gap: 10px;
        padding: 14px;
        border-top: 1px solid var(--assign-line);
        background: var(--rs-canvas);
    }
    .assign-mobile-list > article {
        overflow: hidden;
        border: 1px solid var(--rs-border);
        border-radius: 14px;
        background: white;
    }
    .assign-mobile-list > article.selected {
        border-color: var(--rs-primary-border);
        box-shadow: 0 0 0 2px rgba(var(--rs-primary-rgb), 0.08);
    }
    .assign-mobile-list header {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 13px;
    }
    .assign-mobile-list .employee-cell {
        flex: 1;
        min-width: 0;
    }
    .mobile-account {
        padding: 4px 7px;
        border-radius: 999px;
        font-size: 8px;
        font-weight: 750;
    }
    .mobile-detail {
        display: grid;
        gap: 3px;
        padding: 10px 13px;
        border-block: 1px solid var(--rs-surface-muted);
        color: var(--rs-text-soft);
        background: var(--rs-canvas);
        font-size: 10px;
    }
    .mobile-detail span {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .mobile-detail svg {
        width: 13px;
    }
    .mobile-detail small {
        color: var(--rs-muted);
        font-size: 9px;
    }
    .mobile-assignment {
        display: grid;
        gap: 7px;
        padding: 13px;
    }
    .mobile-assignment label {
        color: var(--rs-text-soft);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }
    .mobile-assignment button {
        width: 100%;
        margin-top: 2px;
    }
    .assign-mobile-list footer {
        padding: 9px 13px;
        border-top: 1px solid var(--rs-surface-muted);
        color: var(--rs-muted);
        font-size: 8px;
    }
}

@keyframes assign-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
