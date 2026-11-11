<script setup lang="ts">
import { Dialog, DialogDescription, DialogPanel, DialogTitle } from '@headlessui/vue';
import { AlertCircle, CalendarDays, ChevronLeft, ChevronRight, Clock3, LoaderCircle, LockKeyhole, Pencil, Save, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import SearchableSelect from '../../presensi/components/SearchableSelect.vue';

interface Shift {
    id: number;
    nama: string;
    is_day_off: boolean;
    jam_masuk: string;
    jam_keluar: string;
}
interface ScheduleDay {
    date: string;
    day: number;
    source: 'scheduled' | 'default';
    shift: Shift | null;
    is_day_off: boolean;
    day_off_reason: string | null;
    locked_reason: string | null;
}
interface Schedule {
    month: string;
    today: string;
    default_shift: Shift | null;
    days: ScheduleDay[];
}

const props = defineProps<{ employee: { id: number; nama: string; nik: string | null }; canEdit: boolean; shifts: Shift[] }>();
const emit = defineEmits<{ close: [] }>();
const closeButton = ref<HTMLButtonElement | null>(null);
const schedule = ref<Schedule | null>(null);
const month = ref('');
const loading = ref(true);
const error = ref('');
const selectedDate = ref('');
const selectedShift = ref<number | string>('');
const saving = ref(false);
const saveError = ref('');
const success = ref('');
const calendar = ref<HTMLElement | null>(null);
const editorField = ref<HTMLElement | null>(null);
let request: AbortController | null = null;
const endpoint = `/absensi/admin-web/master-data/shift/assign/${props.employee.id}/schedule`;

const monthLabel = computed(() =>
    month.value
        ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${month.value}-01T00:00:00`))
        : 'Memuat jadwal',
);
const offset = computed(() => (schedule.value ? (new Date(`${schedule.value.month}-01T00:00:00`).getDay() + 6) % 7 : 0));
const scheduledCount = computed(() => schedule.value?.days.filter((day) => day.source === 'scheduled').length ?? 0);
const dayOffCount = computed(() => schedule.value?.days.filter((day) => day.is_day_off).length ?? 0);
const dateLabel = (date: string) =>
    new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${date}T00:00:00`));
const weekday = (date: string) => new Intl.DateTimeFormat('id-ID', { weekday: 'short' }).format(new Date(`${date}T00:00:00`));
const selectedDay = computed(() => schedule.value?.days.find((day) => day.date === selectedDate.value));
const canSave = computed(
    () =>
        props.canEdit &&
        !!selectedDate.value &&
        Number(selectedShift.value) > 0 &&
        !selectedDay.value?.locked_reason &&
        (selectedDay.value?.source !== 'scheduled' || Number(selectedShift.value) !== selectedDay.value?.shift?.id),
);
const shiftOptions = computed(() =>
    props.shifts.map((shift) => ({
        value: shift.id,
        label: shift.nama,
        description: shift.is_day_off ? 'OFF / Libur · Tanpa jam kerja' : `${shift.jam_masuk}–${shift.jam_keluar}`,
    })),
);

const close = () => {
    if (!saving.value) emit('close');
};
const selectDay = async (day: ScheduleDay) => {
    if (!props.canEdit || saving.value || day.locked_reason) return;
    selectedDate.value = day.date;
    selectedShift.value = day.shift?.id ?? '';
    saveError.value = '';
    success.value = '';
    await nextTick();
    editorField.value?.querySelector<HTMLElement>('button, select')?.focus({ preventScroll: true });
};
const clearEditor = async () => {
    const date = selectedDate.value;
    selectedDate.value = '';
    selectedShift.value = '';
    saveError.value = '';
    await nextTick();
    calendar.value?.querySelector<HTMLElement>(`[data-date="${date}"]`)?.focus({ preventScroll: true });
};
const save = async () => {
    if (saving.value || !canSave.value) return;
    saving.value = true;
    saveError.value = '';
    try {
        const token = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch(endpoint, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
            body: JSON.stringify({ date: selectedDate.value, shift_id: Number(selectedShift.value) }),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.schedule) {
            const validation = data?.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(
                validation ||
                    ([401, 419].includes(response.status) || response.redirected
                        ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali.'
                        : response.status === 403
                          ? 'Anda tidak memiliki izin mengubah jadwal shift.'
                          : 'Jadwal belum dapat disimpan. Silakan coba kembali.'),
            );
        }
        schedule.value = data.schedule;
        month.value = data.schedule.month;
        success.value = data.message;
        saving.value = false;
        await clearEditor();
    } catch (cause) {
        saveError.value = cause instanceof Error ? cause.message : 'Jadwal belum dapat disimpan.';
    } finally {
        saving.value = false;
    }
};

