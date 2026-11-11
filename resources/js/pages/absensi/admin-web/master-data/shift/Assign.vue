<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    CalendarDays,
    Check,
    Clock3,
    Layers3,
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
import BulkShiftAssignment from './BulkShiftAssignment.vue';
import EmployeeShiftSchedule from './EmployeeShiftSchedule.vue';
import ShiftCalendar from './ShiftCalendar.vue';
import type { BulkShiftOptions } from './bulk-shift';

interface ShiftOption {
    id: number;
    nama: string;
    is_day_off: boolean;
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
    today_schedule: {
        date: string;
        source: 'scheduled' | 'default';
        shift: ShiftOption | null;
        is_day_off: boolean;
        day_off_reason: string | null;
    };
    assigned_at: string | null;
    assigned_by: string | null;
}

const props = defineProps<{
    assignments: Paginated<AssignmentRow>;
    overview: { total: number; assigned: number; unassigned: number };
    shifts: ShiftOption[];
    filters: { search?: string | null; status?: string | null; shift_id?: number | null };
    can: { assign: boolean };
    bulkOptions: BulkShiftOptions | null;
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
const filtering = ref(false);
const rowErrors = reactive<Record<number, string>>({});
const batchOpen = ref(false);
const scheduleEmployee = ref<AssignmentRow | null>(null);
const shiftCalendar = ref<InstanceType<typeof ShiftCalendar> | null>(null);
const closeSchedule = () => {
    scheduleEmployee.value = null;
    router.reload({ only: ['assignments'] });
};
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
watch(
    () => props.assignments,
    () => shiftCalendar.value?.refresh(),
);

const allVisibleSelected = computed(
    () => props.assignments.data.length > 0 && props.assignments.data.every((pegawai) => selectedIds.value.includes(pegawai.id)),
);
const someVisibleSelected = computed(() => selectedIds.value.length > 0 && !allVisibleSelected.value);
const resultSummary = computed(() => {
    if (!props.assignments.total) return 'Belum ada pegawai berakun';
    return `${props.assignments.from}-${props.assignments.to} dari ${props.assignments.total} pegawai`;
});
const assignmentRate = computed(() => (props.overview.total ? Math.round((props.overview.assigned / props.overview.total) * 100) : 0));
const scheduleDateLabel = (date: string) => new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' }).format(new Date(`${date}T00:00:00`));
const scheduleDescription = (schedule: AssignmentRow['today_schedule']) =>
    schedule.is_day_off
        ? schedule.day_off_reason || 'Hari libur'
        : schedule.shift
          ? `${schedule.shift.jam_masuk}–${schedule.shift.jam_keluar}`
          : 'Belum dijadwalkan';
const shiftDescription = (id: number | string) => {
    const shift = props.shifts.find((item) => item.id === Number(id));
    return shift ? (shift.is_day_off ? 'OFF / Libur · Tanpa jam kerja' : `${shift.jam_masuk}–${shift.jam_keluar}`) : 'Belum memiliki shift default';
};
const clearSelection = () => {
    selectedIds.value = [];
    bulkShift.value = '';
    bulkError.value = '';
};
const searchableShiftOptions = computed<SearchableSelectOption[]>(() =>
    props.shifts.map((shift) => ({
        value: shift.id,
        label: shift.nama,
        description: shift.is_day_off ? 'OFF / Libur · Tanpa jam kerja' : `${shift.jam_masuk}–${shift.jam_keluar}`,
        searchText: `${shift.nama} ${shift.jam_masuk} ${shift.jam_keluar}`,
    })),
);
const bulkShiftOptions = computed<SearchableSelectOption[]>(() => [
    ...searchableShiftOptions.value,
    {
        value: 'clear',
        label: 'Kosongkan shift',
        description: 'Hapus shift default pegawai terpilih',
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
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (filtering.value = true),
            onFinish: () => (filtering.value = false),
        },
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
    if (!props.can.assign || savingRow.value !== null || savingBulk.value) return;
    delete rowErrors[pegawai.id];
    savingRow.value = pegawai.id;
    router.put(
        baseUrl,
        { pegawai_ids: [pegawai.id], shift_id: Number(rowShifts[pegawai.id]) || null },
        {
            preserveScroll: true,
            onError: (errors) => {
                rowErrors[pegawai.id] = String(errors.pegawai_ids ?? errors.shift_id ?? 'Shift gagal disimpan. Coba kembali.');
            },
            onFinish: () => (savingRow.value = null),
        },
    );
};

const saveBulk = () => {
    if (!props.can.assign || savingBulk.value || savingRow.value !== null) return;
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
                    <p>Tetapkan shift default atau susun jadwal per tanggal untuk banyak pegawai melalui template Excel.</p>
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

            <section class="assign-overview" aria-label="Ringkasan shift default seluruh pegawai">
                <article>
                    <span class="stat-icon"><UsersRound /></span>
                    <div>
                        <p>Pegawai berakun</p>
                        <strong>{{ overview.total.toLocaleString('id-ID') }}</strong>
                    </div>
                    <small>Total pegawai</small>
                </article>
                <article>
                    <span class="stat-icon assigned"><BadgeCheck /></span>
                    <div>
                        <p>Memiliki shift default</p>
                        <strong>{{ overview.assigned.toLocaleString('id-ID') }}</strong>
                    </div>
                    <span class="stat-rate">{{ assignmentRate }}%</span>
                </article>
                <article :class="{ 'needs-attention': overview.unassigned > 0 }">
                    <span class="stat-icon unassigned"><TimerOff /></span>
                    <div>
                        <p>Belum memiliki shift default</p>
                        <strong>{{ overview.unassigned.toLocaleString('id-ID') }}</strong>
                    </div>
                    <small>{{ overview.unassigned ? 'Perlu diatur' : 'Semua sudah diatur' }}</small>
                </article>
            </section>

            <ShiftCalendar ref="shiftCalendar" />

            <section class="assign-directory" aria-labelledby="assign-directory-title" :aria-busy="filtering">
                <header class="assign-directory-header">
                    <div>
                        <h2 id="assign-directory-title">
                            Jadwal shift pegawai <span>{{ assignments.total.toLocaleString('id-ID') }}</span>
                        </h2>
                        <p>Jadwal per tanggal dari Assign Batch diprioritaskan. Shift default digunakan saat tanggal tersebut belum dijadwalkan.</p>
                    </div>
                    <div class="assign-directory-actions">
                        <span class="directory-hint"><Clock3 /> Perubahan disimpan per pegawai</span>
                        <button v-if="can.assign && bulkOptions" type="button" class="assign-primary-button" @click="batchOpen = true">
                            <Layers3 /> Assign Batch
                        </button>
                    </div>
                </header>

                <form class="assign-filters" role="search" @submit.prevent="applyFilters">
                    <label class="assign-search">
                        <Search />
                        <span class="visually-hidden">Cari pegawai</span>
                        <input v-model="search" type="search" placeholder="Cari nama, NIK, email, atau unit..." autocomplete="off" />
                        <LoaderCircle v-if="filtering" class="search-loading spinner" />
                        <button v-else-if="search" type="button" aria-label="Hapus pencarian" @click="search = ''"><X /></button>
                    </label>
                    <label>
                        <span class="visually-hidden">Filter status assignment</span>
                        <select v-model="status" @change="applyFilters">
                            <option value="">Semua status</option>
                            <option value="assigned">Memiliki shift default</option>
                            <option value="unassigned">Belum memiliki shift default</option>
                        </select>
                    </label>
                    <SearchableSelect
                        v-model="shiftFilter"
                        class="filter-shift-select"
                        label="Filter shift default"
                        :options="searchableShiftOptions"
                        placeholder="Semua shift default"
                        placeholder-value=""
                        search-placeholder="Cari nama atau jam shift..."
                        :search-threshold="0"
                        @change="applyFilters"
                    />
                    <button v-if="search || status || shiftFilter" type="button" class="reset-filter" @click="resetFilters"><X /> Reset</button>
                </form>

                <div class="assign-list-toolbar">
                    <label v-if="can.assign && assignments.data.length" class="select-page">
                        <input
                            type="checkbox"
                            :checked="allVisibleSelected"
                            :indeterminate="someVisibleSelected"
                            :disabled="savingBulk"
                            @change="toggleAll"
                        />
                        Pilih semua di halaman ini
                    </label>
                    <span v-else>Direktori pegawai berakun</span>
                    <span aria-live="polite">{{ filtering ? 'Memuat pegawai...' : resultSummary }}</span>
                </div>

                <Transition name="selection">
                    <div v-if="can.assign && selectedIds.length" class="assign-bulk-bar">
                        <div class="assign-bulk-copy">
                            <span class="selected-count">{{ selectedIds.length }}</span>
                            <div><strong>Pegawai dipilih</strong><small>Terapkan satu shift default ke semua pilihan.</small></div>
                        </div>
                        <SearchableSelect
                            v-model="bulkShift"
                            class="bulk-shift-select"
                            label="Shift untuk pegawai terpilih"
                            :options="bulkShiftOptions"
                            placeholder="Pilih shift atau kosongkan"
                            placeholder-value=""
                            placeholder-disabled
                            search-placeholder="Cari nama atau jam shift..."
                            :search-threshold="0"
                            :disabled="savingBulk || savingRow !== null"
                        />
                        <button
                            type="button"
                            class="assign-primary-button"
                            :disabled="savingBulk || savingRow !== null || bulkShift === ''"
                            @click="saveBulk"
                        >
                            <LoaderCircle v-if="savingBulk" class="spinner" /><Save v-else /> {{ savingBulk ? 'Menyimpan...' : 'Terapkan shift' }}
                        </button>
                        <button
                            type="button"
                            class="clear-selection"
                            aria-label="Batalkan pilihan pegawai"
                            :disabled="savingBulk"
                            @click="clearSelection"
                        >
                            <X />
                        </button>
                        <p v-if="bulkError" class="assign-error" role="alert">{{ bulkError }}</p>
                    </div>
                </Transition>

                <template v-if="assignments.data.length">
                    <div class="assign-table-wrap">
                        <table class="assign-table">
                            <thead>
                                <tr>
                                    <th v-if="can.assign" class="check-column"><span class="visually-hidden">Pilih pegawai</span></th>
                                    <th class="employee-column">Pegawai</th>
                                    <th class="unit-column">Unit &amp; jabatan</th>
                                    <th class="shift-column">Jadwal &amp; default</th>
                                    <th class="history-column">Default terakhir diubah</th>
                                    <th v-if="can.assign" class="action-column"><span class="visually-hidden">Simpan perubahan</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="pegawai in assignments.data"
                                    :key="pegawai.id"
                                    :class="{
                                        selected: selectedIds.includes(pegawai.id),
                                        changed: Number(rowShifts[pegawai.id]) !== (pegawai.shift_id ?? 0),
                                    }"
                                >
                                    <td v-if="can.assign" class="check-column">
                                        <input
                                            v-model="selectedIds"
                                            type="checkbox"
                                            :value="pegawai.id"
                                            :aria-label="`Pilih ${pegawai.nama}`"
                                            :disabled="savingBulk"
                                        />
                                    </td>
                                    <td>
                                        <div class="employee-cell">
                                            <span class="employee-avatar">{{ pegawai.nama.charAt(0).toUpperCase() }}</span>
                                            <div>
                                                <strong>{{ pegawai.nama }}</strong>
                                                <small>{{ pegawai.nik || 'NIK belum tersedia' }}</small>
                                                <span
                                                    class="employee-account"
                                                    :class="{ pending: !pegawai.user.active }"
                                                    :title="pegawai.user.active ? 'Akun aktif' : 'Menunggu aktivasi akun'"
                                                >
                                                    <i></i>{{ pegawai.user.email || pegawai.user.name || 'Email belum tersedia'
                                                    }}<span class="visually-hidden">
                                                        · {{ pegawai.user.active ? 'Akun aktif' : 'Menunggu aktivasi' }}</span
                                                    >
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="organization-cell">
                                            <strong>{{ pegawai.unit || 'Tanpa unit' }}</strong
                                            ><small>{{ pegawai.jabatan || 'Jabatan belum diatur' }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="shift-editor">
                                            <div class="today-schedule" :class="{ scheduled: pegawai.today_schedule.source === 'scheduled' }">
                                                <span>Hari ini · {{ scheduleDateLabel(pegawai.today_schedule.date) }}</span>
                                                <strong>{{
                                                    pegawai.today_schedule.is_day_off
                                                        ? 'OFF / Libur'
                                                        : pegawai.today_schedule.shift?.nama || 'Belum ada shift'
                                                }}</strong>
                                                <small>{{ scheduleDescription(pegawai.today_schedule) }}</small>
                                                <span class="schedule-source">{{
                                                    pegawai.today_schedule.source === 'scheduled' ? 'Jadwal per tanggal' : 'Mengikuti default'
                                                }}</span>
                                            </div>
                                            <span class="default-shift-label">Shift default (cadangan)</span>
                                            <SearchableSelect
                                                v-if="can.assign"
                                                v-model="rowShifts[pegawai.id]"
                                                :label="`Shift default ${pegawai.nama}`"
                                                :options="searchableShiftOptions"
                                                placeholder="Tanpa shift"
                                                :placeholder-value="0"
                                                search-placeholder="Cari nama atau jam shift..."
                                                :search-threshold="0"
                                                :disabled="savingBulk || savingRow === pegawai.id"
                                            />
                                            <strong v-else>{{ pegawai.shift?.nama || 'Belum ada shift' }}</strong>
                                            <small :class="{ 'unsaved-shift': Number(rowShifts[pegawai.id]) !== (pegawai.shift_id ?? 0) }">
                                                <Clock3 />{{ shiftDescription(rowShifts[pegawai.id]) }}
                                            </small>
                                            <button
                                                type="button"
                                                class="view-schedule"
                                                :aria-label="`Lihat jadwal ${pegawai.nama}`"
                                                @click="scheduleEmployee = pegawai"
                                            >
                                                <CalendarDays /> Lihat jadwal
                                            </button>
                                            <p v-if="rowErrors[pegawai.id]" class="assign-error" role="alert">{{ rowErrors[pegawai.id] }}</p>
                                        </div>
                                    </td>
                                    <td>
                                        <div v-if="pegawai.assigned_at" class="history-cell">
                                            <span>{{ pegawai.assigned_at }}</span
                                            ><small>oleh {{ pegawai.assigned_by || 'sistem' }}</small>
                                        </div>
                                        <span v-else class="history-empty">Belum pernah diatur</span>
                                    </td>
                                    <td v-if="can.assign" class="action-column">
                                        <button
                                            type="button"
                                            class="save-row"
                                            :aria-label="`Simpan shift ${pegawai.nama}`"
                                            :disabled="savingRow !== null || savingBulk || Number(rowShifts[pegawai.id]) === (pegawai.shift_id ?? 0)"
                                            @click="saveRow(pegawai)"
                                        >
                                            <LoaderCircle v-if="savingRow === pegawai.id" class="spinner" /><Save v-else /><span>Simpan</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="assign-mobile-list">
                        <article v-for="pegawai in assignments.data" :key="pegawai.id" :class="{ selected: selectedIds.includes(pegawai.id) }">
                            <header>
                                <input
                                    v-if="can.assign"
                                    v-model="selectedIds"
                                    type="checkbox"
                                    :value="pegawai.id"
                                    :aria-label="`Pilih ${pegawai.nama}`"
                                    :disabled="savingBulk"
                                />
                                <div class="employee-cell">
                                    <span class="employee-avatar">{{ pegawai.nama.charAt(0).toUpperCase() }}</span>
                                    <div>
                                        <strong>{{ pegawai.nama }}</strong
                                        ><small>{{ pegawai.nik || 'NIK belum tersedia' }}</small>
                                    </div>
                                </div>
                                <span class="mobile-account" :class="{ pending: !pegawai.user.active }">{{
                                    pegawai.user.active ? 'Aktif' : 'Pending'
                                }}</span>
                            </header>
                            <div class="mobile-detail">
                                <span><BriefcaseBusiness />{{ pegawai.unit || 'Tanpa unit' }} · {{ pegawai.jabatan || 'Jabatan belum diatur' }}</span>
                                <small>{{ pegawai.user.email || pegawai.user.name }}</small>
                            </div>
                            <div class="mobile-assignment">
                                <div class="today-schedule" :class="{ scheduled: pegawai.today_schedule.source === 'scheduled' }">
                                    <span>Hari ini · {{ scheduleDateLabel(pegawai.today_schedule.date) }}</span>
                                    <strong>{{
                                        pegawai.today_schedule.is_day_off ? 'OFF / Libur' : pegawai.today_schedule.shift?.nama || 'Belum ada shift'
                                    }}</strong>
                                    <small>{{ scheduleDescription(pegawai.today_schedule) }}</small>
                                    <span class="schedule-source">{{
                                        pegawai.today_schedule.source === 'scheduled' ? 'Jadwal per tanggal' : 'Mengikuti default'
                                    }}</span>
                                </div>
                                <span class="field-label">Shift default (cadangan)</span>
                                <div class="mobile-shift-controls">
                                    <SearchableSelect
                                        v-if="can.assign"
                                        v-model="rowShifts[pegawai.id]"
                                        :label="`Shift default ${pegawai.nama}`"
                                        :options="searchableShiftOptions"
                                        placeholder="Tanpa shift"
                                        :placeholder-value="0"
                                        search-placeholder="Cari nama atau jam shift..."
                                        :search-threshold="0"
                                        :disabled="savingBulk || savingRow === pegawai.id"
                                    />
                                    <strong v-else>{{ pegawai.shift?.nama || 'Belum ada shift' }}</strong>
                                    <button
                                        v-if="can.assign"
                                        type="button"
                                        class="save-row"
                                        :aria-label="`Simpan shift ${pegawai.nama}`"
                                        :disabled="savingRow !== null || savingBulk || Number(rowShifts[pegawai.id]) === (pegawai.shift_id ?? 0)"
                                        @click="saveRow(pegawai)"
                                    >
                                        <LoaderCircle v-if="savingRow === pegawai.id" class="spinner" /><Save v-else /> Simpan
                                    </button>
                                </div>
                                <small>{{ shiftDescription(rowShifts[pegawai.id]) }}</small>
                                <button
                                    type="button"
                                    class="view-schedule"
                                    :aria-label="`Lihat jadwal ${pegawai.nama}`"
                                    @click="scheduleEmployee = pegawai"
                                >
                                    <CalendarDays /> Lihat jadwal
                                </button>
                                <p v-if="rowErrors[pegawai.id]" class="assign-error" role="alert">{{ rowErrors[pegawai.id] }}</p>
                            </div>
                            <footer>
                                {{
                                    pegawai.assigned_at
                                        ? `Diubah ${pegawai.assigned_at} oleh ${pegawai.assigned_by || 'sistem'}`
                                        : 'Shift default belum pernah diatur'
                                }}
                            </footer>
                        </article>
                    </div>
                </template>

                <div v-else class="assign-empty">
                    <span><Search v-if="search || status || shiftFilter" /><UsersRound v-else /></span>
                    <h3>{{ search || status || shiftFilter ? 'Pegawai tidak ditemukan' : 'Belum ada pegawai berakun' }}</h3>
                    <p>
                        {{
                            search || status || shiftFilter
                                ? 'Coba kata kunci lain atau reset filter untuk melihat seluruh pegawai.'
                                : 'Buat akun dari menu Pegawai agar pegawai dapat menerima shift.'
                        }}
                    </p>
                    <button v-if="search || status || shiftFilter" type="button" class="assign-primary-button" @click="resetFilters">
                        Reset filter
                    </button>
                </div>

                <Pagination :links="assignments.links" :from="assignments.from" :to="assignments.to" :total="assignments.total" />
            </section>
            <p class="assign-footnote">
                <Layers3 /> Ingin mengatur shift berbeda setiap hari? Gunakan Assign Batch untuk mengisi jadwal melalui Excel.
            </p>
            <BulkShiftAssignment v-if="can.assign && bulkOptions" :open="batchOpen" :options="bulkOptions" @close="batchOpen = false" />
            <EmployeeShiftSchedule
                v-if="scheduleEmployee"
                :key="scheduleEmployee.id"
                :employee="scheduleEmployee"
                :can-edit="can.assign"
                :shifts="shifts"
                @close="closeSchedule"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.assign-page {
    display: grid;
    gap: 20px;
    padding-bottom: 24px;
    color: var(--rs-ink);
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
@media (max-width: 760px) {
    .assign-hero {
        grid-template-columns: 1fr;
        gap: 24px;
        padding: 28px 23px;
        border-radius: 17px;
    }
    .assign-hero h1 {
        font-size: 32px;
    }
}
.stat-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 13px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.assign-directory-header p {
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 13px;
    line-height: 1.65;
}
.assign-primary-button,
.save-row {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
    min-height: 42px;
    padding: 10px 16px;
    border: 1px solid var(--rs-primary);
    border-radius: 9px;
    background: var(--rs-primary);
    color: white;
    font-size: 13px;
    font-weight: 650;
    white-space: nowrap;
    transition:
        background 150ms,
        box-shadow 150ms;
}
.assign-primary-button:hover:not(:disabled),
.save-row:hover:not(:disabled) {
    background: var(--rs-primary-hover);
    box-shadow: 0 3px 8px rgba(var(--rs-primary-rgb), 0.12);
}
.assign-page button:focus-visible,
.assign-page input:focus-visible,
.assign-page select:focus-visible {
    outline: 3px solid var(--rs-primary-pale);
    outline-offset: 3px;
}
.assign-page button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
.assign-primary-button svg,
.save-row svg {
    width: 16px;
    height: 16px;
}
.assign-overview {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.assign-overview article {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--rs-border);
    border-radius: 12px;
    background: white;
}
.stat-icon {
    width: 42px;
    height: 42px;
    background: var(--rs-canvas);
}
.stat-icon svg {
    width: 20px;
    height: 20px;
}
.stat-icon.assigned {
    color: #14765b;
    background: #eaf6f0;
}
.stat-icon.unassigned {
    color: #a67422;
    background: #fff6e5;
}
.assign-overview p {
    margin: 0 0 5px;
    color: var(--rs-text-soft);
    font-size: 12px;
}
.assign-overview strong {
    display: block;
    color: var(--rs-primary-deep);
    font-size: 27px;
    font-weight: 750;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}
.assign-overview article > small,
.stat-rate {
    margin-left: auto;
    align-self: flex-end;
    color: var(--rs-muted);
    font-size: 11px;
    text-align: right;
}
.stat-rate {
    padding: 4px 7px;
    border-radius: 6px;
    color: #14765b;
    background: #eaf6f0;
    font-weight: 700;
}
.needs-attention {
    border-color: #edddbd !important;
}
.assign-directory {
    min-width: 0;
    border: 1px solid var(--rs-border);
    border-radius: 14px;
    background: white;
    box-shadow: 0 3px 16px rgba(var(--rs-ink-rgb), 0.025);
}
.assign-directory-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 24px 24px 20px;
}
.assign-directory-header h2 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 6px;
    font-size: 16px;
    font-weight: 750;
    letter-spacing: -0.025em;
}
.assign-directory-header h2 > span {
    padding: 3px 7px;
    border: 1px solid var(--rs-border);
    border-radius: 6px;
    color: var(--rs-text-soft);
    font-size: 11px;
    font-weight: 650;
}
.assign-directory-actions {
    display: flex;
    align-items: center;
    gap: 16px;
}
.directory-hint {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--rs-muted);
    font-size: 11px;
    white-space: nowrap;
}
.directory-hint svg {
    width: 14px;
    height: 14px;
}
.assign-filters {
    display: grid;
    grid-template-columns: minmax(200px, 1fr) 190px 200px auto;
    gap: 10px;
    padding: 0 24px 20px;
}
.assign-filters > label {
    position: relative;
    display: flex;
    align-items: center;
    min-width: 0;
    margin: 0;
}
.assign-filters input,
.assign-filters select {
    width: 100%;
    height: 42px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    color: var(--rs-ink);
    background-color: white;
    font-size: 12px;
}
.assign-filters select {
    padding: 0 32px 0 12px;
}
.assign-search > svg {
    position: absolute;
    left: 13px;
    width: 17px;
    height: 17px;
    color: var(--rs-muted);
    pointer-events: none;
}
.assign-search input {
    padding: 0 40px 0 39px;
}
.assign-search input::-webkit-search-cancel-button {
    display: none;
}
.assign-search .search-loading {
    right: 12px;
    left: auto;
}
.assign-search button,
.clear-selection {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 0;
    border-radius: 6px;
    color: var(--rs-text-soft);
    background: transparent;
}
.assign-search button {
    position: absolute;
    right: 6px;
}
.assign-search button:hover,
.clear-selection:hover {
    background: var(--rs-surface-muted);
}
.assign-search button svg,
.clear-selection svg {
    width: 16px;
    height: 16px;
}
.reset-filter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 0 10px;
    border: 0;
    background: transparent;
    color: var(--rs-text-soft);
    font-size: 12px;
}
.reset-filter svg {
    width: 14px;
    height: 14px;
}
.assign-list-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    min-height: 46px;
    padding: 10px 24px;
    border-top: 1px solid var(--rs-border);
    color: var(--rs-muted);
    font-size: 11px;
}
.select-page {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    color: var(--rs-text-soft);
    cursor: pointer;
}
.assign-page input[type='checkbox'] {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    border: 1px solid var(--rs-primary-border);
    border-radius: 4px;
    accent-color: var(--rs-primary);
    color: var(--rs-primary);
    cursor: pointer;
}
.assign-bulk-bar {
    display: grid;
    grid-template-columns: minmax(200px, 1fr) minmax(190px, 260px) auto 30px;
    align-items: center;
    gap: 12px;
    margin: 0 16px 16px;
    padding: 14px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 10px;
    background: var(--rs-primary-soft);
}
.assign-bulk-copy {
    display: flex;
    align-items: center;
    gap: 10px;
}
.selected-count {
    display: grid;
    place-items: center;
    min-width: 34px;
    height: 34px;
    padding: 0 6px;
    border-radius: 8px;
    color: white;
    background: var(--rs-primary);
    font-size: 14px;
    font-weight: 750;
}
.assign-bulk-copy strong {
    display: block;
    color: var(--rs-primary-deep);
    font-size: 12px;
}
.assign-bulk-copy small {
    display: block;
    margin-top: 3px;
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.5;
}
.assign-bulk-bar .assign-primary-button {
    font-size: 12px;
    padding-inline: 12px;
}
.assign-error {
    grid-column: 1 / -1;
    margin: 4px 0 0;
    color: #b23d50;
    font-size: 11px;
    line-height: 1.6;
}
:deep(.searchable-select-trigger),
:deep(.searchable-select-native) {
    height: 42px;
    border-radius: 8px;
    font-size: 12px;
}
:deep(.searchable-select-panel) {
    min-width: 230px;
    z-index: 40;
}
:deep(.searchable-select.open) {
    z-index: 41;
}
.assign-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}
.assign-table th {
    padding: 12px 16px;
    border-block: 1px solid var(--rs-border);
    background: var(--rs-canvas);
    color: var(--rs-text-soft);
    text-align: left;
    font-size: 11px;
    font-weight: 650;
}
.assign-table td {
    padding: 18px 16px;
    border-bottom: 1px solid var(--rs-surface-muted);
    vertical-align: middle;
}
.assign-table tr:last-child td {
    border-bottom: 0;
}
.assign-table tbody tr {
    transition: background 150ms;
}
.assign-table tbody tr:hover {
    background: #fafcfb;
}
.assign-table tbody tr.selected {
    background: #f1f9f6;
}
.check-column {
    width: 40px;
    padding-right: 0 !important;
    padding-left: 24px !important;
}
.employee-column {
    width: 29%;
}
.unit-column {
    width: 20%;
}
.shift-column {
    width: 22%;
}
.history-column {
    width: 18%;
}
.action-column {
    width: 100px;
    text-align: right !important;
    padding-left: 4px !important;
    padding-right: 24px !important;
}
.employee-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.employee-avatar {
    display: grid;
    place-items: center;
    width: 35px;
    height: 35px;
    flex-shrink: 0;
    border: 1px solid var(--rs-border);
    border-radius: 50%;
    background: var(--rs-canvas);
    color: var(--rs-primary);
    font-size: 13px;
    font-weight: 700;
}
.employee-cell > div {
    display: grid;
    gap: 4px;
    min-width: 0;
}
.employee-cell strong,
.organization-cell strong {
    color: var(--rs-ink);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.employee-cell small,
.organization-cell small,
.history-cell small,
.shift-editor > small,
.history-empty {
    color: var(--rs-muted);
    font-size: 11px;
    line-height: 1.6;
}
.employee-account {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--rs-text-soft);
    font-size: 10px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.employee-account i {
    width: 5px;
    height: 5px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #2b9b76;
}
.employee-account.pending i {
    background: #ca973b;
}
.organization-cell,
.history-cell {
    display: grid;
    gap: 4px;
    overflow-wrap: anywhere;
}
.history-cell > span {
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.6;
}
.shift-editor {
    display: grid;
    gap: 6px;
    min-width: 0;
}
.shift-editor > strong {
    font-size: 12px;
}
.today-schedule {
    display: grid;
    gap: 3px;
    padding: 9px 10px;
    margin-bottom: 4px;
    border: 1px solid var(--rs-border);
    border-radius: 7px;
    background: var(--rs-canvas);
    overflow-wrap: anywhere;
}
.today-schedule.scheduled {
    border-color: #c5e4d6;
    background: #f1f9f6;
}
.today-schedule > span,
.today-schedule > small,
.default-shift-label {
    color: var(--rs-text-soft);
    font-size: 10px;
    line-height: 1.5;
}
.today-schedule > strong {
    color: var(--rs-ink);
    font-size: 13px;
}
.today-schedule.scheduled > .schedule-source {
    color: var(--rs-primary);
    font-weight: 650;
}
.view-schedule {
    display: inline-flex;
    align-items: center;
    justify-self: start;
    gap: 6px;
    min-height: 32px;
    padding: 4px 0;
    border: 0;
    border-radius: 4px;
    color: var(--rs-primary);
    background: transparent;
    font-size: 11px;
    font-weight: 650;
}
.view-schedule:hover {
    text-decoration: underline;
}
.view-schedule svg {
    width: 14px;
    height: 14px;
}
.shift-editor > small {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
}
.shift-editor > small svg {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
}
.shift-editor > .unsaved-shift {
    color: var(--rs-primary);
}
.changed .shift-editor :deep(.searchable-select-trigger),
.changed .shift-editor :deep(.searchable-select-native) {
    border-color: var(--rs-primary);
    background: #f5fbf8;
}
.save-row {
    min-height: 36px;
    gap: 6px;
    padding: 7px 10px;
    font-size: 11px;
}
.save-row:disabled {
    border-color: var(--rs-border);
    background: var(--rs-canvas);
    color: var(--rs-muted);
    opacity: 1 !important;
}
.assign-mobile-list {
    display: none;
}
.assign-empty {
    display: grid;
    justify-items: center;
    gap: 12px;
    padding: 60px 24px;
    border-block: 1px solid var(--rs-border);
    text-align: center;
}
.assign-empty > span {
    display: grid;
    place-items: center;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    color: var(--rs-muted);
    background: var(--rs-canvas);
}
.assign-empty h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
}
.assign-empty p {
    max-width: 420px;
    margin: 0 0 6px;
    color: var(--rs-text-soft);
    font-size: 13px;
    line-height: 1.7;
}
.assign-footnote {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    gap: 8px;
    margin: 0;
    color: var(--rs-muted);
    font-size: 12px;
    line-height: 1.7;
}
.assign-footnote svg {
    width: 15px;
    height: 15px;
    margin-top: 3px;
    flex-shrink: 0;
}
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}
.spinner {
    animation: assign-spin 0.75s linear infinite;
}
.selection-enter-active,
.selection-leave-active {
    transition:
        opacity 160ms,
        transform 160ms;
}
.selection-enter-from,
.selection-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
@keyframes assign-spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 1200px) {
    .assign-overview article {
        padding: 16px;
        gap: 10px;
    }
    .assign-overview article > small {
        display: none;
    }
    .directory-hint {
        display: none;
    }
    .assign-filters {
        grid-template-columns: minmax(180px, 1fr) 170px 180px auto;
    }
    .employee-column {
        width: 28%;
    }
    .history-column {
        width: 17%;
    }
    .assign-table td,
    .assign-table th {
        padding-inline: 12px;
    }
}
@media (max-width: 1100px) {
    .assign-table-wrap {
        display: none;
    }
    .assign-mobile-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        padding: 0 20px 20px;
    }
    .assign-mobile-list article {
        min-width: 0;
        border: 1px solid var(--rs-border);
        border-radius: 11px;
        background: white;
    }
    .assign-mobile-list article.selected {
        border-color: var(--rs-primary);
        background: #f9fcfa;
        box-shadow: 0 0 0 1px var(--rs-primary);
    }
    .assign-mobile-list header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px;
    }
    .assign-mobile-list .employee-cell {
        flex: 1;
    }
    .mobile-account {
        padding: 4px 7px;
        border-radius: 5px;
        background: #eaf6f0;
        color: #14765b;
        font-size: 10px;
    }
    .mobile-account.pending {
        background: #fff6e5;
        color: #a67422;
    }
    .mobile-detail {
        display: grid;
        gap: 5px;
        padding: 0 16px 14px;
        color: var(--rs-text-soft);
        font-size: 11px;
        overflow-wrap: anywhere;
    }
    .mobile-detail > span {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        line-height: 1.6;
    }
    .mobile-detail svg {
        width: 13px;
        height: 13px;
        flex-shrink: 0;
        margin-top: 3px;
    }
    .mobile-detail small {
        font-size: 11px;
        color: var(--rs-muted);
    }
    .mobile-assignment {
        display: grid;
        gap: 7px;
        padding: 14px 16px;
        border-top: 1px solid var(--rs-border);
    }
    .field-label {
        color: var(--rs-text-soft);
        font-size: 11px;
        font-weight: 650;
    }
    .mobile-shift-controls {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .mobile-shift-controls :deep(.searchable-select) {
        min-width: 0;
    }
    .mobile-assignment > small {
        color: var(--rs-muted);
        font-size: 11px;
    }
    .assign-mobile-list footer {
        padding: 10px 16px;
        border-top: 1px solid var(--rs-border);
        color: var(--rs-muted);
        font-size: 10px;
        line-height: 1.6;
    }
    .assign-bulk-bar {
        grid-template-columns: 1fr auto 30px;
    }
    .assign-bulk-copy {
        grid-column: 1 / 3;
    }
    .clear-selection {
        grid-column: 3;
        grid-row: 1;
    }
}
@media (max-width: 700px) {
    .assign-page {
        gap: 16px;
    }
    .assign-directory-header {
        flex-wrap: wrap;
    }
    .assign-directory-actions,
    .assign-directory-actions > button {
        width: 100%;
    }
    .assign-overview {
        gap: 8px;
    }
    .assign-overview article {
        display: grid;
        gap: 10px;
        padding: 12px;
        align-content: start;
    }
    .stat-icon {
        width: 32px;
        height: 32px;
        border-radius: 9px;
    }
    .stat-icon svg {
        width: 17px;
        height: 17px;
    }
    .assign-overview p {
        font-size: 10px;
        line-height: 1.6;
        min-height: 32px;
    }
    .assign-overview strong {
        font-size: 23px;
    }
    .stat-rate {
        display: none;
    }
    .assign-directory-header {
        padding: 20px 16px 16px;
    }
    .assign-directory-header p {
        font-size: 12px;
    }
    .assign-filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        padding: 0 16px 16px;
        gap: 8px;
    }
    .assign-search {
        grid-column: 1 / -1;
    }
    .reset-filter {
        grid-column: 1 / -1;
        justify-self: end;
        min-height: 30px;
    }
    .assign-list-toolbar {
        padding: 12px 16px;
        flex-wrap: wrap;
        gap: 8px;
        font-size: 10px;
    }
    .assign-mobile-list {
        grid-template-columns: 1fr;
        padding: 0 12px 12px;
        gap: 12px;
    }
    .assign-bulk-bar {
        grid-template-columns: 1fr 30px;
        margin-inline: 12px;
        gap: 12px;
    }
    .assign-bulk-copy {
        grid-column: 1;
    }
    .clear-selection {
        grid-column: 2;
    }
    .bulk-shift-select,
    .assign-bulk-bar > .assign-primary-button {
        grid-column: 1 / -1;
    }
    .assign-footnote {
        justify-content: flex-start;
        font-size: 11px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .selection-enter-active,
    .selection-leave-active,
    .assign-primary-button,
    .save-row {
        transition: none;
    }
}
</style>
