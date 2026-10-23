<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    BriefcaseBusiness,
    CalendarCheck2,
    CalendarDays,
    CheckCircle2,
    Clock3,
    Coffee,
    LoaderCircle,
    LocateFixed,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    SlidersHorizontal,
    Sparkles,
    TimerReset,
    UserRound,
    UsersRound,
    X,
    Zap,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import CrudModal from '../setting/components/CrudModal.vue';
import FlashMessage from '../setting/components/FlashMessage.vue';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';
import ClockDetail from './components/ClockDetail.vue';
import SearchableSelect from './components/SearchableSelect.vue';
import TimePicker24 from './components/TimePicker24.vue';

interface LocationData {
    name: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    map_url: string | null;
}

interface ClockData {
    time: string | null;
    date_time: string | null;
    location: LocationData | null;
    photo_url: string | null;
}

interface PresensiRow {
    id: number | null;
    has_presensi: boolean;
    pegawai_id: number;
    pegawai: { nama: string; nik: string | null; unit: string | null; jabatan: string | null };
    tanggal: string;
    tanggal_label: string;
    shift_id: number | null;
    shift: { nama: string; jam_masuk: string; jam_keluar: string } | null;
    clock_in: ClockData;
    clock_out: ClockData;
    is_day_off: boolean;
    overtime_minutes: number;
    notes: string | null;
    late_minutes: number;
    lembur_late_arrival: { minutes: number; original_time: string; adjusted_time: string } | null;
    early_departure_minutes: number;
    status: 'day-off' | 'incomplete' | 'late' | 'on-time' | null;
    updated_by: string | null;
    updated_at: string | null;
}

interface PegawaiOption {
    id: number;
    nik: string | null;
    nama: string;
    unit: string | null;
    jabatan: string | null;
    shift_id: number | null;
}

interface ShiftOption {
    id: number;
    nama: string;
    jam_masuk: string;
    jam_keluar: string;
}

interface SearchableSelectOption {
    value: string | number;
    label: string;
    description?: string | null;
    searchText?: string | null;
}

type PresensiForm = {
    pegawai_id: number;
    tanggal_presensi: string;
    shift_id: number;
    clock_in_time: string;
    clock_out_time: string;
    is_day_off: boolean;
    overtime_minutes: number;
    notes: string;
};

const props = defineProps<{
    records: Paginated<PresensiRow>;
    overview: { total: number; present: number; late: number; overtime: number; day_off: number };
    filters: { search?: string | null; date_from: string; date_to: string; status?: string | null };
    manage: boolean;
    options: { pegawai: PegawaiOption[]; shifts: ShiftOption[] } | null;
}>();

const historyUrl = '/absensi/admin-web/presensi/pegawai';
const manageUrl = '/absensi/admin-web/presensi/set-pegawai';
const baseUrl = computed(() => (props.manage ? manageUrl : historyUrl));
const pageTitle = computed(() => (props.manage ? 'Set Presensi Pegawai' : 'Presensi Pegawai'));
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Presensi', href: historyUrl },
    { title: pageTitle.value, href: baseUrl.value },
]);

const search = ref(props.filters.search ?? '');
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const status = ref(props.filters.status ?? 'all');
const formOpen = ref(false);
const editing = ref<PresensiRow | null>(null);
const previewPhoto = ref<{ url: string; label: string } | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const emptyForm = (): PresensiForm => ({
    pegawai_id: 0,
    tanggal_presensi: props.filters.date_from,
    shift_id: 0,
    clock_in_time: '',
    clock_out_time: '',
    is_day_off: false,
    overtime_minutes: 0,
    notes: '',
});
const form = useForm<PresensiForm>(emptyForm());

const hasFilters = computed(
    () => Boolean(search.value.trim()) || status.value !== 'all' || dateFrom.value !== dateTo.value || dateFrom.value !== localToday(),
);
const resultLabel = computed(() => {
    if (!props.records.total) return 'Belum ada data';
    return `${props.records.from}-${props.records.to} dari ${props.records.total} data pegawai`;
});
const dateRangeLabel = computed(() =>
    dateFrom.value === dateTo.value ? formatDate(dateFrom.value) : `${formatDate(dateFrom.value)} – ${formatDate(dateTo.value)}`,
);
const selectedEmployee = computed(() => props.options?.pegawai.find((item) => item.id === Number(form.pegawai_id)) ?? null);
const selectedShift = computed(() => props.options?.shifts.find((item) => item.id === Number(form.shift_id)) ?? null);
const employeeOptions = computed<SearchableSelectOption[]>(() =>
    (props.options?.pegawai ?? []).map((pegawai) => ({
        value: pegawai.id,
        label: `${pegawai.nama}${pegawai.nik ? ` · ${pegawai.nik}` : ''}`,
        description: pegawai.unit || pegawai.jabatan || 'Unit belum diatur',
        searchText: [pegawai.nama, pegawai.nik, pegawai.unit, pegawai.jabatan].filter(Boolean).join(' '),
    })),
);
const shiftOptions = computed<SearchableSelectOption[]>(() =>
    (props.options?.shifts ?? []).map((shift) => ({
        value: shift.id,
        label: shift.nama,
        description: `${shift.jam_masuk}–${shift.jam_keluar}`,
        searchText: `${shift.nama} ${shift.jam_masuk} ${shift.jam_keluar}`,
    })),
);
const statusOptions: SearchableSelectOption[] = [
    { value: 'all', label: 'Semua status' },
    { value: 'present', label: 'Hadir' },
    { value: 'on-time', label: 'Tepat waktu' },
    { value: 'late', label: 'Terlambat' },
    { value: 'incomplete', label: 'Belum lengkap' },
    { value: 'overtime', label: 'Lembur' },
    { value: 'day-off', label: 'Libur' },
];

const summaryCards = computed(() => [
    { label: 'Catatan', value: props.overview.total, note: dateRangeLabel.value, icon: CalendarCheck2, tone: 'indigo' },
    { label: 'Hadir', value: props.overview.present, note: 'Sudah melakukan clock-in', icon: UsersRound, tone: 'teal' },
    { label: 'Terlambat', value: props.overview.late, note: 'Melewati jam shift', icon: AlarmClock, tone: 'coral' },
    { label: 'Lembur', value: props.overview.overtime, note: `${props.overview.day_off} pegawai libur`, icon: Zap, tone: 'violet' },
]);

