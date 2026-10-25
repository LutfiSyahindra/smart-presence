<script setup lang="ts">
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowRightLeft,
    CalendarDays,
    Check,
    CheckCheck,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Info,
    LoaderCircle,
    Plus,
    Send,
    ShieldCheck,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import CutiSheet from './components/CutiSheet.vue';
import MobileBackLink from './components/MobileBackLink.vue';

type Shift = { id: number; name: string; start_time: string; end_time: string; next_day: boolean };
type Status = '' | 'pending' | 'approved' | 'rejected' | 'cancelled';
type Approval = { level: number; name: string; status: string; note?: string | null };
type ShiftRequest = {
    id: number;
    name: string;
    can_cancel: boolean;
    can_decide: boolean;
    cancelled_at: string | null;
    date: string;
    status: Status;
    original_shift: Shift;
    requested_shift: Shift;
    reason: string;
    created_at: string;
    current_level: number | null;
    approvals: Approval[];
};
const props = defineProps<{
    scope: 'mine' | 'delegation';
    allPeriods: boolean;
    approvalCount: number;
    initialRequest: ShiftRequest | null;
    month: string;
    today: string;
    status: Status;
    summary: { count: number; pending: number };
    requests: {
        data: ShiftRequest[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    employee: { name: string; position: string | null; unit: string | null } | null;
    currentShift: Shift | null;
    shifts: Shift[];
    approvalPreview: Pick<Approval, 'level' | 'name'>[];
    unavailableReason: string | null;
}>();
const page = usePage<SharedData>();
const sheet = ref<'month' | 'filter' | 'create' | 'discard' | null>(null);
const selected = ref<ShiftRequest | null>(props.initialRequest ?? null);
const confirmingCancel = ref(false);
const cancellation = useForm({});
const decision = useForm({ decision: 'approved', note: '' });
const detailBusy = computed(() => cancellation.processing || decision.processing);
const cancellationError = computed(() => (cancellation.errors as Record<string, string | undefined>).cancel);
const content = ref<HTMLElement | null>(null);
const pickerYear = ref(Number(props.month.slice(0, 4)));
const draftStatus = ref<Status>(props.status);
const loading = ref(false);
const error = ref('');
const form = useForm({ tanggal_perubahan: props.today, original_shift_id: props.currentShift?.id ?? null, requested_shift_id: '', alasan: '' });
const submissionError = computed(() => (form.errors as Record<string, string | undefined>).pengajuan);
const statuses: { value: Status; label: string; description: string }[] = [
    { value: '', label: 'Semua', description: 'Seluruh pengajuan pada periode pilihan' },
    { value: 'pending', label: 'Menunggu', description: 'Masih menunggu persetujuan' },
    { value: 'approved', label: 'Disetujui', description: 'Pengajuan yang telah disetujui' },
    { value: 'rejected', label: 'Ditolak', description: 'Pengajuan yang tidak disetujui' },
    { value: 'cancelled', label: 'Dibatalkan', description: 'Pengajuan yang telah dibatalkan' },
];
const months = Array.from({ length: 12 }, (_, index) => ({
    value: String(index + 1).padStart(2, '0'),
    label: new Intl.DateTimeFormat('id-ID', { month: 'short' }).format(new Date(2026, index, 1)),
}));
const monthLabel = computed(() =>
    props.allPeriods
        ? 'Semua periode'
        : new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${props.month}-01T12:00:00`)),
);
const isCurrentMonth = computed(() => !props.allPeriods && props.month === props.today.slice(0, 7));
const targetShifts = computed(() => props.shifts.filter((shift) => shift.id !== props.currentShift?.id));
const targetShift = computed(() => targetShifts.value.find((shift) => shift.id === Number(form.requested_shift_id)));
const ready = computed(
    () => !props.unavailableReason && props.currentShift && targetShift.value && form.tanggal_perubahan && form.alasan.trim() && !form.processing,
);
const statusLabel = (status: Status) => statuses.find((item) => item.value === status)?.label ?? status;
const dateLabel = (date: string) =>
    new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00`));
const timeLabel = (shift: Shift) => `${shift.start_time} – ${shift.end_time}${shift.next_day ? ' (+1 hari)' : ''}`;
const approvalLabel = (status: string) =>
    ({ pending: 'Menunggu persetujuan', waiting: 'Tahap berikutnya', approved: 'Disetujui', rejected: 'Ditolak', skipped: 'Dilewati' })[status] ??
    status;

function applyFilters(changes: { month?: string; status?: Status; scope?: 'mine' | 'delegation' } = {}) {
    if (loading.value) return;
    sheet.value = null;
    loading.value = true;
    error.value = '';
    router.get(
        route('absensi.mobile.perubahan-shift.index'),
        { month: props.allPeriods ? undefined : props.month, status: props.status, scope: props.scope, ...changes },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => content.value?.scrollTo({ top: 0 }),
            onError: () => {
                error.value = 'Pengajuan belum dapat dimuat. Silakan coba kembali.';
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function switchScope(scope: 'mine' | 'delegation') {
    applyFilters({ scope, status: '', month: scope === 'mine' ? props.today.slice(0, 7) : undefined });
}
function openDetail(item: ShiftRequest) {
    cancellation.clearErrors();
    decision.reset();
    decision.clearErrors();
    confirmingCancel.value = false;
    selected.value = item;
}
function closeDetail() {
    if (detailBusy.value) return;
    if (confirmingCancel.value) {
        confirmingCancel.value = false;
        return;
    }
    selected.value = null;
}
function cancelRequest() {
    if (!selected.value?.can_cancel || cancellation.processing) return;
    cancellation.post(route('absensi.mobile.perubahan-shift.cancel', { perubahanShiftRequest: selected.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            confirmingCancel.value = false;
            selected.value = null;
        },
    });
}
function decide(value: 'approved' | 'rejected') {
    if (!selected.value?.can_decide || detailBusy.value) return;
    decision.clearErrors();
    if (value === 'rejected' && !decision.note.trim()) {
        decision.setError('note', 'Alasan penolakan wajib diisi.');
        return;
    }
    decision.decision = value;
    decision
        .transform((data) => ({ ...data, note: data.note.trim() }))
        .post(route('absensi.mobile.perubahan-shift.decide', { perubahanShiftRequest: selected.value.id }), {
            preserveScroll: true,
            onSuccess: () => {
                decision.reset();
                selected.value = null;
            },
        });
}
function openMonth() {
    pickerYear.value = Number(props.month.slice(0, 4));
    sheet.value = 'month';
}
function openFilter() {
    draftStatus.value = props.status;
    sheet.value = 'filter';
}
function openForm() {
    form.defaults({ tanggal_perubahan: props.today, original_shift_id: props.currentShift?.id ?? null, requested_shift_id: '', alasan: '' });
    form.reset();
    form.clearErrors();
    sheet.value = 'create';
}
function closeSheet() {
    if (form.processing) return;
    if (sheet.value === 'discard') {
        sheet.value = 'create';
        return;
    }
    if (sheet.value === 'create' && form.isDirty) {
        sheet.value = 'discard';
        return;
    }
    sheet.value = null;
}
function discard() {
    form.reset();
    form.clearErrors();
    sheet.value = null;
}
function submit() {
    if (!ready.value) return;
    form.transform((data) => ({ ...data, alasan: data.alasan.trim() })).post(route('absensi.mobile.perubahan-shift.store'), {
        preserveScroll: true,
        onSuccess: () => {
            sheet.value = null;
            form.reset();
            content.value?.scrollTo({ top: 0 });
        },
        onError: () => {
            sheet.value = 'create';
        },
    });
}
let removeBackHandler: (() => void) | undefined;
onMounted(() => {
    removeBackHandler = registerMobileBackHandler(() => {
        if (selected.value) {
            closeDetail();
            return true;
        }
        if (!sheet.value) return false;
        closeSheet();
        return true;
    });
});
onUnmounted(() => removeBackHandler?.());
watch(
    () => props.initialRequest,
    (item) => {
        if (item) openDetail(item);
    },
);
</script>

<template>
    <Head title="Perubahan Shift" />
    <main class="shift-workspace">
        <div class="shift-shell">
            <header class="shift-header">
                <MobileBackLink :href="route('absensi.mobile.home')" class="icon-button" aria-label="Kembali ke beranda"
                    ><ArrowLeft
                /></MobileBackLink>
                <div class="header-copy">
                    <p>RSARSY PRESENCE</p>
                    <h1>Perubahan shift</h1>
                </div>
                <span class="header-mark" aria-hidden="true"><ArrowRightLeft /></span>
            </header>

            <nav v-if="scope === 'delegation' || approvalCount" class="scope-tabs" aria-label="Daftar perubahan shift">
                <button
                    type="button"
                    :class="{ active: scope === 'mine' }"
                    :aria-pressed="scope === 'mine'"
                    :disabled="loading"
                    @click="switchScope('mine')"
                >
                    Pengajuan saya
                </button>
                <button
                    type="button"
                    :class="{ active: scope === 'delegation' }"
                    :aria-pressed="scope === 'delegation'"
                    :disabled="loading"
                    @click="switchScope('delegation')"
                >
                    Untuk ditinjau<span v-if="approvalCount">{{ approvalCount }}</span>
                </button>
            </nav>

            <div ref="content" class="shift-content" tabindex="0" aria-label="Daftar pengajuan perubahan shift" :aria-busy="loading" scroll-region>
                <p v-if="page.props.flash.success" class="notice success-notice" role="status"><CheckCheck />{{ page.props.flash.success }}</p>
                <section class="shift-hero" aria-labelledby="shift-hero-title">
                    <div class="hero-orbit" aria-hidden="true"></div>
                    <div class="hero-copy">
                        <span class="eyebrow">JADWAL KERJA ANDA</span>
                        <h2 id="shift-hero-title">Beri ruang untuk<br />rencana baru<span>.</span></h2>
                        <p>Ajukan perubahan. Pantau dalam genggaman.</p>
                    </div>
                    <span class="hero-symbol" aria-hidden="true"><ArrowRightLeft /></span>
                    <div v-if="summary.count" class="hero-summary">
                        <span
                            ><strong>{{ summary.count }}</strong> Pengajuan</span
                        ><i></i
                        ><span
                            ><span class="gold-dot"></span><strong>{{ summary.pending }}</strong> Menunggu</span
                        >
                    </div>
                </section>

                <div class="period-toolbar">
                    <button type="button" class="period-picker" :disabled="loading" aria-haspopup="dialog" @click="openMonth">
                        <span class="period-icon"><CalendarDays /></span
                        ><span
                            ><small>PERIODE PENGAJUAN</small><strong>{{ monthLabel }}</strong></span
                        ><ChevronDown />
                    </button>
                    <button
                        type="button"
                        class="icon-button filter-button"
                        :class="{ 'is-active': status }"
                        :disabled="loading"
                        aria-label="Filter status pengajuan"
                        aria-haspopup="dialog"
                        @click="openFilter"
                    >
                        <SlidersHorizontal /><i v-if="status"></i>
                    </button>
                </div>
                <div class="list-heading">
                    <h2>{{ scope === 'delegation' ? 'Untuk ditinjau' : 'Pengajuan saya' }}</h2>
                    <span>{{ allPeriods ? 'Seluruh periode' : isCurrentMonth ? 'Bulan ini' : 'Arsip pengajuan' }}</span>
                </div>
                <div v-if="status" class="active-filter">
                    <span>Filter aktif</span>
                    <button
                        type="button"
                        :disabled="loading"
                        :aria-label="`Hapus filter ${statusLabel(status)}`"
                        @click="applyFilters({ status: '' })"
                    >
                        {{ statusLabel(status) }}<X />
                    </button>
                </div>
                <p v-if="error" class="notice error-notice" role="alert">{{ error }}</p>
                <div v-if="loading" class="loading-notice" role="status"><LoaderCircle />Memuat pengajuan…</div>

                <section v-if="!requests.data.length" class="empty-card" aria-labelledby="empty-title" aria-live="polite">
                    <div class="empty-art" aria-hidden="true">
                        <svg viewBox="0 0 240 190" fill="none">
                            <defs>
                                <linearGradient id="shift-paper" x1="69" y1="34" x2="169" y2="152" gradientUnits="userSpaceOnUse">
                                    <stop stop-color="#fff" />
                                    <stop offset="1" stop-color="#f5f4ed" />
                                </linearGradient>
                                <linearGradient id="shift-green" x1="54" y1="47" x2="137" y2="148" gradientUnits="userSpaceOnUse">
                                    <stop stop-color="#428878" />
                                    <stop offset="1" stop-color="#1e5447" />
                                </linearGradient>
                                <linearGradient id="shift-gold" x1="155" y1="101" x2="205" y2="156" gradientUnits="userSpaceOnUse">
                                    <stop stop-color="#f2d9a7" />
                                    <stop offset="1" stop-color="#c59a55" />
                                </linearGradient>
                                <filter id="shift-shadow" x="20" y="10" width="215" height="180" filterUnits="userSpaceOnUse">
                                    <feDropShadow dx="0" dy="7" stdDeviation="6" flood-color="#244d3d" flood-opacity=".13" />
                                </filter>
                            </defs>
                            <circle cx="120" cy="92" r="77" fill="#eef3ec" />
                            <circle cx="120" cy="92" r="62" stroke="#d9e4d7" stroke-dasharray="2 6" />
                            <ellipse cx="125" cy="170" rx="74" ry="6" fill="#e4eadd" />
                            <g filter="url(#shift-shadow)">
                                <rect x="43" y="50" width="112" height="113" rx="17" transform="rotate(-10 43 50)" fill="url(#shift-green)" />
                                <rect
                                    x="72"
                                    y="31"
                                    width="111"
                                    height="125"
                                    rx="16"
                                    transform="rotate(6 72 31)"
                                    fill="url(#shift-paper)"
                                    stroke="#dce4d5"
                                />
                                <path d="m72 62 108 11" stroke="#e2e8dd" />
                                <path d="m97 29-1 17m58-11-2 17" stroke="#749787" stroke-width="6" stroke-linecap="round" />
                                <rect x="88" y="83" width="17" height="15" rx="4" transform="rotate(6 88 83)" fill="#dce8da" />
                                <rect x="114" y="86" width="17" height="15" rx="4" transform="rotate(6 114 86)" fill="#dce8da" />
                                <rect x="140" y="89" width="17" height="15" rx="4" transform="rotate(6 140 89)" fill="#cfaf75" />
                                <rect x="85" y="108" width="17" height="15" rx="4" transform="rotate(6 85 108)" fill="#dce8da" />
                                <rect x="111" y="111" width="17" height="15" rx="4" transform="rotate(6 111 111)" fill="#dce8da" />
                                <circle cx="171" cy="136" r="33" fill="url(#shift-gold)" stroke="#fff9ed" stroke-width="4" />
                                <path
                                    d="M155 129h29m-7-7 7 7-7 7m7 7h-29m7-7-7 7 7 7"
                                    stroke="#634a24"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </g>
                            <path d="M44 94v10m-5-5h10M201 58v8m-4-4h8" stroke="#c4ab78" stroke-width="2" stroke-linecap="round" />
                            <circle cx="49" cy="140" r="3" fill="#9cb5a1" />
                            <circle cx="192" cy="86" r="2" fill="#b8cbb5" />
                        </svg>
                    </div>
                    <span class="empty-eyebrow">{{
                        status ? statusLabel(status) : scope === 'delegation' ? 'SEMUA SUDAH DITINJAU' : 'AWAL DARI RENCANA BARU'
                    }}</span>
                    <h2 id="empty-title">
                        {{ status ? 'Belum ada yang sesuai' : scope === 'delegation' ? 'Belum ada untuk ditinjau' : 'Belum ada pengajuan' }}
                    </h2>
                    <p>
                        {{
                            status
                                ? `Tidak ada pengajuan berstatus ${statusLabel(status).toLowerCase()} pada ${monthLabel}.`
                                : scope === 'delegation'
                                  ? 'Pengajuan perubahan shift yang menunggu giliran Anda akan tampil di sini.'
                                  : 'Pengajuan perubahan shift Anda akan muncul di sini. Mulai saat Anda membutuhkannya.'
                        }}
                    </p>
                    <button v-if="status" type="button" class="text-button" @click="applyFilters({ status: '' })">
                        Tampilkan semua status <ArrowRight />
                    </button>
                    <div v-else-if="scope !== 'delegation'" class="empty-note">
                        <ShieldCheck /><span>Jadwal aktif Anda tetap berlaku<br />selama pengajuan menunggu.</span>
                    </div>
                </section>
                <div v-else class="request-list" aria-live="polite">
                    <button
                        v-for="item in requests.data"
                        :key="item.id"
                        type="button"
                        class="request-card"
                        :aria-label="`Lihat pengajuan tanggal ${dateLabel(item.date)}`"
                        @click="openDetail(item)"
                    >
                        <span class="request-top"
                            ><span><CalendarDays />{{ dateLabel(item.date) }}</span
                            ><span class="status-badge" :class="`status-${item.status}`">{{ statusLabel(item.status) }}</span></span
                        >
                        <strong v-if="scope === 'delegation'" class="request-employee">{{ item.name }}</strong>
                        <span class="shift-comparison"
                            ><span
                                ><small>SHIFT SAAT INI</small><strong>{{ item.original_shift.name }}</strong
                                ><span>{{ timeLabel(item.original_shift) }}</span></span
                            ><ArrowRight /><span
                                ><small>SHIFT TUJUAN</small><strong>{{ item.requested_shift.name }}</strong
                                ><span>{{ timeLabel(item.requested_shift) }}</span></span
                            ></span
                        >
                        <span class="request-reason">{{ item.reason }}</span
                        ><span class="request-bottom"
                            >{{ item.status === 'pending' ? `Menunggu persetujuan tahap ${item.current_level}` : 'Lihat detail pengajuan'
                            }}<ChevronRight
                        /></span>
                    </button>
                </div>
                <nav v-if="requests.last_page > 1" class="pagination" aria-label="Halaman pengajuan">
                    <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" preserve-scroll><ChevronLeft />Sebelumnya</Link
                    ><span>{{ requests.current_page }} / {{ requests.last_page }}</span
                    ><Link v-if="requests.next_page_url" :href="requests.next_page_url" preserve-scroll>Berikutnya<ChevronRight /></Link>
                </nav>
                <p class="period-caption"><Clock3 />Tanggal dan waktu mengikuti WIB.</p>
            </div>

            <footer v-if="scope !== 'delegation'" class="shift-action-bar">
                <div>
                    <span>RENCANAKAN LEBIH BAIK</span>
                    <p>Perlu menyesuaikan shift?</p>
                </div>
                <button type="button" class="apply-button" aria-label="Ajukan perubahan shift" aria-haspopup="dialog" @click="openForm">
                    <span><Plus /></span>Ajukan<ArrowRight />
                </button>
            </footer>
        </div>
    </main>

    <CutiSheet :open="sheet === 'month'" title="Pilih periode" description="Lihat pengajuan berdasarkan tanggal perubahan shift." @close="closeSheet">
        <div class="year-picker">
            <button type="button" class="icon-button" :disabled="pickerYear <= 1900" aria-label="Tahun sebelumnya" @click="pickerYear--">
                <ChevronLeft /></button
            ><strong>{{ pickerYear }}</strong
            ><button type="button" class="icon-button" :disabled="pickerYear >= 2100" aria-label="Tahun berikutnya" @click="pickerYear++">
                <ChevronRight />
            </button>
        </div>
        <div class="month-grid">
            <button
                v-for="item in months"
                :key="item.value"
                type="button"
                :class="{ selected: month === `${pickerYear}-${item.value}` }"
                :aria-pressed="month === `${pickerYear}-${item.value}`"
                @click="applyFilters({ month: `${pickerYear}-${item.value}` })"
            >
                {{ item.label }}<i v-if="today.startsWith(`${pickerYear}-${item.value}`)"></i>
            </button>
        </div>
        <button type="button" class="outline-button full-width" @click="applyFilters({ month: today.slice(0, 7) })">
            <CalendarDays />Kembali ke bulan ini
        </button>
        <button v-if="scope === 'delegation'" type="button" class="text-button full-width" @click="applyFilters({ month: undefined })">
            Tampilkan semua periode<ArrowRight />
        </button>
    </CutiSheet>
    <CutiSheet :open="sheet === 'filter'" title="Filter pengajuan" description="Pilih status yang ingin Anda tampilkan." @close="closeSheet">
        <div class="filter-options">
            <button
                v-for="item in statuses"
                :key="item.value"
                type="button"
                :class="{ selected: draftStatus === item.value }"
                :aria-pressed="draftStatus === item.value"
                @click="draftStatus = item.value"
            >
                <span
                    ><strong>{{ item.value ? item.label : 'Semua status' }}</strong
                    ><small>{{ item.description }}</small></span
                ><span class="filter-check"><Check v-if="draftStatus === item.value" /></span>
            </button>
        </div>
        <template #footer
            ><div class="sheet-actions">
                <button type="button" class="outline-button" @click="draftStatus = ''"><X />Reset</button
                ><button type="button" class="primary-button" @click="applyFilters({ status: draftStatus })">Terapkan filter<Check /></button></div
        ></template>
    </CutiSheet>
    <CutiSheet
        :open="sheet === 'create'"
        title="Ajukan perubahan shift"
        description="Satu penyesuaian untuk satu tanggal kerja."
        :busy="form.processing"
        @close="closeSheet"
    >
        <div v-if="unavailableReason || !targetShifts.length" class="unavailable-note" role="status">
            <Info />
            <div>
                <strong>Pengajuan belum dapat dibuat</strong>
                <p>{{ unavailableReason || 'Belum ada shift tujuan lain yang tersedia. Hubungi administrator.' }}</p>
            </div>
        </div>
        <form v-else id="shift-request-form" class="shift-form" @submit.prevent="submit">
            <div class="employee-card">
                <span><ArrowRightLeft /></span>
                <div>
                    <strong>{{ employee?.name }}</strong
                    ><small>{{ employee?.position }} · {{ employee?.unit }}</small>
                </div>
            </div>
            <p v-if="submissionError" class="notice error-notice" role="alert">{{ submissionError }}</p>
            <div class="form-section-title">
                <span>01</span>
                <h3>Rencana perubahan</h3>
            </div>
            <div class="form-field">
                <label for="shift-date">Tanggal perubahan <span>*</span></label
                ><input
                    id="shift-date"
                    v-model="form.tanggal_perubahan"
                    type="date"
                    :min="today"
                    max="2100-12-31"
                    required
                    :disabled="form.processing"
                    :aria-invalid="Boolean(form.errors.tanggal_perubahan)"
                    aria-describedby="shift-date-help shift-date-error"
                /><small id="shift-date-help">Tanggal mengacu pada hari dimulainya shift.</small>
                <p v-if="form.errors.tanggal_perubahan" id="shift-date-error" class="field-error" role="alert">{{ form.errors.tanggal_perubahan }}</p>
            </div>
            <div v-if="currentShift" class="current-shift">
                <small>SHIFT SAAT INI</small><strong>{{ currentShift.name }}</strong
                ><span><Clock3 />{{ timeLabel(currentShift) }}</span>
            </div>
            <p v-if="form.errors.original_shift_id" class="field-error" role="alert">{{ form.errors.original_shift_id }}</p>
            <span class="change-direction" aria-hidden="true"><ArrowDown /></span>
            <div class="form-field">
                <label for="target-shift">Shift tujuan <span>*</span></label>
                <div class="select-wrap">
                    <select
                        id="target-shift"
                        v-model="form.requested_shift_id"
                        required
                        :disabled="form.processing"
                        :aria-invalid="Boolean(form.errors.requested_shift_id)"
                        aria-describedby="target-shift-error"
                    >
                        <option disabled value="">Pilih shift pengganti</option>
                        <option v-for="shift in targetShifts" :key="shift.id" :value="shift.id">
                            {{ shift.name }} · {{ timeLabel(shift) }}
                        </option></select
                    ><ChevronDown />
                </div>
                <p v-if="form.errors.requested_shift_id" id="target-shift-error" class="field-error" role="alert">
                    {{ form.errors.requested_shift_id }}
                </p>
            </div>
            <div v-if="targetShift" class="target-preview">
                <Check /><span
                    ><strong>{{ targetShift.name }}</strong
                    ><small>{{ timeLabel(targetShift) }}</small></span
                >
            </div>
            <div class="form-section-title">
                <span>02</span>
                <h3>Alasan pengajuan</h3>
            </div>
            <div class="form-field">
                <label for="shift-reason">Alasan perubahan <span>*</span></label
                ><textarea
                    id="shift-reason"
                    v-model="form.alasan"
                    rows="4"
                    maxlength="200"
                    placeholder="Ceritakan alasan Anda menyesuaikan shift…"
                    required
                    :disabled="form.processing"
                    :aria-invalid="Boolean(form.errors.alasan)"
                    aria-describedby="shift-reason-error"
                />
                <div class="field-caption">
                    <small>Singkat, jelas, dan sesuai kebutuhan Anda.</small><span>{{ form.alasan.length }}/200</span>
                </div>
                <p v-if="form.errors.alasan" id="shift-reason-error" class="field-error" role="alert">{{ form.errors.alasan }}</p>
            </div>
            <div class="approval-preview">
                <div class="approval-heading"><ShieldCheck /><strong>Alur persetujuan</strong></div>
                <ol>
                    <li v-for="step in approvalPreview" :key="step.level">
                        <span>{{ step.level }}</span>
                        <div>
                            <strong>{{ step.name }}</strong
                            ><small>Persetujuan tahap {{ step.level }}</small>
                        </div>
                    </li>
                </ol>
            </div>
            <p class="form-note">
                <Info />Pengajuan akan disimpan dengan status menunggu. Pengiriman pengajuan tidak langsung mengubah jadwal aktif Anda.
            </p>
        </form>
        <template #footer
            ><button
                v-if="!unavailableReason && targetShifts.length"
                type="submit"
                form="shift-request-form"
                class="primary-button full-width"
                :disabled="!ready"
            >
                <LoaderCircle v-if="form.processing" class="spin" /><Send v-else />{{
                    form.processing ? 'Menyimpan pengajuan…' : 'Kirim pengajuan'
                }}</button
            ><button v-else type="button" class="outline-button full-width" @click="closeSheet">Mengerti</button></template
        >
    </CutiSheet>
    <CutiSheet
        :open="sheet === 'discard'"
        title="Tinggalkan pengajuan?"
        description="Isian Anda belum disimpan. Anda dapat melanjutkan mengisi atau menghapusnya."
        @close="closeSheet"
        ><div class="discard-actions">
            <button type="button" class="primary-button full-width" @click="sheet = 'create'">Lanjutkan mengisi</button
            ><button type="button" class="outline-button full-width" @click="discard">Hapus isian</button>
        </div></CutiSheet
    >
    <CutiSheet
        :open="Boolean(selected) && !confirmingCancel"
        title="Detail perubahan shift"
        :description="selected ? `Pengajuan #${selected.id} · ${dateLabel(selected.date)}` : ''"
        :busy="detailBusy"
        @close="closeDetail"
    >
        <template v-if="selected"
            ><span class="status-badge" :class="`status-${selected.status}`">{{ statusLabel(selected.status) }}</span>
            <p class="request-employee">{{ selected.name }}</p>
            <div class="detail-shifts">
                <div>
                    <small>SHIFT SAAT INI</small><strong>{{ selected.original_shift.name }}</strong
                    ><span>{{ timeLabel(selected.original_shift) }}</span>
                </div>
                <ArrowDown />
                <div>
                    <small>SHIFT TUJUAN</small><strong>{{ selected.requested_shift.name }}</strong
                    ><span>{{ timeLabel(selected.requested_shift) }}</span>
                </div>
            </div>
            <div class="detail-reason">
                <h3>Alasan perubahan</h3>
                <p>{{ selected.reason }}</p>
            </div>
            <div class="approval-preview">
                <div class="approval-heading"><ShieldCheck /><strong>Alur persetujuan</strong></div>
                <ol>
                    <li v-for="step in selected.approvals" :key="step.level">
                        <span>{{ step.level }}</span>
                        <div>
                            <strong>{{ step.name }}</strong
                            ><small>{{ approvalLabel(step.status) }}</small>
                            <p v-if="step.note">{{ step.note }}</p>
                        </div>
                    </li>
                </ol>
            </div>
            <div v-if="selected.can_decide" class="decision-review">
                <div class="approval-heading">
                    <ShieldCheck /><strong>Persetujuan tahap {{ selected.current_level }}</strong>
                </div>
                <div class="form-field">
                    <label for="shift-decision-note">Catatan <small>(wajib untuk penolakan)</small></label
                    ><textarea
                        id="shift-decision-note"
                        v-model="decision.note"
                        rows="3"
                        maxlength="1000"
                        :disabled="detailBusy"
                        placeholder="Tambahkan catatan keputusan…"
                        :aria-invalid="Boolean(decision.errors.note)"
                    />
                    <p v-if="decision.errors.note" class="field-error" role="alert">{{ decision.errors.note }}</p>
                </div>
            </div>
            <p v-if="decision.errors.decision" class="notice error-notice" role="alert">{{ decision.errors.decision }}</p>
            <p v-if="selected.cancelled_at" class="form-note">
                <Info />Dibatalkan pada
                {{
                    new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(
                        new Date(selected.cancelled_at),
                    )
                }}
                WIB.
            </p>
            <p class="period-caption">
                Diajukan
                {{
                    new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(
                        new Date(selected.created_at),
                    )
                }}
                WIB
            </p></template
        >
        <template v-if="selected?.can_cancel || selected?.can_decide" #footer>
            <div v-if="selected.can_decide" class="sheet-actions">
                <button type="button" class="cancel-button" :disabled="detailBusy" @click="decide('rejected')"><X />Tolak</button
                ><button type="button" class="primary-button" :disabled="detailBusy" @click="decide('approved')">
                    <LoaderCircle v-if="decision.processing" class="spin" /><Check v-else />{{ decision.processing ? 'Memproses…' : 'Setujui' }}
                </button>
            </div>
            <button
                v-else
                type="button"
                class="cancel-button full-width"
                @click="
                    cancellation.clearErrors();
                    confirmingCancel = true;
                "
            >
                <X />Batalkan pengajuan
            </button></template
        >
    </CutiSheet>
    <CutiSheet
        :open="Boolean(selected) && confirmingCancel"
        title="Batalkan pengajuan?"
        description="Pengajuan akan dibatalkan dan approver akan diberi tahu. Jadwal aktif Anda tetap berlaku."
        :busy="cancellation.processing"
        @close="closeDetail"
    >
        <template v-if="selected"
            ><p class="cancel-summary">Pengajuan #{{ selected.id }} · {{ dateLabel(selected.date) }}</p>
            <div class="target-preview">
                <ArrowRightLeft /><span
                    ><strong>{{ selected.requested_shift.name }}</strong
                    ><small>{{ timeLabel(selected.requested_shift) }}</small></span
                >
            </div>
            <p v-if="cancellationError" class="notice error-notice" role="alert">{{ cancellationError }}</p></template
        >
        <template #footer
            ><div class="discard-actions">
                <button type="button" class="cancel-button full-width" :disabled="cancellation.processing" @click="cancelRequest">
                    <LoaderCircle v-if="cancellation.processing" class="spin" /><X v-else />{{
                        cancellation.processing ? 'Membatalkan…' : 'Ya, batalkan pengajuan'
                    }}</button
                ><button type="button" class="outline-button full-width" :disabled="cancellation.processing" @click="confirmingCancel = false">
                    Tetap ajukan
                </button>
            </div></template
        >
    </CutiSheet>
</template>

<style scoped>
.decision-review {
    margin-top: 24px;
    padding: 16px;
    border: 1px solid #d9e3ce;
    border-radius: 17px;
    background: #f3f6ec;
}
.decision-review .form-field {
    margin: 18px 0 0;
}
.scope-tabs {
    display: flex;
    flex-shrink: 0;
    gap: 8px;
    padding: 12px 22px;
    border-bottom: 1px solid #e4e9df;
    background: #fcfcf8;
}
.scope-tabs button {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
    gap: 7px;
    min-height: 44px;
    padding: 8px;
    border: 1px solid #e2e8db;
    border-radius: 13px;
    background: #f6f8f0;
    color: #738168;
    font-size: 11px;
    font-weight: 700;
}
.scope-tabs button.active {
    color: #fff;
    background: #355b40;
    border-color: #355b40;
}
.scope-tabs span {
    padding: 2px 6px;
    border-radius: 6px;
    background: #e4cc98;
    color: #5b562e;
    font-size: 9px;
}
.request-employee {
    display: block;
    margin: 15px 0 0;
    font-size: 13px;
    line-height: 1.8;
    font-weight: 750;
    overflow-wrap: anywhere;
}
.cancel-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 50px;
    padding: 12px 16px;
    border: 1px solid #ebd8cc;
    border-radius: 15px;
    background: #fcf0e9;
    color: #a35c45;
    font-size: 12px;
    font-weight: 750;
}
.cancel-button svg {
    width: 17px;
    height: 17px;
}
.cancel-summary {
    margin: 0 0 15px;
    color: #7b886b;
    font-size: 12px;
    line-height: 1.8;
}
.shift-workspace {
    background: radial-gradient(ellipse at top left, #dfe9df, transparent 65%), #eaf0e8;
    color: #233f36;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}
.shift-shell {
    position: relative;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 500px;
    height: 100vh;
    height: 100dvh;
    margin: 0 auto;
    overflow: hidden;
    background: #f6f7f2;
    box-shadow: 0 0 80px #243e3012;
}
.shift-header {
    display: grid;
    flex-shrink: 0;
    grid-template-columns: 44px minmax(0, 1fr) 44px;
    align-items: center;
    gap: 10px;
    padding: calc(16px + env(safe-area-inset-top, 0px)) 22px 17px;
    border-bottom: 1px solid #e4e9df;
    background: #fcfcf8;
    z-index: 20;
}
.header-copy {
    text-align: center;
    min-width: 0;
}
.header-copy p {
    margin: 0 0 5px;
    color: #8c774c;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.2em;
}
.header-copy h1 {
    margin: 0;
    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.055em;
    line-height: 1.4;
}
.icon-button,
.header-mark {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 15px;
    border: 1px solid #e0e6dc;
    background: #fff;
    color: #355747;
    text-decoration: none;
}
.icon-button svg,
.header-mark svg {
    width: 19px;
    height: 19px;
}
.header-mark {
    border-color: #e8deca;
    background: #f7f2e7;
    color: #947748;
}
.shift-content {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior-y: contain;
    -webkit-overflow-scrolling: touch;
    padding: 22px 22px 16px;
    scrollbar-width: thin;
    scrollbar-color: #cedbca transparent;
}
button {
    font: inherit;
    cursor: pointer;
    touch-action: manipulation;
}
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
.shift-content:focus-visible {
    outline: 3px solid #c6a76b;
    outline-offset: 3px;
}
button:disabled {
    cursor: default;
    opacity: 0.5;
}
.shift-hero {
    position: relative;
    overflow: hidden;
    padding: 23px 23px 0;
    border: 1px solid #40644e;
    border-radius: 25px;
    background: linear-gradient(120deg, #1f473b, #345a43);
    box-shadow: 0 10px 23px #254f3812;
    color: #fff;
}
.hero-copy {
    position: relative;
    z-index: 1;
}
.hero-copy:last-of-type {
    padding-bottom: 22px;
}
.eyebrow {
    color: #d7c192;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.17em;
}
.hero-copy h2 {
    margin: 11px 0 10px;
    font-size: 25px;
    font-weight: 700;
    line-height: 1.32;
    letter-spacing: -0.055em;
}
.hero-copy h2 span {
    color: #e4c58b;
}
.hero-copy p {
    max-width: 230px;
    margin: 0;
    color: #cbdccd;
    font-size: 10px;
    line-height: 1.8;
}
.hero-orbit {
    position: absolute;
    width: 180px;
    height: 180px;
    right: -100px;
    top: -40px;
    border: 1px solid #ffffff12;
    border-radius: 50%;
    box-shadow:
        0 0 0 24px #ffffff04,
        0 0 0 50px #ffffff03;
}
.hero-symbol {
    position: absolute;
    right: 21px;
    top: 39px;
    display: grid;
    place-items: center;
    width: 63px;
    height: 72px;
    transform: rotate(-9deg);
    border: 1px solid #c5cba54d;
    border-radius: 20px;
    background: linear-gradient(145deg, #ffffff16, #ffffff04);
    box-shadow: 0 8px 18px #153d2c26;
    color: #e5d3a9;
}
.hero-symbol svg {
    width: 31px;
    height: 31px;
    stroke-width: 1.4;
}
.hero-summary {
    position: relative;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-top: 21px;
    padding-block: 14px;
    border-top: 1px solid #ffffff1c;
}
.hero-summary > span {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #d4e0d2;
    font-size: 10px;
}
.hero-summary strong {
    color: #fff;
    font-size: 16px;
    font-weight: 750;
}
.hero-summary > i {
    height: 14px;
    width: 1px;
    background: #ffffff28;
}
.gold-dot {
    height: 5px;
    width: 5px;
    border-radius: 50%;
    background: #e7c58c;
}
.period-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 22px;
}
.period-picker {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
    min-height: 66px;
    padding: 10px 14px;
    border: 1px solid #dfe6d9;
    border-radius: 18px;
    background: #fff;
    color: #294b3b;
    text-align: left;
    box-shadow: 0 3px 10px #334f3012;
}
.period-icon {
    display: grid;
    place-items: center;
    width: 37px;
    height: 37px;
    border-radius: 12px;
    background: #f0f4eb;
    color: #6e8360;
    flex-shrink: 0;
}
.period-icon svg {
    width: 20px;
    height: 20px;
}
.period-picker > span:nth-child(2) {
    min-width: 0;
    flex: 1;
}
.period-picker small {
    display: block;
    margin-bottom: 5px;
    color: #7b8770;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 0.12em;
}
.period-picker strong {
    display: block;
    font-size: 14px;
    font-weight: 750;
}
.period-picker > svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: #819077;
}
.filter-button {
    position: relative;
    width: 50px;
    height: 66px;
    border-radius: 17px;
    border-color: #dfe6d9;
    box-shadow: 0 3px 10px #334f3008;
}
.filter-button.is-active {
    background: #e8efdf;
    border-color: #afc39f;
}
.filter-button i {
    position: absolute;
    top: 13px;
    right: 10px;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #b8975e;
}
.list-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin: 24px 0 12px;
}
.list-heading h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.04em;
}
.list-heading > span {
    padding: 5px 9px;
    border: 1px solid #e3e8db;
    border-radius: 20px;
    color: #7d886f;
    font-size: 8px;
    font-weight: 650;
}
.active-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
    color: #859078;
    font-size: 10px;
}
.active-filter button {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 0 14px;
    border: 1px solid #d5dfc9;
    border-radius: 14px;
    background: #eaf0e1;
    color: #5b7449;
    font-size: 10px;
    font-weight: 650;
}
.active-filter svg {
    width: 13px;
    height: 13px;
}
.empty-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 360px;
    padding: 24px 20px 26px;
    border: 1px solid #e4e9de;
    border-radius: 24px;
    background: linear-gradient(150deg, #fff, #fdfefb);
    box-shadow: 0 7px 20px #2b4d3405;
    text-align: center;
}
.empty-art {
    width: 205px;
    max-width: 100%;
    margin: 0 auto 7px;
}
.empty-art svg {
    width: 100%;
    height: auto;
}
.empty-eyebrow {
    color: #9a8356;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}
.empty-card h2 {
    margin: 10px 0 10px;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.05em;
}
.empty-card > p {
    max-width: 285px;
    margin: 0;
    color: #7a8573;
    font-size: 12px;
    line-height: 1.85;
}
.empty-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid #edf0e7;
    width: 100%;
    color: #8a957e;
    font-size: 9px;
    line-height: 1.7;
    text-align: left;
}
.empty-note svg {
    width: 21px;
    height: 21px;
    color: #a5b397;
    flex-shrink: 0;
}
.period-caption {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 17px 0 0;
    color: #7f8b74;
    font-size: 9px;
    line-height: 1.6;
}
.period-caption svg {
    width: 12px;
    height: 12px;
}
.shift-action-bar {
    z-index: 20;
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 15px 22px calc(15px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #e3e8da;
    background: #fcfcf8;
    box-shadow: 0 -6px 25px #29453004;
}
.shift-action-bar > div {
    min-width: 0;
}
.shift-action-bar > div > span {
    color: #968259;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 0.1em;
}
.shift-action-bar p {
    margin: 5px 0 0;
    color: #6a7a61;
    font-size: 10px;
}
.apply-button {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    gap: 11px;
    min-height: 55px;
    padding: 8px 17px 8px 10px;
    border: 1px solid #30513d;
    border-radius: 18px;
    background: linear-gradient(115deg, #284d3c, #3d6144);
    color: #fff;
    font-size: 14px;
    font-weight: 750;
    box-shadow: 0 7px 16px #31533d26;
}
.apply-button > span {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 11px;
    background: #d8bf86;
    color: #36513a;
}
.apply-button > svg {
    width: 15px;
    height: 15px;
    color: #c7d8be;
}
.apply-button > span svg {
    width: 20px;
    height: 20px;
}
.notice {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 16px;
    padding: 13px;
    border-radius: 14px;
    font-size: 12px;
    line-height: 1.7;
}
.notice svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}
.success-notice {
    color: #35613d;
    border: 1px solid #cadfc2;
    background: #edf5e8;
}
.error-notice {
    color: #9c4235;
    border: 1px solid #efd8ce;
    background: #fff2ec;
}
.loading-notice {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 12px 0;
    color: #67815d;
    font-size: 12px;
}
.loading-notice svg,
.spin {
    width: 17px;
    height: 17px;
    animation: shift-spin 1s linear infinite;
}
.request-list {
    display: grid;
    gap: 13px;
}
.request-card {
    display: block;
    width: 100%;
    padding: 18px;
    border: 1px solid #e0e7d8;
    border-radius: 22px;
    background: #fff;
    color: #294837;
    text-align: left;
    box-shadow: 0 5px 16px #294d3305;
}
.request-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.request-top > span:first-child {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #77866e;
    font-size: 10px;
}
.request-top svg {
    width: 14px;
    height: 14px;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 9px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 750;
    line-height: 1.5;
    background: #f0f1eb;
    color: #78806d;
}
.status-pending {
    background: #fcf1d8;
    color: #94712e;
}
.status-approved {
    background: #eaf2e3;
    color: #54753d;
}
.status-rejected {
    background: #faece5;
    color: #a25b48;
}
.shift-comparison {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 20px minmax(0, 1fr);
    align-items: center;
    gap: 10px;
    margin-top: 18px;
}
.shift-comparison > span {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.shift-comparison small,
.current-shift > small,
.detail-shifts small {
    color: #8f997f;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 0.1em;
}
.shift-comparison strong {
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.shift-comparison > span > span {
    font-size: 9px;
    line-height: 1.7;
    color: #738268;
}
.shift-comparison > svg {
    width: 18px;
    height: 18px;
    color: #baa170;
}
.request-reason {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin: 15px 0;
    color: #7b8672;
    font-size: 11px;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.request-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid #eef1e7;
    color: #748467;
    font-size: 9px;
    line-height: 1.7;
}
.request-bottom svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 15px;
    margin-top: 20px;
    color: #697f5b;
    font-size: 10px;
}
.pagination a {
    display: flex;
    align-items: center;
    gap: 3px;
    min-height: 44px;
    color: #355e40;
    text-decoration: none;
}
.pagination svg {
    width: 15px;
    height: 15px;
}
.year-picker {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.year-picker strong {
    font-size: 25px;
    font-weight: 750;
}
.month-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 20px;
}
.month-grid button {
    position: relative;
    min-height: 52px;
    border: 1px solid #dfe7d7;
    border-radius: 14px;
    background: #fff;
    color: #59724a;
    font-size: 13px;
    font-weight: 700;
}
.month-grid button.selected {
    background: #365c42;
    border-color: #365c42;
    color: #fff;
}
.month-grid i {
    position: absolute;
    bottom: 6px;
    left: calc(50% - 2px);
    height: 4px;
    width: 4px;
    border-radius: 50%;
    background: #c6a76b;
}
.outline-button,
.primary-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 50px;
    padding: 12px 16px;
    border: 1px solid #d9e2d0;
    border-radius: 15px;
    background: #fff;
    color: #4c6940;
    font-size: 12px;
    font-weight: 750;
    text-decoration: none;
}
.primary-button {
    background: #345940;
    border-color: #345940;
    color: #fff;
    box-shadow: 0 5px 12px #34594015;
}
.outline-button svg,
.primary-button svg {
    width: 17px;
    height: 17px;
    flex-shrink: 0;
}
.full-width {
    width: 100%;
}
.text-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 44px;
    margin-top: 14px;
    border: 0;
    background: none;
    color: #54713e;
    font-size: 11px;
    font-weight: 750;
}
.text-button svg {
    width: 15px;
    height: 15px;
}
.filter-options {
    display: grid;
    gap: 10px;
}
.filter-options > button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    min-height: 70px;
    padding: 15px;
    border: 1px solid #e0e7d7;
    border-radius: 16px;
    background: #fff;
    color: #34553e;
    text-align: left;
}
.filter-options > button.selected {
    background: #eef3e7;
    border-color: #acbf94;
}
.filter-options strong {
    display: block;
    font-size: 13px;
}
.filter-options small {
    display: block;
    margin-top: 5px;
    color: #79866c;
    font-size: 10px;
    line-height: 1.7;
}
.filter-check {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border: 1px solid #d5dfcb;
    border-radius: 50%;
}
.selected .filter-check {
    background: #597a45;
    border-color: #597a45;
    color: #fff;
}
.filter-check svg {
    width: 15px;
    height: 15px;
}
.sheet-actions {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 10px;
}
.employee-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    margin-bottom: 24px;
    border: 1px solid #e2e7d8;
    border-radius: 17px;
    background: #f3f6ed;
}
.employee-card > span {
    display: grid;
    place-items: center;
    width: 39px;
    height: 39px;
    flex-shrink: 0;
    border-radius: 13px;
    background: #e4ecd9;
    color: #6b8457;
}
.employee-card svg {
    width: 20px;
    height: 20px;
}
.employee-card > div {
    min-width: 0;
}
.employee-card strong {
    display: block;
    font-size: 12px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.employee-card small {
    display: block;
    margin-top: 3px;
    color: #7e8a72;
    font-size: 9px;
    line-height: 1.8;
}
.form-section-title {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 25px 0 17px;
}
.form-section-title > span {
    display: grid;
    place-items: center;
    width: 27px;
    height: 27px;
    border-radius: 9px;
    background: #eee8d9;
    color: #9a7f46;
    font-size: 9px;
    font-weight: 800;
}
.form-section-title h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 750;
    letter-spacing: -0.025em;
}
.form-field {
    margin-bottom: 17px;
}
.form-field label {
    display: block;
    margin: 0 0 9px;
    color: #4b6440;
    font-size: 12px;
    font-weight: 750;
}
.form-field label > span {
    color: #a68b50;
}
.form-field input,
.form-field select,
.form-field textarea {
    display: block;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    min-height: 52px;
    box-sizing: border-box;
    padding: 12px 13px;
    border: 1px solid #dce5d1;
    border-radius: 14px;
    background: #fff;
    color: #34513d;
    font-family: inherit;
    font-size: 16px;
    line-height: 1.6;
    box-shadow: none;
}
.form-field input[type='date'] {
    -webkit-appearance: none;
    appearance: none;
}
.form-field input::-webkit-date-and-time-value {
    text-align: left;
}
.form-field textarea {
    resize: vertical;
    min-height: 116px;
}
.form-field textarea::placeholder {
    color: #9ca68f;
    font-size: 13px;
}
.form-field select {
    appearance: none;
    padding-right: 34px;
    text-overflow: ellipsis;
    background-image: none;
}
.select-wrap {
    position: relative;
}
.select-wrap > svg {
    position: absolute;
    right: 13px;
    top: 17px;
    width: 17px;
    height: 17px;
    color: #859775;
    pointer-events: none;
}
.form-field > small,
.field-caption {
    display: block;
    margin-top: 8px;
    color: #89947c;
    font-size: 10px;
    line-height: 1.7;
}
.field-caption {
    display: flex;
    justify-content: space-between;
    gap: 10px;
}
.field-caption small {
    font-size: 9px;
}
.field-caption > span {
    flex-shrink: 0;
    font-size: 9px;
}
.field-error {
    margin: 8px 0;
    color: #ac5543;
    font-size: 11px;
    line-height: 1.7;
}
.form-field [aria-invalid='true'] {
    border-color: #cc8c76;
}
.current-shift {
    padding: 15px;
    border: 1px solid #e1e7d8;
    border-radius: 15px;
    background: #f5f7ef;
}
.current-shift strong {
    display: block;
    margin: 7px 0;
    color: #506947;
    font-size: 14px;
    overflow-wrap: anywhere;
}
.current-shift > span {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #7f8c73;
    font-size: 11px;
}
.current-shift svg {
    width: 14px;
    height: 14px;
}
.change-direction {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    margin: 9px auto;
    border: 1px solid #e4dcc7;
    border-radius: 10px;
    background: #faf6ec;
    color: #ac925b;
}
.change-direction svg {
    width: 16px;
    height: 16px;
}
.target-preview {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 14px;
    border: 1px solid #cfdebf;
    border-radius: 14px;
    background: #eff5e7;
    color: #587a3d;
}
.target-preview > svg {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
}
.target-preview strong {
    display: block;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.target-preview small {
    display: block;
    margin-top: 5px;
    font-size: 11px;
    color: #7e906c;
}
.approval-preview {
    margin-top: 24px;
    padding: 17px;
    border: 1px solid #e1e7d8;
    border-radius: 17px;
    background: #f5f7ef;
}
.approval-heading {
    display: flex;
    align-items: center;
    gap: 8px;
}
.approval-heading svg {
    width: 18px;
    height: 18px;
    color: #9e8752;
}
.approval-heading strong {
    font-size: 12px;
    color: #547045;
}
.approval-preview ol {
    display: grid;
    gap: 17px;
    list-style: none;
    padding: 0;
    margin: 20px 0 0;
}
.approval-preview li {
    display: flex;
    align-items: flex-start;
    gap: 11px;
}
.approval-preview li > span {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 25px;
    height: 25px;
    border-radius: 50%;
    background: #e2ead7;
    color: #6a8352;
    font-size: 9px;
    font-weight: 800;
}
.approval-preview li > div {
    min-width: 0;
}
.approval-preview li strong {
    display: block;
    font-size: 11px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.approval-preview li small {
    display: block;
    margin-top: 3px;
    color: #8b977e;
    font-size: 9px;
}
.approval-preview li p {
    margin: 8px 0 0;
    font-size: 11px;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.form-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 18px 0 0;
    color: #8c967e;
    font-size: 10px;
    line-height: 1.8;
}
.form-note svg {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
    margin-top: 2px;
}
.unavailable-note {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 17px;
    border: 1px solid #eadfc4;
    border-radius: 16px;
    background: #fbf5e8;
    color: #8e7440;
}
.unavailable-note > svg {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
}
.unavailable-note strong {
    display: block;
    font-size: 13px;
    line-height: 1.7;
}
.unavailable-note p {
    margin: 8px 0 0;
    font-size: 12px;
    line-height: 1.8;
}
.discard-actions {
    display: grid;
    gap: 10px;
}
.detail-shifts {
    display: grid;
    gap: 13px;
    margin-top: 20px;
}
.detail-shifts > div {
    padding: 17px;
    border: 1px solid #e0e7d6;
    border-radius: 16px;
    background: #f5f7ef;
}
.detail-shifts > div:last-child {
    border-color: #d9dfc0;
    background: #f5f4e9;
}
.detail-shifts strong {
    display: block;
    margin: 8px 0;
    font-size: 16px;
    overflow-wrap: anywhere;
}
.detail-shifts span {
    color: #849074;
    font-size: 12px;
}
.detail-shifts > svg {
    width: 18px;
    height: 18px;
    margin: 0 auto;
    color: #b69d67;
}
.detail-reason {
    margin-top: 24px;
}
.detail-reason h3 {
    margin: 0 0 10px;
    font-size: 13px;
    font-weight: 750;
}
.detail-reason p {
    margin: 0;
    color: #78886b;
    font-size: 12px;
    line-height: 1.9;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
@keyframes shift-spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 380px) {
    .shift-header {
        padding-inline: 16px;
        gap: 7px;
    }
    .header-copy h1 {
        font-size: 19px;
    }
    .header-copy p {
        font-size: 7px;
    }
    .shift-content {
        padding: 18px 16px 14px;
    }
    .shift-hero {
        padding-inline: 19px;
    }
    .hero-copy h2 {
        font-size: 23px;
    }
    .hero-symbol {
        right: 18px;
        width: 51px;
        height: 62px;
        top: 42px;
    }
    .hero-copy p {
        max-width: 180px;
        font-size: 9px;
    }
    .period-picker {
        padding-inline: 10px;
        gap: 8px;
    }
    .period-picker strong {
        font-size: 13px;
    }
    .period-icon {
        width: 32px;
        height: 35px;
    }
    .period-picker small {
        font-size: 6px;
    }
    .shift-action-bar {
        padding-inline: 16px;
        gap: 8px;
    }
    .shift-action-bar > div > span {
        font-size: 6px;
    }
    .shift-action-bar p {
        font-size: 9px;
    }
    .apply-button {
        gap: 8px;
        padding-right: 13px;
        font-size: 13px;
    }
    .apply-button > svg {
        display: none;
    }
    .empty-card {
        padding-inline: 16px;
    }
    .empty-card h2 {
        font-size: 19px;
    }
    .request-card {
        padding: 15px;
    }
    .shift-comparison {
        gap: 7px;
    }
    .shift-comparison strong {
        font-size: 12px;
    }
    .shift-comparison > span > span {
        font-size: 8px;
    }
}
@media (max-height: 500px) {
    .shift-header {
        padding-block: calc(8px + env(safe-area-inset-top, 0px)) 8px;
    }
    .header-copy p {
        display: none;
    }
    .shift-action-bar {
        padding-block: 8px calc(8px + env(safe-area-inset-bottom, 0px));
    }
    .apply-button {
        min-height: 46px;
    }
    .shift-content {
        padding-top: 16px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .loading-notice svg,
    .spin {
        animation: none;
    }
}
</style>
