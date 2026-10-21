<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bell, CheckCircle2, Inbox, Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import '../approval-workflow.css';
import CutiRequestDetail from '../cuti/components/CutiRequestDetail.vue';
import { date, days, labels } from '../cuti/formatters';
import type { CutiRow } from '../cuti/types';
import FlashMessage from '../setting/components/FlashMessage.vue';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';

const props = defineProps<{
    requests: Paginated<CutiRow>;
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
const search = ref(props.filters.search);
let timer: ReturnType<typeof setTimeout> | undefined;
const applySearch = () => {
    if (timer) clearTimeout(timer);
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
                        />
                    </form>
                </header>
                <div v-if="requests.data.length" class="al-table-wrap">
                    <table class="al-table">
                        <thead>
                            <tr>
                                <th>Pemohon</th>
                                <th>Pengajuan cuti</th>
                                <th>Status & progres</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cuti in requests.data" :key="cuti.id">
                                <td>
                                    <strong>{{ cuti.nama_pegawai }}</strong>
                                    <small>{{ cuti.unit }} · {{ cuti.jabatan }}</small>
                                    <small>#CUTI-{{ cuti.id }} · {{ cuti.created_at }}</small>
                                </td>
                                <td>
                                    <strong>{{ cuti.nama_cuti || 'Cuti' }}</strong>
                                    <small>{{ date(cuti.tanggal_mulai) }} s.d. {{ date(cuti.tanggal_selesai) }}</small>
                                    <small>{{ cuti.jumlah_hari ?? days(cuti.tanggal_mulai, cuti.tanggal_selesai) }} hari kalender</small>
                                </td>
                                <td>
                                    <span class="al-status" :class="cuti.status">{{ labels[cuti.status] }}</span>
                                    <small v-if="cuti.current_level">Tingkat {{ cuti.current_level }} / {{ cuti.approvals.length }}</small>
                                    <small v-else>Seluruh approver telah menyetujui</small>
                                </td>
                                <td class="text-end">
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        :class="cuti.can_decide ? 'btn-primary' : 'btn-outline-secondary'"
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
    </AppLayout>
</template>

<style scoped>
.notification-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px 16px;
    border: 1px solid transparent;
    border-radius: 9px;
    color: #81899c;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
}
.notification-tab.active {
    border-color: #dedaf6;
    background: #f1effc;
    color: #6f60c6;
}
.notification-tab svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.notification-tab:focus-visible {
    outline: 2px solid #6f60c6;
    outline-offset: 2px;
}
</style>
