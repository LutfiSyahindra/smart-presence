<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck, CheckCircle2, Inbox, Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import '../approval-workflow.css';
import CutiRequestDetail from '../cuti/components/CutiRequestDetail.vue';
import { date, days, labels } from '../cuti/formatters';
import type { CutiRow } from '../cuti/types';
import CrudModal from '../setting/components/CrudModal.vue';
import FlashMessage from '../setting/components/FlashMessage.vue';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';
import { duration, requestCode, type ApprovalRequestRow } from './types';

const props = defineProps<{
    requests: Paginated<ApprovalRequestRow>;
    category: 'butuh-approval' | 'disetujui';
    filters: { search: string };
    overview: { inbox: number; approved: number };
}>();
const page = usePage<SharedData>();
const baseUrl = '/absensi/admin-web/notifikasi';
const categories = computed(() => [
    { key: 'butuh-approval', title: 'Pengajuan Butuh Approval', count: props.overview.inbox, icon: Inbox },
    { key: 'disetujui', title: 'Pengajuan Disetujui', count: props.overview.approved, icon: CheckCircle2 },
]);
const activeCategory = computed(() => categories.value.find((item) => item.key === props.category)!);
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    ...(page.props.portal.can_access_admin ? [{ title: 'Dashboard', href: '/absensi/admin-web/dashboard' }] : []),
    { title: 'Notifikasi', href: `${baseUrl}/butuh-approval` },
    { title: activeCategory.value.title, href: `${baseUrl}/${props.category}` },
]);
const selected = ref<CutiRow | null>(null);
const bulkForm = useForm({ request_ids: [] as number[], lembur_request_ids: [] as number[] });
const bulkReviewOpen = ref(false);
const selectableRequests = computed(() => (props.category === 'butuh-approval' ? props.requests.data.filter((cuti) => cuti.can_decide) : []));
const isSelected = (request: ApprovalRequestRow) =>
    (request.request_type === 'lembur' ? bulkForm.lembur_request_ids : bulkForm.request_ids).includes(request.id);
