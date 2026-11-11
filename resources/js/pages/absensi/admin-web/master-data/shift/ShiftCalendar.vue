<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight, Clock3, LoaderCircle, RefreshCw, Search, UsersRound } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface CalendarDay {
    date: string;
    day: number;
    working: number;
    day_off: number;
    unassigned: number;
}
interface Employee {
    id: number;
    nama: string;
    nik: string | null;
    unit: string | null;
    jabatan: string | null;
    source: 'scheduled' | 'default';
    shift: { id: number; nama: string; jam_masuk: string; jam_keluar: string } | null;
    is_day_off: boolean;
    day_off_reason: string | null;
}
interface Calendar {
    month: string;
    today: string;
    selected_date: string;
    total: number;
    days: CalendarDay[];
    employees: Employee[];
}

const data = ref<Calendar | null>(null);
const month = ref('');
const selectedDate = ref('');
const loading = ref(true);
const error = ref('');
const search = ref('');
const status = ref('all');
let request: AbortController | null = null;

const monthLabel = computed(() =>
    month.value
        ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${month.value}-01T00:00:00`))
        : 'Memuat kalender',
);
const dateLabel = (date: string) =>
    new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${date}T00:00:00`));
const offset = computed(() => (month.value ? (new Date(`${month.value}-01T00:00:00`).getDay() + 6) % 7 : 0));
const selectedDay = computed(() => data.value?.days.find((day) => day.date === selectedDate.value));
const statusOptions = computed(() => [
    { value: 'all', label: 'Semua', count: data.value?.total ?? 0 },
    { value: 'working', label: 'Bertugas', count: selectedDay.value?.working ?? 0 },
    { value: 'off', label: 'OFF / Libur', count: selectedDay.value?.day_off ?? 0 },
    { value: 'unassigned', label: 'Belum diatur', count: selectedDay.value?.unassigned ?? 0 },
]);
const employees = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('id-ID');
    return (data.value?.employees ?? []).filter((employee) => {
        if (status.value === 'working' && (employee.is_day_off || !employee.shift)) return false;
        if (status.value === 'off' && !employee.is_day_off) return false;
        if (status.value === 'unassigned' && (employee.shift || employee.is_day_off)) return false;
        return (
            !query ||
            [employee.nama, employee.nik, employee.unit, employee.jabatan, employee.shift?.nama].some((value) =>
                value?.toLocaleLowerCase('id-ID').includes(query),
            )
        );
    });
});

