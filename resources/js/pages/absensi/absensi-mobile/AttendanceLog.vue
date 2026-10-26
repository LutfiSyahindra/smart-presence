<script setup lang="ts">
import { usePermission } from '@/composables/usePermission';
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowLeft,
    ArrowUpRight,
    CalendarDays,
    CalendarRange,
    CheckCheck,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Coffee,
    History,
    LogIn,
    LogOut,
    MapPin,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import CutiSheet from './components/CutiSheet.vue';
import MobileBackLink from './components/MobileBackLink.vue';

type MetricKey = 'late' | 'early_departure' | 'absent' | 'no_clock_in' | 'no_clock_out' | 'day_off' | 'on_time' | 'leave' | 'invalid' | 'upcoming';
type LogDay = {
    date: string;
    date_label: string;
    full_date_label: string;
    day_number: string;
    day_name: string;
    shift_name: string | null;
    schedule: string | null;
    status: string;
    status_label: string;
    tone: string;
    day_off_type: 'weekend' | 'libur_nasional' | 'cuti_bersama' | 'day_off' | null;
    day_off_reason: string | null;
    clock_in: string | null;
    clock_out: string | null;
    clock_in_date_label: string | null;
    clock_out_date_label: string | null;
    clock_in_location: string | null;
    clock_out_location: string | null;
    clock_in_address: string | null;
    clock_out_address: string | null;
    late_minutes: number;
    early_departure_minutes: number;
    lembur_late_allowance: number;
    lembur_early_allowance: number;
    duration: string | null;
    notes: string | null;
    flags: Record<MetricKey, boolean>;
};
const props = defineProps<{
    month: string;
    monthLabel: string;
    periodLabel: string;
    today: string;
    summary: Record<MetricKey, number>;
    days: LogDay[];
    unavailableReason: string | null;
}>();
const expanded = ref(true);
const loading = ref(false);
const error = ref('');
const monthOpen = ref(false);
const pickerYear = ref(Number(props.month.slice(0, 4)));
const selectedDay = ref<LogDay | null>(null);
watch(
    () => props.days,
    (days) => {
        if (selectedDay.value) selectedDay.value = days.find((day) => day.date === selectedDay.value?.date) ?? null;
    },
);
const content = ref<HTMLElement | null>(null);
const activeFilter = ref('all');
let removeBackHandler: (() => void) | undefined;

const primaryMetrics = [
    { key: 'on_time', label: 'Tepat waktu', tone: 'green', icon: CheckCheck },
    { key: 'late', label: 'Terlambat masuk', tone: 'amber', icon: Clock3 },
    { key: 'absent', label: 'Absen', tone: 'rose', icon: CalendarDays },
    { key: 'day_off', label: 'Hari libur', tone: 'violet', icon: Coffee },
] as const;
const extraMetrics = [
    { key: 'early_departure', label: 'Pulang lebih awal' },
    { key: 'no_clock_in', label: 'Tidak clock in' },
    { key: 'no_clock_out', label: 'Tidak clock out' },
    { key: 'leave', label: 'Cuti' },
    { key: 'invalid', label: 'Tidak valid' },
    { key: 'upcoming', label: 'Hari kerja berikutnya' },
] as const;
const filters = [
    { key: 'all', label: 'Semua' },
    { key: 'present', label: 'Hadir' },
    { key: 'attention', label: 'Perlu perhatian' },
    { key: 'rest', label: 'Libur & cuti' },
];
const months = Array.from({ length: 12 }, (_, index) => ({
    value: index + 1,
    label: new Intl.DateTimeFormat('id-ID', { month: 'short' }).format(new Date(2026, index, 1)),
}));
const visibleDays = computed(() =>
    props.days.filter((day) => {
        if (activeFilter.value === 'present') return Boolean(day.clock_in || day.clock_out);
        if (activeFilter.value === 'attention') return ['warning', 'danger'].includes(day.tone);
        if (activeFilter.value === 'rest') return day.flags.day_off || day.flags.leave;
        return true;
    }),
);
const recordedDays = computed(() => props.days.filter((day) => day.clock_in || day.clock_out).length);
const isCurrentMonth = computed(() => props.month === props.today.slice(0, 7));