function localToday() {
    const date = new Date();
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 10);
}

function formatDate(value: string) {
    if (!value) return '-';
    return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(`${value}T00:00:00`));
}

const initials = (name: string) =>
    name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();

const overtimeLabel = (minutes: number) => {
    if (!minutes) return 'Tidak ada';
    const hours = Math.floor(minutes / 60);
    const remaining = minutes % 60;
    return [hours ? `${hours} jam` : '', remaining ? `${remaining} menit` : ''].filter(Boolean).join(' ');
};

const statusMeta = (record: PresensiRow) => {
    if (record.status === 'day-off') return { label: 'Libur', tone: 'day-off', icon: Coffee };
    if (record.status === 'late') return { label: `Telat ${record.late_minutes} mnt`, tone: 'late', icon: AlarmClock };
    if (record.status === 'on-time') return { label: 'Tepat waktu', tone: 'on-time', icon: CheckCircle2 };
    return { label: 'Belum lengkap', tone: 'incomplete', icon: TimerReset };
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        baseUrl.value,
        {
            search: search.value.trim() || undefined,
            date_from: dateFrom.value,
            date_to: dateTo.value,
            status: status.value === 'all' ? undefined : status.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = () => {
    search.value = '';
    dateFrom.value = localToday();
    dateTo.value = localToday();
    status.value = 'all';
    applyFilters();
};

watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 450);
});
onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});

