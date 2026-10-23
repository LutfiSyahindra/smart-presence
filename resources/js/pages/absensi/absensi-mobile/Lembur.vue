<script setup lang="ts">
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowUpRight,
    CalendarDays,
    Check,
    CheckCheck,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    Info,
    Plus,
    ShieldCheck,
    SlidersHorizontal,
    Timer,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import CutiSheet from './components/CutiSheet.vue';
import LemburDetail from './components/LemburDetail.vue';
import MobileBackLink from './components/MobileBackLink.vue';
import type { LemburBalance } from './lembur/types';
import { lemburDuration as duration, lemburStatuses as statuses, type LemburRow, type LemburStatus } from './lembur/types';

const props = defineProps<{
    month: string;
    today: string;
    scope: 'mine' | 'delegation';
    allPeriods: boolean;
    status: '' | LemburStatus;
    summary: { count: number; submitted_minutes: number; approved_minutes: number; pending_count: number };
    approvalCount: number;
    balance: LemburBalance;
    requests: {
        data: LemburRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    initialRequest: LemburRow | null;
}>();
const page = usePage<SharedData>();
const selected = ref<LemburRow | null>(props.initialRequest);
const detailBusy = ref(false);
const detail = ref<InstanceType<typeof LemburDetail> | null>(null);

const sheet = ref<'month' | 'filter' | 'info' | null>(null);
const infoMetric = ref<'submitted' | 'approved'>('submitted');
const pickerYear = ref(Number(props.month.slice(0, 4)));
const status = computed(() => props.status);
const draftStatus = ref<'' | LemburStatus>(props.status);
const loading = ref(false);
const error = ref('');
const content = ref<HTMLElement | null>(null);
let removeBackHandler: (() => void) | undefined;

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
const submittedMinutes = computed(() => props.summary.submitted_minutes);
const approvedMinutes = computed(() => props.summary.approved_minutes);
const pendingCount = computed(() => props.summary.pending_count);
const visibleRequests = computed(() => props.requests.data);
const activeStatusLabel = computed(() => statuses.find((item) => item.value === status.value)?.label.toLowerCase());

function dateLabel(date: string) {
    return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${date}T12:00:00`));
}
function applyFilters(changes: { month?: string; status?: '' | LemburStatus; scope?: 'mine' | 'delegation' } = {}) {
    sheet.value = null;
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    router.get(
        route('absensi.mobile.lembur.index'),
        { month: props.allPeriods ? undefined : props.month, status: props.status, scope: props.scope, ...changes },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => content.value?.scrollTo({ top: 0 }),
            onError: () => {
                error.value = 'Periode belum dapat dimuat. Silakan coba kembali.';
            },
            onFinish: () => {
                loading.value = false;
            },
        },
    );
}
function changeMonth(month: string) {
    applyFilters({ month });
}
function switchScope(scope: 'mine' | 'delegation') {
    applyFilters({ scope, status: '', month: scope === 'mine' ? props.today.slice(0, 7) : undefined });
}
function moveMonth(offset: number) {
    const [year, month] = props.month.split('-').map(Number);
    const next = new Date(year, month - 1 + offset, 1);
    changeMonth(`${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`);
}
function openMonth() {
    pickerYear.value = Number(props.month.slice(0, 4));
    sheet.value = 'month';
}
function openFilter() {
    draftStatus.value = status.value;
    sheet.value = 'filter';
}
function showInfo(metric: 'submitted' | 'approved') {
    infoMetric.value = metric;
    sheet.value = 'info';
}
onMounted(() => {
    removeBackHandler = registerMobileBackHandler(() => {
        if (selected.value) {
            if (detail.value?.handleBack()) return true;
            if (!detailBusy.value) selected.value = null;
            return true;
        }
        if (!sheet.value) return false;
        sheet.value = null;
        return true;
    });
});
onUnmounted(() => removeBackHandler?.());
watch(
    () => props.initialRequest,
    (request) => {
        if (request) selected.value = request;
    },
);
</script>

<template>
    <Head title="Hari Lembur" />
    <main class="overtime-workspace">
        <div class="overtime-shell">
            <header class="overtime-header">
                <MobileBackLink :href="route('absensi.mobile.home')" class="icon-button back-button" aria-label="Kembali ke beranda"
                    ><ArrowLeft
                /></MobileBackLink>
                <div class="header-copy">
                    <p>RSARSY PRESENCE</p>
                    <h1>Hari lembur</h1>
                </div>
                <span class="header-emblem" aria-hidden="true"><Timer /></span>
            </header>

            <nav class="scope-tabs" aria-label="Daftar lembur">
                <button
                    type="button"
                    :class="{ active: scope === 'mine' }"
                    :aria-pressed="scope === 'mine'"
                    :disabled="loading"
                    @click="switchScope('mine')"
                >
                    <Timer />Pengajuan saya
                </button>
                <button
                    type="button"
                    :class="{ active: scope === 'delegation' }"
                    :aria-pressed="scope === 'delegation'"
                    :disabled="loading"
                    @click="switchScope('delegation')"
                >
                    <UsersRound />Persetujuan<span v-if="approvalCount">{{ approvalCount }}</span>
                </button>
            </nav>

            <div ref="content" class="overtime-content" :aria-busy="loading">
                <p v-if="page.props.flash.success" class="notice success-notice" role="status">{{ page.props.flash.success }}</p>
                <div class="page-intro">
                    <span class="eyebrow">{{ scope === 'mine' ? 'SETIAP KONTRIBUSI BERARTI' : 'PERSETUJUAN DALAM GENGGAMAN' }}</span>
                    <p>{{ scope === 'mine' ? 'Waktu ekstra, tercatat dengan baik.' : 'Tinjau pengajuan sesuai giliran persetujuan Anda.' }}</p>
                </div>

                <section v-if="scope === 'mine'" class="usage-balance" aria-label="Saldo waktu lembur">
                    <div>
                        <span>SALDO WAKTU LEMBUR</span><strong>{{ duration(balance.available_minutes) }}</strong>
                        <p>Dicadangkan {{ duration(balance.reserved_minutes) }} · Terpakai {{ duration(balance.used_minutes) }}</p>
                    </div>
                    <Link :href="route('absensi.mobile.lembur.usage.create')">Gunakan<ArrowUpRight /></Link>
                    <small>Dari lembur cuti pengganti yang disetujui dan selesai, seluruh periode.</small>
                </section>

                <section class="overview-card" aria-label="Ringkasan lembur bulanan">
                    <div class="overview-orbit orbit-one" aria-hidden="true"></div>
                    <div class="overview-orbit orbit-two" aria-hidden="true"></div>
                    <div class="period-row">
                        <span class="calendar-icon" aria-hidden="true"><CalendarDays /></span>
                        <button type="button" class="period-picker" :disabled="loading" aria-haspopup="dialog" @click="openMonth">
                            <small>PERIODE LEMBUR</small><span>{{ monthLabel }} <ChevronDown /></span>
                        </button>
                        <span v-if="isCurrentMonth" class="current-badge"><i></i> Bulan ini</span
                        ><span v-else class="current-badge">{{ allPeriods ? 'Semua' : 'Arsip' }}</span>
                    </div>
                    <div class="duration-grid">
                        <div class="duration-metric submitted-metric">
                            <div class="metric-label">
                                <Clock3 /><span>Diajukan</span
                                ><button type="button" aria-label="Informasi durasi diajukan" @click="showInfo('submitted')"><Info /></button>
                            </div>
                            <p>
                                <strong>{{ Math.floor(submittedMinutes / 60) }}</strong
                                ><span>j</span><strong>{{ submittedMinutes % 60 }}</strong
                                ><span>m</span>
                            </p>
                            <span class="metric-caption">Total waktu pengajuan</span>
                        </div>
                        <div class="duration-metric approved-metric">
                            <div class="metric-label">
                                <CheckCheck /><span>Disetujui</span
                                ><button type="button" aria-label="Informasi durasi disetujui" @click="showInfo('approved')"><Info /></button>
                            </div>
                            <p>
                                <strong>{{ Math.floor(approvedMinutes / 60) }}</strong
                                ><span>j</span><strong>{{ approvedMinutes % 60 }}</strong
                                ><span>m</span>
                            </p>
                            <span class="metric-caption">Waktu yang disetujui</span>
                        </div>
                    </div>
                    <div class="overview-footer">
                        <span><span class="activity-dot"></span>{{ summary.count }} pengajuan pada periode ini</span
                        ><span>{{ pendingCount }} menunggu</span>
                    </div>
                </section>

                <p v-if="error" class="notice error-notice" role="alert">{{ error }}</p>
                <div v-if="!allPeriods" class="period-navigation">
                    <button type="button" :disabled="loading || month === '1900-01'" aria-label="Bulan sebelumnya" @click="moveMonth(-1)">
                        <ChevronLeft />
                    </button>
                    <span v-if="isCurrentMonth">Catatan bulan ini</span
                    ><button v-else type="button" class="return-current" :disabled="loading" @click="changeMonth(today.slice(0, 7))">
                        Kembali ke bulan ini <ArrowUpRight />
                    </button>
                    <button type="button" :disabled="loading || month === '2100-12'" aria-label="Bulan berikutnya" @click="moveMonth(1)">
                        <ChevronRight />
                    </button>
                </div>

                <section class="requests-card" aria-labelledby="requests-title">
                    <div class="section-heading">
                        <div>
                            <span class="eyebrow">AKTIVITAS ANDA</span>
                            <h2 id="requests-title">
                                {{ scope === 'mine' ? 'Pengajuan lembur' : 'Persetujuan lembur'
                                }}<span class="request-count">{{ requests.total }}</span>
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="icon-button filter-button"
                            :class="{ 'filter-active': status }"
                            aria-label="Filter pengajuan lembur"
                            aria-haspopup="dialog"
                            @click="openFilter"
                        >
                            <SlidersHorizontal /><i v-if="status"></i>
                        </button>
                    </div>
                    <div class="status-tabs" role="group" aria-label="Status pengajuan">
                        <button
                            v-for="item in statuses"
                            :key="item.value"
                            type="button"
                            :class="{ active: status === item.value }"
                            :aria-pressed="status === item.value"
                            :disabled="loading"
                            @click="applyFilters({ status: item.value })"
                        >
                            {{ item.label }}<span v-if="item.value === 'pending' && pendingCount">{{ pendingCount }}</span>
                        </button>
                    </div>
                    <div v-if="visibleRequests.length" class="request-list" aria-live="polite">
                        <button
                            v-for="item in visibleRequests"
                            :key="item.id"
                            type="button"
                            class="request-item"
                            :aria-label="`Lihat pengajuan lembur ${item.name} pada ${dateLabel(item.date)}`"
                            @click="selected = item"
                        >
                            <div class="request-top">
                                <span><CalendarDays />{{ dateLabel(item.date) }}</span
                                ><span class="status-badge" :class="`status-${item.status}`">{{
                                    statuses.find((entry) => entry.value === item.status)?.label
                                }}</span>
                            </div>
                            <strong class="request-name">{{ scope === 'delegation' ? item.name : item.shift_name }}</strong>
                            <span v-if="item.request_kind === 'usage'" class="usage-label"
                                >Penggunaan lembur · {{ item.usage_type === 'late_arrival' ? 'Telat masuk' : 'Pulang cepat' }}</span
                            >
                            <div class="request-hours">
                                <strong v-if="item.before_minutes && item.after_minutes" class="both-segments">Sebelum &amp; setelah shift</strong>
                                <strong v-else>{{ item.start_time }} <span>—</span> {{ item.end_time }}</strong
                                ><span><Timer />{{ duration(item.duration_minutes) }}</span>
                            </div>
                            <p>{{ item.reason }}</p>
                            <span class="request-detail-link"
                                ><span>{{
                                    item.can_decide
                                        ? 'Menunggu keputusan Anda'
                                        : item.status === 'pending'
                                          ? `Persetujuan tahap ${item.current_level}`
                                          : 'Lihat detail pengajuan'
                                }}</span
                                ><ChevronRight
                            /></span>
                        </button>
                    </div>
                    <div v-else class="empty-state" aria-live="polite">
                        <div class="empty-art" aria-hidden="true">
                            <svg viewBox="0 0 240 200" fill="none">
                                <defs>
                                    <linearGradient id="lembur-paper" x1="78" y1="33" x2="162" y2="173" gradientUnits="userSpaceOnUse">
                                        <stop stop-color="#fff" />
                                        <stop offset="1" stop-color="#f0f4ec" />
                                    </linearGradient>
                                    <linearGradient id="lembur-clock" x1="136" y1="96" x2="205" y2="166" gradientUnits="userSpaceOnUse">
                                        <stop stop-color="#e4c58b" />
                                        <stop offset="1" stop-color="#bf9656" />
                                    </linearGradient>
                                    <filter id="lembur-shadow" x="25" y="10" width="200" height="190" filterUnits="userSpaceOnUse">
                                        <feDropShadow dx="0" dy="8" stdDeviation="7" flood-color="#294e40" flood-opacity=".09" />
                                    </filter>
                                </defs>
                                <circle cx="120" cy="99" r="81" fill="#f0f4ec" />
                                <circle cx="120" cy="99" r="65" stroke="#dfe8d8" stroke-dasharray="3 6" />
                                <ellipse cx="126" cy="179" rx="72" ry="7" fill="#dce5d6" opacity=".55" />
                                <g filter="url(#lembur-shadow)">
                                    <rect x="54" y="47" width="113" height="127" rx="15" transform="rotate(-9 54 47)" fill="#d7e4d6" />
                                    <rect
                                        x="70"
                                        y="34"
                                        width="108"
                                        height="136"
                                        rx="15"
                                        transform="rotate(4 70 34)"
                                        fill="url(#lembur-paper)"
                                        stroke="#d7e1d1"
                                    />
                                    <rect x="101" y="28" width="46" height="15" rx="6" transform="rotate(4 101 28)" fill="#285649" />
                                    <rect x="88" y="62" width="62" height="6" rx="3" transform="rotate(4 88 62)" fill="#c5d5c1" />
                                    <rect x="87" y="76" width="41" height="4" rx="2" transform="rotate(4 87 76)" fill="#e0e7db" />
                                    <rect x="86" y="97" width="14" height="14" rx="4" transform="rotate(4 86 97)" fill="#e0ebe0" />
                                    <path d="m90 104 3 3 5-6" stroke="#638772" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="m110 104 34 2M109 112l22 1" stroke="#d8e2d2" stroke-width="4" stroke-linecap="round" />
                                    <rect x="84" y="123" width="14" height="14" rx="4" transform="rotate(4 84 123)" fill="#f1e7d0" />
                                    <path d="m108 130 25 2M107 138l17 1" stroke="#d8e2d2" stroke-width="4" stroke-linecap="round" />
                                    <circle cx="171" cy="137" r="37" fill="url(#lembur-clock)" />
                                    <circle cx="171" cy="137" r="29" fill="#fffaf0" stroke="#f4e5c8" stroke-width="2" />
                                    <path d="M171 117v20l12 8" stroke="#295348" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="171" cy="137" r="3" fill="#295348" />
                                    <path d="M171 113v2m0 44v2m-24-24h2m44 0h2" stroke="#cbb681" stroke-width="2" stroke-linecap="round" />
                                </g>
                                <path d="M48 82v10m-5-5h10M192 52v8m-4-4h8" stroke="#bca16b" stroke-width="2" stroke-linecap="round" />
                                <circle cx="44" cy="131" r="3" fill="#a9c1a6" />
                                <circle cx="197" cy="81" r="3" fill="#d8c18f" />
                            </svg>
                        </div>
                        <span class="empty-eyebrow">{{ status ? 'HASIL FILTER' : 'AWAL YANG BARU' }}</span>
                        <h3>
                            {{
                                status
                                    ? `Belum ada pengajuan ${activeStatusLabel}`
                                    : scope === 'delegation'
                                      ? 'Belum ada pengajuan untuk ditinjau'
                                      : 'Belum ada pengajuan'
                            }}
                        </h3>
                        <p>
                            {{
                                status
                                    ? `Tidak ada pengajuan berstatus ${activeStatusLabel} pada periode ini.`
                                    : scope === 'delegation'
                                      ? 'Pengajuan lembur yang ditugaskan kepada Anda akan muncul di sini.'
                                      : `Pengajuan lembur Anda di ${monthLabel} akan muncul di sini. Siap mencatat kontribusi berikutnya?`
                            }}
                        </p>
                        <button v-if="status" type="button" class="empty-link" @click="applyFilters({ status: '' })">
                            Tampilkan semua pengajuan <ArrowUpRight />
                        </button>
                        <div v-else class="empty-rule"><span></span><Timer /><span></span></div>
                    </div>
                </section>
                <nav v-if="requests.last_page > 1" class="pagination" aria-label="Halaman pengajuan lembur">
                    <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" preserve-scroll preserve-state><ChevronLeft />Sebelumnya</Link
                    ><span v-else></span><span>{{ requests.current_page }} / {{ requests.last_page }}</span
                    ><Link v-if="requests.next_page_url" :href="requests.next_page_url" preserve-scroll preserve-state
                        >Berikutnya<ChevronRight /></Link
                    ><span v-else></span>
                </nav>
                <p class="reassurance"><ShieldCheck />Setiap waktu dan usaha Anda berharga.</p>
            </div>

            <footer class="action-dock">
                <div>
                    <span>WAKTU EKSTRA ANDA</span>
                    <p>Catat kontribusi hari ini.</p>
                </div>
                <Link :href="route('absensi.mobile.lembur.create')" class="primary-button"><Plus /><span>Ajukan</span><ArrowUpRight /></Link>
            </footer>
        </div>
    </main>

    <CutiSheet :open="sheet === 'month'" title="Pilih periode" description="Lihat kembali catatan lembur bulanan Anda." @close="sheet = null">
        <div class="year-selector">
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
                :class="{ selected: month === `${pickerYear}-${item.value}`, 'this-month': today.startsWith(`${pickerYear}-${item.value}`) }"
                :aria-pressed="month === `${pickerYear}-${item.value}`"
                @click="changeMonth(`${pickerYear}-${item.value}`)"
            >
                {{ item.label }}<span v-if="today.startsWith(`${pickerYear}-${item.value}`)"></span>
            </button>
        </div>
        <button type="button" class="outline-button" @click="changeMonth(today.slice(0, 7))"><CalendarDays /> Pilih bulan ini</button>
        <button v-if="scope === 'delegation'" type="button" class="all-periods" @click="applyFilters({ month: undefined })">
            Tampilkan semua periode <ArrowUpRight />
        </button>
    </CutiSheet>
    <CutiSheet :open="sheet === 'filter'" title="Filter pengajuan" description="Pilih status pengajuan yang ingin ditampilkan." @close="sheet = null">
        <div class="filter-options" role="group" aria-label="Pilihan status">
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
            ><div class="filter-actions">
                <button type="button" class="outline-button" @click="draftStatus = ''"><X /> Reset</button
                ><button type="button" class="primary-button" @click="applyFilters({ status: draftStatus })">Terapkan filter <Check /></button></div
        ></template>
    </CutiSheet>
    <CutiSheet :open="sheet === 'info'" :title="infoMetric === 'submitted' ? 'Durasi yang diajukan' : 'Durasi yang disetujui'" @close="sheet = null">
        <div class="metric-info">
            <span><component :is="infoMetric === 'submitted' ? Clock3 : CheckCheck" /></span
            ><strong>{{ duration(infoMetric === 'submitted' ? submittedMinutes : approvedMinutes) }}</strong>
            <p>
                {{
                    infoMetric === 'submitted'
                        ? 'Total durasi seluruh pengajuan lembur pada periode yang dipilih, termasuk pengajuan menunggu dan ditolak. Pengajuan yang dibatalkan tidak dihitung.'
                        : 'Total durasi pengajuan lembur yang sudah disetujui pada periode yang dipilih.'
                }}
            </p>
            <small>{{ monthLabel }} · Ringkasan tidak dipengaruhi filter status.</small>
        </div>
    </CutiSheet>
    <LemburDetail ref="detail" :request="selected" @close="selected = null" @busy="detailBusy = $event" />
</template>

<style scoped>
.usage-balance {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding: 20px;
    border: 1px solid #d6e0cf;
    border-radius: 20px;
    background: #edf2e6;
}
.usage-balance span {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: #647756;
}
.usage-balance strong {
    display: block;
    margin-top: 7px;
    font-size: 28px;
    line-height: 1.2;
}
.usage-balance p {
    margin: 8px 0 0;
    font-size: 10px;
    line-height: 1.6;
    color: #66745f;
}
.usage-balance a {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 11px 12px;
    border-radius: 12px;
    background: #315640;
    color: white;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.usage-balance svg {
    width: 16px;
    height: 16px;
}
.usage-balance small {
    grid-column: 1 / -1;
    font-size: 10px;
    line-height: 1.6;
    color: #66745f;
}
.usage-label {
    display: block;
    margin: 8px 0;
    font-size: 11px;
    font-weight: 700;
    color: #81622e;
}
button {
    font: inherit;
}
.overtime-workspace {
    color: #233d35;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    background: radial-gradient(ellipse at 12% 0%, #dce8dd, transparent 60%), #eaf0e9;
}
.overtime-shell {
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
    box-shadow: 0 0 80px #203b2c12;
}
.overtime-header {
    z-index: 20;
    display: grid;
    flex-shrink: 0;
    grid-template-columns: 42px minmax(0, 1fr) 42px;
    align-items: center;
    gap: 12px;
    padding: calc(15px + env(safe-area-inset-top, 0px)) 22px 16px;
    background: #fafbf7;
    border-bottom: 1px solid #e5e9df;
}
.header-copy {
    text-align: center;
    min-width: 0;
}
.header-copy p {
    margin: 0 0 5px;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.2em;
    color: #958154;
}
.header-copy h1 {
    margin: 0;
    font-size: 21px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: -0.045em;
}
.icon-button {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 42px;
    height: 42px;
    border: 1px solid #e0e6da;
    border-radius: 14px;
    background: #fff;
    color: #365547;
}
.icon-button svg {
    width: 18px;
    height: 18px;
}
.header-emblem {
    display: grid;
    place-items: center;
    justify-self: end;
    width: 38px;
    height: 38px;
    border: 1px solid #e7ddc3;
    border-radius: 13px;
    background: #f5f0e3;
    color: #9c7d43;
}
.header-emblem svg {
    width: 20px;
    height: 20px;
}
.overtime-content {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior-y: contain;
    padding: 25px 20px 8px;
    scrollbar-width: thin;
    scrollbar-color: #c2cdbd transparent;
}
.scope-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    flex-shrink: 0;
    border-bottom: 1px solid #e3e8da;
    background: #fafbf7;
    padding: 9px 20px;
}
.scope-tabs button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 37px;
    padding: 8px 5px;
    border: 1px solid transparent;
    border-radius: 10px;
    color: #96a286;
    font-size: 10px;
    font-weight: 650;
}
.scope-tabs button.active {
    border-color: #e1e8d4;
    background: #eff4e6;
    color: #5b7747;
}
.scope-tabs svg {
    width: 14px;
    height: 14px;
}
.scope-tabs button > span {
    display: grid;
    place-items: center;
    min-width: 17px;
    height: 17px;
    border-radius: 5px;
    padding-inline: 3px;
    background: #e4ebd4;
    color: #809765;
    font-size: 8px;
}
.success-notice {
    margin-top: 0;
    color: #5d7a47;
    background: #eef5e3;
    border: 1px solid #d4e3bf;
}
.request-name {
    display: block;
    margin-top: 12px;
    font-size: 12px;
    font-weight: 700;
    color: #5b7548;
    overflow-wrap: anywhere;
}
.request-item {
    width: 100%;
    text-align: left;
}
.request-item:hover {
    border-color: #b4c3a2;
}
.request-hours .both-segments {
    font-size: 11px;
}
.request-detail-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px dashed #e4e9d9;
    margin-top: 12px;
    padding-top: 10px;
    font-size: 9px;
    color: #a08f64;
}
.request-detail-link svg {
    width: 13px;
    height: 13px;
}
.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-top: 17px;
    font-size: 10px;
    color: #95a17e;
}
.pagination a {
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 38px;
    color: #718b56;
}
.pagination svg {
    width: 13px;
    height: 13px;
}
.all-periods {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    min-height: 42px;
    margin-top: 9px;
    color: #7c925f;
    font-size: 11px;
}
.all-periods svg {
    width: 14px;
    height: 14px;
}
.overview-card + .requests-card {
    margin-top: 20px;
}
.page-intro {
    padding: 0 3px;
    margin-bottom: 18px;
}
.eyebrow {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.17em;
    color: #968057;
}
.page-intro p {
    margin: 6px 0 0;
    font-size: 13px;
    line-height: 1.6;
    color: #6b7b6d;
}
.overview-card {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    padding: 21px 21px 0;
    border: 1px solid #254d3e;
    border-radius: 25px;
    color: #fff;
    background: radial-gradient(ellipse at 110% 0, #64815a65, transparent 65%), linear-gradient(120deg, #1b4137, #2c5541);
    box-shadow: 0 12px 24px -12px #193c3a55;
}
.overview-orbit {
    position: absolute;
    z-index: -1;
    pointer-events: none;
    width: 210px;
    height: 210px;
    border: 1px solid #d9c58d16;
    border-radius: 50%;
}
.orbit-one {
    right: -110px;
    top: -100px;
}
.orbit-two {
    width: 260px;
    height: 260px;
    right: -135px;
    top: -125px;
}
.period-row {
    display: flex;
    align-items: center;
    gap: 11px;
}
.calendar-icon {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 40px;
    height: 42px;
    background: #ffffff0d;
    border: 1px solid #ffffff15;
    border-radius: 12px;
    color: #ddc68f;
}
.calendar-icon svg {
    width: 19px;
    height: 19px;
}
.period-picker {
    min-width: 0;
    text-align: left;
    padding-block: 3px;
}
.period-picker small {
    display: block;
    margin-bottom: 5px;
    font-size: 8px;
    font-weight: 650;
    letter-spacing: 0.15em;
    color: #c5cdb7;
}
.period-picker > span {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.035em;
}
.period-picker svg {
    width: 14px;
    height: 14px;
    color: #cbd7c5;
}
.current-badge {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    gap: 5px;
    margin-left: auto;
    border: 1px solid #e3d19b30;
    border-radius: 20px;
    background: #dbca8b12;
    padding: 5px 8px;
    color: #e6d59f;
    font-size: 8px;
    font-weight: 650;
    white-space: nowrap;
}
.current-badge i {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #d8c183;
}
.duration-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    margin: 24px 0 21px;
}
.duration-metric + .duration-metric {
    padding-left: 21px;
    border-left: 1px solid #d7e0c91f;
}
.duration-metric:first-child {
    padding-right: 15px;
}
.metric-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #d5dfd1;
}
.metric-label > svg {
    width: 13px;
    height: 13px;
    color: #b8ccae;
}
.metric-label button {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    margin: -7px -3px -7px auto;
    color: #a1b39c;
}
.metric-label button svg {
    width: 13px;
    height: 13px;
}
.duration-metric p {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin: 10px 0 7px;
    font-variant-numeric: tabular-nums;
}
.duration-metric p strong {
    font-size: clamp(28px, 8.7vw, 39px);
    font-weight: 650;
    line-height: 1.2;
    letter-spacing: -0.06em;
}
.duration-metric p > span {
    color: #aabea3;
    font-size: 16px;
    margin-right: 5px;
}
.approved-metric p strong {
    color: #e6ce96;
}
.metric-caption {
    font-size: 9px;
    color: #a8bca3;
}
.overview-footer {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    border-top: 1px solid #d7e0c91f;
    padding-block: 13px;
    font-size: 9px;
    color: #c5d2bd;
}
.overview-footer > span:first-child {
    display: flex;
    align-items: center;
    gap: 6px;
}
.activity-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #b7ca9e;
}
.period-navigation {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin: 13px 2px 17px;
    color: #8a9587;
    font-size: 10px;
}
.period-navigation > button:not(.return-current) {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border: 1px solid #e4e8dd;
    border-radius: 10px;
    background: #fbfcf8;
    color: #71846c;
}
.period-navigation svg {
    width: 14px;
    height: 14px;
}
.return-current {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #46664c;
}
.requests-card {
    border: 1px solid #e5e9de;
    border-radius: 25px;
    background: #fff;
    padding: 21px 18px 0;
    box-shadow: 0 4px 16px #394c2903;
}
.section-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.section-heading h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 7px 0 0;
    font-size: 16px;
    line-height: 1.4;
    font-weight: 750;
    letter-spacing: -0.04em;
}
.request-count {
    display: grid;
    place-items: center;
    min-width: 22px;
    height: 22px;
    border: 1px solid #e5e9dd;
    border-radius: 8px;
    padding-inline: 4px;
    background: #f6f8f1;
    font-size: 10px;
    font-weight: 700;
    color: #7e8b72;
}
.filter-button {
    position: relative;
    width: 39px;
    height: 39px;
    border-radius: 12px;
    background: #fbfcf8;
}
.filter-active {
    border-color: #6f9074;
    background: #edf3e9;
}
.filter-active i {
    position: absolute;
    right: 5px;
    top: 5px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #a9854c;
}
.status-tabs {
    display: flex;
    gap: 5px;
    overflow-x: auto;
    padding: 18px 0 15px;
    border-bottom: 1px solid #edf0e7;
    scrollbar-width: none;
}
.status-tabs::-webkit-scrollbar {
    display: none;
}
.status-tabs button {
    display: flex;
    align-items: center;
    flex-shrink: 0;
    gap: 5px;
    min-height: 33px;
    padding: 7px 11px;
    border: 1px solid transparent;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    color: #879180;
    white-space: nowrap;
}
.status-tabs button.active {
    color: #35563d;
    background: #eef3e6;
    border-color: #e2e9d5;
}
.status-tabs button > span {
    display: grid;
    place-items: center;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 5px;
    background: #e8ecd9;
    font-size: 8px;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 22px 9px 27px;
}
.empty-art {
    position: relative;
    width: 210px;
    max-width: 100%;
    margin-bottom: 10px;
}
.empty-art svg {
    width: 100%;
    height: auto;
}
.empty-eyebrow {
    margin: 0 0 9px;
    color: #a08b5e;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 0.19em;
}
.empty-state h3 {
    max-width: 100%;
    margin: 0;
    color: #2f4838;
    font-size: 18px;
    font-weight: 750;
    letter-spacing: -0.045em;
    line-height: 1.5;
}
.empty-state > p {
    max-width: 258px;
    margin: 9px 0 0;
    color: #8b9486;
    font-size: 11px;
    line-height: 1.9;
}
.empty-rule {
    display: flex;
    align-items: center;
    gap: 11px;
    margin-top: 22px;
    color: #c2cbb7;
}
.empty-rule > span {
    width: 28px;
    height: 1px;
    background: #e9eddf;
}
.empty-rule svg {
    width: 13px;
    height: 13px;
}
.empty-link {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 16px;
    color: #50714e;
    font-size: 11px;
    font-weight: 650;
}
.empty-link svg {
    width: 15px;
    height: 15px;
}
.request-list {
    display: grid;
    gap: 13px;
    padding-block: 18px;
}
.request-item {
    border: 1px solid #e7ebdf;
    border-radius: 16px;
    padding: 15px 13px;
    background: #fcfdf9;
}
.request-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 10px;
    color: #6e7d65;
}
.request-top > span:first-child {
    display: flex;
    align-items: center;
    gap: 5px;
}
.request-top svg {
    width: 13px;
    height: 13px;
}
.status-badge {
    border-radius: 6px;
    padding: 4px 6px;
    font-size: 8px;
    font-weight: 650;
}
.status-pending {
    background: #f9efd8;
    color: #9b792e;
}
.status-approved {
    background: #e6f1e5;
    color: #4b714a;
}
.status-rejected {
    background: #fae9e3;
    color: #a46651;
}
.status-cancelled {
    background: #eaede6;
    color: #7c8577;
}
.request-hours {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 15px;
}
.request-hours strong {
    font-size: 15px;
    letter-spacing: -0.03em;
}
.request-hours strong > span {
    color: #bac3af;
    margin-inline: 3px;
}
.request-hours > span {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #907d4e;
    font-size: 10px;
}
.request-hours svg {
    width: 13px;
    height: 13px;
}
.request-item > p {
    margin: 10px 0 0;
    color: #86917e;
    font-size: 11px;
    line-height: 1.7;
    overflow-wrap: anywhere;
    white-space: pre-line;
}
.reassurance {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 18px 0;
    color: #93a088;
    font-size: 9px;
    line-height: 1.6;
}
.reassurance svg {
    flex-shrink: 0;
    width: 13px;
    height: 13px;
}
.action-dock {
    z-index: 10;
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 23px calc(17px + env(safe-area-inset-bottom, 0px));
    background: #fafbf7;
    border-top: 1px solid #e4e9dc;
    box-shadow: 0 -8px 24px #374d2304;
}
.action-dock > div > span {
    display: block;
    color: #a38c59;
    font-size: 7px;
    font-weight: 750;
    letter-spacing: 0.14em;
}
.action-dock p {
    margin: 6px 0 0;
    color: #75856b;
    font-size: 10px;
    line-height: 1.6;
}
.primary-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 49px;
    border: 1px solid #264c35;
    border-radius: 16px;
    padding: 13px 17px;
    color: #fff;
    background: linear-gradient(125deg, #234e3e, #365c3d);
    box-shadow: 0 6px 13px #1f493520;
    font-size: 13px;
    font-weight: 650;
}
.primary-button svg {
    width: 17px;
    height: 17px;
}
.primary-button svg:last-child:not(:first-child) {
    width: 14px;
    height: 14px;
    margin-left: 8px;
    color: #d6c48f;
}
.year-selector {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 19px;
}
.year-selector strong {
    font-size: 22px;
    letter-spacing: -0.05em;
}
.month-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}
.month-grid button {
    position: relative;
    min-height: 50px;
    padding: 13px 10px;
    border: 1px solid #e2e9dd;
    border-radius: 13px;
    font-size: 13px;
    color: #6c8069;
    background: #fff;
}
.month-grid button.selected {
    background: #264e3d;
    border-color: #264e3d;
    color: #fff;
    font-weight: 700;
}
.month-grid button.this-month:not(.selected) {
    color: #3d6547;
    border-color: #9eb793;
    background: #f0f5e9;
}
.month-grid button > span {
    position: absolute;
    bottom: 6px;
    left: calc(50% - 2px);
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #bda168;
}
.outline-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    min-height: 45px;
    border: 1px solid #dae3d2;
    border-radius: 13px;
    padding: 11px;
    background: #f6f8f1;
    color: #55704d;
    font-size: 12px;
    font-weight: 650;
}
.outline-button svg {
    width: 16px;
    height: 16px;
}
.filter-options {
    display: grid;
    gap: 10px;
}
.filter-options > button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 15px;
    border: 1px solid #e2e8db;
    border-radius: 15px;
    background: #fff;
    text-align: left;
}
.filter-options > button.selected {
    border-color: #93ad88;
    background: #f0f5e9;
}
.filter-options strong {
    display: block;
    color: #36543b;
    font-size: 13px;
    font-weight: 700;
}
.filter-options small {
    display: block;
    margin-top: 5px;
    color: #86937d;
    font-size: 11px;
}
.filter-check {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 22px;
    height: 22px;
    border: 1px solid #d8e0cd;
    border-radius: 50%;
    background: #fff;
}
.selected .filter-check {
    border-color: #3f6445;
    background: #3f6445;
    color: #fff;
}
.filter-check svg {
    width: 13px;
    height: 13px;
}
.filter-actions {
    display: grid;
    grid-template-columns: 1fr 1.6fr;
    align-items: stretch;
    gap: 10px;
}
.filter-actions .primary-button {
    min-height: 45px;
    border-radius: 13px;
    font-size: 12px;
}
.metric-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 8px 0;
    text-align: center;
}
.metric-info > span {
    display: grid;
    place-items: center;
    width: 54px;
    height: 54px;
    margin-bottom: 15px;
    border: 1px solid #e0e7d4;
    border-radius: 17px;
    background: #f0f5e6;
    color: #5f7e51;
}
.metric-info > strong {
    font-size: 32px;
    letter-spacing: -0.05em;
}
.metric-info p {
    margin: 15px 0 10px;
    color: #7a8c70;
    font-size: 13px;
    line-height: 1.9;
}
.metric-info small {
    font-size: 10px;
    line-height: 1.7;
    color: #9b9f8e;
}
.notice {
    padding: 12px;
    margin-block: 15px;
    border-radius: 12px;
    font-size: 12px;
    line-height: 1.7;
}
.error-notice {
    border: 1px solid #efd6c8;
    background: #fcf0e9;
    color: #a06449;
}
button,
a {
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
button:not(:disabled),
a {
    cursor: pointer;
}
button:disabled {
    opacity: 0.45;
    cursor: default;
}
button:focus-visible,
a:focus-visible {
    outline: 3px solid #bc9a56;
    outline-offset: 3px;
}
.primary-button:hover:not(:disabled) {
    background: #365c3d;
}
@media (max-width: 360px) {
    .overtime-header {
        padding-inline: 16px;
    }
    .overtime-content {
        padding-inline: 14px;
    }
    .overview-card {
        padding-inline: 17px;
    }
    .calendar-icon {
        width: 34px;
    }
    .period-row {
        gap: 8px;
    }
    .period-picker > span {
        font-size: 13px;
    }
    .current-badge {
        padding-inline: 6px;
        font-size: 7px;
    }
    .requests-card {
        padding-inline: 14px;
    }
    .section-heading h2 {
        font-size: 15px;
    }
    .duration-metric + .duration-metric {
        padding-left: 15px;
    }
    .metric-caption {
        font-size: 8px;
    }
    .action-dock {
        padding-inline: 17px;
    }
    .action-dock p {
        font-size: 9px;
    }
}
@media (prefers-reduced-motion: no-preference) {
    .empty-art {
        animation: artwork-appear 0.55s ease-out both;
    }
    button,
    a {
        transition:
            background-color 0.2s,
            border-color 0.2s,
            box-shadow 0.2s;
    }
    @keyframes artwork-appear {
        from {
            opacity: 0;
            transform: translateY(8px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
}
@media (min-width: 640px) {
    .overtime-content {
        padding-top: 29px;
    }
    .empty-state {
        padding-top: 30px;
        padding-bottom: 34px;
    }
    .empty-art {
        width: 230px;
    }
}
</style>