const load = async (targetMonth?: string) => {
    if (saving.value) return;
    request?.abort();
    const current = new AbortController();
    request = current;
    loading.value = true;
    error.value = '';
    selectedDate.value = '';
    selectedShift.value = '';
    saveError.value = '';
    success.value = '';
    try {
        const url = `${endpoint}${targetMonth ? `?month=${encodeURIComponent(targetMonth)}` : ''}`;
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: current.signal });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) {
            throw new Error(
                response.status === 401 || response.redirected
                    ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali.'
                    : 'Jadwal belum dapat dimuat. Silakan coba kembali.',
            );
        }
        const data: Schedule = await response.json();
        if (request !== current) return;
        schedule.value = data;
        month.value = data.month;
    } catch (cause) {
        if (current.signal.aborted) return;
        error.value = cause instanceof Error ? cause.message : 'Jadwal belum dapat dimuat.';
    } finally {
        if (request === current) loading.value = false;
    }
};
const changeMonth = () => {
    if (/^\d{4}-\d{2}$/.test(month.value)) void load(month.value);
    else month.value = schedule.value?.month ?? '';
};
const moveMonth = (direction: number) => {
    const [year, number] = month.value.split('-').map(Number);
    const target = new Date(year, number - 1 + direction, 1);
    const targetYear = target.getFullYear();
    if (targetYear < 1900 || targetYear > 9999) return;
    month.value = `${targetYear}-${String(target.getMonth() + 1).padStart(2, '0')}`;
    void load(month.value);
};

onMounted(() => void load());
onBeforeUnmount(() => request?.abort());
</script>