const openCreate = (pegawaiId = 0, date = props.filters.date_from) => {
    const assignedShiftId = props.options?.pegawai.find((pegawai) => pegawai.id === pegawaiId)?.shift_id ?? 0;
    editing.value = null;
    form.defaults({
        ...emptyForm(),
        pegawai_id: pegawaiId,
        tanggal_presensi: date,
        shift_id: assignedShiftId,
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
};

watch(
    () => form.pegawai_id,
    (pegawaiId, previousPegawaiId) => {
        if (editing.value || Number(pegawaiId) === Number(previousPegawaiId)) return;
        form.shift_id = props.options?.pegawai.find((pegawai) => pegawai.id === Number(pegawaiId))?.shift_id ?? 0;
    },
);

const openEdit = (record: PresensiRow) => {
    if (!record.id) return;
    editing.value = record;
    form.defaults({
        pegawai_id: record.pegawai_id,
        tanggal_presensi: record.tanggal,
        shift_id: record.shift_id ?? 0,
        clock_in_time: record.clock_in.time ?? '',
        clock_out_time: record.clock_out.time ?? '',
        is_day_off: record.is_day_off,
        overtime_minutes: record.overtime_minutes,
        notes: record.notes ?? '',
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
};

const setPresensi = (record: PresensiRow) => {
    if (record.has_presensi) openEdit(record);
    else openCreate(record.pegawai_id, record.tanggal);
};

const closeForm = () => {
    if (form.processing) return;
    formOpen.value = false;
    editing.value = null;
    form.clearErrors();
};

const submitForm = () => {
    const options = {
        preserveScroll: true,
        onSuccess: closeForm,
    };
    if (editing.value) form.put(`${manageUrl}/${editing.value.id}`, options);
    else form.post(manageUrl, options);
};

watch(
    () => form.is_day_off,
    (dayOff) => {
        if (!dayOff) return;
        form.clock_in_time = '';
        form.clock_out_time = '';
        form.overtime_minutes = 0;
    },
);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="attendance-page">
            <FlashMessage />

            <section class="attendance-hero">
                <div class="attendance-hero-copy">
                    <span class="attendance-eyebrow"><Sparkles /> RSARSY SMART PRESENCE</span>
                    <h1>{{ pageTitle }}</h1>
                    <p v-if="manage">Koreksi waktu presensi, tentukan durasi lembur, atau tandai hari libur pegawai dari satu panel.</p>
                    <p v-else>
                        Pantau aktivitas clock-in dan clock-out pegawai lengkap dengan shift, lokasi, foto wajah, dan status ketepatan waktu.
                    </p>
                    <div class="attendance-hero-points">
                        <span><LocateFixed /> Lokasi presensi</span>
                        <span><UserRound /> Verifikasi wajah</span>
                        <span><Clock3 /> Status real-time</span>
                    </div>
                </div>

                <div class="attendance-hero-actions">
                    <nav aria-label="Pilih fitur presensi">
                        <Link :href="historyUrl" :class="{ active: !manage }"><CalendarDays /> Riwayat Presensi</Link>
                        <Link :href="manageUrl" :class="{ active: manage }"><SlidersHorizontal /> Set Presensi</Link>
                    </nav>
                    <button v-if="manage" type="button" @click="openCreate()"><Plus /> Tambah Presensi</button>
                    <small>{{ dateRangeLabel }}</small>
                </div>
            </section>

            <section class="attendance-overview" aria-label="Ringkasan presensi">
                <article v-for="card in summaryCards" :key="card.label" :class="`tone-${card.tone}`">
                    <span><component :is="card.icon" /></span>
                    <div>
                        <small>{{ card.label }}</small
                        ><strong>{{ card.value.toLocaleString('id-ID') }}</strong>
                        <p>{{ card.note }}</p>
                    </div>
                </article>
            </section>

            <section class="attendance-directory">
                <header class="attendance-directory-head">
                    <div>
                        <small>AKTIVITAS PEGAWAI</small>
                        <h2>{{ manage ? 'Pengaturan data presensi' : 'Riwayat presensi harian' }}</h2>
                        <p>{{ resultLabel }}</p>
                    </div>
                    <strong><span></span> Data presensi tersinkron</strong>
                </header>

                <div class="attendance-filters">
                    <label class="search-field">
                        <Search />
                        <input v-model="search" type="search" placeholder="Cari nama, NIK, unit, atau jabatan..." />
                    </label>
                    <label class="date-field">
                        <small>Dari tanggal</small>
                        <input v-model="dateFrom" type="date" @change="applyFilters" />
                    </label>
                    <span class="date-arrow"><ArrowRight /></span>
                    <label class="date-field">
                        <small>Sampai tanggal</small>
                        <input v-model="dateTo" type="date" @change="applyFilters" />
                    </label>
                    <div class="status-field">
                        <small>Status</small>
                        <SearchableSelect
                            v-model="status"
                            :options="statusOptions"
                            placeholder="Semua status"
                            placeholder-value="all"
                            placeholder-disabled
                            search-placeholder="Cari status..."
                            @change="applyFilters"
                        />
                    </div>
                    <button v-if="hasFilters" type="button" class="clear-filter" title="Reset filter" @click="clearFilters"><RefreshCw /></button>
                </div>

                <div v-if="records.data.length" class="attendance-table-wrap">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Pegawai & tanggal</th>
                                <th>Shift</th>
                                <th>Clock-in</th>
                                <th>Clock-out</th>
                                <th>Status</th>
                                <th v-if="manage" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in records.data" :key="record.id ?? `pegawai-${record.pegawai_id}-${record.tanggal}`">
                                <td>
                                    <div class="employee-cell">
                                        <span :class="`avatar-tone-${(record.pegawai_id % 5) + 1}`">{{ initials(record.pegawai.nama) }}</span>
                                        <div>
                                            <strong>{{ record.pegawai.nama }}</strong>
                                            <small>{{ record.pegawai.nik || 'NIK belum diisi' }}</small>
                                            <p><CalendarDays /> {{ record.tanggal_label }}</p>
                                            <em>{{ record.pegawai.unit || record.pegawai.jabatan || 'Unit belum diatur' }}</em>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div v-if="record.shift" class="shift-cell">
                                        <strong>{{ record.shift.nama }}</strong>
                                        <span><Clock3 /> {{ record.shift.jam_masuk }} – {{ record.shift.jam_keluar }}</span>
                                    </div>
                                    <span v-else class="muted-value">-</span>
                                </td>
                                <td>
                                    <ClockDetail
                                        type="in"
                                        :clock="record.clock_in"
                                        :employee="record.pegawai.nama"
                                        :empty="!record.has_presensi"
                                        :late-minutes="record.late_minutes"
                                        :lembur-late-arrival="record.lembur_late_arrival"
                                        @preview="previewPhoto = $event"
                                    />
                                </td>
                                <td>
                                    <ClockDetail
                                        type="out"
                                        :clock="record.clock_out"
                                        :employee="record.pegawai.nama"
                                        :empty="!record.has_presensi"
                                        :early-departure-minutes="record.early_departure_minutes"
                                        @preview="previewPhoto = $event"
                                    />
                                </td>
                                <td>
                                    <div v-if="record.has_presensi" class="status-cell">
                                        <span class="status-pill" :class="statusMeta(record).tone"
                                            ><component :is="statusMeta(record).icon" />{{ statusMeta(record).label }}</span
                                        >
                                        <small v-if="record.overtime_minutes"><Zap /> Lembur {{ overtimeLabel(record.overtime_minutes) }}</small>
                                        <small v-else-if="record.notes" class="note-text" :title="record.notes">{{ record.notes }}</small>
                                    </div>
                                    <span v-else class="muted-value">-</span>
                                </td>
                                <td v-if="manage" class="action-cell">
                                    <button
                                        type="button"
                                        class="set-presensi-button"
                                        :title="record.has_presensi ? 'Atur presensi' : 'Set presensi'"
                                        @click="setPresensi(record)"
                                    >
                                        <Pencil v-if="record.has_presensi" />
                                        <Plus v-else />
                                        <span>{{ record.has_presensi ? 'Atur Presensi' : 'Set Presensi' }}</span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="records.data.length" class="attendance-mobile-list">
                    <article v-for="record in records.data" :key="record.id ?? `pegawai-${record.pegawai_id}-${record.tanggal}`">
                        <header>
                            <div class="employee-cell">
                                <span :class="`avatar-tone-${(record.pegawai_id % 5) + 1}`">{{ initials(record.pegawai.nama) }}</span>
                                <div>
                                    <strong>{{ record.pegawai.nama }}</strong
                                    ><small>{{ record.pegawai.nik || 'Tanpa NIK' }} · {{ record.tanggal_label }}</small>
                                </div>
                            </div>
                            <button
                                v-if="manage"
                                type="button"
                                class="mobile-set-presensi"
                                :title="record.has_presensi ? 'Atur presensi' : 'Set presensi'"
                                @click="setPresensi(record)"
                            >
                                <Pencil v-if="record.has_presensi" /><Plus v-else />
                                <span>{{ record.has_presensi ? 'Atur' : 'Set Presensi' }}</span>
                            </button>
                        </header>
                        <div class="mobile-shift">
                            <span><BriefcaseBusiness /> {{ record.shift?.nama || '-' }}</span>
                            <small v-if="record.shift">{{ record.shift.jam_masuk }} – {{ record.shift.jam_keluar }}</small>
                        </div>
                        <div class="mobile-clock-grid">
                            <ClockDetail
                                type="in"
                                :clock="record.clock_in"
                                :employee="record.pegawai.nama"
                                :empty="!record.has_presensi"
                                :late-minutes="record.late_minutes"
                                :lembur-late-arrival="record.lembur_late_arrival"
                                @preview="previewPhoto = $event"
                            />
                            <ClockDetail
                                type="out"
                                :clock="record.clock_out"
                                :employee="record.pegawai.nama"
                                :empty="!record.has_presensi"
                                :early-departure-minutes="record.early_departure_minutes"
                                @preview="previewPhoto = $event"
                            />
                        </div>
                        <footer>
                            <span v-if="record.has_presensi" class="status-pill" :class="statusMeta(record).tone"
                                ><component :is="statusMeta(record).icon" />{{ statusMeta(record).label }}</span
                            >
                            <span v-else class="muted-value">-</span>
                            <small v-if="record.overtime_minutes"><Zap /> {{ overtimeLabel(record.overtime_minutes) }}</small>
                        </footer>
                    </article>
                </div>

                <div v-else class="attendance-empty">
                    <span><CalendarCheck2 /></span>
                    <small>BELUM ADA AKTIVITAS</small>
                    <h3>Data presensi tidak ditemukan</h3>
                    <p>Ubah rentang tanggal atau filter pencarian.{{ manage ? ' Anda juga dapat menambahkan presensi secara manual.' : '' }}</p>
                    <button v-if="manage" type="button" @click="openCreate()"><Plus /> Tambah presensi</button>
                    <button v-else-if="hasFilters" type="button" @click="clearFilters"><RefreshCw /> Reset filter</button>
                </div>

                <Pagination :links="records.links" :from="records.from" :to="records.to" :total="records.total" />
            </section>
        </div>

        <CrudModal
            :open="formOpen"
            :title="editing ? 'Atur Presensi Pegawai' : 'Tambah Presensi Pegawai'"
            description="Waktu menggunakan format 24 jam. Shift malam otomatis mencatat clock-out di hari berikutnya."
            @close="closeForm"
        >
            <form @submit.prevent="submitForm">
                <div class="attendance-form-body">
                    <div class="form-summary">
                        <span><SlidersHorizontal /></span>
                        <div>
                            <small>{{ editing ? 'KOREKSI DATA PRESENSI' : 'PRESENSI MANUAL' }}</small>
                            <strong>{{ selectedEmployee?.nama || 'Pilih pegawai' }}</strong>
                            <p>
                                {{
                                    selectedShift
                                        ? `${selectedShift.nama} · ${selectedShift.jam_masuk}–${selectedShift.jam_keluar}`
                                        : 'Shift belum dipilih'
                                }}
                            </p>
                        </div>
                        <em v-if="form.is_day_off"><Coffee /> Libur</em>
                    </div>

                    <div class="form-grid">
                        <div class="form-field employee-field">
                            <span>Pegawai <b>*</b></span>
                            <SearchableSelect
                                v-model="form.pegawai_id"
                                :options="employeeOptions"
                                :disabled="Boolean(editing)"
                                :placeholder-value="0"
                                placeholder="Pilih pegawai"
                                placeholder-disabled
                                search-placeholder="Cari nama, NIK, unit, atau jabatan..."
                                :class="{ invalid: form.errors.pegawai_id }"
                            />
                            <small v-if="selectedEmployee">{{ selectedEmployee.unit || selectedEmployee.jabatan || 'Unit belum diatur' }}</small>
                            <p v-if="form.errors.pegawai_id">{{ form.errors.pegawai_id }}</p>
                        </div>

                        <label class="form-field">
                            <span>Tanggal <b>*</b></span>
                            <input
                                v-model="form.tanggal_presensi"
                                type="date"
                                :disabled="Boolean(editing)"
                                :class="{ invalid: form.errors.tanggal_presensi }"
                            />
                            <p v-if="form.errors.tanggal_presensi">{{ form.errors.tanggal_presensi }}</p>
                        </label>

                        <div class="form-field">
                            <span>Shift <b v-if="!form.is_day_off">*</b></span>
                            <SearchableSelect
                                v-model="form.shift_id"
                                :options="shiftOptions"
                                :placeholder-value="0"
                                :placeholder="form.is_day_off ? 'Tanpa shift' : 'Pilih shift'"
                                search-placeholder="Cari nama atau jam shift..."
                                :class="{ invalid: form.errors.shift_id }"
                            />
                            <p v-if="form.errors.shift_id">{{ form.errors.shift_id }}</p>
                        </div>

                        <label class="day-off-toggle">
                            <input v-model="form.is_day_off" type="checkbox" />
                            <span><Coffee /></span>
                            <div><strong>Liburkan pegawai</strong><small>Clock-in, clock-out, dan lembur akan dikosongkan</small></div>
                        </label>

                        <div class="form-field time-field">
                            <span>Clock-in</span>
                            <TimePicker24
                                v-model="form.clock_in_time"
                                :disabled="form.is_day_off"
                                :invalid="Boolean(form.errors.clock_in_time)"
                                aria-label="Pilih waktu clock-in dalam format 24 jam"
                            />
                            <p v-if="form.errors.clock_in_time">{{ form.errors.clock_in_time }}</p>
                        </div>

                        <div class="form-field time-field">
                            <span>Clock-out</span>
                            <TimePicker24
                                v-model="form.clock_out_time"
                                :disabled="form.is_day_off"
                                :invalid="Boolean(form.errors.clock_out_time)"
                                aria-label="Pilih waktu clock-out dalam format 24 jam"
                            />
                            <p v-if="form.errors.clock_out_time">{{ form.errors.clock_out_time }}</p>
                        </div>

                        <label class="form-field overtime-field">
                            <span>Durasi lembur <small>menit</small></span>
                            <div>
                                <Zap /><input
                                    v-model.number="form.overtime_minutes"
                                    type="number"
                                    min="0"
                                    max="1440"
                                    step="15"
                                    :disabled="form.is_day_off"
                                />
                            </div>
                            <div class="overtime-quick">
                                <button
                                    v-for="minutes in [60, 120, 180]"
                                    :key="minutes"
                                    type="button"
                                    :disabled="form.is_day_off"
                                    @click="form.overtime_minutes = minutes"
                                >
                                    +{{ minutes / 60 }} jam
                                </button>
                            </div>
                            <p v-if="form.errors.overtime_minutes">{{ form.errors.overtime_minutes }}</p>
                        </label>

                        <label class="form-field notes-field">
                            <span>Catatan <small>opsional</small></span>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                maxlength="1000"
                                placeholder="Contoh: koreksi berdasarkan konfirmasi kepala unit"
                            ></textarea>
                            <p v-if="form.errors.notes">{{ form.errors.notes }}</p>
                        </label>
                    </div>
                </div>
                <footer class="attendance-form-footer">
                    <span><Clock3 /> Perubahan akan tercatat atas nama admin</span>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="closeForm">Batal</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <LoaderCircle v-if="form.processing" class="spin" />
                            <CheckCircle2 v-else />
                            {{ form.processing ? 'Menyimpan...' : 'Simpan Presensi' }}
                        </button>
                    </div>
                </footer>
            </form>
        </CrudModal>

        <Teleport to="body">
            <div v-if="previewPhoto" class="photo-preview" @click.self="previewPhoto = null">
                <section>
                    <header>
                        <div>
                            <small>FOTO PRESENSI</small><strong>{{ previewPhoto.label }}</strong>
                        </div>
                        <button type="button" @click="previewPhoto = null"><X /></button>
                    </header>
                    <img :src="previewPhoto.url" :alt="previewPhoto.label" />
                </section>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.attendance-page {
    --ink: var(--rs-primary-deep);
    --muted: var(--rs-muted);
    --line: var(--rs-surface-muted);
    --primary: var(--rs-primary);
    display: grid;
    gap: 18px;
    padding: 4px 0 30px;
}
.attendance-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 370px;
    overflow: hidden;
    min-height: 252px;
    padding: 42px 48px;
    border-radius: 24px;
    color: white;
    background:
        radial-gradient(circle at 82% 5%, rgba(var(--rs-primary-pale-rgb), 0.32), transparent 26%),
        linear-gradient(125deg, var(--rs-primary-deep) 0%, var(--rs-primary-hover) 55%, var(--rs-primary) 100%);
    box-shadow: 0 18px 42px rgba(var(--rs-primary-deep-rgb), 0.18);
}
.attendance-hero::after {
    position: absolute;
    right: -70px;
    bottom: -135px;
    width: 330px;
    height: 330px;
    border: 45px solid rgba(255, 255, 255, 0.045);
    border-radius: 50%;
    content: '';
}
.attendance-hero-copy {
    position: relative;
    z-index: 1;
    max-width: 720px;
}
.attendance-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--rs-primary-border);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.16em;
}
.attendance-eyebrow svg {
    width: 14px;
}
.attendance-hero h1 {
    margin: 9px 0 8px;
    font-size: clamp(31px, 4vw, 49px);
    font-weight: 800;
    letter-spacing: -0.035em;
}
.attendance-hero-copy > p {
    max-width: 660px;
    margin: 0;
    color: var(--rs-primary-border);
    font-size: 12px;
    line-height: 1.75;
}
.attendance-hero-points {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin-top: 25px;
}
.attendance-hero-points span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    border: 1px solid rgba(255, 255, 255, 0.11);
    border-radius: 9px;
    color: var(--rs-primary-border);
    background: rgba(255, 255, 255, 0.06);
    font-size: 9px;
    font-weight: 650;
}
.attendance-hero-points svg {
    width: 13px;
}
.attendance-hero-actions {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: stretch;
    justify-content: center;
    flex-direction: column;
    gap: 11px;
    padding-left: 40px;
}
.attendance-hero-actions nav {
    display: grid;
    gap: 7px;
    padding: 7px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 15px;
    background: rgba(var(--rs-primary-deep-rgb), 0.18);
    backdrop-filter: blur(8px);
}
.attendance-hero-actions nav a {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 12px;
    border-radius: 10px;
    color: var(--rs-primary-border);
    font-size: 10px;
    font-weight: 700;
    text-decoration: none;
    transition: 0.15s;
}
.attendance-hero-actions nav a.active {
    color: var(--rs-primary-hover);
    background: white;
    box-shadow: 0 8px 20px rgba(var(--rs-primary-deep-rgb), 0.18);
}
.attendance-hero-actions svg {
    width: 16px;
}
.attendance-hero-actions > button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 11px 13px;
    border: 0;
    border-radius: 11px;
    color: white;
    background: #f19a5b;
    font-size: 10px;
    font-weight: 800;
    box-shadow: 0 8px 20px rgba(231, 132, 65, 0.25);
}
.attendance-hero-actions > small {
    color: var(--rs-primary-pale);
    font-size: 9px;
    text-align: center;
}
.attendance-overview {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 13px;
}
.attendance-overview article {
    display: flex;
    align-items: center;
    gap: 13px;
    min-width: 0;
    padding: 17px;
    border: 1px solid var(--line);
    border-radius: 16px;
    background: white;
    box-shadow: 0 8px 24px rgba(var(--rs-primary-deep-rgb), 0.045);
}
.attendance-overview article > span {
    display: grid;
    width: 43px;
    height: 43px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 13px;
}
.attendance-overview article svg {
    width: 19px;
}
.attendance-overview article div {
    display: grid;
    min-width: 0;
}
.attendance-overview small {
    color: var(--rs-muted);
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.attendance-overview strong {
    color: var(--ink);
    font-size: 20px;
    line-height: 1.35;
}
.attendance-overview p {
    overflow: hidden;
    margin: 0;
    color: var(--rs-muted);
    font-size: 8px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tone-indigo > span {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.tone-teal > span {
    color: #159980;
    background: #e9faf6;
}
.tone-coral > span {
    color: #e06666;
    background: #fff0ef;
}
.tone-violet > span {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.attendance-directory {
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 19px;
    background: white;
    box-shadow: 0 10px 30px rgba(var(--rs-primary-deep-rgb), 0.05);
}
.attendance-directory-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 23px 25px 17px;
}
.attendance-directory-head small {
    color: var(--primary);
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.13em;
}
.attendance-directory-head h2 {
    margin: 3px 0 1px;
    color: var(--ink);
    font-size: 17px;
}
.attendance-directory-head p {
    margin: 0;
    color: var(--muted);
    font-size: 9px;
}
.attendance-directory-head > strong {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 9px;
    border-radius: 8px;
    color: #328975;
    background: #edf9f6;
    font-size: 8px;
}
.attendance-directory-head > strong span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #38b994;
    box-shadow: 0 0 0 4px rgba(56, 185, 148, 0.13);
}
.attendance-filters {
    display: grid;
    grid-template-columns: minmax(190px, 1fr) 145px 20px 145px 145px auto;
    align-items: end;
    gap: 8px;
    padding: 0 25px 20px;
}
.search-field {
    position: relative;
    display: flex;
    align-items: center;
}
.search-field svg {
    position: absolute;
    left: 12px;
    width: 15px;
    color: var(--rs-muted);
}
.search-field input,
.date-field input,
.status-field > .searchable-select {
    width: 100%;
}
.search-field input,
.date-field input {
    height: 40px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    outline: 0;
    color: var(--ink);
    background: var(--rs-canvas);
    font-size: 10px;
}
.search-field input {
    padding: 0 12px 0 36px;
}
.date-field,
.status-field {
    display: grid;
    gap: 4px;
}
.date-field small,
.status-field small {
    color: var(--rs-muted);
    font-size: 8px;
    font-weight: 750;
}
.date-field input {
    padding: 0 9px;
}
.search-field input:focus,
.date-field input:focus {
    border-color: var(--rs-primary-pale);
    box-shadow: 0 0 0 3px rgba(var(--rs-primary-rgb), 0.09);
    background: white;
}
.status-field :deep(.searchable-select-native),
.status-field :deep(.searchable-select-trigger) {
    height: 40px;
    border-color: var(--rs-border);
    border-radius: 10px;
    background: var(--rs-canvas);
}
.date-arrow {
    display: grid;
    height: 40px;
    place-items: center;
    color: var(--rs-muted);
}
.date-arrow svg {
    width: 14px;
}
.clear-filter {
    display: grid;
    width: 40px;
    height: 40px;
    place-items: center;
    border: 1px solid #ffd8dc;
    border-radius: 10px;
    color: #d95968;
    background: #fff5f6;
}
.clear-filter svg {
    width: 15px;
}
.attendance-table-wrap {
    overflow-x: auto;
    border-top: 1px solid var(--line);
}
.attendance-table {
    width: 100%;
    min-width: 1140px;
    border-collapse: collapse;
}
.attendance-table th {
    padding: 12px 14px;
    color: var(--rs-muted);
    background: var(--rs-canvas);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-align: left;
    text-transform: uppercase;
}
.attendance-table th:first-child,
.attendance-table td:first-child {
    padding-left: 25px;
}
.attendance-table th:last-child,
.attendance-table td:last-child {
    padding-right: 25px;
}
.attendance-table td {
    padding: 15px 14px;
    border-top: 1px solid var(--rs-surface-muted);
    color: var(--rs-text-soft);
    font-size: 13px;
    vertical-align: middle;
}
.attendance-table tbody tr:hover {
    background: var(--rs-canvas);
}
.employee-cell {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    min-width: 195px;
}
.employee-cell > span {
    display: grid;
    width: 39px;
    height: 39px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 850;
}
.avatar-tone-1 {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.avatar-tone-2 {
    color: #168b79;
    background: #e8f9f5;
}
.avatar-tone-3 {
    color: #d05c6e;
    background: #ffedef;
}
.avatar-tone-4 {
    color: #9a6a25;
    background: #fff5e5;
}
.avatar-tone-5 {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.employee-cell > div {
    display: grid;
    min-width: 0;
}
.employee-cell strong {
    max-width: 175px;
    overflow: hidden;
    color: var(--ink);
    font-size: 14px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.employee-cell small {
    color: var(--rs-muted);
    font-size: 11px;
}
.employee-cell p {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 3px 0 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    font-weight: 700;
}
.employee-cell p svg {
    width: 10px;
}
.employee-cell em {
    max-width: 175px;
    overflow: hidden;
    margin-top: 2px;
    color: var(--rs-muted);
    font-size: 11px;
    font-style: normal;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.shift-cell {
    display: grid;
    min-width: 112px;
}
.shift-cell strong {
    color: var(--ink);
    font-size: 13px;
}
.shift-cell span {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
    color: var(--rs-muted);
    font-size: 11px;
}
.shift-cell svg {
    width: 11px;
}
.muted-value {
    color: var(--rs-muted);
    font-size: 11px;
}
:deep(.empty-presensi) {
    color: var(--rs-muted);
    font-size: 12px;
}
:deep(.clock-detail) {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 180px;
}
:deep(.clock-detail > div) {
    display: grid;
    min-width: 0;
}
:deep(.clock-detail strong) {
    color: var(--ink);
    font-size: 15px;
    letter-spacing: 0.025em;
}
:deep(.clock-detail a),
:deep(.clock-detail span) {
    display: flex;
    align-items: center;
    gap: 3px;
    max-width: 125px;
    overflow: hidden;
    margin-top: 2px;
    color: var(--rs-primary);
    font-size: 11px;
    text-decoration: none;
    text-overflow: ellipsis;
    white-space: nowrap;
}
:deep(.clock-detail small) {
    color: var(--rs-muted);
    font-size: 11px;
}
:deep(.clock-detail .late-indicator) {
    display: flex;
    align-items: center;
    gap: 3px;
    margin-top: 2px;
    color: #d75662;
    font-weight: 800;
    white-space: nowrap;
}
:deep(.clock-detail .late-indicator svg) {
    width: 10px;
    height: 10px;
    flex: 0 0 auto;
}
:deep(.clock-detail .lembur-indicator) {
    display: flex;
    align-items: flex-start;
    gap: 4px;
    max-width: 200px;
    margin-top: 5px;
    padding: 5px 7px;
    border-radius: 6px;
    color: #168674;
    background: #e8f8f4;
    font-weight: 800;
    line-height: 1.5;
}
:deep(.clock-detail .lembur-indicator svg) {
    width: 12px;
    height: 12px;
    flex: 0 0 auto;
    margin-top: 2px;
}
:deep(.clock-detail .lembur-schedule) {
    margin-top: 3px;
    line-height: 1.5;
}
:deep(.clock-detail a > svg),
:deep(.clock-detail span > svg) {
    width: 10px;
    flex: 0 0 auto;
}
:deep(.clock-detail .external-icon) {
    width: 8px;
}
:deep(.face-photo) {
    position: relative;
    display: grid;
    overflow: hidden;
    width: 39px;
    height: 39px;
    flex: 0 0 auto;
    padding: 0;
    place-items: center;
    border: 1px solid var(--rs-border);
    border-radius: 11px;
    color: var(--rs-muted);
    background: var(--rs-canvas);
}
:deep(.face-photo img) {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
:deep(.face-photo > svg) {
    width: 16px;
}
:deep(.face-photo:not(.empty)::after) {
    position: absolute;
    inset: 0;
    background: rgba(var(--rs-primary-deep-rgb), 0.45);
    opacity: 0;
    content: '';
    transition: 0.15s;
}
:deep(.face-photo:not(.empty) > svg) {
    position: absolute;
    z-index: 1;
    width: 14px;
    color: white;
    opacity: 0;
    transition: 0.15s;
}
:deep(.face-photo:not(.empty):hover::after),
:deep(.face-photo:not(.empty):hover > svg) {
    opacity: 1;
}
.status-cell {
    display: grid;
    justify-items: start;
    gap: 5px;
    min-width: 110px;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 7px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}
.status-pill svg {
    width: 11px;
}
.status-pill.on-time {
    color: #168674;
    background: #e8f8f4;
}
.status-pill.late {
    color: #d75662;
    background: #ffedef;
}
.status-pill.incomplete {
    color: #a16918;
    background: #fff6e5;
}
.status-pill.day-off {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.status-cell > small {
    display: flex;
    align-items: center;
    gap: 4px;
    max-width: 125px;
    color: var(--rs-muted);
    font-size: 11px;
}
.status-cell > small svg {
    width: 10px;
}
.status-cell .note-text {
    overflow: hidden;
    color: var(--rs-muted);
    text-overflow: ellipsis;
    white-space: nowrap;
}
.action-cell {
    text-align: right;
}
.action-cell button,
.attendance-mobile-list header > button {
    display: inline-grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    color: var(--rs-primary);
    background: white;
}
.action-cell button:hover {
    border-color: var(--rs-primary-border);
    background: var(--rs-primary-soft);
}
.action-cell .set-presensi-button {
    display: inline-flex;
    width: auto;
    padding: 0 10px;
    gap: 5px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}
.action-cell svg,
.attendance-mobile-list header > button svg {
    width: 14px;
}
.attendance-mobile-list header > .mobile-set-presensi {
    display: inline-flex;
    width: auto;
    padding: 0 9px;
    gap: 5px;
    font-size: 8px;
    font-weight: 800;
    white-space: nowrap;
}
.attendance-mobile-list {
    display: none;
}
.attendance-empty {
    display: grid;
    justify-items: center;
    padding: 65px 20px;
    border-top: 1px solid var(--line);
    text-align: center;
}
.attendance-empty > span {
    display: grid;
    width: 60px;
    height: 60px;
    margin-bottom: 14px;
    place-items: center;
    border-radius: 19px;
    color: var(--primary);
    background: var(--rs-primary-soft);
}
.attendance-empty > span svg {
    width: 27px;
}
.attendance-empty small {
    color: var(--primary);
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.12em;
}
.attendance-empty h3 {
    margin: 5px 0;
    color: var(--ink);
    font-size: 17px;
}
.attendance-empty p {
    margin: 0 0 15px;
    color: var(--muted);
    font-size: 10px;
}
.attendance-empty button {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 9px 12px;
    border: 0;
    border-radius: 9px;
    color: white;
    background: var(--primary);
    font-size: 9px;
    font-weight: 750;
}
.attendance-empty button svg {
    width: 14px;
}
.attendance-form-body {
    display: grid;
    flex: 1 1 auto;
    gap: 17px;
    min-height: 0;
    overflow-y: auto;
    padding: 20px;
    background: var(--rs-canvas);
}
:deep(.admin-modal-header h5) {
    font-size: 20px;
}
:deep(.admin-modal-header p) {
    font-size: 13px;
    line-height: 1.55;
}
.form-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    border-radius: 14px;
    color: white;
    background: linear-gradient(120deg, var(--rs-primary-deep), var(--rs-primary));
}
.form-summary > span {
    display: grid;
    width: 40px;
    height: 40px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 11px;
    color: var(--rs-primary-border);
    background: rgba(255, 255, 255, 0.1);
}
.form-summary svg {
    width: 18px;
}
.form-summary > div {
    display: grid;
    flex: 1;
}
.form-summary small {
    color: var(--rs-primary-border);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.09em;
}
.form-summary strong {
    font-size: 16px;
}
.form-summary p {
    margin: 1px 0 0;
    color: var(--rs-primary-border);
    font-size: 12px;
}
.form-summary em {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 8px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.1);
    font-size: 11px;
    font-style: normal;
}
.form-summary em svg {
    width: 13px;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 13px;
}
.form-field {
    display: grid;
    align-content: start;
    gap: 5px;
    min-width: 0;
}
.form-field > span {
    color: var(--rs-text-soft);
    font-size: 12px;
    font-weight: 750;
}
.form-field > span b {
    color: #db4f61;
}
.form-field > span small {
    float: right;
    color: var(--rs-muted);
    font-size: 10px;
    font-weight: 650;
}
.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    outline: none;
    color: var(--ink);
    background: white;
    font-size: 13px;
}
.form-field input,
.form-field select {
    height: 44px;
    padding: 0 12px;
}
.form-field textarea {
    padding: 10px;
    resize: vertical;
}
.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    border-color: var(--rs-primary-pale);
    box-shadow: 0 0 0 3px rgba(var(--rs-primary-rgb), 0.09);
}
.form-field input:disabled,
.form-field select:disabled {
    color: var(--rs-muted);
    background: var(--rs-surface-muted);
}
.form-field .invalid {
    border-color: #dc5364;
}
.form-field > p {
    margin: 0;
    color: #d5485a;
    font-size: 11px;
}
.form-field > small {
    color: var(--rs-muted);
    font-size: 11px;
}
.employee-field,
.day-off-toggle,
.notes-field {
    grid-column: 1 / -1;
}
.day-off-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 11px;
    background: var(--rs-canvas);
    cursor: pointer;
}
.day-off-toggle > input {
    width: 15px;
    height: 15px;
    accent-color: var(--primary);
}
.day-off-toggle > span {
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    border-radius: 9px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.day-off-toggle svg {
    width: 15px;
}
.day-off-toggle > div {
    display: grid;
}
.day-off-toggle strong {
    color: var(--rs-ink);
    font-size: 13px;
}
.day-off-toggle small {
    color: var(--rs-muted);
    font-size: 11px;
}
.overtime-field > div:first-of-type {
    position: relative;
    display: flex;
    align-items: center;
}
.overtime-field > div:first-of-type > svg {
    position: absolute;
    left: 10px;
    width: 14px;
    color: var(--rs-muted);
    pointer-events: none;
}
.overtime-field input {
    padding-left: 32px;
}
.overtime-quick {
    display: flex;
    gap: 5px;
}
.overtime-quick button {
    padding: 4px 7px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 6px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-size: 10px;
    font-weight: 750;
}
.overtime-quick button:disabled {
    opacity: 0.45;
}
.attendance-form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 20px;
    border-top: 1px solid var(--rs-border);
    background: white;
}
.attendance-form-footer > span {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--rs-muted);
    font-size: 11px;
}
.attendance-form-footer > span svg {
    width: 12px;
}
.attendance-form-footer > div {
    display: flex;
    gap: 7px;
}
.attendance-form-footer .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
}
.attendance-form-footer .btn svg {
    width: 14px;
}
.spin {
    animation: spin 0.8s linear infinite;
}
.photo-preview {
    position: fixed;
    z-index: 1100;
    inset: 0;
    display: grid;
    padding: 25px;
    place-items: center;
    background: rgba(var(--rs-primary-deep-rgb), 0.78);
    backdrop-filter: blur(5px);
}
.photo-preview section {
    overflow: hidden;
    width: min(480px, 100%);
    border-radius: 18px;
    background: white;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.3);
}
.photo-preview header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
}
.photo-preview header div {
    display: grid;
}
.photo-preview header small {
    color: var(--primary);
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.12em;
}
.photo-preview header strong {
    color: var(--ink);
    font-size: 11px;
}
.photo-preview header button {
    display: grid;
    width: 31px;
    height: 31px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: var(--rs-surface-muted);
}
.photo-preview header svg {
    width: 15px;
}
.photo-preview img {
    display: block;
    width: 100%;
    max-height: 68vh;
    object-fit: contain;
    background: var(--rs-primary-deep);
}
@media (max-width: 1100px) {
    .attendance-hero {
        grid-template-columns: 1fr 310px;
        padding: 36px;
    }
    .attendance-overview {
        grid-template-columns: repeat(2, 1fr);
    }
    .attendance-filters {
        grid-template-columns: 1fr 145px 20px 145px auto;
    }
    .status-field {
        grid-column: 1 / 5;
    }
    .clear-filter {
        grid-column: 5;
        grid-row: 2;
    }
}
@media (max-width: 760px) {
    .attendance-page {
        gap: 13px;
    }
    .attendance-hero {
        grid-template-columns: 1fr;
        gap: 25px;
        min-height: 0;
        padding: 28px 23px;
        border-radius: 18px;
    }
    .attendance-hero-actions {
        padding-left: 0;
    }
    .attendance-overview {
        gap: 9px;
    }
    .attendance-overview article {
        padding: 13px;
    }
    .attendance-directory-head {
        align-items: flex-start;
        padding: 20px;
    }
    .attendance-directory-head > strong {
        display: none;
    }
    .attendance-filters {
        grid-template-columns: 1fr 1fr auto;
        padding: 0 20px 18px;
    }
    .search-field {
        grid-column: 1 / -1;
    }
    .date-arrow {
        display: none;
    }
    .status-field {
        grid-column: 1 / 3;
    }
    .clear-filter {
        grid-column: 3;
        grid-row: 3;
    }
    .attendance-table-wrap {
        display: none;
    }
    .attendance-mobile-list {
        display: grid;
        gap: 10px;
        padding: 13px;
        border-top: 1px solid var(--line);
        background: var(--rs-canvas);
    }
    .attendance-mobile-list > article {
        padding: 13px;
        border: 1px solid var(--rs-border);
        border-radius: 14px;
        background: white;
    }
    .attendance-mobile-list header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .mobile-shift {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        margin: 11px 0 8px;
        padding: 8px 10px;
        border-radius: 8px;
        color: var(--rs-text-soft);
        background: var(--rs-canvas);
        font-size: 8px;
    }
    .mobile-shift span {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .mobile-shift svg {
        width: 11px;
    }
    .mobile-shift small {
        color: var(--rs-muted);
    }
    .mobile-clock-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    .mobile-clock-grid :deep(.clock-detail) {
        padding: 9px;
        border: 1px solid var(--rs-border);
        border-radius: 10px;
        min-width: 0;
    }
    .mobile-clock-grid :deep(.clock-detail a),
    .mobile-clock-grid :deep(.clock-detail span) {
        max-width: 80px;
    }
    .attendance-mobile-list footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 10px;
    }
    .attendance-mobile-list footer > small {
        display: flex;
        align-items: center;
        gap: 4px;
        color: var(--rs-text-soft);
        font-size: 8px;
    }
    .attendance-mobile-list footer svg {
        width: 10px;
    }
    .attendance-form-footer {
        align-items: stretch;
        flex-direction: column;
    }
    .attendance-form-footer > div {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }
    .attendance-form-footer .btn {
        justify-content: center;
    }
}
@media (max-width: 480px) {
    .attendance-overview {
        grid-template-columns: 1fr;
    }
    .attendance-hero-points {
        display: grid;
    }
    .attendance-filters {
        grid-template-columns: 1fr auto;
    }
    .date-field {
        grid-column: 1 / -1;
    }
    .status-field {
        grid-column: 1;
    }
    .clear-filter {
        grid-column: 2;
        grid-row: 4;
    }
    .mobile-clock-grid {
        grid-template-columns: 1fr;
    }
    .form-grid {
        grid-template-columns: 1fr;
    }
    .employee-field,
    .day-off-toggle,
    .notes-field {
        grid-column: auto;
    }
    .form-summary em {
        display: none;
    }
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
