<script setup lang="ts">
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    CalendarDays,
    Check,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Clock3,
    FileText,
    FolderOpen,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { date, days } from '../admin-web/cuti/formatters';
import type { CutiRow } from '../admin-web/cuti/types';
import CutiSheet from './components/CutiSheet.vue';
import MobileBackLink from './components/MobileBackLink.vue';

type Filters = { search: string; month: string; sort: 'oldest' | 'newest' };
const props = defineProps<{
    filters: Filters;
    totalPending: number;
    requests: { data: CutiRow[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
}>();
const page = usePage<SharedData>();
const base = '/absensi/mobile/notifikasi/cuti';
const search = ref(props.filters.search);
const loading = ref(false);
const filterOpen = ref(false);
const draft = reactive({ month: props.filters.month, sort: props.filters.sort });
const selectedIds = ref<number[]>([]);
const selected = ref<CutiRow | null>(null);
const confirmingBulk = ref(false);
const decision = useForm({ decision: 'approved', note: '' });
const bulk = useForm({ request_ids: [] as number[] });
const bulkErrors = computed(() => Object.values(bulk.errors).filter(Boolean));
const decisionErrors = computed(() => decision.errors as Record<string, string | undefined>);
const activeFilterCount = computed(() => Number(Boolean(props.filters.month)) + Number(props.filters.sort !== 'oldest'));
const filtered = computed(() => Boolean(props.filters.search || props.filters.month));
const periodLabel = computed(() =>
    props.filters.month
        ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${props.filters.month}-01T12:00:00`))
        : 'Semua periode',
);
const selectableRequests = computed(() => props.requests.data.filter((cuti) => cuti.can_decide));
const allSelected = computed(
    () => selectableRequests.value.length > 0 && selectableRequests.value.every((cuti) => selectedIds.value.includes(cuti.id)),
);
const selectedRequests = computed(() => selectableRequests.value.filter((cuti) => selectedIds.value.includes(cuti.id)));
const someSelected = computed(() => selectedRequests.value.length > 0 && !allSelected.value);
const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();
const stepLabels: Record<string, string> = {
    approved: 'Disetujui',
    rejected: 'Ditolak',
    pending: 'Menunggu keputusan',
    waiting: 'Menunggu giliran',
    skipped: 'Dilewati',
};
const stepLabel = (status: string) => stepLabels[status] ?? status;
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function cancelSelection() {
    selectedIds.value = [];
    confirmingBulk.value = false;
    bulk.reset();
}
function applyFilters(changes: Partial<Filters> = {}) {
    if (bulk.processing) return;
    if (searchTimer) clearTimeout(searchTimer);
    filterOpen.value = false;
    cancelSelection();
    bulk.clearErrors();
    const next = { ...props.filters, search: search.value.trim(), ...changes };
    search.value = next.search;
    router.get(base, next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}
function openFilter() {
    draft.month = props.filters.month;
    draft.sort = props.filters.sort;
    filterOpen.value = true;
}
function toggleSelection(id: number) {
    if (bulk.processing || loading.value || !selectableRequests.value.some((cuti) => cuti.id === id)) return;
    bulk.clearErrors();
    selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter((value) => value !== id) : [...selectedIds.value, id];
}
function selectAll() {
    if (bulk.processing || loading.value) return;
    bulk.clearErrors();
    selectedIds.value = allSelected.value ? [] : selectableRequests.value.map((cuti) => cuti.id);
}
function openDetail(cuti: CutiRow) {
    if (bulk.processing || loading.value) return;
    decision.reset();
    decision.clearErrors();
    selected.value = cuti;
}
function decide() {
    if (!selected.value || decision.processing) return;
    decision.post(`/absensi/mobile/cuti/${selected.value.id}/decision`, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
            decision.reset();
        },
    });
}
function reviewSelection() {
    if (!selectedRequests.value.length || bulk.processing || loading.value) return;
    bulk.clearErrors();
    confirmingBulk.value = true;
}
function approveSelected() {
    if (!confirmingBulk.value || !selectedRequests.value.length || bulk.processing || loading.value) return;
    bulk.request_ids = selectedRequests.value.map((cuti) => cuti.id);
    bulk.post(`${base}/approve-many`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelSelection();
            bulk.clearErrors();
        },
        onError: () => (confirmingBulk.value = false),
    });
}
function scrollTop() {
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
}
function scheduleSearch() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
}
watch(
    () => props.filters.search,
    (value) => (search.value = value),
);
watch([() => props.requests, () => props.filters], cancelSelection);
onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
    <Head title="Persetujuan Cuti" />
    <main class="approval-workspace" :class="{ 'is-selecting': selectedRequests.length > 0 }">
        <div class="approval-shell">
            <header class="approval-header">
                <div class="header-row">
                    <div class="header-back">
                        <MobileBackLink href="/absensi/mobile/notifikasi?tab=approvals" class="icon-button" aria-label="Kembali"
                            ><ArrowLeft
                        /></MobileBackLink>
                    </div>
                    <div class="header-title">
                        <h1>Cuti</h1>
                        <span>PERSETUJUAN</span>
                    </div>
                    <div class="header-actions">
                        <button
                            type="button"
                            class="icon-button"
                            :class="{ active: activeFilterCount }"
                            aria-label="Filter pengajuan"
                            @click="openFilter"
                        >
                            <SlidersHorizontal /><i v-if="activeFilterCount" class="filter-dot"></i>
                        </button>
                    </div>
                </div>
                <form class="search-field" role="search" @submit.prevent="applyFilters()">
                    <Search aria-hidden="true" /><input
                        v-model="search"
                        @input="scheduleSearch"
                        type="search"
                        maxlength="150"
                        aria-label="Cari nama, unit, atau jenis cuti"
                        placeholder="Cari nama, unit, atau jenis cuti..."
                    />
                    <button v-if="search" type="button" aria-label="Hapus pencarian" @click="applyFilters({ search: '' })"><X /></button>
                    <span v-if="loading" class="search-spinner" aria-label="Memuat pengajuan"></span>
                </form>
            </header>

            <div class="approval-content" :aria-busy="loading">
                <div v-if="page.props.flash.success" class="notice success" role="status">
                    <CircleCheck /><span>{{ page.props.flash.success }}</span>
                </div>
                <div v-if="page.props.flash.error" class="notice" role="alert">{{ page.props.flash.error }}</div>
                <div v-if="bulkErrors.length" class="notice" role="alert">{{ bulkErrors.join(' ') }}</div>
                <div class="queue-heading">
                    <div>
                        <span class="eyebrow">RUANG PERSETUJUAN</span>
                        <h2>
                            Menunggu Anda<span>{{ totalPending }}</span>
                        </h2>
                    </div>
                    <span class="queue-mark" aria-hidden="true"><ShieldCheck /></span>
                </div>
                <p class="queue-description">Centang pengajuan untuk menyetujui sekaligus, atau buka rincian untuk meninjau satu per satu.</p>
                <div class="queue-context">
                    <span><CalendarDays />{{ periodLabel }}</span
                    ><span>{{ requests.total }} pengajuan{{ filtered ? ' ditemukan' : '' }}</span>
                </div>
                <div v-if="filtered || filters.sort !== 'oldest'" class="filter-chips">
                    <button v-if="filters.search" type="button" @click="applyFilters({ search: '' })"><Search />{{ filters.search }}<X /></button>
                    <button v-if="filters.month" type="button" @click="applyFilters({ month: '' })">{{ periodLabel }}<X /></button>
                    <button v-if="filters.sort === 'newest'" type="button" @click="applyFilters({ sort: 'oldest' })">Terbaru dahulu<X /></button>
                </div>

                <div v-if="selectableRequests.length" class="selection-tools">
                    <label class="select-all">
                        <span class="selection-target"
                            ><input
                                type="checkbox"
                                :checked="allSelected"
                                :indeterminate="someSelected"
                                :disabled="bulk.processing || loading"
                                @change="selectAll"
                        /></span>
                        Pilih semua di halaman ini
                    </label>
                    <span role="status" aria-live="polite">{{ selectedRequests.length }} dipilih</span>
                    <button v-if="selectedRequests.length" type="button" :disabled="bulk.processing" @click="cancelSelection">Hapus pilihan</button>
                </div>
                <div v-if="requests.data.length" class="request-list">
                    <article v-for="cuti in requests.data" :key="cuti.id" class="request-card" :class="{ selected: selectedIds.includes(cuti.id) }">
                        <label class="request-person"
                            ><span class="person-avatar">{{ initials(cuti.nama_pegawai) }}</span
                            ><span class="person-copy"
                                ><strong>{{ cuti.nama_pegawai }}</strong
                                ><small>{{ cuti.unit || cuti.jabatan }}</small></span
                            ><span class="selection-target"
                                ><input
                                    type="checkbox"
                                    :checked="selectedIds.includes(cuti.id)"
                                    :disabled="!cuti.can_decide || bulk.processing || loading"
                                    :aria-label="`Tandai pengajuan ${cuti.nama_pegawai}, ${date(cuti.tanggal_mulai)} sampai ${date(cuti.tanggal_selesai)}`"
                                    @change="toggleSelection(cuti.id)" /></span
                        ></label>
                        <button
                            type="button"
                            class="request-review"
                            :aria-label="`Lihat rincian pengajuan ${cuti.nama_pegawai}`"
                            :disabled="bulk.processing || loading"
                            @click="openDetail(cuti)"
                        >
                            <span class="request-details"
                                ><span class="leave-label"><CalendarDays />{{ cuti.nama_cuti || 'Pengajuan cuti' }}</span
                                ><span class="duration">{{ cuti.jumlah_hari || days(cuti.tanggal_mulai, cuti.tanggal_selesai) }} hari</span
                                ><span class="request-period"
                                    >{{ date(cuti.tanggal_mulai) }}<ArrowRight />{{ date(cuti.tanggal_selesai) }}</span
                                ></span
                            >
                            <span class="request-footer"
                                ><span><Clock3 />Menunggu keputusan Anda</span><span>Tinjau<ArrowRight /></span
                            ></span>
                        </button>
                    </article>
                </div>

                <section v-else class="empty-card" :class="{ 'empty-filtered': filtered && totalPending > 0 }">
                    <div class="empty-art" aria-hidden="true">
                        <span class="art-orbit orbit-one"></span><span class="art-orbit orbit-two"></span><span class="art-spark spark-one"></span
                        ><span class="art-spark spark-two"></span><span class="art-paper"><CalendarDays /><i></i><i></i><i></i></span
                        ><span class="art-folder-back"></span><span class="art-folder-front"><FolderOpen /></span
                        ><span class="art-badge"><Search v-if="filtered && totalPending" /><CheckCheck v-else /></span>
                    </div>
                    <span class="empty-eyebrow">{{ filtered && totalPending ? 'COBA KATA KUNCI LAIN' : 'SEMUA SUDAH TERTANGANI' }}</span>
                    <h2>{{ filtered && totalPending ? 'Pengajuan tidak ditemukan' : 'Tidak ada pengajuan' }}</h2>
                    <p>
                        {{
                            filtered && totalPending
                                ? 'Belum ada pengajuan yang sesuai dengan pencarian atau periode pilihan Anda.'
                                : 'Semua permintaan persetujuan cuti akan muncul di sini saat membutuhkan keputusan Anda.'
                        }}
                    </p>
                    <button
                        v-if="filtered && totalPending"
                        type="button"
                        class="reset-search"
                        @click="applyFilters({ search: '', month: '', sort: 'oldest' })"
                    >
                        Tampilkan semua pengajuan<ArrowRight />
                    </button>
                    <span v-else class="empty-note"><ShieldCheck />Satu ruang, semua persetujuan.</span>
                </section>

                <nav v-if="requests.last_page > 1" class="pagination" aria-label="Halaman persetujuan cuti">
                    <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" preserve-scroll @click="cancelSelection"
                        ><ChevronLeft />Sebelumnya</Link
                    ><span v-else></span><span>{{ requests.current_page }} / {{ requests.last_page }}</span
                    ><Link v-if="requests.next_page_url" :href="requests.next_page_url" preserve-scroll @click="cancelSelection"
                        >Berikutnya<ChevronRight /></Link
                    ><span v-else></span>
                </nav>
                <p class="privacy-note"><ShieldCheck />Hanya pengajuan yang menjadi giliran persetujuan Anda.</p>
            </div>
            <button v-if="requests.data.length > 3" type="button" class="scroll-top" aria-label="Kembali ke atas" @click="scrollTop">
                <ArrowUp />
            </button>
            <div v-if="selectedRequests.length" class="selection-bar">
                <div>
                    <strong>{{ selectedRequests.length }} pengajuan</strong><span>dipilih untuk disetujui</span>
                </div>
                <button type="button" :disabled="bulk.processing || loading" @click="reviewSelection"><CheckCheck />Setujui pilihan</button>
            </div>
        </div>
    </main>

    <CutiSheet
        :open="filterOpen"
        title="Filter pengajuan"
        description="Temukan pengajuan sesuai periode yang Anda butuhkan."
        @close="filterOpen = false"
    >
        <div class="filter-form">
            <label for="approval-month">Periode cuti<span>Kosongkan untuk menampilkan seluruh periode.</span></label>
            <div class="month-field">
                <CalendarDays /><input id="approval-month" v-model="draft.month" type="month" /><button
                    v-if="draft.month"
                    type="button"
                    aria-label="Hapus periode"
                    @click="draft.month = ''"
                >
                    <X />
                </button>
            </div>
            <span class="field-label">Urutkan pengajuan</span>
            <div class="sort-options">
                <button
                    v-for="option in [
                        { value: 'oldest', label: 'Terlama dahulu' },
                        { value: 'newest', label: 'Terbaru dahulu' },
                    ]"
                    :key="option.value"
                    type="button"
                    :class="{ active: draft.sort === option.value }"
                    :aria-pressed="draft.sort === option.value"
                    @click="draft.sort = option.value as Filters['sort']"
                >
                    {{ option.label }}<Check v-if="draft.sort === option.value" />
                </button>
            </div>
        </div>
        <template #footer
            ><div class="sheet-actions">
                <button
                    type="button"
                    class="secondary-button"
                    @click="
                        draft.month = '';
                        draft.sort = 'oldest';
                    "
                >
                    Atur ulang</button
                ><button type="button" class="primary-button" @click="applyFilters({ month: draft.month, sort: draft.sort })">
                    Terapkan filter<ArrowRight />
                </button></div
        ></template>
    </CutiSheet>

    <CutiSheet
        :open="Boolean(selected)"
        title="Rincian pengajuan"
        description="Periksa rincian sebelum memberikan keputusan."
        :busy="decision.processing"
        @close="selected = null"
    >
        <template v-if="selected"
            ><div class="detail-person">
                <span class="person-avatar">{{ initials(selected.nama_pegawai) }}</span>
                <div>
                    <h3>{{ selected.nama_pegawai }}</h3>
                    <p>
                        {{ selected.jabatan }}<span v-if="selected.unit"> · {{ selected.unit }}</span>
                    </p>
                </div>
            </div>
            <div class="detail-leave">
                <span><CalendarDays />{{ selected.nama_cuti || 'Pengajuan cuti' }}</span
                ><strong>{{ selected.jumlah_hari || days(selected.tanggal_mulai, selected.tanggal_selesai) }} <small>hari</small></strong>
                <p>{{ date(selected.tanggal_mulai) }}<ArrowRight />{{ date(selected.tanggal_selesai) }}</p>
            </div>
            <div class="reason-block">
                <h3><FileText />Alasan pengajuan</h3>
                <p>{{ selected.alasan }}</p>
                <small>Diajukan {{ selected.created_at }}</small>
            </div>
            <div class="approval-flow">
                <h3><ShieldCheck />Alur persetujuan</h3>
                <ol>
                    <li v-for="step in selected.approvals" :key="step.level" :class="step.status">
                        <span class="step-number"
                            ><Check v-if="step.status === 'approved'" /><span v-else>{{ step.level }}</span></span
                        >
                        <div>
                            <strong>{{ step.name }}</strong
                            ><small
                                >{{ stepLabel(step.status) }}<template v-if="step.decided_at"> · {{ step.decided_at }}</template></small
                            >
                            <p v-if="step.note">{{ step.note }}</p>
                        </div>
                    </li>
                </ol>
            </div>
            <form id="approval-decision" class="decision-form" @submit.prevent="decide">
                <label for="approval-note"
                    >{{ decision.decision === 'rejected' ? 'Alasan penolakan' : 'Catatan (opsional)'
                    }}<span v-if="decision.decision === 'rejected'">Wajib diisi agar pemohon memahami keputusan Anda.</span></label
                ><textarea
                    id="approval-note"
                    v-model="decision.note"
                    :required="decision.decision === 'rejected'"
                    maxlength="1000"
                    rows="3"
                    :aria-invalid="Boolean(decisionErrors.note)"
                    :disabled="decision.processing"
                    :placeholder="decision.decision === 'rejected' ? 'Tuliskan alasan penolakan...' : 'Tambahkan catatan bila diperlukan...'"
                />
                <p v-if="decisionErrors.note" class="form-error" role="alert">{{ decisionErrors.note }}</p>
                <p v-if="decisionErrors.decision" class="form-error" role="alert">{{ decisionErrors.decision }}</p>
            </form>
        </template>
        <template #footer
            ><div class="sheet-actions">
                <button
                    type="button"
                    :disabled="decision.processing"
                    class="secondary-button"
                    :class="{ 'reject-button': decision.decision === 'approved' }"
                    @click="
                        decision.decision = decision.decision === 'approved' ? 'rejected' : 'approved';
                        decision.clearErrors();
                    "
                >
                    {{ decision.decision === 'approved' ? 'Tolak' : 'Kembali' }}</button
                ><button
                    type="submit"
                    form="approval-decision"
                    class="primary-button"
                    :class="{ 'reject-submit': decision.decision === 'rejected' }"
                    :disabled="decision.processing"
                >
                    <Check v-if="decision.decision === 'approved'" />{{
                        decision.processing ? 'Menyimpan...' : decision.decision === 'rejected' ? 'Tolak pengajuan' : 'Setujui pengajuan'
                    }}
                </button>
            </div></template
        >
    </CutiSheet>

    <CutiSheet
        :open="confirmingBulk"
        title="Setujui pengajuan pilihan?"
        description="Keputusan disimpan pada tingkat persetujuan Anda dan diteruskan sesuai alurnya."
        :busy="bulk.processing"
        @close="confirmingBulk = false"
        ><div class="bulk-summary">
            <span><ShieldCheck /></span><strong>{{ selectedRequests.length }} pengajuan</strong>
            <p>Periksa daftar berikut sebelum menyetujui.</p>
        </div>
        <ul class="bulk-list">
            <li v-for="cuti in selectedRequests" :key="cuti.id">
                <span
                    >{{ cuti.nama_pegawai
                    }}<small>{{ cuti.nama_cuti }} · {{ date(cuti.tanggal_mulai) }} – {{ date(cuti.tanggal_selesai) }}</small></span
                ><Check />
            </li>
        </ul>
        <template #footer
            ><div class="sheet-actions">
                <button type="button" class="secondary-button" :disabled="bulk.processing" @click="confirmingBulk = false">Kembali</button
                ><button
                    type="button"
                    class="primary-button"
                    :disabled="bulk.processing || !selectedRequests.length || loading"
                    @click="approveSelected"
                >
                    <CheckCheck />{{ bulk.processing ? 'Menyimpan...' : `Setujui ${selectedRequests.length} pengajuan` }}
                </button>
            </div></template
        ></CutiSheet
    >
</template>

<style scoped>
.approval-workspace {
    --ink: #243d32;
    --muted: #72816c;
    --green: #295c45;
    min-height: 100dvh;
    background: #eaf0e9;
    color: var(--ink);
    font-family: 'Plus Jakarta Sans', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.approval-shell {
    position: relative;
    max-width: 500px;
    min-height: 100dvh;
    margin: auto;
    background: #f5f7f2;
}
.approval-header {
    position: sticky;
    top: 0;
    z-index: 30;
    padding: calc(16px + env(safe-area-inset-top)) 18px 16px;
    border-bottom: 1px solid #e3e9de;
    background: #f5f7f2f5;
    backdrop-filter: blur(18px);
}
.header-row {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 44px;
    align-items: center;
    gap: 8px;
}
.header-title {
    text-align: center;
}
.header-title h1 {
    margin: 0;
    color: #243d32;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.7px;
}
.header-title > span {
    display: block;
    margin-top: 5px;
    color: #8b987c;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.6px;
}
.header-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.icon-button {
    position: relative;
    display: grid;
    place-items: center;
    width: 40px;
    height: 42px;
    border: 1px solid #dfe7d8;
    border-radius: 13px;
    background: #fff;
    color: #526e48;
    text-decoration: none;
}
.icon-button svg {
    width: 18px;
    height: 18px;
    stroke-width: 1.7;
}
.icon-button.active {
    border-color: #b9cca8;
    background: #eaf1e1;
    color: #45673b;
}
.icon-button:disabled {
    opacity: 0.4;
}
.filter-dot {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #8a9f60;
}
.search-field {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
    padding: 0 14px;
    border: 1px solid #dfe6d7;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 3px 10px #344c2503;
}
.search-field:focus-within {
    border-color: #94ad7b;
    box-shadow: 0 0 0 3px #a5ba8c16;
}
.search-field > svg {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    color: #93a084;
}
.search-field input {
    flex: 1;
    min-width: 0;
    width: 100%;
    height: 48px;
    padding: 0;
    border: 0;
    outline: 0;
    background: transparent;
    color: #344e3b;
    box-shadow: none;
    font-size: 12px;
}
.search-field input::placeholder {
    color: #9ca68f;
}
.search-field input::-webkit-search-cancel-button {
    display: none;
}
.search-field button {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 44px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #829471;
}
.search-field button > svg {
    width: 15px;
    height: 15px;
}
.search-spinner {
    flex-shrink: 0;
    width: 13px;
    height: 13px;
    border: 2px solid #e1e8d8;
    border-top-color: #64864b;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
.approval-content {
    padding: 25px 18px calc(24px + env(safe-area-inset-bottom));
}
.queue-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 0 3px;
}
.eyebrow {
    display: block;
    margin-bottom: 7px;
    color: #8d997d;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.7px;
}
.queue-heading h2 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    color: #243d32;
    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.7px;
}
.queue-heading h2 > span {
    display: grid;
    place-items: center;
    min-width: 24px;
    height: 24px;
    padding: 0 6px;
    border: 1px solid #dfe8d3;
    border-radius: 8px;
    background: #eaf0e1;
    color: #708958;
    font-size: 11px;
    letter-spacing: 0;
}
.queue-mark {
    display: grid;
    place-items: center;
    width: 37px;
    height: 37px;
    border: 1px solid #e2e9d8;
    border-radius: 12px;
    background: #eef3e6;
    color: #83996a;
}
.queue-mark > svg {
    width: 17px;
    height: 17px;
    stroke-width: 1.6;
}
.queue-description {
    margin: 10px 3px 20px;
    color: #7d8b72;
    font-size: 11px;
    line-height: 1.8;
}
.queue-context {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    margin: 0 3px 15px;
    color: #8c987f;
    font-size: 10px;
}
.queue-context > span:first-child {
    display: flex;
    align-items: center;
    gap: 6px;
}
.queue-context svg {
    width: 13px;
    height: 13px;
}
.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-bottom: 15px;
}
.filter-chips button {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 36px;
    max-width: 100%;
    padding: 7px 10px;
    border: 1px solid #dce7cf;
    border-radius: 10px;
    background: #edf3e5;
    color: #6b8454;
    font-size: 10px;
    overflow-wrap: anywhere;
}
.filter-chips svg {
    flex-shrink: 0;
    width: 12px;
    height: 12px;
}
.request-list {
    display: grid;
    gap: 14px;
}
.request-card {
    overflow: hidden;
    width: 100%;
    padding: 0;
    border: 1px solid #e0e7d8;
    border-radius: 21px;
    background: #fff;
    color: #243d32;
    box-shadow: 0 4px 15px #324e2103;
    text-align: left;
}
.request-card.selected {
    border-color: #8fa96e;
    box-shadow: 0 0 0 2px #8fa96e14;
}
.request-review {
    display: block;
    width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
}
.request-person {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 18px 17px 16px;
    cursor: pointer;
}
.person-avatar {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 43px;
    height: 43px;
    border: 1px solid #e0e8d4;
    border-radius: 15px;
    background: linear-gradient(130deg, #f0f4e8, #e6edd9);
    color: #78945c;
    font-size: 12px;
    font-weight: 800;
}
.person-copy {
    flex: 1;
    min-width: 0;
}
.person-copy > strong {
    display: block;
    color: #2c4534;
    font-size: 13px;
    font-weight: 800;
    line-height: 1.7;
    overflow-wrap: anywhere;
}
.person-copy > small {
    display: block;
    margin-top: 3px;
    color: #89967b;
    font-size: 10px;
    line-height: 1.6;
}
.request-details {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin: 0 17px 16px;
    padding: 14px;
    border: 1px solid #f0f3e9;
    border-radius: 13px;
    background: #f7f9f3;
}
.leave-label {
    display: flex;
    align-items: center;
    gap: 7px;
    flex: 1;
    color: #6b8057;
    font-size: 11px;
    font-weight: 700;
}
.leave-label svg {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
}
.duration {
    flex-shrink: 0;
    color: #84936f;
    font-size: 11px;
    font-weight: 700;
}
.request-period {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    width: 100%;
    color: #87947a;
    font-size: 11px;
    line-height: 1.7;
}
.request-period svg {
    width: 13px;
    height: 13px;
}
.request-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 12px 17px;
    border-top: 1px solid #e9eedf;
    background: #f3f6ec;
    color: #819467;
    font-size: 9px;
    font-weight: 600;
}
.request-footer > span {
    display: flex;
    align-items: center;
    gap: 6px;
}
.request-footer > span:last-child {
    color: #6c8552;
}
.request-footer svg {
    flex-shrink: 0;
    width: 13px;
    height: 13px;
}
.empty-card {
    display: flex;
    align-items: center;
    flex-direction: column;
    justify-content: center;
    min-height: max(430px, calc(100dvh - 350px - env(safe-area-inset-top) - env(safe-area-inset-bottom)));
    padding: 40px 20px 52px;
    border: 1px solid #e4e9de;
    border-radius: 25px;
    background: radial-gradient(ellipse at 50% 35%, #f4f7ed, #fff 65%);
    box-shadow: 0 6px 30px #3c542504;
    text-align: center;
}
.empty-art {
    position: relative;
    isolation: isolate;
    width: 200px;
    height: 170px;
    margin-bottom: 25px;
}
.art-orbit {
    position: absolute;
    z-index: -1;
    border-radius: 50%;
}
.orbit-one {
    inset: 0 17px 4px;
    border: 1px solid #e9eedf;
}
.orbit-two {
    inset: 15px 32px 18px;
    background: #edf3e4;
}
.art-paper {
    position: absolute;
    top: 17px;
    left: 64px;
    z-index: 2;
    display: flex;
    align-items: flex-start;
    flex-direction: column;
    gap: 7px;
    width: 76px;
    height: 99px;
    padding: 14px 13px;
    transform: rotate(9deg);
    border: 1px solid #dce5d2;
    border-radius: 10px;
    background: linear-gradient(150deg, #fff, #f4f7ee);
    box-shadow: 2px 6px 15px #4661280d;
}
.art-paper > svg {
    width: 18px;
    height: 18px;
    margin-bottom: 4px;
    color: #a1b286;
    stroke-width: 1.5;
}
.art-paper > i {
    width: 38px;
    height: 3px;
    border-radius: 3px;
    background: #dde7d0;
}
.art-paper > i:last-child {
    width: 25px;
}
.art-folder-back {
    position: absolute;
    top: 73px;
    left: 43px;
    z-index: 1;
    width: 112px;
    height: 70px;
    transform: rotate(-6deg);
    border: 1px solid #53764c;
    border-radius: 9px;
    background: #5e8156;
}
.art-folder-back::before {
    position: absolute;
    top: -12px;
    left: -1px;
    width: 47px;
    height: 17px;
    border: 1px solid #53764c;
    border-bottom: 0;
    border-radius: 7px 10px 0 0;
    background: #5e8156;
    content: '';
}
.art-folder-front {
    position: absolute;
    top: 90px;
    left: 42px;
    z-index: 3;
    display: grid;
    place-items: center;
    width: 118px;
    height: 65px;
    transform: rotate(-6deg) skewX(-5deg);
    border: 1px solid #52784c;
    border-radius: 9px 9px 13px 13px;
    background: linear-gradient(130deg, #809c65, #53764c);
    box-shadow:
        0 13px 19px #65834722,
        inset 0 1px 0 #ffffff35;
}
.art-folder-front svg {
    width: 29px;
    height: 29px;
    color: #dae5c88c;
    stroke-width: 1.2;
}
.art-badge {
    position: absolute;
    top: 98px;
    right: 18px;
    z-index: 4;
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    border: 4px solid #f8faf4;
    border-radius: 16px;
    background: linear-gradient(140deg, #e4d5a7, #cdb577);
    box-shadow: 0 6px 14px #8e84401f;
    transform: rotate(9deg);
}
.art-badge svg {
    width: 21px;
    height: 21px;
    color: #806d38;
    stroke-width: 1.8;
}
.art-spark {
    position: absolute;
    width: 5px;
    height: 5px;
    border-radius: 2px;
    background: #c2cfa9;
    transform: rotate(35deg);
}
.spark-one {
    top: 46px;
    right: 18px;
}
.spark-two {
    top: 109px;
    left: 16px;
    width: 4px;
    height: 4px;
    background: #dbcc9e;
}
.empty-eyebrow {
    color: #94a17d;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.5px;
}
.empty-card h2 {
    margin: 12px 0;
    color: #2b4432;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.7px;
    line-height: 1.5;
}
.empty-card > p {
    max-width: 265px;
    margin: 0;
    color: #839175;
    font-size: 12px;
    line-height: 1.9;
}
.empty-note {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 27px;
    padding: 8px 12px;
    border: 1px solid #edf0e5;
    border-radius: 9px;
    background: #f7f9f2;
    color: #9aa68a;
    font-size: 9px;
}
.empty-note svg {
    width: 12px;
    height: 12px;
}
.reset-search {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    margin-top: 18px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #66824e;
    font-size: 11px;
    font-weight: 700;
}
.reset-search svg {
    width: 14px;
    height: 14px;
}
.privacy-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 21px 0 0;
    color: #9aa68b;
    font-size: 9px;
    line-height: 1.8;
    text-align: center;
}
.privacy-note svg {
    flex-shrink: 0;
    width: 12px;
    height: 12px;
}
.notice {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    margin-bottom: 20px;
    padding: 14px;
    border: 1px solid #e0e8d6;
    border-radius: 13px;
    background: #edf4e4;
    color: #5c7849;
    font-size: 12px;
    line-height: 1.8;
}
.notice svg {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    margin-top: 2px;
}
.selection-tools {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
    color: #859575;
    font-size: 10px;
}
.select-all {
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 44px;
    color: #687f53;
    cursor: pointer;
}
.selection-tools > span {
    margin-left: auto;
}
.selection-tools button {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #687f53;
    font-size: 10px;
}
.selection-target {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 44px;
    height: 44px;
}
.selection-target input {
    width: 22px;
    height: 22px;
    margin: 0;
    border: 1.5px solid #a7b798;
    border-radius: 6px;
    accent-color: #345d3e;
    color: #345d3e;
    cursor: pointer;
}
.selection-bar {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 35;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    max-width: 500px;
    margin: auto;
    padding: 16px 20px calc(16px + env(safe-area-inset-bottom));
    border-top: 1px solid #dce5d2;
    background: #f9fbf6f5;
    box-shadow: 0 -5px 25px #35522408;
    backdrop-filter: blur(18px);
}
.selection-bar strong {
    display: block;
    font-size: 13px;
}
.selection-bar > div > span {
    display: block;
    margin-top: 5px;
    color: #889b75;
    font-size: 10px;
}
.selection-bar > button {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 45px;
    padding: 0 15px;
    border: 0;
    border-radius: 12px;
    background: #345d3e;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
}
.selection-bar > button > svg {
    width: 16px;
    height: 16px;
}
.selection-bar > button:disabled {
    opacity: 0.45;
}
.is-selecting .approval-content {
    padding-bottom: calc(120px + env(safe-area-inset-bottom));
}
.scroll-top {
    position: fixed;
    right: max(22px, calc((100vw - 500px) / 2 + 22px));
    bottom: calc(24px + env(safe-area-inset-bottom));
    z-index: 20;
    display: grid;
    place-items: center;
    width: 45px;
    height: 45px;
    border: 1px solid #315e42;
    border-radius: 16px;
    background: #315e42;
    box-shadow: 0 6px 20px #284b3826;
    color: #fff;
}
.scroll-top svg {
    width: 19px;
    height: 19px;
}
.is-selecting .scroll-top {
    bottom: calc(105px + env(safe-area-inset-bottom));
}
.pagination {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
    color: #8a9979;
    font-size: 10px;
}
.pagination a {
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 44px;
    color: #527342;
    text-decoration: none;
}
.pagination > :last-child {
    justify-self: end;
}
.pagination svg {
    width: 15px;
    height: 15px;
}
.filter-form label,
.field-label,
.decision-form label {
    display: block;
    margin: 0 0 10px;
    color: #405b36;
    font-size: 13px;
    font-weight: 700;
}
.filter-form label > span,
.decision-form label > span {
    display: block;
    margin-top: 6px;
    color: #88977a;
    font-size: 11px;
    font-weight: 400;
    line-height: 1.8;
}
.month-field {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 26px;
    padding: 0 13px;
    border: 1px solid #dfe6d7;
    border-radius: 12px;
    background: #fff;
}
.month-field > svg {
    width: 17px;
    height: 17px;
    color: #83966e;
}
.month-field input {
    flex: 1;
    min-width: 0;
    padding: 13px 0;
    border: 0;
    background: transparent;
    color: #48643c;
    box-shadow: none;
    font-size: 13px;
}
.month-field button {
    display: grid;
    place-items: center;
    width: 30px;
    height: 44px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #92a082;
}
.month-field button > svg {
    width: 15px;
    height: 15px;
}
.sort-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.sort-options button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 47px;
    padding: 10px 8px;
    border: 1px solid #e0e7d8;
    border-radius: 12px;
    background: #fff;
    color: #889879;
    font-size: 12px;
}
.sort-options button.active {
    border-color: #c6d5b7;
    background: #eff4e7;
    color: #65804c;
}
.sort-options button > svg {
    width: 14px;
    height: 14px;
}
.sheet-actions {
    display: flex;
    gap: 10px;
}
.secondary-button,
.primary-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 48px;
    padding: 12px 15px;
    border: 1px solid #dfe6d5;
    border-radius: 13px;
    background: #f8faf4;
    color: #78915e;
    font-size: 12px;
    font-weight: 700;
}
.secondary-button {
    min-width: 90px;
}
.primary-button {
    flex: 1;
    border-color: #345e3d;
    background: #345e3d;
    color: #fff;
}
.primary-button > svg {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
}
.reject-button {
    border-color: #ecdcd5;
    background: #fcf6f3;
    color: #b17d67;
}
.reject-submit {
    border-color: #ad6c57;
    background: #ad6c57;
}
.sheet-actions button:disabled {
    opacity: 0.5;
}
.detail-person {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}
.detail-person > div {
    min-width: 0;
}
.detail-person h3 {
    margin: 0 0 6px;
    color: #2d4934;
    font-size: 15px;
    font-weight: 800;
    line-height: 1.7;
    overflow-wrap: anywhere;
}
.detail-person p {
    margin: 0;
    color: #89987a;
    font-size: 11px;
    line-height: 1.8;
}
.detail-leave {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    padding: 17px;
    border: 1px solid #e5ecd9;
    border-radius: 16px;
    background: #f3f7ec;
}
.detail-leave > span {
    display: flex;
    align-items: center;
    gap: 7px;
    flex: 1;
    color: #6c8454;
    font-size: 12px;
    font-weight: 700;
}
.detail-leave svg {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
}
.detail-leave > strong {
    color: #759251;
    font-size: 24px;
    font-weight: 800;
}
.detail-leave small {
    font-size: 11px;
    font-weight: 500;
}
.detail-leave > p {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    width: 100%;
    margin: 0;
    color: #859674;
    font-size: 12px;
    line-height: 1.8;
}
.reason-block,
.approval-flow {
    margin-top: 24px;
}
.reason-block h3,
.approval-flow h3 {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 12px;
    color: #49643c;
    font-size: 12px;
    font-weight: 700;
}
.reason-block h3 > svg,
.approval-flow h3 > svg {
    width: 15px;
    height: 15px;
    color: #8aa16e;
}
.reason-block > p {
    margin: 0 0 8px;
    color: #6e8260;
    font-size: 12px;
    line-height: 1.9;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.reason-block > small {
    color: #9aa78d;
    font-size: 10px;
}
.approval-flow ol {
    display: grid;
    gap: 15px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.approval-flow li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.step-number {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 26px;
    height: 26px;
    border: 1px solid #e1e7d9;
    border-radius: 9px;
    background: #f4f7ed;
    color: #99a78b;
    font-size: 10px;
}
.step-number svg {
    width: 13px;
    height: 13px;
}
.pending .step-number,
.approved .step-number {
    border-color: #d4e1c5;
    background: #e8f1de;
    color: #739356;
}
.approval-flow li strong {
    display: block;
    margin: 2px 0 5px;
    color: #637f50;
    font-size: 11px;
}
.approval-flow li small {
    color: #9ba88c;
    font-size: 10px;
    line-height: 1.7;
}
.approval-flow li p {
    margin: 5px 0 0;
    color: #869975;
    font-size: 11px;
    line-height: 1.8;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.decision-form {
    margin-top: 26px;
    padding-top: 20px;
    border-top: 1px solid #e7ecdf;
}
.decision-form textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #dfe6d6;
    border-radius: 12px;
    background: #fff;
    color: #526d43;
    font-size: 12px;
    line-height: 1.8;
    resize: vertical;
}
.decision-form textarea::placeholder {
    color: #a2ad96;
}
.decision-form textarea[aria-invalid='true'] {
    border-color: #c1927b;
}
.form-error {
    margin: 10px 0 0;
    color: #ae735b;
    font-size: 12px;
    line-height: 1.8;
}
.bulk-summary {
    text-align: center;
}
.bulk-summary > span {
    display: grid;
    place-items: center;
    width: 55px;
    height: 55px;
    margin: 0 auto 15px;
    border: 1px solid #dde7ce;
    border-radius: 18px;
    background: #edf4e4;
    color: #82a05f;
}
.bulk-summary > strong {
    display: block;
    color: #4f703d;
    font-size: 20px;
    font-weight: 800;
}
.bulk-summary > p {
    margin: 9px 0 20px;
    color: #91a080;
    font-size: 12px;
}
.bulk-list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.bulk-list li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 0;
    border-bottom: 1px solid #e9eedf;
    color: #6d8853;
    font-size: 12px;
}
.bulk-list li small {
    display: block;
    margin-top: 5px;
    color: #9cab8d;
    font-size: 10px;
}
.bulk-list li > svg {
    width: 15px;
    height: 15px;
    color: #8ca776;
}
a:focus-visible,
button:focus-visible,
input:focus-visible,
textarea:focus-visible {
    outline: 3px solid #b7c79b;
    outline-offset: 3px;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (hover: hover) {
    .request-card:hover {
        border-color: #b9cda5;
        background: #fdfff9;
    }
}
@media (max-width: 359px) {
    .approval-header {
        padding-inline: 12px;
    }
    .approval-content {
        padding-inline: 12px;
    }
    .header-row {
        grid-template-columns: 84px minmax(0, 1fr) 84px;
        gap: 4px;
    }
    .header-actions {
        gap: 4px;
    }
    .search-field {
        gap: 8px;
        padding-inline: 11px;
    }
    .search-field input {
        font-size: 11px;
    }
    .request-person {
        padding-inline: 13px;
    }
    .request-details {
        margin-inline: 13px;
        padding: 12px;
    }
    .person-copy > strong {
        font-size: 12px;
    }
    .request-period {
        font-size: 10px;
    }
    .request-footer {
        padding-inline: 13px;
        font-size: 8px;
    }
    .selection-bar {
        gap: 8px;
        padding-inline: 14px;
    }
    .selection-bar > button {
        padding-inline: 10px;
        font-size: 10px;
    }
    .sort-options button {
        font-size: 11px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .search-spinner {
        animation: none;
    }
}
</style>
