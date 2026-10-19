<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, CheckCircle2, Clock3, Inbox, Plus, Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import '../approval-workflow.css';
import CrudModal from '../setting/components/CrudModal.vue';
import FlashMessage from '../setting/components/FlashMessage.vue';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';
import CutiRequestDetail from './components/CutiRequestDetail.vue';
import { date, days, labels } from './formatters';
import type { CutiRow } from './types';

type Scope = 'mine' | 'inbox' | 'history' | 'all';
const props = defineProps<{
    requests: Paginated<CutiRow>;
    filters: { scope: Scope; status: string; search: string };
    overview: { mine_pending: number; inbox: number; mine_approved: number };
    employee: { nama: string; jabatan: string | null; unit: string | null } | null;
    approvalPreview: { level: number; name: string }[];
    unavailableReason: string | null;
    canViewAll: boolean;
    canSubmit: boolean;
    today: string;
    leaveOptions: { id: number; nama: string; is_tahunan: boolean; tahun: number; jumlah_hari: number; sisa: number }[];
}>();
const baseUrl = '/absensi/admin-web/cuti';
const breadcrumbs: BreadcrumbItem[] = [
    ...(props.canSubmit ? [{ title: 'Dashboard', href: '/absensi/admin-web/dashboard' }] : []),
    { title: 'Pengajuan Cuti', href: baseUrl },
];
const creating = ref(false);
const selected = ref<CutiRow | null>(null);
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const form = useForm({ master_cuti_id: '', tanggal_mulai: '', tanggal_selesai: '', alasan: '' });
const optionYear = computed(() => Number((form.tanggal_mulai || props.today).slice(0, 4)));
const availableTypes = computed(() => props.leaveOptions.filter((option) => !option.is_tahunan || option.tahun === optionYear.value));
const selectedType = computed(() => availableTypes.value.find((option) => String(option.id) === String(form.master_cuti_id)));
const formErrors = computed(() => form.errors as Record<string, string | undefined>);
const tabs = computed(() => [
    { key: 'mine' as Scope, label: 'Pengajuan saya' },
    { key: 'inbox' as Scope, label: `Perlu persetujuan (${props.overview.inbox})` },
    { key: 'history' as Scope, label: 'Riwayat persetujuan' },
    ...(props.canViewAll ? [{ key: 'all' as Scope, label: 'Semua pengajuan' }] : []),
]);
let timer: ReturnType<typeof setTimeout> | undefined;
const applyFilters = (scope: Scope = props.filters.scope) => {
    if (timer) clearTimeout(timer);
    router.get(
        baseUrl,
        { scope, search: search.value.trim() || undefined, status: status.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const changeScope = (scope: Scope) => {
    status.value = '';
    applyFilters(scope);
};
watch(search, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => applyFilters(), 400);
});
watch(
    () => props.filters,
    (filters) => {
        search.value = filters.search;
        status.value = filters.status;
    },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
const create = () => {
    form.reset();
    form.clearErrors();
    creating.value = true;
};
const submit = () => form.post(baseUrl, { preserveScroll: true, onSuccess: () => (creating.value = false) });
</script>

<template>
    <Head title="Pengajuan Cuti" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><CalendarDays /> Pengajuan & persetujuan</span>
                    <h1>Pengajuan Cuti</h1>
                    <p>Ajukan cuti, pantau setiap tingkat persetujuan, dan proses pengajuan yang menjadi tanggung jawab Anda.</p>
                </div>
                <button v-if="canSubmit" type="button" class="btn btn-primary al-button" :disabled="!!unavailableReason" @click="create">
                    <Plus /> Ajukan cuti
                </button>
            </section>
            <div v-if="canSubmit && unavailableReason" class="al-info" role="status">{{ unavailableReason }}</div>
            <div class="al-stats">
                <article>
                    <span class="al-stat-icon"><Clock3 /></span>
                    <div>
                        <strong>{{ overview.mine_pending }}</strong
                        ><span>Pengajuan saya menunggu</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon amber"><Inbox /></span>
                    <div>
                        <strong>{{ overview.inbox }}</strong
                        ><span>Perlu persetujuan saya</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon cyan"><CheckCircle2 /></span>
                    <div>
                        <strong>{{ overview.mine_approved }}</strong
                        ><span>Pengajuan saya disetujui</span>
                    </div>
                </article>
            </div>
            <section class="al-panel">
                <div class="al-tabs" role="group" aria-label="Jenis daftar cuti">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        :class="{ active: filters.scope === tab.key }"
                        :aria-pressed="filters.scope === tab.key"
                        @click="changeScope(tab.key)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
                <header class="al-panel-header">
                    <div>
                        <h2>{{ tabs.find((tab) => tab.key === filters.scope)?.label }}</h2>
                        <p>
                            {{
                                filters.scope === 'inbox'
                                    ? 'Hanya pengajuan yang sudah mencapai giliran Anda.'
                                    : 'Buka detail untuk melihat alur dan catatan persetujuan.'
                            }}
                        </p>
                    </div>
                    <div class="al-filter-group">
                        <form class="al-search" role="search" @submit.prevent="applyFilters()">
                            <Search /><input
                                v-model="search"
                                type="search"
                                maxlength="150"
                                aria-label="Cari pengajuan cuti"
                                placeholder="Cari pegawai, jabatan, unit..."
                            />
                        </form>
                        <select
                            v-if="filters.scope !== 'inbox'"
                            v-model="status"
                            class="al-filter-select"
                            aria-label="Filter status cuti"
                            @change="applyFilters()"
                        >
                            <option value="">Semua status</option>
                            <option value="pending">Menunggu</option>
                            <option value="approved">Disetujui</option>
                            <option value="rejected">Ditolak</option>
                        </select>
                    </div>
                </header>
                <div v-if="requests.data.length" class="al-table-wrap">
                    <table class="al-table">
                        <thead>
                            <tr>
                                <th>Pemohon</th>
                                <th>Tanggal cuti</th>
                                <th>Status & progres</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cuti in requests.data" :key="cuti.id">
                                <td>
                                    <strong>{{ cuti.nama_pegawai }}</strong
                                    ><small>{{ cuti.unit }} · {{ cuti.jabatan }}</small
                                    ><small>#CUTI-{{ cuti.id }} · {{ cuti.created_at }}</small>
                                </td>
                                <td>
                                    <strong>{{ date(cuti.tanggal_mulai) }}</strong
                                    ><small>{{ cuti.nama_cuti || 'Pengajuan sebelum master cuti' }}</small
                                    ><small>s.d. {{ date(cuti.tanggal_selesai) }}</small
                                    ><small>{{ days(cuti.tanggal_mulai, cuti.tanggal_selesai) }} hari kalender</small>
                                </td>
                                <td>
                                    <span class="al-status" :class="cuti.status">{{ labels[cuti.status] }}</span
                                    ><small v-if="cuti.current_level"
                                        >Tingkat {{ cuti.current_level }} / {{ cuti.approvals.length }} ·
                                        {{ cuti.approvals.find((step) => step.level === cuti.current_level)?.name }}</small
                                    ><small v-else>{{
                                        cuti.status === 'approved' ? 'Seluruh approver telah menyetujui' : 'Alur persetujuan dihentikan'
                                    }}</small>
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
                    <Inbox />
                    <h3>
                        {{
                            search || status
                                ? 'Pengajuan tidak ditemukan'
                                : filters.scope === 'inbox'
                                  ? 'Tidak ada pengajuan yang perlu disetujui'
                                  : 'Belum ada pengajuan cuti'
                        }}
                    </h3>
                    <p>
                        {{
                            filters.scope === 'inbox'
                                ? 'Pengajuan akan muncul saat sudah mencapai tingkat approval Anda.'
                                : 'Pengajuan cuti beserta progres persetujuannya akan tampil di sini.'
                        }}
                    </p>
                </div>
                <Pagination :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" />
            </section>
        </div>

        <CrudModal
            :open="creating"
            title="Ajukan cuti"
            description="Pengajuan dikirim ke approver sesuai jabatan Anda."
            @close="!form.processing && (creating = false)"
        >
            <form class="al-form" @submit.prevent="submit">
                <div class="al-form-body">
                    <div class="al-info">
                        <strong>{{ employee?.nama }}</strong
                        ><br />{{ employee?.unit }} · {{ employee?.jabatan }}
                    </div>
                    <p v-if="formErrors.cuti" class="al-error" role="alert">{{ formErrors.cuti }}</p>
                    <label class="al-field">
                        <span>Jenis cuti <b>*</b></span>
                        <select v-model="form.master_cuti_id" required>
                            <option value="" disabled>Pilih jenis cuti</option>
                            <option v-for="option in availableTypes" :key="option.id" :value="String(option.id)">
                                {{ option.nama }} ·
                                {{ option.is_tahunan ? `sisa ${option.sisa} hari (${option.tahun})` : `maksimal ${option.jumlah_hari} hari` }}
                            </option>
                        </select>
                        <small v-if="form.errors.master_cuti_id" class="al-error">{{ form.errors.master_cuti_id }}</small>
                        <small class="al-help"
                            >Kuota tahunan mengikuti tahun tanggal mulai. Pengajuan menunggu sudah diperhitungkan pada sisa kuota.</small
                        >
                    </label>
                    <div class="al-date-fields">
                        <label class="al-field"
                            ><span>Tanggal mulai <b>*</b></span
                            ><input v-model="form.tanggal_mulai" type="date" :min="today" required /><small
                                v-if="form.errors.tanggal_mulai"
                                class="al-error"
                                >{{ form.errors.tanggal_mulai }}</small
                            ></label
                        ><label class="al-field"
                            ><span>Tanggal selesai <b>*</b></span
                            ><input v-model="form.tanggal_selesai" type="date" :min="form.tanggal_mulai || today" required /><small
                                v-if="form.errors.tanggal_selesai"
                                class="al-error"
                                >{{ form.errors.tanggal_selesai }}</small
                            ></label
                        >
                    </div>
                    <p v-if="days(form.tanggal_mulai, form.tanggal_selesai)" class="al-help">
                        Durasi: {{ days(form.tanggal_mulai, form.tanggal_selesai) }} hari kalender.
                        <template v-if="selectedType"
                            >{{
                                selectedType.is_tahunan ? `Sisa kuota: ${selectedType.sisa}` : `Batas durasi: ${selectedType.jumlah_hari}`
                            }}
                            hari.</template
                        >
                    </p>
                    <label class="al-field"
                        ><span>Alasan cuti <b>*</b></span
                        ><textarea v-model="form.alasan" rows="4" required maxlength="2000" placeholder="Tuliskan alasan pengajuan cuti..." /><small
                            v-if="form.errors.alasan"
                            class="al-error"
                            >{{ form.errors.alasan }}</small
                        ></label
                    >
                    <div>
                        <div class="al-section-label"><strong>Alur persetujuan Anda</strong></div>
                        <ol class="al-preview">
                            <li v-for="step in approvalPreview" :key="step.level">
                                <span>{{ step.level }}</span
                                >{{ step.name }}
                            </li>
                        </ol>
                        <p class="al-help">Cuti disetujui setelah seluruh approver menyetujui secara berurutan.</p>
                    </div>
                </div>
                <footer class="al-form-footer">
                    <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="creating = false">Batal</button
                    ><button type="submit" class="btn btn-primary" :disabled="form.processing">
                        {{ form.processing ? 'Mengirim...' : 'Kirim pengajuan' }}
                    </button>
                </footer>
            </form>
        </CrudModal>

        <CutiRequestDetail :request="selected" @close="selected = null" />
    </AppLayout>
</template>