const load = async (targetMonth = month.value, targetDate = selectedDate.value) => {
    request?.abort();
    const current = new AbortController();
    request = current;
    loading.value = true;
    error.value = '';
    if (targetMonth) month.value = targetMonth;
    if (targetDate) selectedDate.value = targetDate;
    try {
        const params = new URLSearchParams();
        if (targetMonth) params.set('month', targetMonth);
        if (targetDate) params.set('date', targetDate);
        const response = await fetch(`/absensi/admin-web/master-data/shift/assign/calendar?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: current.signal,
        });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) {
            throw new Error(
                response.status === 401 || response.redirected
                    ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali.'
                    : response.status === 403
                      ? 'Anda tidak memiliki izin melihat kalender shift.'
                      : 'Kalender shift belum dapat dimuat. Silakan coba kembali.',
            );
        }
        const result: Calendar = await response.json();
        if (request !== current) return;
        data.value = result;
        month.value = result.month;
        selectedDate.value = result.selected_date;
    } catch (cause) {
        if (!current.signal.aborted) error.value = cause instanceof Error ? cause.message : 'Kalender shift belum dapat dimuat.';
    } finally {
        if (request === current) loading.value = false;
    }
};
const changeMonth = () => {
    if (/^(?:19|[2-9]\d)\d{2}-(?:0[1-9]|1[0-2])$/.test(month.value)) void load(month.value, `${month.value}-01`);
    else month.value = data.value?.month ?? '';
};
const moveMonth = (direction: number) => {
    if (!month.value) return;
    const target = new Date(`${month.value}-01T00:00:00`);
    target.setMonth(target.getMonth() + direction);
    if (target.getFullYear() < 1900 || target.getFullYear() > 9999) return;
    const next = `${target.getFullYear()}-${String(target.getMonth() + 1).padStart(2, '0')}`;
    void load(next, `${next}-01`);
};
const today = () => {
    if (data.value) void load(data.value.today.slice(0, 7), data.value.today);
};
const refresh = () => void load();
defineExpose({ refresh });
onMounted(refresh);
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <section class="shift-calendar" aria-labelledby="shift-calendar-title" :aria-busy="loading">
        <header class="calendar-heading">
            <div class="calendar-title">
                <span class="calendar-icon"><CalendarDays /></span>
                <div>
                    <h2 id="shift-calendar-title">Kalender shift pegawai</h2>
                    <p>Pilih tanggal untuk melihat siapa saja yang bertugas beserta shiftnya.</p>
                </div>
            </div>
            <button type="button" class="calendar-button" :disabled="loading" @click="refresh"><RefreshCw /> Muat ulang</button>
        </header>

        <div class="calendar-toolbar">
            <div class="month-navigation">
                <button type="button" class="icon-button" aria-label="Bulan sebelumnya" :disabled="loading || !month" @click="moveMonth(-1)">
                    <ChevronLeft />
                </button>
                <h3>{{ monthLabel }}</h3>
                <button type="button" class="icon-button" aria-label="Bulan berikutnya" :disabled="loading || !month" @click="moveMonth(1)">
                    <ChevronRight />
                </button>
            </div>
            <div class="month-actions">
                <label for="shift-calendar-month" class="sr-only">Pilih bulan kalender shift</label>
                <input id="shift-calendar-month" v-model="month" type="month" min="1900-01" max="9999-12" :disabled="loading" @change="changeMonth" />
                <button type="button" class="calendar-button" :disabled="loading || !data" @click="today">Hari ini</button>
            </div>
        </div>

        <div v-if="error" class="calendar-message" role="alert">
            <p>{{ error }}</p>
            <button type="button" class="calendar-button" @click="refresh">Coba kembali</button>
        </div>
        <div v-else-if="!data || data.month !== month" class="calendar-message" role="status">
            <LoaderCircle class="spinning" /> Memuat kalender shift...
        </div>
        <div v-else class="calendar-body">
            <div class="month-panel">
                <div class="calendar-legend"><span class="working-dot"></span> Jumlah pegawai bertugas <small>OFF/libur tidak dihitung</small></div>
                <div class="weekdays" aria-hidden="true">
                    <span v-for="day in ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']" :key="day">{{ day }}</span>
                </div>
                <div class="month-grid" role="group" aria-label="Pilih tanggal jadwal shift">
                    <span v-for="blank in offset" :key="`blank-${blank}`" class="blank-day" aria-hidden="true"></span>
                    <button
                        v-for="day in data.days"
                        :key="day.date"
                        type="button"
                        class="calendar-day"
                        :class="{ selected: day.date === selectedDate, today: day.date === data.today }"
                        :aria-pressed="day.date === selectedDate"
                        :aria-current="day.date === data.today ? 'date' : undefined"
                        :aria-label="`${dateLabel(day.date)}: ${day.working} bertugas, ${day.day_off} libur, ${day.unassigned} belum diatur`"
                        :disabled="loading"
                        @click="load(month, day.date)"
                    >
                        <span class="day-number">{{ day.day }}<i v-if="day.date === data.today" aria-hidden="true"></i></span>
                        <strong>{{ day.working }} <span>bertugas</span></strong>
                        <small>{{ day.day_off }} libur</small>
                    </button>
                </div>
                <p class="calendar-note">Jadwal per tanggal diprioritaskan. Tanggal lainnya mengikuti shift default dan aturan hari libur.</p>
            </div>

            <div class="roster-panel" :aria-busy="loading">
                <header class="roster-heading">
                    <span>Jadwal pada tanggal</span>
                    <h3>{{ dateLabel(selectedDate) }}</h3>
                    <p>{{ data.total.toLocaleString('id-ID') }} pegawai berakun</p>
                </header>
                <div class="roster-filters" role="group" aria-label="Filter status jadwal">
                    <button
                        v-for="option in statusOptions"
                        :key="option.value"
                        type="button"
                        :class="{ active: status === option.value }"
                        :aria-pressed="status === option.value"
                        @click="status = option.value"
                    >
                        {{ option.label }} <span>{{ option.count }}</span>
                    </button>
                </div>
                <label class="roster-search">
                    <Search aria-hidden="true" />
                    <span class="sr-only">Cari nama, NIK, unit, jabatan, atau shift</span>
                    <input v-model="search" type="search" placeholder="Cari pegawai, unit, atau shift..." />
                </label>
                <div v-if="loading" class="calendar-message" role="status"><LoaderCircle class="spinning" /> Memuat jadwal pegawai...</div>
                <div v-else-if="!employees.length" class="calendar-message" role="status">
                    <UsersRound />
                    <p>{{ !data.total ? 'Belum ada pegawai berakun.' : 'Tidak ada pegawai yang sesuai dengan pencarian atau filter ini.' }}</p>
                </div>
                <template v-else>
                    <p class="roster-count" role="status">Menampilkan {{ employees.length.toLocaleString('id-ID') }} pegawai</p>
                    <div class="roster-list" role="region" aria-label="Daftar pegawai dan shift pada tanggal terpilih" tabindex="0">
                        <article v-for="employee in employees" :key="employee.id" class="roster-row">
                            <div class="employee-identity">
                                <strong>{{ employee.nama }}</strong>
                                <small>{{
                                    [employee.nik, employee.unit, employee.jabatan].filter(Boolean).join(' · ') || 'Data unit belum diisi'
                                }}</small>
                            </div>
                            <div class="employee-shift">
                                <strong :class="{ off: employee.is_day_off, unassigned: !employee.shift && !employee.is_day_off }">
                                    {{ employee.is_day_off ? 'OFF / Libur' : employee.shift?.nama || 'Belum diatur' }}
                                </strong>
                                <small v-if="employee.is_day_off"
                                    >{{ employee.day_off_reason
                                    }}<template v-if="employee.shift && employee.shift.nama !== employee.day_off_reason">
                                        · {{ employee.shift.nama }}</template
                                    ></small
                                >
                                <small v-else-if="employee.shift"><Clock3 /> {{ employee.shift.jam_masuk }}–{{ employee.shift.jam_keluar }}</small>
                                <small v-else>Belum memiliki jadwal shift</small>
                                <span v-if="employee.shift || employee.is_day_off" class="schedule-source">{{
                                    employee.source === 'scheduled' ? 'Per tanggal' : 'Default'
                                }}</span>
                            </div>
                        </article>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>

<style scoped>
.shift-calendar {
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 18px;
    background: white;
}
.calendar-heading,
.calendar-toolbar,
.calendar-title,
.month-navigation,
.month-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.calendar-heading {
    justify-content: space-between;
    padding: 24px 26px;
    border-bottom: 1px solid var(--rs-border);
}
.calendar-title {
    min-width: 0;
}
.calendar-icon {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.calendar-icon svg {
    width: 22px;
}
h2,
h3,
p {
    margin: 0;
}
h2 {
    color: var(--rs-primary-deep);
    font-size: 19px;
    font-weight: 750;
}
.calendar-title p {
    margin-top: 5px;
    color: var(--rs-muted);
    font-size: 12px;
    line-height: 1.6;
}
.calendar-toolbar {
    justify-content: space-between;
    flex-wrap: wrap;
    padding: 18px 26px;
    background: var(--rs-surface);
    border-bottom: 1px solid var(--rs-border);
}
.month-navigation h3 {
    min-width: 155px;
    text-align: center;
    text-transform: capitalize;
    font-size: 16px;
    font-weight: 750;
}
.calendar-button,
.icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 36px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    color: var(--rs-primary-deep);
    background: white;
    font-size: 11px;
    font-weight: 650;
}
.calendar-button {
    padding: 7px 12px;
    white-space: nowrap;
}
.icon-button {
    width: 36px;
    flex-shrink: 0;
}
.calendar-button svg,
.icon-button svg {
    width: 15px;
    height: 15px;
}
button {
    cursor: pointer;
}
button:disabled {
    cursor: wait;
    opacity: 0.55;
}
button:focus-visible,
input:focus-visible,
.roster-list:focus-visible {
    outline: 2px solid var(--rs-primary);
    outline-offset: 3px;
}
.calendar-button:hover:not(:disabled),
.icon-button:hover:not(:disabled) {
    border-color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.month-actions input {
    min-width: 0;
    padding: 7px 10px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    font-size: 12px;
    color: var(--rs-ink);
    background: white;
}
.calendar-body {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
}
.month-panel {
    min-width: 0;
    padding: 22px 24px;
    border-right: 1px solid var(--rs-border);
}
.calendar-legend {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 18px;
    color: var(--rs-primary-deep);
    font-size: 11px;
    font-weight: 650;
}
.working-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--rs-primary);
}
.calendar-legend small {
    margin-left: auto;
    color: var(--rs-muted);
    font-size: 10px;
    font-weight: 400;
}
.weekdays,
.month-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 5px;
}
.weekdays {
    margin-bottom: 8px;
    text-align: center;
    color: var(--rs-muted);
    font-size: 10px;
    font-weight: 650;
}
.calendar-day {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-height: 92px;
    padding: 9px 7px;
    text-align: left;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    background: white;
    transition:
        background 0.15s,
        border-color 0.15s;
}
.calendar-day:hover:not(:disabled) {
    border-color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.day-number {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    font-size: 13px;
    font-weight: 700;
}
.day-number i {
    width: 6px;
    height: 6px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--rs-primary);
}
.calendar-day strong {
    color: var(--rs-primary);
    font-size: 13px;
    line-height: 1.3;
    overflow-wrap: anywhere;
}
.calendar-day strong span {
    display: block;
    font-size: 9px;
    font-weight: 500;
}
.calendar-day small {
    color: var(--rs-muted);
    font-size: 9px;
}
.calendar-day.today {
    border-color: var(--rs-primary);
}
.calendar-day.selected {
    border-color: var(--rs-primary);
    color: white;
    background: var(--rs-primary);
}
.calendar-day.selected strong,
.calendar-day.selected small,
.calendar-day.selected:hover {
    color: white;
    background: var(--rs-primary);
}
.calendar-day.selected .day-number i {
    background: white;
}
.calendar-note {
    margin-top: 16px;
    color: var(--rs-muted);
    font-size: 10px;
    line-height: 1.7;
}
.roster-panel {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 22px 24px;
}
.roster-heading > span {
    color: var(--rs-muted);
    font-size: 10px;
}
.roster-heading h3 {
    margin: 5px 0;
    color: var(--rs-primary-deep);
    font-size: 16px;
    font-weight: 750;
}
.roster-heading p {
    color: var(--rs-muted);
    font-size: 11px;
}
.roster-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 18px 0 12px;
}
.roster-filters button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 9px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    color: var(--rs-muted);
    background: white;
    font-size: 10px;
    font-weight: 650;
}
.roster-filters button.active {
    border-color: var(--rs-primary-border);
    color: var(--rs-primary-deep);
    background: var(--rs-primary-soft);
}
.roster-filters button span {
    min-width: 18px;
    padding: 1px 3px;
    border-radius: 4px;
    background: var(--rs-surface);
    text-align: center;
}
.roster-search {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 10px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
}
.roster-search > svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    color: var(--rs-muted);
}
.roster-search input {
    width: 100%;
    min-width: 0;
    padding: 10px 0;
    border: none;
    color: var(--rs-ink);
    background: transparent;
    font-size: 11px;
    box-shadow: none;
}
.roster-count {
    margin: 12px 0 5px;
    color: var(--rs-muted);
    font-size: 10px;
}
.roster-list {
    max-height: 390px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.roster-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    padding: 13px 3px;
    border-bottom: 1px solid var(--rs-border);
}
.roster-row:last-child {
    border-bottom: 0;
}
.employee-identity,
.employee-shift {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
    overflow-wrap: anywhere;
}
.employee-identity {
    flex: 1;
}
.employee-identity strong {
    font-size: 12px;
    font-weight: 700;
}
.employee-identity small,
.employee-shift small {
    color: var(--rs-muted);
    font-size: 10px;
    line-height: 1.6;
}
.employee-shift {
    align-items: flex-end;
    flex: 0 1 47%;
    text-align: right;
}
.employee-shift strong {
    color: var(--rs-primary);
    font-size: 11px;
}
.employee-shift strong.off {
    color: #a16207;
}
.employee-shift strong.unassigned {
    color: var(--rs-muted);
}
.employee-shift small svg {
    display: inline;
    width: 10px;
    height: 10px;
    vertical-align: -1px;
}
.schedule-source {
    padding: 2px 6px;
    border-radius: 4px;
    color: var(--rs-muted);
    background: var(--rs-surface);
    font-size: 9px;
}
.calendar-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    min-height: 180px;
    padding: 28px;
    text-align: center;
    color: var(--rs-muted);
    font-size: 12px;
    line-height: 1.7;
}
.calendar-message > svg {
    width: 25px;
    height: 25px;
    color: var(--rs-primary);
}
.spinning {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}
@media (max-width: 1100px) {
    .calendar-body {
        grid-template-columns: 1fr;
    }
    .month-panel {
        border-right: 0;
        border-bottom: 1px solid var(--rs-border);
    }
    .calendar-day {
        min-height: 88px;
    }
    .roster-list {
        max-height: 360px;
    }
}
@media (max-width: 600px) {
    .calendar-heading {
        padding: 18px 16px;
        flex-wrap: wrap;
    }
    .calendar-icon {
        display: none;
    }
    h2 {
        font-size: 17px;
    }
    .calendar-heading > .calendar-button {
        margin-left: auto;
    }
    .calendar-toolbar {
        gap: 14px;
        padding: 14px 16px;
    }
    .month-navigation,
    .month-actions {
        justify-content: space-between;
        width: 100%;
    }
    .month-actions input {
        flex: 1;
    }
    .month-panel,
    .roster-panel {
        padding: 18px 14px;
    }
    .calendar-legend {
        gap: 5px;
        font-size: 10px;
    }
    .calendar-legend small {
        width: 100%;
        margin-left: 12px;
    }
    .month-grid,
    .weekdays {
        gap: 3px;
    }
    .calendar-day {
        min-height: 87px;
        gap: 7px;
        padding: 7px 4px;
        border-radius: 7px;
    }
    .calendar-day strong {
        font-size: 12px;
    }
    .calendar-day strong span,
    .calendar-day small {
        font-size: 8px;
    }
    .day-number {
        font-size: 12px;
    }
    .roster-heading h3 {
        font-size: 15px;
    }
}
</style>
