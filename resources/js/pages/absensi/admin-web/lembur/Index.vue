<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CalendarDays, CheckCircle2, Clock3, Hourglass, Plus, RotateCcw, Search, SlidersHorizontal, Timer } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { lemburDuration, lemburStatuses } from '../../absensi-mobile/lembur/types';
import '../approval-workflow.css';
import { date, labels } from '../cuti/formatters';
import SearchableSelect from '../presensi/components/SearchableSelect.vue';
import CrudModal from '../setting/components/CrudModal.vue';
import FlashMessage from '../setting/components/FlashMessage.vue';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';
import LemburDetailModal from './LemburDetailModal.vue';
import type { EmployeeOption, LemburFilters, MasterLemburRow } from './types';

const props = defineProps<{
    requests: Paginated<MasterLemburRow>;
    employeeOptions: EmployeeOption[];
    shifts: { value: number; label: string; description: string }[];
    units: string[];
    filters: LemburFilters;
    overview: { total: number; pending: number; overtime_minutes: number; usage_minutes: number };
    today: string;
    can: { create: boolean };
}>();
const base = '/absensi/admin-web/lembur';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Lembur', href: base },
];
const page = usePage();
const usageOnly = computed(() => props.filters.request_kind === 'usage');
const tableTitle = computed(() =>
    usageOnly.value ? 'Penggunaan lembur pegawai' : props.filters.request_kind === 'overtime' ? 'Lembur pegawai' : 'Transaksi lembur pegawai',
);
const filters = reactive({ ...props.filters });
watch(
    () => props.filters,
    (value) => Object.assign(filters, value),
);
const filter = () =>
    router.get(base, Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
const open = ref(false);
const selectedId = ref<number | null>(null);
const fileKey = ref(0);
const form = useForm({
    pegawai_id: '' as number | string,
    tanggal_lembur: props.today,
    shift_id: '' as number | string,
    before_minutes: 0,
    before_break_minutes: 0,
    after_minutes: 60,
    after_break_minutes: 0,
    compensation: 'pay',
    alasan: '',
    attachments: [] as File[],
});
const eligibleEmployees = computed(() => props.employeeOptions.filter((employee) => employee.has_account));
const netMinutes = computed(() =>
    Math.max(0, Number(form.before_minutes) + Number(form.after_minutes) - Number(form.before_break_minutes) - Number(form.after_break_minutes)),
);
const openForm = () => {
    form.reset();
    form.clearErrors();
    form.tanggal_lembur = props.today;
    const selected = eligibleEmployees.value.find((employee) => employee.value === filters.pegawai_id);
    form.pegawai_id = selected?.value ?? '';
    selectEmployee();
    fileKey.value++;
    open.value = true;
};
const selectEmployee = () => {
    const shift = eligibleEmployees.value.find((employee) => employee.value === form.pegawai_id)?.shift_id;
    form.shift_id = props.shifts.some((item) => item.value === shift) ? shift! : '';
};
const chooseFiles = (event: Event) => {
    form.attachments = Array.from((event.target as HTMLInputElement).files ?? []);
};
const save = () =>
    form.post(base, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
</script>

<template>
    <Head title="Lembur" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="al-page ml-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><Timer /> Menu utama</span>
                    <h1>Lembur</h1>
                    <p>Pantau penggunaan saldo lembur pegawai sesuai rentang tanggal yang dipilih.</p>
                </div>
                <button v-if="can.create" type="button" class="btn btn-primary al-button" @click="openForm"><Plus /> Catat lembur pegawai</button>
            </section>
            <form class="al-panel ml-filter-card" aria-labelledby="lembur-filter-title" @submit.prevent="filter">
                <header class="ml-filter-header">
                    <span class="ml-filter-icon"><SlidersHorizontal aria-hidden="true" /></span>
                    <div>
                        <h2 id="lembur-filter-title">Filter data lembur</h2>
                        <p>Pilih periode dan kriteria untuk menampilkan data pegawai.</p>
                    </div>
                </header>
                <div class="ml-filter-grid">
                    <label class="al-field">
                        <span>Tanggal mulai</span>
                        <input v-model="filters.start_date" type="date" min="1900-01-01" max="2100-12-31" required />
                    </label>
                    <label class="al-field">
                        <span>Tanggal akhir</span>
                        <input v-model="filters.end_date" type="date" :min="filters.start_date" max="2100-12-31" required />
                    </label>
                    <div class="al-field">
                        <span>Pegawai</span>
                        <SearchableSelect v-model="filters.pegawai_id" :options="employeeOptions" label="Pegawai" placeholder="Semua pegawai" />
                    </div>
                    <label class="al-field">
                        <span>Unit kerja</span>
                        <select v-model="filters.unit">
                            <option value="">Semua unit</option>
                            <option v-for="unit in units" :key="unit" :value="unit">{{ unit }}</option>
                        </select>
                    </label>
                    <label class="al-field">
                        <span>Pencarian</span>
                        <span class="ml-search-control">
                            <Search aria-hidden="true" />
                            <input v-model="filters.search" type="search" maxlength="150" placeholder="Nama, NIK, atau alasan" />
                        </span>
                    </label>
                    <label class="al-field">
                        <span>Jenis transaksi</span>
                        <select v-model="filters.request_kind">
                            <option value="usage">Penggunaan saldo lembur</option>
                            <option value="overtime">Pencatatan lembur</option>
                            <option value="all">Semua transaksi</option>
                        </select>
                    </label>
                    <label class="al-field">
                        <span>Status</span>
                        <select v-model="filters.status">
                            <option v-for="status in lemburStatuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                        </select>
                    </label>
                    <label class="al-field">
                        <span>Kompensasi</span>
                        <select v-model="filters.compensation">
                            <option value="">Semua kompensasi</option>
                            <option value="pay">Upah lembur</option>
                            <option value="time_off">Pengganti waktu libur</option>
                        </select>
                    </label>
                    <p v-for="(error, key) in page.props.errors" :key="key" class="al-error" role="alert">{{ error }}</p>
                </div>
                <footer class="ml-filter-footer">
                    <div class="ml-filter-period">
                        <CalendarDays aria-hidden="true" />
                        <div>
                            <span>Periode ditampilkan</span>
                            <strong>{{ date(props.filters.start_date) }} sampai {{ date(props.filters.end_date) }}</strong>
                        </div>
                    </div>
                    <div class="ml-filter-actions">
                        <Link :href="base" class="btn btn-outline-secondary"><RotateCcw aria-hidden="true" /> Reset filter</Link>
                        <button type="submit" class="btn btn-primary"><Search aria-hidden="true" /> Tampilkan</button>
                    </div>
                </footer>
            </form>
            <div class="al-stats ml-stats">
                <article>
                    <span class="al-stat-icon"><Timer /></span>
                    <div>
                        <strong>{{ overview.total }}</strong
                        ><span>{{ usageOnly ? 'Penggunaan sesuai filter' : 'Transaksi sesuai filter' }}</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon amber"><Hourglass /></span>
                    <div>
                        <strong>{{ overview.pending }}</strong
                        ><span>Menunggu persetujuan</span>
                    </div>
                </article>
                <article v-if="!usageOnly">
                    <span class="al-stat-icon cyan"><CheckCircle2 /></span>
                    <div>
                        <strong>{{ lemburDuration(overview.overtime_minutes) }}</strong
                        ><span>Lembur disetujui</span>
                    </div>
                </article>
                <article v-if="props.filters.request_kind !== 'overtime'">
                    <span class="al-stat-icon"><Clock3 /></span>
                    <div>
                        <strong>{{ lemburDuration(overview.usage_minutes) }}</strong
                        ><span>Penggunaan disetujui</span>
                    </div>
                </article>
            </div>
            <section class="al-panel">
                <header class="al-panel-header">
                    <div>
                        <h2>{{ tableTitle }}</h2>
                        <p>
                            {{ date(props.filters.start_date) }} sampai {{ date(props.filters.end_date) }}.
                            {{ usageOnly ? 'Penggunaan untuk telat masuk dan pulang cepat.' : 'Durasi lembur bersih sudah dikurangi istirahat.' }}
                        </p>
                    </div>
                </header>
                <div v-if="requests.data.length" class="al-table-wrap">
                    <table class="al-table ml-table">
                        <thead>
                            <tr>
                                <th>Pegawai</th>
                                <th>Tanggal & shift</th>
                                <th>{{ usageOnly ? 'Jenis penggunaan' : 'Transaksi' }}</th>
                                <th>{{ usageOnly ? 'Durasi penggunaan' : 'Durasi bersih' }}</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in requests.data" :key="item.id">
                                <td>
                                    <strong>{{ item.name }}</strong
                                    ><small>{{ item.unit }} / {{ item.position }}</small>
                                </td>
                                <td>
                                    <strong>{{ date(item.date) }}</strong
                                    ><small>{{ item.shift_name }}</small>
                                    <small v-if="item.usage_start && item.usage_end">{{ item.usage_start }} – {{ item.usage_end }}</small>
                                </td>
                                <td>
                                    <strong>{{
                                        item.request_kind === 'usage'
                                            ? item.usage_type === 'late_arrival'
                                                ? 'Telat masuk'
                                                : 'Pulang cepat'
                                            : 'Lembur'
                                    }}</strong
                                    ><small>{{ item.compensation === 'pay' ? 'Upah lembur' : 'Pengganti waktu libur' }}</small
                                    ><small class="ml-reason" :title="item.reason">{{ item.reason }}</small>
                                </td>
                                <td>{{ lemburDuration(item.duration_minutes) }}</td>
                                <td>
                                    <span class="al-status" :class="item.status">{{ labels[item.status] }}</span>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        :aria-label="`Detail lembur ${item.name} ${date(item.date)}`"
                                        @click="selectedId = item.id"
                                    >
                                        Detail
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="al-empty">
                    <Timer />
                    <h3>{{ usageOnly ? 'Belum ada penggunaan lembur sesuai filter' : 'Belum ada transaksi sesuai filter' }}</h3>
                    <p>Ubah rentang tanggal atau filter untuk melihat data lembur pegawai lainnya.</p>
                </div>
                <Pagination :links="requests.links" :from="requests.from" :to="requests.to" :total="requests.total" />
            </section>
        </div>
        <LemburDetailModal
            v-if="selectedId !== null"
            :key="selectedId"
            :request-id="selectedId"
            @close="selectedId = null"
            @open="selectedId = $event"
        />
        <CrudModal
            :open="open"
            title="Catat lembur pegawai"
            description="Pengajuan diteruskan sesuai approval line lembur jabatan pegawai."
            @close="!form.processing && (open = false)"
        >
            <form class="al-form" @submit.prevent="save">
                <fieldset class="ml-fieldset" :disabled="form.processing">
                    <div class="al-form-body">
                        <p v-for="(error, key) in form.errors" :key="key" class="al-error" role="alert">{{ error }}</p>
                        <p v-if="!eligibleEmployees.length || !shifts.length" class="al-info">
                            Siapkan pegawai dengan akun pengguna dan shift kerja terlebih dahulu.
                        </p>
                        <SearchableSelect
                            v-model="form.pegawai_id"
                            :options="eligibleEmployees"
                            label="Pegawai"
                            placeholder="Pilih pegawai"
                            :placeholder-disabled="true"
                            @change="selectEmployee"
                        />
                        <div class="al-date-fields">
                            <label class="al-field"
                                ><span>Tanggal lembur <b>*</b></span
                                ><input v-model="form.tanggal_lembur" type="date" min="1900-01-01" max="2100-12-31" required
                            /></label>
                            <SearchableSelect
                                v-model="form.shift_id"
                                :options="shifts"
                                label="Shift kerja"
                                placeholder="Pilih shift"
                                :placeholder-disabled="true"
                            />
                        </div>
                        <p class="al-help">Isi durasi dalam menit, termasuk istirahat. Gunakan 0 jika tidak ada lembur pada bagian tersebut.</p>
                        <div class="al-date-fields">
                            <label class="al-field"
                                ><span>Sebelum shift (menit)</span
                                ><input v-model="form.before_minutes" type="number" min="0" max="1440" step="1" required
                            /></label>
                            <label class="al-field"
                                ><span>Istirahat sebelum shift</span
                                ><input v-model="form.before_break_minutes" type="number" min="0" max="1440" step="1" required
                            /></label>
                            <label class="al-field"
                                ><span>Setelah shift (menit)</span
                                ><input v-model="form.after_minutes" type="number" min="0" max="1440" step="1" required
                            /></label>
                            <label class="al-field"
                                ><span>Istirahat setelah shift</span
                                ><input v-model="form.after_break_minutes" type="number" min="0" max="1440" step="1" required
                            /></label>
                        </div>
                        <div class="al-info">
                            Durasi lembur bersih: <strong>{{ lemburDuration(netMinutes) }}</strong>
                        </div>
                        <label class="al-field"
                            ><span>Kompensasi <b>*</b></span
                            ><select v-model="form.compensation">
                                <option value="pay">Upah lembur</option>
                                <option value="time_off">Pengganti waktu libur</option>
                            </select></label
                        >
                        <label class="al-field"
                            ><span>Alasan lembur <b>*</b></span
                            ><textarea v-model="form.alasan" rows="3" maxlength="200" required />
                        </label>
                        <label class="al-field"
                            ><span>Lampiran</span
                            ><input
                                :key="fileKey"
                                type="file"
                                multiple
                                accept=".pdf,.jpg,.jpeg,.png,.xlsx,.xls,.docx,.doc,.csv,.txt,.ppt,.pptx"
                                @change="chooseFiles"
                            /><small class="al-help">Maksimal 5 file, masing-masing 10 MB. PDF, gambar, atau dokumen Office.</small></label
                        >
                        <progress v-if="form.progress" :value="form.progress.percentage" max="100">{{ form.progress.percentage }}%</progress>
                    </div>
                </fieldset>
                <footer class="al-form-footer">
                    <button class="btn btn-outline-secondary" type="button" :disabled="form.processing" @click="open = false">Batal</button
                    ><button class="btn btn-primary" type="submit" :disabled="form.processing || !form.pegawai_id || !form.shift_id">
                        {{ form.processing ? 'Menyimpan...' : 'Simpan & ajukan' }}
                    </button>
                </footer>
            </form>
        </CrudModal>
    </AppLayout>
</template>

<style scoped>
.ml-filter-card {
    overflow: visible;
}
.ml-filter-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--rs-surface-muted);
}
.ml-filter-header h2 {
    margin: 0 0 4px;
    font-size: 15px;
}
.ml-filter-header p {
    margin: 0;
    color: var(--rs-muted);
    font-size: 12px;
    line-height: 1.6;
}
.ml-filter-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 11px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.ml-filter-icon svg {
    width: 19px;
    height: 19px;
}
.ml-filter-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    align-items: end;
    gap: 20px 18px;
    padding: 24px;
}
.ml-filter-grid .al-field {
    min-width: 0;
}
.ml-filter-grid input,
.ml-filter-grid select,
.ml-filter-grid :deep(.searchable-select-trigger),
.ml-filter-grid :deep(.searchable-select-native) {
    min-width: 0;
    height: 44px;
    color: var(--rs-ink);
    font-size: 13px;
    font-weight: 400;
}
.ml-filter-grid > .al-error {
    grid-column: 1 / -1;
}
.ml-search-control {
    position: relative;
}
.ml-search-control input {
    padding-left: 38px;
}
.ml-search-control svg {
    position: absolute;
    top: 50%;
    left: 12px;
    width: 16px;
    height: 16px;
    color: var(--rs-muted);
    pointer-events: none;
    transform: translateY(-50%);
}
.ml-filter-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    padding: 16px 24px;
    border-top: 1px solid var(--rs-surface-muted);
    border-radius: 0 0 16px 16px;
    background: var(--rs-canvas);
}
.ml-filter-period {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.ml-filter-period > svg {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    color: var(--rs-primary);
}
.ml-filter-period span,
.ml-filter-period strong {
    display: block;
    line-height: 1.6;
}
.ml-filter-period span {
    color: var(--rs-muted);
    font-size: 11px;
}
.ml-filter-period strong {
    color: var(--rs-text-soft);
    font-size: 12px;
    font-weight: 600;
}
.ml-filter-actions {
    display: flex;
    gap: 10px;
}
.ml-filter-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    white-space: nowrap;
}
.ml-filter-actions svg {
    width: 15px;
    height: 15px;
}
.ml-stats {
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}
.ml-stats article {
    padding: 20px 16px;
    gap: 12px;
}
.ml-stats article strong {
    font-size: 23px;
}
.ml-reason {
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ml-balance,
.ml-panel-icon {
    color: var(--rs-primary);
}
.ml-fieldset {
    border: 0;
    padding: 0;
    margin: 0;
    min-width: 0;
}
@media (max-width: 1200px) {
    .ml-stats,
    .ml-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 640px) {
    .ml-filter-grid,
    .ml-stats {
        grid-template-columns: 1fr;
    }
    .ml-filter-header,
    .ml-filter-grid,
    .ml-filter-footer {
        padding: 18px;
    }
    .ml-filter-actions {
        width: 100%;
    }
    .ml-filter-actions .btn {
        flex: 1;
    }
}
</style>