const selectedRequests = computed(() => selectableRequests.value.filter(isSelected));
const allSelected = computed(() => selectableRequests.value.length > 0 && selectedRequests.value.length === selectableRequests.value.length);
const someSelected = computed(() => selectedRequests.value.length > 0 && !allSelected.value);
const bulkError = computed(() => Object.values(bulkForm.errors)[0]);
const toggleAll = (event: Event) => {
    bulkForm.clearErrors();
    const checked = (event.target as HTMLInputElement).checked;
    bulkForm.request_ids = checked ? selectableRequests.value.filter((row) => row.request_type !== 'lembur').map((row) => row.id) : [];
    bulkForm.lembur_request_ids = checked ? selectableRequests.value.filter((row) => row.request_type === 'lembur').map((row) => row.id) : [];
};
const closeBulkReview = () => {
    if (!bulkForm.processing) bulkReviewOpen.value = false;
};
const approveSelected = () => {
    if (bulkForm.processing || !selectedRequests.value.length) return;
    if (timer) clearTimeout(timer);
    bulkForm.post(`${baseUrl}/approve-many`, {
        preserveScroll: true,
        onSuccess: () => {
            bulkReviewOpen.value = false;
            bulkForm.reset();
        },
        onError: () => (bulkReviewOpen.value = false),
    });
};
const search = ref(props.filters.search);
let timer: ReturnType<typeof setTimeout> | undefined;
const applySearch = () => {
    if (timer) clearTimeout(timer);
    if (bulkForm.processing) return;
    router.get(
        `${baseUrl}/${props.category}`,
        { search: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
watch(search, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(applySearch, 400);
});
watch(
    () => props.filters.search,
    (value) => {
        if (search.value !== value) search.value = value;
    },
);
watch(
    () => props.category,
    () => {
        if (timer) clearTimeout(timer);
        selected.value = null;
    },
);
watch(
    () => [props.requests, props.category],
    () => {
        bulkForm.reset();
        bulkReviewOpen.value = false;
    },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
</script>

<template>
    <Head :title="`Notifikasi - ${activeCategory.title}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><Bell /> Notifikasi pengajuan</span>
                    <h1>Notifikasi</h1>
                    <p>Pantau pengajuan yang membutuhkan approval Anda dan pengajuan yang telah disetujui.</p>
                </div>
            </section>
            <section class="al-panel">
                <nav class="al-tabs" aria-label="Kategori notifikasi">
                    <Link :href="`${baseUrl}/semua`" class="notification-tab"><Bell /> Semua notifikasi</Link>
                    <Link
                        v-for="item in categories"
                        :key="item.key"
                        :href="`${baseUrl}/${item.key}`"
                        class="notification-tab"
                        :class="{ active: category === item.key }"
                        :aria-current="category === item.key ? 'page' : undefined"
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        {{ item.title }} ({{ item.count }})
                    </Link>
                </nav>
                <header class="al-panel-header">
                    <div>
                        <h2>{{ activeCategory.title }}</h2>
                        <p>
                            {{
                                category === 'butuh-approval'
                                    ? 'Pengajuan yang sudah mencapai giliran approval Anda.'
                                    : 'Pengajuan yang telah disetujui oleh seluruh approver.'
                            }}
                        </p>
                    </div>
                    <form class="al-search" role="search" @submit.prevent="applySearch">
                        <Search />
                        <input
                            v-model="search"
                            type="search"
                            maxlength="150"
                            aria-label="Cari notifikasi pengajuan"
                            placeholder="Cari pegawai, jabatan, unit..."
                            :disabled="bulkForm.processing"
                        />
                    </form>
                </header>
                <div v-if="category === 'butuh-approval' && requests.data.length" class="bulk-approval-bar">
                    <div aria-live="polite">
                        <strong>{{ selectedRequests.length }} pengajuan dipilih</strong>
                        <small>Pilih pengajuan satu per satu atau pilih semua di halaman ini.</small>
                    </div>
                    <div class="bulk-approval-actions">
                        <button
                            v-if="selectedRequests.length"
                            type="button"
                            class="btn btn-sm btn-outline-secondary"
                            :disabled="bulkForm.processing"
                            @click="
                                bulkForm.reset();
                                bulkForm.clearErrors();
                            "
                        >
                            Batalkan pilihan
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-primary al-button"
                            :disabled="!selectedRequests.length || bulkForm.processing"
                            @click="bulkReviewOpen = true"
                        >
                            <CheckCheck /> Setujui terpilih ({{ selectedRequests.length }})
                        </button>
                    </div>
                </div>
                <p v-if="bulkError" class="al-error bulk-approval-error" role="alert">{{ bulkError }}</p>
                <div v-if="requests.data.length" class="al-table-wrap">
                    <table class="al-table">
                        <thead>
                            <tr>
                                <th v-if="category === 'butuh-approval'" class="bulk-select-cell">
                                    <input
                                        type="checkbox"
                                        class="bulk-checkbox"
                                        :checked="allSelected"
                                        :indeterminate="someSelected"
                                        :disabled="!selectableRequests.length || bulkForm.processing"
                                        aria-label="Pilih semua pengajuan di halaman ini"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th>Pemohon</th>
                                <th>Pengajuan cuti / lembur</th>
                                <th>Status & progres</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cuti in requests.data" :key="requestCode(cuti)" :class="{ 'bulk-selected-row': isSelected(cuti) }">
                                <td v-if="category === 'butuh-approval'" class="bulk-select-cell">
                                    <input
                                        v-if="cuti.can_decide && cuti.request_type !== 'lembur'"
                                        v-model="bulkForm.request_ids"
                                        type="checkbox"
                                        class="bulk-checkbox"
                                        :value="cuti.id"
                                        :disabled="bulkForm.processing"
                                        :aria-label="`Pilih pengajuan #${requestCode(cuti)} dari ${cuti.nama_pegawai}`"
                                        @change="bulkForm.clearErrors()"
                                    />
                                    <input
                                        v-else-if="cuti.can_decide"
                                        v-model="bulkForm.lembur_request_ids"
                                        type="checkbox"
                                        class="bulk-checkbox"
                                        :value="cuti.id"
                                        :disabled="bulkForm.processing"
                                        :aria-label="`Pilih pengajuan #${requestCode(cuti)} dari ${cuti.nama_pegawai}`"
                                        @change="bulkForm.clearErrors()"
                                    />
                                </td>
                                <td>
                                    <strong>{{ cuti.nama_pegawai }}</strong>
                                    <small>{{ cuti.unit }} · {{ cuti.jabatan }}</small>
                                    <small>#{{ requestCode(cuti) }} · {{ cuti.created_at }}</small>
                                </td>
                                <td>
                                    <template v-if="cuti.request_type === 'lembur'">
                                        <strong
                                            >{{ cuti.request_kind === 'usage' ? 'Penggunaan lembur' : 'Lembur' }} ·
                                            {{ duration(cuti.duration_minutes ?? 0) }}</strong
                                        >
                                        <small v-if="cuti.request_kind === 'usage'">{{
                                            cuti.usage_type === 'late_arrival' ? 'Telat masuk' : 'Pulang cepat'
                                        }}</small>
                                        <small>{{ date(cuti.tanggal_mulai) }}</small>
                                        <small>Shift {{ cuti.shift_name }}</small>
                                    </template>
                                    <template v-else>
                                        <strong>{{ cuti.nama_cuti || 'Cuti' }}</strong>
                                        <small>{{ date(cuti.tanggal_mulai) }} s.d. {{ date(cuti.tanggal_selesai) }}</small>
                                        <small>{{ cuti.jumlah_hari ?? days(cuti.tanggal_mulai, cuti.tanggal_selesai) }} hari kalender</small>
                                    </template>
                                </td>
                                <td>
                                    <span class="al-status" :class="cuti.status">{{ labels[cuti.status] }}</span>
                                    <small v-if="cuti.current_level">Tingkat {{ cuti.current_level }} / {{ cuti.approvals.length }}</small>
                                    <small v-else>Seluruh approver telah menyetujui</small>
                                </td>
                                <td class="text-end">
                                    <Link
                                        v-if="cuti.request_type === 'lembur'"
                                        :href="`${baseUrl}/pengajuan/lembur/${cuti.id}`"
                                        class="btn btn-sm"
                                        :class="cuti.can_decide ? 'btn-primary' : 'btn-outline-secondary'"
                                    >
                                        {{ cuti.can_decide ? 'Tinjau pengajuan' : 'Lihat detail' }}
                                    </Link>
                                    <button
                                        v-else
                                        type="button"
                                        class="btn btn-sm"
                                        :class="cuti.can_decide ? 'btn-primary' : 'btn-outline-secondary'"
                                        :disabled="bulkForm.processing"
                                        @click="selected = cuti"
                                    >
                                        {{ cuti.can_decide ? 'Tinjau pengajuan' : 'Lihat detail' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="al-empty">
                    <component :is="activeCategory.icon" />
                    <h3>
                        {{
                            search
                                ? 'Pengajuan tidak ditemukan'
                                : category === 'butuh-approval'
                                  ? 'Tidak ada pengajuan yang butuh approval'
                                  : 'Belum ada pengajuan disetujui'
                        }}
                    </h3>
                    <p>
                        {{
                            search
                                ? 'Coba cari nama pegawai, jabatan, atau unit lain.'
                                : category === 'butuh-approval'
                                  ? 'Pengajuan akan tampil saat mencapai giliran approval Anda.'
                                  : 'Pengajuan akan tampil setelah seluruh approver menyetujui.'
                        }}
                    </p>
                </div>
                <Pagination :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" />
            </section>
        </div>
        <CutiRequestDetail :request="selected" @close="selected = null" />
        <CrudModal
            :open="bulkReviewOpen"
            title="Setujui pengajuan terpilih"
            description="Periksa daftar pengajuan sebelum menyetujui sekaligus."
            @close="closeBulkReview"
        >
            <form class="al-form" @submit.prevent="approveSelected">
                <div class="al-form-body">
                    <p class="al-info">
                        {{ selectedRequests.length }} pengajuan akan disetujui pada tingkat approval Anda. Pengajuan yang masih memiliki approver
                        berikutnya akan diteruskan sesuai urutannya.
                    </p>
                    <ul class="bulk-review-list">
                        <li v-for="cuti in selectedRequests" :key="requestCode(cuti)">
                            <strong>#{{ requestCode(cuti) }} · {{ cuti.nama_pegawai }}</strong>
                            <span v-if="cuti.request_type === 'lembur'"
                                >{{ cuti.request_kind === 'usage' ? 'Penggunaan lembur' : 'Lembur' }} · {{ date(cuti.tanggal_mulai) }} ·
                                {{ duration(cuti.duration_minutes ?? 0) }}</span
                            >
                            <span v-else>{{ cuti.nama_cuti || 'Cuti' }} · {{ date(cuti.tanggal_mulai) }} s.d. {{ date(cuti.tanggal_selesai) }}</span>
                            <small>Persetujuan tingkat {{ cuti.current_level }}</small>
                        </li>
                    </ul>
                </div>
                <footer class="al-form-footer">
                    <button type="button" class="btn btn-outline-secondary" :disabled="bulkForm.processing" @click="closeBulkReview">Batal</button>
                    <button type="submit" class="btn btn-primary" :disabled="bulkForm.processing || !selectedRequests.length">
                        {{ bulkForm.processing ? 'Memproses...' : `Setujui ${selectedRequests.length} pengajuan` }}
                    </button>
                </footer>
            </form>
        </CrudModal>
    </AppLayout>
</template>

<style scoped>
.bulk-approval-bar,
.bulk-approval-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.bulk-approval-bar {
    justify-content: space-between;
    padding: 16px 24px;
    border-top: 1px solid var(--rs-surface-muted);
    background: var(--rs-canvas);
}
.bulk-approval-bar strong,
.bulk-approval-bar small {
    display: block;
    font-size: 12px;
}
.bulk-approval-bar small {
    margin-top: 5px;
    color: var(--rs-muted);
}
.al-table .bulk-select-cell {
    width: 44px;
    padding-right: 0;
}
.bulk-checkbox {
    width: 17px;
    height: 17px;
    border: 1px solid var(--rs-muted-light);
    border-radius: 4px;
    accent-color: var(--rs-primary);
    cursor: pointer;
}
.bulk-checkbox:focus-visible {
    outline: 2px solid var(--rs-primary);
    outline-offset: 3px;
}
.bulk-checkbox:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
.bulk-selected-row {
    background: var(--rs-canvas);
}
.bulk-approval-error {
    padding: 12px 24px;
}
.bulk-review-list {
    display: grid;
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.bulk-review-list li {
    display: grid;
    gap: 6px;
    padding: 14px;
    border: 1px solid var(--rs-primary-soft);
    border-radius: 10px;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.bulk-review-list span,
.bulk-review-list small {
    color: var(--rs-muted);
}
.notification-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 16px;
    border: 1px solid transparent;
    border-radius: 9px;
    color: var(--rs-muted);
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
}
.notification-tab.active {
    border-color: var(--rs-primary-border);
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
}
.notification-tab svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.notification-tab:focus-visible {
    outline: 2px solid var(--rs-primary);
    outline-offset: 2px;
}
</style>
