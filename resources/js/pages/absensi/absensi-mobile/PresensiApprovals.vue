<script setup lang="ts">
import type { PresensiApprovalItem } from '@/components/PresensiApprovalContent.vue';
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
    FolderOpen,
    MapPin,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { date } from '../admin-web/cuti/formatters';
import CutiSheet from './components/CutiSheet.vue';
import MobileBackLink from './components/MobileBackLink.vue';
import PresensiApprovalReview from './components/PresensiApprovalReview.vue';
import { actionLabel, initials, presensiLabels } from './presensi-approval';

type Filters = { search: string; month: string; sort: 'oldest' | 'newest' };
const props = defineProps<{
    scope: 'mine' | 'delegation';
    filters: Filters;
    totalRequests: number;
    requests: {
        data: PresensiApprovalItem[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
const page = usePage<SharedData>();
const base = '/absensi/mobile/notifikasi/presensi';
const search = ref(props.filters.search);
const loading = ref(false);
const filterOpen = ref(false);
const draft = reactive({ month: props.filters.month, sort: props.filters.sort });
const selected = ref<PresensiApprovalItem | null>(null);
const deciding = ref(false);
const defaultSort = computed(() => (props.scope === 'mine' ? 'newest' : 'oldest'));
const activeFilterCount = computed(() => Number(Boolean(props.filters.month)) + Number(props.filters.sort !== defaultSort.value));
const filtered = computed(() => Boolean(props.filters.search || props.filters.month));
const periodLabel = computed(() =>
    props.filters.month
        ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(
              new Date(props.filters.month + '-01T12:00:00+07:00'),
          )
        : 'Semua periode',
);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(changes: Partial<Filters> = {}, scope = props.scope) {
    if (deciding.value) return;
    clearSearchTimer();
    filterOpen.value = false;
    selected.value = null;
    const next = { ...props.filters, search: search.value.trim(), ...changes };
    search.value = next.search;
    router.get(
        base,
        { scope, ...next },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}
function changeScope(scope: 'mine' | 'delegation') {
    if (scope === props.scope || loading.value) return;
    applyFilters({ sort: scope === 'mine' ? 'newest' : 'oldest' }, scope);
}
function openFilter() {
    draft.month = props.filters.month;
    draft.sort = props.filters.sort;
    filterOpen.value = true;
}
function openDetail(item: PresensiApprovalItem) {
    if (!loading.value) selected.value = item;
}
function scheduleSearch() {
    clearSearchTimer();
    searchTimer = setTimeout(() => applyFilters(), 400);
}
function clearSearchTimer() {
    if (searchTimer) clearTimeout(searchTimer);
}
function scrollTop() {
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
}
watch(
    () => props.filters.search,
    (value) => (search.value = value),
);
watch(
    () => props.requests.data,
    (requests) => {
        if (selected.value) selected.value = requests.find((item) => item.id === selected.value?.id) ?? null;
    },
);
const removeBackHandler = registerMobileBackHandler(() => {
    if (selected.value) {
        if (!deciding.value) selected.value = null;
        return true;
    }
    if (filterOpen.value) {
        filterOpen.value = false;
        return true;
    }
    return false;
});
onBeforeUnmount(() => {
    clearSearchTimer();
    removeBackHandler();
});
</script>

<template>
    <Head title="Persetujuan Presensi" />
    <main class="approval-workspace">
        <div class="approval-shell">
            <header class="approval-header">
                <div class="header-row">
                    <MobileBackLink href="/absensi/mobile/notifikasi?tab=approvals" class="icon-button" aria-label="Kembali ke Inbox"
                        ><ArrowLeft
                    /></MobileBackLink>
                    <div class="header-title">
                        <h1>Presensi</h1>
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
                        type="search"
                        maxlength="150"
                        aria-label="Cari nama atau unit"
                        placeholder="Cari nama atau unit..."
                        @input="scheduleSearch"
                    />
                    <button v-if="search" type="button" aria-label="Hapus pencarian" @click="applyFilters({ search: '' })"><X /></button>
                    <span v-if="loading" class="search-spinner" role="status" aria-label="Memuat pengajuan"></span>
                </form>
                <nav class="scope-tabs" aria-label="Jenis pengajuan presensi">
                    <button
                        type="button"
                        :class="{ active: scope === 'delegation' }"
                        :aria-current="scope === 'delegation' ? 'page' : undefined"
                        :disabled="loading"
                        @click="changeScope('delegation')"
                    >
                        Butuh persetujuan
                    </button>
                    <button
                        type="button"
                        :class="{ active: scope === 'mine' }"
                        :aria-current="scope === 'mine' ? 'page' : undefined"
                        :disabled="loading"
                        @click="changeScope('mine')"
                    >
                        Presensi saya
                    </button>
                </nav>
            </header>
            <div class="approval-content" :aria-busy="loading">
                <div v-if="page.props.flash.success" class="notice success" role="status">
                    <CircleCheck /><span>{{ page.props.flash.success }}</span>
                </div>
                <div v-if="page.props.flash.error" class="notice" role="alert">{{ page.props.flash.error }}</div>
                <div class="queue-heading">
                    <div>
                        <span class="eyebrow">RUANG PERSETUJUAN</span>
                        <h2>
                            {{ scope === 'delegation' ? 'Menunggu Anda' : 'Presensi saya' }}<span>{{ totalRequests }}</span>
                        </h2>
                    </div>
                    <span class="queue-mark" aria-hidden="true"><ShieldCheck /></span>
                </div>
                <p class="queue-description">
                    {{
                        scope === 'delegation'
                            ? 'Buka rincian untuk meninjau bukti lokasi, foto, dan alasan presensi di luar radius.'
                            : 'Pantau status dan alur persetujuan presensi Anda di luar radius.'
                    }}
                </p>
                <div class="queue-context">
                    <span><CalendarDays />{{ periodLabel }}</span
                    ><span>{{ requests.total }} pengajuan{{ filtered ? ' ditemukan' : '' }}</span>
                </div>
                <div v-if="filtered || activeFilterCount" class="filter-chips">
                    <button
                        v-if="filters.search"
                        type="button"
                        :aria-label="'Hapus pencarian ' + filters.search"
                        @click="applyFilters({ search: '' })"
                    >
                        <Search />{{ filters.search }}<X />
                    </button>
                    <button v-if="filters.month" type="button" aria-label="Hapus filter periode" @click="applyFilters({ month: '' })">
                        {{ periodLabel }}<X />
                    </button>
                    <button
                        v-if="filters.sort !== defaultSort"
                        type="button"
                        aria-label="Kembalikan urutan pengajuan"
                        @click="applyFilters({ sort: defaultSort })"
                    >
                        {{ filters.sort === 'newest' ? 'Terbaru dahulu' : 'Terlama dahulu' }}<X />
                    </button>
                </div>
                <div v-if="requests.data.length" class="request-list">
                    <button
                        v-for="item in requests.data"
                        :key="item.id"
                        type="button"
                        class="request-card"
                        :disabled="loading"
                        :aria-label="'Lihat rincian ' + actionLabel(item.action) + ' ' + item.name + ', ' + item.recorded_at"
                        @click="openDetail(item)"
                    >
                        <span class="request-person"
                            ><span class="person-avatar">{{ initials(item.name) }}</span
                            ><span class="person-copy"
                                ><strong>{{ item.name }}</strong
                                ><small>{{ item.unit || item.position }}</small></span
                            ><ChevronRight class="card-chevron" aria-hidden="true"
                        /></span>
                        <span class="request-details"
                            ><span class="leave-label"><MapPin />{{ actionLabel(item.action) }} di luar radius</span
                            ><span class="request-period"><Clock3 />{{ item.recorded_at }} WIB</span
                            ><span class="request-period">Tanggal shift: {{ date(item.date) }}</span></span
                        >
                        <span class="request-footer"
                            ><span class="presensi-status" :class="item.status"
                                ><Clock3 v-if="item.status === 'pending'" /><Check v-else-if="item.status === 'approved'" /><X v-else />{{
                                    item.can_decide ? 'Menunggu keputusan Anda' : presensiLabels[item.status]
                                }}<small v-if="item.status === 'pending' && item.current_level">
                                    · {{ item.current_level }}/{{ item.approvals.length }}</small
                                ></span
                            ><span>Tinjau<ArrowRight /></span
                        ></span>
                    </button>
                </div>
                <section v-else class="empty-card">
                    <div class="empty-art" aria-hidden="true">
                        <span class="art-orbit orbit-one"></span><span class="art-orbit orbit-two"></span><span class="art-spark spark-one"></span
                        ><span class="art-spark spark-two"></span><span class="art-paper"><MapPin /><i></i><i></i><i></i></span
                        ><span class="art-folder-back"></span><span class="art-folder-front"><FolderOpen /></span
                        ><span class="art-badge"><Search v-if="filtered" /><MapPin v-else-if="scope === 'mine'" /><CheckCheck v-else /></span>
                    </div>
                    <span class="empty-eyebrow">{{
                        filtered ? 'COBA FILTER LAIN' : scope === 'mine' ? 'RIWAYAT PRESENSI' : 'SEMUA SUDAH TERTANGANI'
                    }}</span>
                    <h2>{{ filtered ? 'Pengajuan tidak ditemukan' : 'Tidak ada pengajuan' }}</h2>
                    <p>
                        {{
                            filtered
                                ? 'Belum ada pengajuan yang sesuai dengan pencarian atau periode pilihan Anda.'
                                : scope === 'mine'
                                  ? 'Pengajuan presensi Anda di luar radius akan muncul di sini beserta status persetujuannya.'
                                  : 'Permintaan persetujuan presensi akan muncul di sini saat membutuhkan keputusan Anda.'
                        }}
                    </p>
                    <button v-if="filtered" type="button" class="reset-search" @click="applyFilters({ search: '', month: '', sort: defaultSort })">
                        Tampilkan semua pengajuan<ArrowRight />
                    </button>
                    <span v-else class="empty-note"><ShieldCheck />Satu ruang, semua persetujuan.</span>
                </section>
                <nav v-if="requests.last_page > 1" class="pagination" aria-label="Halaman persetujuan presensi">
                    <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" preserve-scroll @click="clearSearchTimer"
                        ><ChevronLeft />Sebelumnya</Link
                    ><span v-else></span><span>{{ requests.current_page }} / {{ requests.last_page }}</span
                    ><Link v-if="requests.next_page_url" :href="requests.next_page_url" preserve-scroll @click="clearSearchTimer"
                        >Berikutnya<ChevronRight /></Link
                    ><span v-else></span>
                </nav>
                <p class="privacy-note">
                    <ShieldCheck />{{
                        scope === 'delegation'
                            ? 'Hanya pengajuan yang menjadi giliran persetujuan Anda.'
                            : 'Bukti dan waktu presensi tetap tersimpan sesuai pengajuan.'
                    }}
                </p>
            </div>
            <button v-if="requests.data.length > 3" type="button" class="scroll-top" aria-label="Kembali ke atas" @click="scrollTop">
                <ArrowUp />
            </button>
        </div>
    </main>
    <CutiSheet
        :open="filterOpen"
        title="Filter pengajuan"
        description="Temukan pengajuan sesuai periode yang Anda butuhkan."
        @close="filterOpen = false"
    >
        <div class="filter-form">
            <label for="presensi-month">Periode presensi<span>Kosongkan untuk menampilkan seluruh periode.</span></label>
            <div class="month-field">
                <CalendarDays /><input id="presensi-month" v-model="draft.month" type="month" /><button
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
                <button type="button" class="secondary-button" @click="applyFilters({ search: '', month: '', sort: defaultSort })">Reset</button
                ><button type="button" class="primary-button" @click="applyFilters({ month: draft.month, sort: draft.sort })">
                    Terapkan filter<ArrowRight />
                </button></div
        ></template>
    </CutiSheet>
    <CutiSheet
        :open="Boolean(selected)"
        title="Rincian presensi"
        description="Tinjau bukti dan alur persetujuan presensi di luar radius."
        :busy="deciding"
        @close="selected = null"
    >
        <PresensiApprovalReview v-if="selected" :key="selected.id" :item="selected" @processing="deciding = $event" @decided="selected = null" />
    </CutiSheet>
</template>

<style scoped src="./approval-workspace.css"></style>
<style scoped>
.scope-tabs {
    display: flex;
    gap: 4px;
    margin-top: 14px;
    padding: 4px;
    border: 1px solid #e3e9de;
    border-radius: 13px;
    background: #edf1e7;
}
.scope-tabs button {
    flex: 1;
    min-height: 42px;
    padding: 8px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #7d8b72;
    font-size: 11px;
    font-weight: 700;
}
.scope-tabs button.active {
    background: #fff;
    color: #345d3e;
    box-shadow: 0 2px 6px #345d3e0a;
}
.card-chevron {
    flex-shrink: 0;
    width: 17px;
    height: 17px;
    color: #8a9f76;
}
.request-card:disabled {
    opacity: 0.6;
}
.presensi-status {
    flex-wrap: wrap;
    min-width: 0;
}
.presensi-status.rejected {
    color: #ae735b;
}
.presensi-status small {
    font-size: inherit;
}
.request-footer > span:last-child {
    flex-shrink: 0;
}
@media (max-width: 359px) {
    .header-row {
        grid-template-columns: 44px minmax(0, 1fr) 44px;
    }
}
</style>