<template>
    <Dialog :open="true" :initial-focus="closeButton" @close="close">
        <div class="schedule-backdrop" aria-hidden="true"></div>
        <div class="schedule-position">
            <DialogPanel class="schedule-panel" :aria-busy="loading || saving">
                <header class="schedule-header">
                    <span class="schedule-icon"><CalendarDays /></span>
                    <div>
                        <DialogTitle as="h2">Jadwal shift pegawai</DialogTitle>
                        <DialogDescription
                            >{{ employee.nama }} <span v-if="employee.nik">· {{ employee.nik }}</span></DialogDescription
                        >
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="icon-button close-button"
                        aria-label="Tutup jadwal pegawai"
                        :disabled="saving"
                        @click="close"
                    >
                        <X />
                    </button>
                </header>

                <div class="schedule-toolbar">
                    <div class="month-navigation">
                        <button
                            type="button"
                            class="icon-button"
                            aria-label="Bulan sebelumnya"
                            :disabled="loading || saving || !month"
                            @click="moveMonth(-1)"
                        >
                            <ChevronLeft />
                        </button>
                        <h3 aria-live="polite">{{ monthLabel }}</h3>
                        <button
                            type="button"
                            class="icon-button"
                            aria-label="Bulan berikutnya"
                            :disabled="loading || saving || !month"
                            @click="moveMonth(1)"
                        >
                            <ChevronRight />
                        </button>
                    </div>
                    <label class="month-picker"
                        >Pilih bulan<input
                            v-model="month"
                            type="month"
                            min="1900-01"
                            max="9999-12"
                            :disabled="loading || saving"
                            @change="changeMonth"
                    /></label>
                </div>

                <div class="schedule-content">
                    <div v-if="loading" class="schedule-state" role="status">
                        <LoaderCircle class="spinner" />
                        <p>Memuat jadwal pegawai...</p>
                    </div>
                    <div v-else-if="error" class="schedule-state error-state" role="alert">
                        <AlertCircle />
                        <p>{{ error }}</p>
                        <button type="button" class="retry-button" @click="load(month || undefined)">Coba kembali</button>
                    </div>
                    <template v-else-if="schedule">
                        <p v-if="success" class="schedule-success" role="status">{{ success }}</p>
                        <p v-if="canEdit" class="schedule-edit-hint">
                            <Pencil /> Pilih tanggal untuk mengubah shift. Tanggal bertanda gembok terkunci.
                        </p>
                        <div class="schedule-summary">
                            <span
                                ><i class="scheduled-dot"></i><strong>{{ scheduledCount }}</strong> hari terjadwal</span
                            >
                            <span
                                ><i class="default-dot"></i><strong>{{ schedule.days.length - scheduledCount }}</strong> hari mengikuti default</span
                            >
                            <span
                                ><i class="off-dot"></i><strong>{{ dayOffCount }}</strong> hari libur</span
                            >
                        </div>
                        <p v-if="!scheduledCount" class="schedule-note">
                            Belum ada jadwal per tanggal pada bulan ini. Kalender menampilkan shift default pegawai.
                        </p>
                        <div class="calendar-weekdays" aria-hidden="true">
                            <span v-for="day in ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']" :key="day">{{ day }}</span>
                        </div>
                        <div ref="calendar" class="schedule-calendar" aria-label="Kalender jadwal shift">
                            <div v-for="blank in offset" :key="`blank-${blank}`" class="calendar-blank" aria-hidden="true"></div>
                            <component
                                :is="canEdit ? 'button' : 'article'"
                                v-for="day in schedule.days"
                                :key="day.date"
                                :type="canEdit ? 'button' : undefined"
                                :data-date="day.date"
                                :disabled="canEdit ? saving || !!day.locked_reason : undefined"
                                :aria-pressed="canEdit ? selectedDate === day.date : undefined"
                                class="calendar-day"
                                :class="{
                                    scheduled: day.source === 'scheduled',
                                    'day-off': day.is_day_off,
                                    today: day.date === schedule.today,
                                    selected: selectedDate === day.date,
                                }"
                                :aria-label="`${dateLabel(day.date)}: ${day.shift?.nama || 'Tanpa shift'}${day.is_day_off ? ', libur' : ''}${canEdit ? (day.locked_reason ? `. Terkunci: ${day.locked_reason}` : '. Ubah shift') : ''}`"
                                :title="canEdit ? day.locked_reason || 'Ubah shift pada tanggal ini' : undefined"
                                @click="selectDay(day)"
                            >
                                <div class="day-number">
                                    <time :datetime="day.date">{{ day.day }}</time
                                    ><span class="mobile-weekday">{{ weekday(day.date) }}</span
                                    ><small v-if="day.date === schedule.today" class="today-label">Hari ini</small>
                                </div>
                                <div class="day-shift">
                                    <strong>{{ day.shift?.nama || (day.is_day_off ? 'Libur' : 'Tanpa shift') }}</strong>
                                    <span v-if="day.is_day_off" class="shift-time">OFF / Libur</span>
                                    <span v-else-if="day.shift" class="shift-time"
                                        ><Clock3 />{{ day.shift.jam_masuk }}–{{ day.shift.jam_keluar }}</span
                                    >
                                    <span v-else class="shift-time">Belum diatur</span>
                                    <small v-if="day.day_off_reason && day.day_off_reason !== day.shift?.nama" class="holiday-reason">{{
                                        day.day_off_reason
                                    }}</small>
                                </div>
                                <span class="day-source"
                                    ><span>{{ day.source === 'scheduled' ? 'Per tanggal' : 'Default' }}</span
                                    ><span v-if="canEdit && day.locked_reason" class="day-locked"><LockKeyhole /> Terkunci</span></span
                                >
                            </component>
                        </div>
                    </template>
                </div>

                <form v-if="canEdit && selectedDay" class="schedule-editor" @submit.prevent="save">
                    <div class="editor-heading">
                        <span><Pencil /> Ubah shift pada</span><strong>{{ dateLabel(selectedDate) }}</strong
                        ><small>Shift saat ini: {{ selectedDay.shift?.nama || 'Tanpa shift' }}</small>
                    </div>
                    <div ref="editorField" class="editor-field">
                        <span>Shift baru</span
                        ><SearchableSelect
                            v-model="selectedShift"
                            label="Shift baru untuk tanggal pilihan"
                            :options="shiftOptions"
                            placeholder="Pilih shift"
                            placeholder-disabled
                            :search-threshold="0"
                            :disabled="saving"
                            search-placeholder="Cari nama atau jam shift..."
                        />
                    </div>
                    <div class="editor-actions">
                        <button type="button" class="cancel-edit" :disabled="saving" @click="clearEditor">Batal</button
                        ><button type="submit" class="done-button" :disabled="saving || !canSave">
                            <LoaderCircle v-if="saving" class="spinner" /><Save v-else />{{ saving ? 'Menyimpan...' : 'Simpan shift' }}
                        </button>
                    </div>
                    <p v-if="saveError" class="editor-error" role="alert"><AlertCircle />{{ saveError }}</p>
                    <p class="editor-note">Perubahan hanya berlaku pada tanggal ini. Shift default pegawai tetap.</p>
                </form>
                <footer v-else class="schedule-footer">
                    <p>
                        <strong>Per tanggal</strong> adalah jadwal tersimpan, termasuk hasil Assign Batch. <strong>Default</strong> mengikuti shift
                        {{ schedule?.default_shift?.nama || 'yang belum diatur' }} saat tidak ada jadwal khusus.
                    </p>
                    <button type="button" class="done-button" @click="close">Selesai</button>
                </footer>
            </DialogPanel>
        </div>
    </Dialog>
