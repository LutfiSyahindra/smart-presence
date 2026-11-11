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
watch(
    [
        () => props.requests.data.map((item) => `${item.id}:${item.can_decide}:${item.current_level}`).join('|'),
        () => props.requests.current_page,
        () => props.filters,
    ],
    cancelSelection,
);
watch(
    () => props.requests.data,
    (requests) => {
        if (selected.value) selected.value = requests.find((item) => item.id === selected.value?.id) ?? null;
    },
);
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

<style scoped src="./approval-workspace.css"></style>