function changeMonth(month: string) {
    monthOpen.value = false;
    if (loading.value || month === props.month) return;
    loading.value = true;
    error.value = '';
    router.get(
        route('absensi.mobile.attendance.log'),
        { month },
        {
            replace: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                activeFilter.value = 'all';
                content.value?.scrollTo({ top: 0 });
            },
            onError: () => {
                error.value = 'Log bulan ini belum dapat dimuat. Silakan pilih bulan kembali.';
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function moveMonth(offset: number) {
    const [year, month] = props.month.split('-').map(Number);
    const date = new Date(year, month - 1 + offset, 1);
    changeMonth(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`);
}
function openMonthPicker() {
    pickerYear.value = Number(props.month.slice(0, 4));
    monthOpen.value = true;
}
onMounted(() => {
    removeBackHandler = registerMobileBackHandler(() => {
        if (monthOpen.value) {
            monthOpen.value = false;
            return true;
        }
        if (selectedDay.value) {
            selectedDay.value = null;
            return true;
        }
        return false;
    });
});
onUnmounted(() => removeBackHandler?.());
const { can } = usePermission();
</script>

<template>
    <Head title="Log Daftar Absensi" />
    <main class="log-workspace">
        <div class="log-shell">
            <header class="log-header">
                <MobileBackLink
                    :href="route(can('mobile.attendance') ? 'absensi.mobile.attendance' : 'absensi.mobile.home')"
                    class="icon-button"
                    aria-label="Kembali ke layanan presensi"
                >
                    <ArrowLeft />
                </MobileBackLink>
                <div class="header-copy">
                    <p>RSARSY PRESENCE</p>
                    <h1>Log Daftar Absensi</h1>
                </div>
                <span class="header-seal" aria-hidden="true"><History /></span>
            </header>

            <div ref="content" class="log-content" :aria-busy="loading">
                <section class="month-card" aria-label="Periode log absensi">
                    <div class="month-eyebrow">
                        <span><CalendarRange /> CATATAN KEHADIRAN</span><i></i>
                    </div>
                    <div class="month-navigation">
                        <button
                            class="month-arrow"
                            type="button"
                            aria-label="Bulan sebelumnya"
                            :disabled="loading || month === '1900-01'"
                            @click="moveMonth(-1)"
                        >
                            <ChevronLeft />
                        </button>
                        <button class="month-picker" type="button" :disabled="loading" aria-haspopup="dialog" @click="openMonthPicker">
                            <strong>{{ monthLabel }}</strong
                            ><span>Pilih periode <ChevronDown /></span>
                        </button>
                        <button
                            class="month-arrow"
                            type="button"
                            aria-label="Bulan berikutnya"
                            :disabled="loading || month === '2100-12'"
                            @click="moveMonth(1)"
                        >
                            <ChevronRight />
                        </button>
                    </div>
                    <div class="month-footer">
                        <span>{{ periodLabel }}</span
                        ><span class="period-badge">{{ isCurrentMonth ? 'Bulan ini' : 'Arsip bulanan' }}</span>
                    </div>
                    <div v-if="loading" class="loading-line" role="status" aria-label="Memuat log absensi"></div>
                </section>
                <p v-if="error" class="error-note" role="alert">{{ error }}</p>
                <button v-if="!isCurrentMonth" type="button" class="current-month" :disabled="loading" @click="changeMonth(today.slice(0, 7))">
                    <CalendarDays /> Kembali ke bulan ini
                </button>

                <template v-if="!unavailableReason">
                    <section class="summary-card" aria-labelledby="summary-title">
                        <div class="section-title">
                            <div>
                                <span class="eyebrow">REKAP KEHADIRAN</span>
                                <h2 id="summary-title">Ringkasan presensi</h2>
                            </div>
                            <span class="summary-icon"><CalendarDays /></span>
                        </div>
                        <dl class="primary-metrics">
                            <div v-for="metric in primaryMetrics" :key="metric.key" :class="`metric-${metric.tone}`">
                                <dt>
                                    <component :is="metric.icon" /><span>{{ metric.label }}</span>
                                </dt>
                                <dd>{{ summary[metric.key] }}<small>hari</small></dd>
                            </div>
                        </dl>
                        <dl v-if="expanded" id="extra-summary" class="extra-metrics">
                            <div v-for="metric in extraMetrics" :key="metric.key">
                                <dt>{{ metric.label }}</dt>
                                <dd :class="{ 'nonzero-count': summary[metric.key] > 0 }">{{ summary[metric.key] }}<small>hari</small></dd>
                            </div>
                        </dl>
                        <button
                            class="summary-toggle"
                            type="button"
                            :aria-expanded="expanded"
                            aria-controls="extra-summary"
                            @click="expanded = !expanded"
                        >
                            {{ expanded ? 'Tampilkan lebih sedikit' : 'Lihat semua ringkasan' }}<ChevronDown :class="{ rotated: expanded }" />
                        </button>
                    </section>

                    <section class="daily-section" aria-labelledby="daily-title">
                        <div class="section-title daily-title">
                            <div>
                                <span class="eyebrow">JEJAK AKTIVITAS ANDA</span>
                                <h2 id="daily-title">Daftar harian</h2>
                            </div>
                            <span class="day-count">{{ recordedDays }} hari tercatat</span>
                        </div>
                        <div class="filter-strip" aria-label="Filter daftar absensi">
                            <button
                                v-for="filter in filters"
                                :key="filter.key"
                                type="button"
                                :aria-pressed="activeFilter === filter.key"
                                :class="{ active: activeFilter === filter.key }"
                                @click="activeFilter = filter.key"
                            >
                                {{ filter.label }}
                            </button>
                        </div>
                        <p class="list-caption" aria-live="polite">{{ visibleDays.length }} hari<span>Ketuk untuk melihat detail</span></p>
                        <div v-if="visibleDays.length" class="daily-list" :class="{ 'is-loading': loading }">
                            <button
                                v-for="day in visibleDays"
                                :key="day.date"
                                type="button"
                                class="day-card"
                                :class="[{ 'is-today': day.date === today }, `tone-${day.tone}`]"
                                :aria-label="`${day.full_date_label}, ${day.status_label}. Lihat detail`"
                                @click="selectedDay = day"
                            >
                                <span class="date-tile"
                                    ><strong>{{ day.day_number }}</strong
                                    ><small>{{ day.day_name }}</small></span
                                >
                                <span class="day-body">
                                    <span class="day-top"
                                        ><strong>{{ day.shift_name || 'Belum ada jadwal' }}</strong
                                        ><i v-if="day.date === today">Hari ini</i></span
                                    >
                                    <span v-if="!day.flags.day_off || day.clock_in || day.clock_out" class="day-times"
                                        ><span :class="{ 'time-warning': day.late_minutes > 0 }"><ArrowDownRight />{{ day.clock_in || '—' }}</span
                                        ><span :class="{ 'time-warning': day.early_departure_minutes > 0 }"
                                            ><ArrowUpRight />{{ day.clock_out || '—' }}</span
                                        ></span
                                    >
                                    <span v-else class="day-off-caption">
                                        <CalendarDays v-if="day.day_off_type === 'libur_nasional'" /><Coffee v-else />Tidak wajib presensi
                                    </span>
                                    <span class="status-badge"><i></i>{{ day.status_label }}</span>
                                </span>
                                <ChevronRight class="day-chevron" />
                            </button>
                        </div>
                        <div v-else class="empty-state">
                            <CalendarDays />
                            <h3>Belum ada hari yang sesuai</h3>
                            <p>Pilih kategori lain untuk melihat catatan presensi.</p>
                            <button type="button" @click="activeFilter = 'all'">Tampilkan semua hari</button>
                        </div>
                    </section>
                    <p class="log-footnote"><ShieldCheck /> Rekap mengikuti jadwal shift, kalender libur, dan cuti yang disetujui.</p>
                </template>
                <section v-else class="empty-state unavailable-state">
                    <History />
                    <h2>Log absensi belum tersedia</h2>
                    <p>{{ unavailableReason }}</p>
                </section>
            </div>
        </div>

        <CutiSheet :open="monthOpen" title="Pilih periode" description="Lihat kembali catatan kehadiran setiap bulan." @close="monthOpen = false">
            <div class="year-navigation">
                <button type="button" class="icon-button" aria-label="Tahun sebelumnya" :disabled="pickerYear <= 1900" @click="pickerYear--">
                    <ChevronLeft /></button
                ><strong aria-live="polite">{{ pickerYear }}</strong
                ><button type="button" class="icon-button" aria-label="Tahun berikutnya" :disabled="pickerYear >= 2100" @click="pickerYear++">
                    <ChevronRight />
                </button>
            </div>
            <div class="month-grid">
                <button
                    v-for="item in months"
                    :key="item.value"
                    type="button"
                    :class="{ selected: month === `${pickerYear}-${String(item.value).padStart(2, '0')}` }"
                    :aria-pressed="month === `${pickerYear}-${String(item.value).padStart(2, '0')}`"
                    @click="changeMonth(`${pickerYear}-${String(item.value).padStart(2, '0')}`)"
                >
                    {{ item.label }}
                </button>
            </div>
            <button type="button" class="picker-today" @click="changeMonth(today.slice(0, 7))">Pilih bulan ini</button>
        </CutiSheet>

        <CutiSheet :open="Boolean(selectedDay)" title="Detail presensi" :description="selectedDay?.full_date_label" @close="selectedDay = null">
            <template v-if="selectedDay">
                <div class="detail-overview" :class="`tone-${selectedDay.tone}`">
                    <span class="status-badge"><i></i>{{ selectedDay.status_label }}</span>
                    <h3>{{ selectedDay.shift_name || 'Belum ada jadwal shift' }}</h3>
                    <p v-if="selectedDay.schedule"><Clock3 />{{ selectedDay.schedule }} WIB</p>
                </div>
                <div v-if="!selectedDay.flags.day_off || selectedDay.clock_in || selectedDay.clock_out" class="detail-events">
                    <article v-for="event in ['in', 'out'] as const" :key="event">
                        <span class="detail-event-icon"><LogIn v-if="event === 'in'" /><LogOut v-else /></span>
                        <div>
                            <h3>
                                {{ event === 'in' ? 'Clock In' : 'Clock Out' }}<strong>{{ selectedDay[`clock_${event}`] || '—' }}</strong>
                            </h3>
                            <p>
                                {{
                                    selectedDay[`clock_${event}_date_label`] ? `${selectedDay[`clock_${event}_date_label`]} WIB` : 'Belum ada rekaman'
                                }}
                            </p>
                            <p v-if="selectedDay[`clock_${event}_location`] || selectedDay[`clock_${event}_address`]" class="event-location">
                                <MapPin /><span
                                    >{{ selectedDay[`clock_${event}_location`]
                                    }}<small v-if="selectedDay[`clock_${event}_address`]">{{ selectedDay[`clock_${event}_address`] }}</small></span
                                >
                            </p>
                            <span v-if="event === 'in' && selectedDay.late_minutes > 0" class="detail-warning"
                                >Terlambat {{ selectedDay.late_minutes }} menit</span
                            >
                            <span v-if="event === 'out' && selectedDay.early_departure_minutes > 0" class="detail-warning"
                                >Pulang lebih awal {{ selectedDay.early_departure_minutes }} menit</span
                            >
                        </div>
                    </article>
                </div>
                <div v-if="selectedDay.duration" class="detail-duration">
                    <Clock3 /><span>Durasi tercatat</span><strong>{{ selectedDay.duration }}</strong>
                </div>
                <p v-if="selectedDay.lembur_late_allowance || selectedDay.lembur_early_allowance" class="detail-notes">
                    <strong>Penggunaan lembur disetujui</strong>
                    <span v-if="selectedDay.lembur_late_allowance">Telat masuk {{ selectedDay.lembur_late_allowance }} menit. </span>
                    <span v-if="selectedDay.lembur_early_allowance">Pulang cepat {{ selectedDay.lembur_early_allowance }} menit. </span>
                    Waktu ini sudah diperhitungkan pada presensi.
                </p>
                <p v-if="selectedDay.notes && selectedDay.notes !== selectedDay.day_off_reason" class="detail-notes">
                    <strong>Catatan</strong>{{ selectedDay.notes }}
                </p>
                <p v-if="selectedDay.flags.day_off && !selectedDay.clock_in && !selectedDay.clock_out" class="detail-hint">
                    Hari libur sesuai pengaturan shift Anda. Tidak ada kewajiban clock in dan clock out; hari ini tidak dihitung absen.
                </p>
                <p v-if="selectedDay.status === 'upcoming'" class="detail-hint">
                    Hari kerja ini belum dimulai. Presensi akan tampil setelah Anda melakukan clock in.
                </p>
                <p v-if="selectedDay.status === 'invalid'" class="detail-hint">
                    Waktu clock out tercatat sebelum clock in. Hubungi administrator untuk memeriksa catatan ini.
                </p>
            </template>
        </CutiSheet>
    </main>
</template>

<style scoped>
.log-workspace {
    color: #1b3934;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    background: radial-gradient(ellipse at 10% 10%, #d7e8e0, transparent 55%), #eaf0eb;
}
.log-shell {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 500px;
    height: 100vh;
    height: 100dvh;
    margin: 0 auto;
    overflow: hidden;
    background: #f6f8f4;
    box-shadow: 0 0 80px #183a3017;
}
.log-header {
    display: grid;
    flex-shrink: 0;
    grid-template-columns: 42px minmax(0, 1fr) 42px;
    align-items: center;
    gap: 10px;
    padding: calc(15px + env(safe-area-inset-top, 0px)) 18px 16px;
    border-bottom: 1px solid #e2e9e1;
    background: #f8faf6;
    z-index: 20;
}
.header-copy {
    text-align: center;
    min-width: 0;
}
.header-copy p {
    margin: 0 0 5px;
    color: #8c774d;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.18em;
}
.header-copy h1 {
    margin: 0;
    font-size: clamp(15px, 4.5vw, 20px);
    line-height: 1.35;
    font-weight: 800;
    letter-spacing: -0.045em;
}
.icon-button {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
    border: 1px solid #dde5db;
    border-radius: 14px;
    color: #315c4e;
    background: #fff;
}
.icon-button svg {
    width: 19px;
    height: 19px;
}
.header-seal {
    justify-self: end;
    display: grid;
    place-items: center;
    width: 35px;
    height: 35px;
    border: 1px solid #e3ddca;
    border-radius: 12px;
    color: #8c7644;
    background: #f4f0e4;
}
.header-seal svg {
    width: 18px;
    height: 18px;
}
.log-content {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior-y: contain;
    padding: 20px 18px calc(24px + env(safe-area-inset-bottom, 0px));
    scrollbar-width: thin;
    scrollbar-color: #b7c8bd transparent;
}
button,
a {
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
button {
    font: inherit;
}
button:disabled {
    cursor: wait;
    opacity: 0.45;
}
button:focus-visible,
a:focus-visible {
    outline: 3px solid #ba995c;
    outline-offset: 3px;
}
.month-card {
    position: relative;
    overflow: hidden;
    border: 1px solid #245749;
    border-radius: 24px;
    padding: 19px 18px 15px;
    color: #fff;
    background: radial-gradient(ellipse at 100% 0, #50735960, transparent 70%), linear-gradient(120deg, #183e36, #2f5b46);
    box-shadow: 0 10px 25px #1c483321;
}
.month-card::before {
    position: absolute;
    content: '';
    pointer-events: none;
    width: 210px;
    height: 210px;
    border: 1px solid #cfb87923;
    border-radius: 50%;
    right: -115px;
    top: -80px;
}
.month-eyebrow {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.month-eyebrow > span {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.15em;
    color: #dec895;
}
.month-eyebrow svg {
    width: 14px;
    height: 14px;
}
.month-eyebrow > i {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #d7be7f;
    box-shadow: 0 0 0 4px #d7be7f18;
}
.month-navigation {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin: 19px 0 20px;
}
.month-arrow {
    display: grid;
    place-items: center;
    width: 38px;
    height: 42px;
    flex-shrink: 0;
    border: 1px solid #ffffff21;
    border-radius: 13px;
    color: #e0eadd;
    background: #ffffff09;
}
.month-arrow:hover {
    background: #ffffff18;
}
.month-arrow svg {
    width: 18px;
    height: 18px;
}
.month-picker {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 7px;
    min-height: 56px;
}
.month-picker strong {
    font-size: clamp(20px, 6vw, 26px);
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -0.045em;
}
.month-picker > span {
    display: flex;
    gap: 5px;
    align-items: center;
    font-size: 10px;
    color: #c1d0bf;
}
.month-picker svg {
    width: 12px;
    height: 12px;
}
.month-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
    padding-top: 13px;
    border-top: 1px solid #ffffff19;
    font-size: 10px;
    color: #ccd8ca;
}
.period-badge {
    border: 1px solid #c8b0743b;
    border-radius: 6px;
    padding: 4px 7px;
    color: #e2cf9e;
    background: #c8b07412;
    font-size: 9px;
}
.loading-line {
    position: absolute;
    inset: auto 0 0;
    height: 3px;
    background: #e2cf9e;
    animation: loading 1s ease-in-out infinite alternate;
    transform-origin: left;
}
.current-month {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    min-height: 40px;
    margin: 8px auto -6px;
    color: #47715a;
    font-size: 11px;
    font-weight: 700;
}
.current-month svg {
    width: 14px;
    height: 14px;
}
.summary-card {
    margin-top: 20px;
    padding: 19px 16px 0;
    border: 1px solid #e2e8dc;
    border-radius: 23px;
    background: #fff;
    box-shadow: 0 5px 18px #1b483006;
}
.section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.eyebrow {
    display: block;
    color: #84917e;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.14em;
}
.section-title h2 {
    margin: 5px 0 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.035em;
    line-height: 1.5;
}
.summary-icon {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 11px;
    color: #8b7a52;
    background: #f6f3e9;
}
.summary-icon svg {
    width: 17px;
    height: 17px;
}
.primary-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    margin: 17px 0 0;
}
.primary-metrics > div {
    border: 1px solid var(--metric-border);
    border-radius: 14px;
    padding: 12px;
    background: var(--metric-bg);
    color: var(--metric-ink);
}
.metric-green {
    --metric-bg: #f0f7f0;
    --metric-border: #e0ece0;
    --metric-ink: #2d654a;
}
.metric-amber {
    --metric-bg: #fcf7eb;
    --metric-border: #f0e8d5;
    --metric-ink: #9a6b2c;
}
.metric-rose {
    --metric-bg: #fcf2ef;
    --metric-border: #f3e3de;
    --metric-ink: #ae6254;
}
.metric-violet {
    --metric-bg: #f4f2fa;
    --metric-border: #e9e5f3;
    --metric-ink: #7a6a9e;
}
.primary-metrics dt {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    font-weight: 600;
}
.primary-metrics dt svg {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
}
.primary-metrics dd {
    display: flex;
    align-items: baseline;
    gap: 5px;
    margin: 7px 0 0;
    font-size: 27px;
    font-weight: 750;
    line-height: 1.2;
    letter-spacing: -0.04em;
    font-variant-numeric: tabular-nums;
}
dd small {
    font-size: 9px;
    font-weight: 500;
    letter-spacing: 0;
    opacity: 0.8;
}
.extra-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0;
    margin: 14px 0 0;
    border-top: 1px solid #edf0e9;
}
.extra-metrics > div {
    min-width: 0;
    padding: 13px 5px 12px;
}
.extra-metrics > div:nth-child(n + 4) {
    border-top: 1px solid #edf0e9;
}
.extra-metrics dt {
    min-height: 29px;
    color: #778475;
    font-size: 9px;
    line-height: 1.6;
}
.extra-metrics dd {
    margin: 4px 0 0;
    color: #8a9685;
    font-size: 21px;
    font-weight: 650;
    font-variant-numeric: tabular-nums;
}
.extra-metrics dd.nonzero-count {
    color: #335f48;
}
.extra-metrics dd small {
    margin-left: 4px;
}
.summary-toggle {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    width: 100%;
    min-height: 46px;
    margin-top: 3px;
    border-top: 1px solid #edf0e9;
    color: #55704d;
    font-size: 10px;
    font-weight: 700;
}
.summary-toggle svg {
    width: 13px;
    height: 13px;
    transition: transform 0.2s;
}
.rotated {
    transform: rotate(180deg);
}
.daily-section {
    margin-top: 26px;
}
.daily-title {
    margin: 0 2px 15px;
}
.day-count {
    padding: 6px 8px;
    border: 1px solid #e1e7db;
    border-radius: 8px;
    color: #6d8062;
    background: #eef3e9;
    font-size: 9px;
    white-space: nowrap;
}
.filter-strip {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding: 2px 1px 7px;
    scrollbar-width: none;
}
.filter-strip::-webkit-scrollbar {
    display: none;
}
.filter-strip button {
    flex-shrink: 0;
    min-height: 36px;
    padding: 8px 12px;
    border: 1px solid #e2e7de;
    border-radius: 11px;
    color: #72806b;
    background: #fff;
    font-size: 10px;
    font-weight: 600;
}
.filter-strip button.active {
    color: #fff;
    border-color: #2e5946;
    background: #2e5946;
    box-shadow: 0 3px 8px #2e594616;
}
.list-caption {
    display: flex;
    justify-content: space-between;
    margin: 8px 2px 11px;
    color: #7b8874;
    font-size: 9px;
}
.list-caption > span {
    color: #929b8c;
}
.daily-list {
    display: grid;
    gap: 9px;
    transition: opacity 0.2s;
}
.is-loading {
    opacity: 0.45;
    pointer-events: none;
}
.day-card {
    display: flex;
    align-items: center;
    gap: 13px;
    width: 100%;
    padding: 15px 13px;
    border: 1px solid #e4e9df;
    border-radius: 18px;
    text-align: left;
    background: #fff;
    box-shadow: 0 3px 10px #1f462303;
}
.day-card:hover {
    border-color: #b7cbb3;
    box-shadow: 0 4px 13px #1f46230a;
}
.day-card.is-today {
    border-color: #b1c5a8;
    background: #fcfff8;
}
.date-tile {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    width: 48px;
    min-height: 60px;
    flex-shrink: 0;
    gap: 3px;
    border: 1px solid #e9ede4;
    border-radius: 13px;
    background: #f5f7f1;
}
.date-tile strong {
    font-size: 23px;
    font-weight: 750;
    letter-spacing: -0.05em;
    line-height: 1.25;
}
.date-tile small {
    color: #829078;
    font-size: 9px;
    font-weight: 650;
}
.tone-rest .date-tile {
    border-color: #f2e6df;
    background: #fcf4ef;
    color: #ba7e65;
}
.tone-rest .date-tile small {
    color: #b18c79;
}
.tone-holiday .date-tile {
    border-color: #f1d8d6;
    background: #fff1f0;
    color: #ad5353;
}
.tone-holiday .date-tile small {
    color: #ad6561;
}
.day-body {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    align-items: flex-start;
    gap: 7px;
}
.day-top {
    display: flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
}
.day-top > strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 11px;
    font-weight: 700;
}
.day-top > i {
    flex-shrink: 0;
    color: #74874c;
    font-style: normal;
    font-size: 8px;
}
.day-times {
    display: flex;
    gap: 26px;
}
.day-times > span {
    display: flex;
    align-items: center;
    gap: 5px;
    min-width: 58px;
    color: #3f5547;
    font-size: 17px;
    font-weight: 650;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.035em;
}
.day-times svg {
    width: 13px;
    height: 13px;
    color: #8ba18a;
}
.day-times > span.time-warning,
.day-times > span.time-warning svg {
    color: #b18044;
}
.day-off-caption {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #8e7b6b;
    font-size: 10px;
    line-height: 1.8;
}
.day-off-caption svg {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
}
.tone-holiday .day-off-caption {
    color: #ac6661;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 6px;
    border-radius: 5px;
    font-size: 8px;
    line-height: 1.5;
    font-weight: 650;
    color: var(--status-color, #7e8b75);
    background: var(--status-bg, #f1f4ed);
}
.status-badge > i {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: currentColor;
}
.tone-warning {
    --status-color: #a47a36;
    --status-bg: #fbf4e5;
}
.tone-success {
    --status-color: #4c7c52;
    --status-bg: #eff6e9;
}
.tone-danger {
    --status-color: #b26453;
    --status-bg: #fcf0ec;
}
.tone-rest {
    --status-color: #a88872;
    --status-bg: #f7f0e9;
}
.tone-holiday {
    --status-color: #ac5555;
    --status-bg: #fff0ee;
}
.tone-leave {
    --status-color: #82759e;
    --status-bg: #f2eff7;
}
.day-chevron {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    color: #a2ae99;
}
.log-footnote {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 5px;
    margin: 22px 0 0;
    color: #8e9a85;
    font-size: 8px;
    line-height: 1.6;
}
.log-footnote svg {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 30px 18px;
    border: 1px dashed #d4dfcd;
    border-radius: 20px;
    text-align: center;
    background: #fcfdf9;
}
.empty-state > svg {
    width: 30px;
    height: 30px;
    color: #9caa89;
}
.empty-state h3,
.empty-state h2 {
    margin: 13px 0 6px;
    font-size: 15px;
    font-weight: 750;
}
.empty-state p {
    margin: 0;
    max-width: 290px;
    color: #7c8973;
    font-size: 12px;
    line-height: 1.8;
}
.empty-state button {
    margin-top: 15px;
    min-height: 40px;
    color: #3d684b;
    font-size: 12px;
    font-weight: 700;
}
.unavailable-state {
    margin-top: 20px;
}
.error-note {
    margin: 12px 0;
    padding: 12px;
    border-radius: 12px;
    color: #995747;
    background: #fbede7;
    font-size: 12px;
}
.year-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.year-navigation strong {
    font-size: 25px;
    font-weight: 750;
    color: #355a43;
}
.month-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}
.month-grid button {
    min-height: 48px;
    border: 1px solid #e1e7d9;
    border-radius: 13px;
    color: #65795a;
    background: #f5f8ef;
    font-size: 13px;
    font-weight: 650;
}
.month-grid button.selected {
    border-color: #315a42;
    background: #315a42;
    color: #fff;
}
.picker-today {
    width: 100%;
    min-height: 44px;
    margin-top: 16px;
    color: #8d7649;
    font-size: 12px;
    font-weight: 700;
}
.detail-overview {
    padding: 17px;
    border: 1px solid #e2e8d9;
    border-radius: 17px;
    background: #f5f8ef;
}
.detail-overview .status-badge {
    font-size: 10px;
    padding: 4px 7px;
}
.detail-overview h3 {
    margin: 10px 0 0;
    color: #31523d;
    font-size: 17px;
    font-weight: 750;
    overflow-wrap: anywhere;
}
.detail-overview p {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 6px 0 0;
    color: #7b896f;
    font-size: 12px;
}
.detail-overview p svg {
    width: 14px;
    height: 14px;
}
.detail-events {
    display: grid;
    gap: 20px;
    padding: 23px 0;
}
.detail-events article {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.detail-event-icon {
    display: grid;
    place-items: center;
    width: 37px;
    height: 37px;
    flex-shrink: 0;
    border-radius: 12px;
    color: #6c875b;
    background: #eff4e7;
}
.detail-event-icon svg {
    width: 17px;
    height: 17px;
}
.detail-events article > div {
    flex: 1;
    min-width: 0;
}
.detail-events h3 {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin: 0;
    font-size: 12px;
    font-weight: 650;
    color: #526a4b;
}
.detail-events h3 strong {
    color: #25462d;
    font-size: 22px;
    font-variant-numeric: tabular-nums;
}
.detail-events p {
    margin: 5px 0 0;
    color: #89937f;
    font-size: 11px;
    line-height: 1.7;
}
.event-location {
    display: flex;
    align-items: flex-start;
    gap: 5px;
}
.event-location svg {
    width: 13px;
    height: 13px;
    margin-top: 3px;
    flex-shrink: 0;
}
.event-location span {
    min-width: 0;
    overflow-wrap: anywhere;
}
.event-location small {
    display: block;
    font-size: 10px;
}
.detail-warning {
    display: inline-block;
    margin-top: 7px;
    padding: 4px 7px;
    border-radius: 6px;
    background: #fbf2de;
    color: #ac813b;
    font-size: 10px;
}
.detail-duration {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 13px 11px;
    border: 1px solid #e4e8dc;
    border-radius: 12px;
    background: #f4f7ee;
    color: #758567;
    font-size: 10px;
}
.detail-duration svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}
.detail-duration strong {
    margin-left: auto;
    text-align: right;
    color: #45623a;
    font-size: 11px;
}
.detail-notes {
    margin: 17px 0 0;
    color: #7c8872;
    font-size: 12px;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.detail-notes strong {
    display: block;
    margin-bottom: 5px;
    color: #526a43;
    font-size: 11px;
}
.detail-hint {
    margin: 16px 0 0;
    padding: 13px;
    border-radius: 12px;
    color: #8e7b56;
    background: #f7f2e5;
    font-size: 12px;
    line-height: 1.8;
}
@keyframes loading {
    from {
        transform: scaleX(0.1);
        opacity: 0.5;
    }
    to {
        transform: scaleX(1);
        opacity: 1;
    }
}
@media (max-width: 359px) {
    .log-header {
        padding-inline: 13px;
        gap: 6px;
    }
    .log-content {
        padding-inline: 12px;
    }
    .month-card {
        padding-inline: 13px;
    }
    .month-footer {
        font-size: 9px;
    }
    .primary-metrics > div {
        padding: 11px 9px;
    }
    .day-card {
        padding-inline: 10px;
        gap: 10px;
    }
    .day-times {
        gap: 15px;
    }
    .extra-metrics dt {
        font-size: 8px;
    }
    .filter-strip button {
        padding-inline: 10px;
        font-size: 9px;
    }
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