</template>

<style scoped>
.schedule-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1100;
    background: rgba(13, 35, 38, 0.5);
    backdrop-filter: blur(3px);
}
.schedule-position {
    position: fixed;
    inset: 0;
    z-index: 1101;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.schedule-panel {
    display: flex;
    flex-direction: column;
    width: 1080px;
    max-width: 100%;
    max-height: calc(100dvh - 48px);
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 16px;
    color: var(--rs-ink);
    background: white;
    box-shadow: 0 24px 80px rgba(8, 30, 33, 0.24);
    font-family: var(--font-sans);
}
.schedule-header {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    padding: 22px 24px;
    border-bottom: 1px solid var(--rs-border);
}
.schedule-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    border-radius: 12px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.schedule-icon svg {
    width: 22px;
    height: 22px;
}
.schedule-header h2 {
    margin: 0 0 6px;
    color: var(--rs-primary-deep);
    font-size: 20px;
    font-weight: 750;
    letter-spacing: -0.025em;
}
.schedule-header p {
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.6;
}
button {
    cursor: pointer;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
button:focus-visible,
input:focus-visible {
    outline: 3px solid var(--rs-primary-pale);
    outline-offset: 3px;
}
.icon-button {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    padding: 0;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: white;
}
.icon-button:hover:not(:disabled) {
    background: var(--rs-canvas);
    color: var(--rs-primary);
}
.icon-button svg {
    width: 18px;
    height: 18px;
}
.close-button {
    margin-left: auto;
    border: 0;
}
.schedule-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-shrink: 0;
    padding: 18px 24px;
}
.month-navigation {
    display: flex;
    align-items: center;
    gap: 12px;
}
.month-navigation h3 {
    min-width: 150px;
    margin: 0;
    color: var(--rs-primary-deep);
    text-align: center;
    font-size: 16px;
    font-weight: 700;
}
.month-picker {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 11px;
}
.month-picker input {
    width: 175px;
    height: 38px;
    padding: 0 10px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    background: white;
    color: var(--rs-ink);
    font-size: 12px;
}
.schedule-content {
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 0 24px 24px;
    scrollbar-width: thin;
}
.schedule-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 20px;
    margin-bottom: 18px;
    color: var(--rs-text-soft);
    font-size: 11px;
}
.schedule-edit-hint {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    margin: 0 0 14px;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.7;
}
.schedule-edit-hint svg {
    width: 14px;
    height: 14px;
    margin-top: 3px;
    flex-shrink: 0;
    color: var(--rs-primary);
}
.schedule-success {
    margin: 0 0 14px;
    padding: 11px 13px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 8px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-size: 12px;
    line-height: 1.7;
}
.schedule-summary > span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.schedule-summary strong {
    font-weight: 700;
}
.schedule-summary i {
    width: 7px;
    height: 7px;
    margin-right: 2px;
    border-radius: 50%;
}
.scheduled-dot {
    background: var(--rs-primary);
}
.default-dot {
    background: #9aa5a7;
}
.off-dot {
    background: #bf8a32;
}
.schedule-note {
    margin: 0 0 16px;
    padding: 12px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: var(--rs-canvas);
    font-size: 12px;
    line-height: 1.7;
}
.calendar-weekdays,
.schedule-calendar {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 8px;
}
.calendar-weekdays {
    margin-bottom: 8px;
    color: var(--rs-muted);
    text-align: center;
    font-size: 11px;
    font-weight: 650;
}
.calendar-weekdays span {
    padding: 7px;
}
.calendar-day {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    min-width: 0;
    min-height: 135px;
    padding: 12px 10px;
    border: 1px dashed var(--rs-primary-border);
    border-radius: 9px;
    background: var(--rs-canvas);
    color: inherit;
    font: inherit;
    text-align: left;
}
button.calendar-day:hover:not(:disabled) {
    border-color: var(--rs-primary);
    box-shadow: 0 0 0 1px var(--rs-primary);
}
button.calendar-day:disabled {
    opacity: 1;
}
.calendar-day.selected {
    outline: 2px solid var(--rs-primary);
    outline-offset: 1px;
}
.calendar-day.scheduled {
    border: 1px solid var(--rs-primary-border);
    background: #eff8f4;
}
.calendar-day.day-off {
    border-color: #e9d6b2;
    background: #fff8eb;
}
.calendar-day.today {
    outline: 2px solid var(--rs-primary);
    outline-offset: 1px;
}
.day-number {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 700;
}
.day-number time {
    font-variant-numeric: tabular-nums;
}
.today-label {
    color: var(--rs-primary);
    font-size: 9px;
    font-weight: 600;
}
.mobile-weekday {
    display: none;
}
.day-shift {
    display: grid;
    gap: 5px;
    min-width: 0;
}
.day-shift strong {
    color: var(--rs-primary-deep);
    font-size: 12px;
    font-weight: 700;
    line-height: 1.5;
    overflow-wrap: anywhere;
}
.shift-time {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--rs-text-soft);
    font-size: 10px;
    line-height: 1.5;
}
.shift-time svg {
    width: 11px;
    height: 11px;
    flex-shrink: 0;
}
.holiday-reason {
    color: #8e682d;
    font-size: 10px;
    line-height: 1.5;
}
.day-source {
    display: grid;
    gap: 4px;
    margin-top: auto;
    color: var(--rs-muted);
    font-size: 9px;
}
.day-locked {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--rs-muted);
    font-size: 9px;
}
.day-locked svg {
    width: 11px;
    height: 11px;
}
.scheduled .day-source {
    color: var(--rs-primary);
}
.day-off .day-source {
    color: #8e682d;
}
.schedule-state {
    display: grid;
    justify-items: center;
    align-content: center;
    gap: 14px;
    min-height: 280px;
    padding: 32px;
    color: var(--rs-muted);
    text-align: center;
}
.schedule-state > svg {
    width: 28px;
    height: 28px;
    color: var(--rs-primary);
}
.schedule-state p {
    margin: 0;
    font-size: 13px;
    line-height: 1.7;
}
.error-state > svg {
    color: #b23d50;
}
.retry-button,
.done-button {
    min-height: 40px;
    padding: 9px 16px;
    border: 1px solid var(--rs-primary);
    border-radius: 8px;
    color: white;
    background: var(--rs-primary);
    font-size: 12px;
    font-weight: 650;
}
.retry-button:hover,
.done-button:hover {
    background: var(--rs-primary-hover);
}
.schedule-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    flex-shrink: 0;
    padding: 16px 24px;
    border-top: 1px solid var(--rs-border);
}
.schedule-footer p {
    max-width: 750px;
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.8;
}
.schedule-footer strong {
    font-weight: 700;
}
.schedule-editor {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: minmax(180px, 1fr) minmax(180px, 260px) auto;
    align-items: center;
    gap: 12px 18px;
    flex-shrink: 0;
    padding: 16px 24px;
    border-top: 1px solid var(--rs-primary-border);
    background: var(--rs-canvas);
}
.editor-heading {
    display: grid;
    gap: 5px;
}
.editor-heading > span {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--rs-primary);
    font-size: 11px;
    font-weight: 650;
}
.editor-heading svg {
    width: 13px;
    height: 13px;
}
.editor-heading strong {
    color: var(--rs-ink);
    font-size: 12px;
    font-weight: 700;
    line-height: 1.6;
}
.editor-heading small {
    color: var(--rs-muted);
    font-size: 11px;
}
.editor-field {
    display: grid;
    gap: 7px;
    min-width: 0;
}
.editor-field > span {
    color: var(--rs-text-soft);
    font-size: 11px;
    font-weight: 650;
}
.editor-field :deep(.searchable-select-trigger),
.editor-field :deep(.searchable-select-native) {
    height: 40px;
    font-size: 12px;
}
.editor-field :deep(.searchable-select-panel) {
    top: auto;
    bottom: calc(100% + 7px);
}
.editor-field :deep(.searchable-select-options) {
    max-height: min(210px, 30dvh);
}
.editor-actions {
    display: flex;
    align-items: center;
    align-self: end;
    gap: 8px;
}
.editor-actions .done-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}
.editor-actions .done-button svg {
    width: 16px;
    height: 16px;
}
.cancel-edit {
    min-height: 40px;
    padding: 9px 13px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: white;
    font-size: 12px;
    font-weight: 650;
}
.cancel-edit:hover:not(:disabled) {
    background: var(--rs-surface-muted);
}
.editor-note {
    grid-column: 1 / -1;
    margin: 0;
    color: var(--rs-muted);
    font-size: 10px;
    line-height: 1.7;
}
.editor-error {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    grid-column: 1 / -1;
    margin: 0;
    padding: 10px 12px;
    border: 1px solid #f1ccd2;
    border-radius: 8px;
    color: #a52c3e;
    background: #fff4f5;
    font-size: 12px;
    line-height: 1.7;
}
.editor-error svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin-top: 2px;
}
@media (max-width: 850px) {
    .schedule-editor {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .editor-heading {
        grid-column: 1 / -1;
    }
}
.spinner {
    animation: schedule-spin 0.75s linear infinite;
}
@keyframes schedule-spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 800px) {
    .calendar-day {
        min-height: 125px;
        padding: 10px 8px;
    }
    .calendar-weekdays,
    .schedule-calendar {
        gap: 6px;
    }
    .shift-time svg {
        display: none;
    }
    .schedule-header,
    .schedule-toolbar,
    .schedule-footer {
        padding-inline: 18px;
    }
    .schedule-content {
        padding-inline: 18px;
    }
}
@media (max-width: 640px) {
    .schedule-editor {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 14px 18px;
    }
    .editor-actions {
        justify-content: flex-end;
    }
    .schedule-position {
        padding: 12px;
    }
    .schedule-panel {
        max-height: calc(100dvh - 24px);
        border-radius: 14px;
    }
    .schedule-header {
        align-items: flex-start;
        padding-block: 18px;
    }
    .schedule-header h2 {
        font-size: 17px;
    }
    .schedule-header p {
        font-size: 11px;
    }
    .schedule-icon {
        display: none;
    }
    .schedule-toolbar {
        flex-wrap: wrap;
        padding-block: 16px;
        gap: 14px;
    }
    .month-navigation,
    .month-picker {
        width: 100%;
        justify-content: space-between;
    }
    .month-navigation h3 {
        font-size: 15px;
    }
    .month-picker input {
        flex: 1;
        max-width: 200px;
    }
    .schedule-summary {
        gap: 10px 14px;
        font-size: 10px;
    }
    .calendar-weekdays,
    .calendar-blank {
        display: none;
    }
    .schedule-calendar {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .calendar-day {
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr) auto;
        align-items: center;
        min-height: 76px;
        gap: 12px;
        padding: 12px;
    }
    .day-number {
        flex-direction: column;
        gap: 3px;
        font-size: 17px;
    }
    .mobile-weekday {
        display: block;
        font-size: 10px;
        font-weight: 500;
        color: var(--rs-muted);
    }
    .today-label {
        display: none;
    }
    .day-source {
        margin: 0;
    }
    .shift-time {
        font-size: 11px;
    }
    .schedule-footer {
        align-items: flex-end;
        gap: 14px;
        padding-block: 14px;
    }
    .schedule-footer p {
        font-size: 10px;
    }
    .done-button {
        padding-inline: 12px;
    }
}
</style>
