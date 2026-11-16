<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    LoaderCircle,
    Pencil,
    Search,
    ShieldCheck,
    Timer,
    Upload,
    UserRound,
    Users,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import type { LemburBalance } from '../../../absensi-mobile/lembur/types';
import { lemburDuration } from '../../../absensi-mobile/lembur/types';
import '../../approval-workflow.css';
import CrudModal from '../../setting/components/CrudModal.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import Pagination from '../../setting/components/Pagination.vue';
import type { Paginated } from '../../setting/types';

type Employee = LemburBalance & {
    id: number;
    name: string;
    nik: string | null;
    unit: string | null;
    position: string | null;
    last_adjustment: { at: string; by: string | null; source: string; note: string; before_minutes: number; after_minutes: number } | null;
};
type EmployeeOption = { id: number; nama: string; nik: string; unit_kerja_id: number | null; jabatan_id: number | null };
const props = defineProps<{
    employees: Paginated<Employee>;
    units: { id: number; nama: string }[];
    positions: { id: number; nama: string; unit_kerja_id: number }[];
    employeeOptions?: EmployeeOption[];
    filters: { search: string; unit_id: number | string; position_id: number | string };
    maxMinutes: number;
    can: { assign: boolean };
}>();
const base = '/absensi/admin-web/master-data/lembur';
const filters = reactive({ ...props.filters });
watch(
    () => props.filters,
    (value) => Object.assign(filters, value),
);
const filter = () =>
    router.get(
        base,
        { search: filters.search.trim() || undefined, unit_id: filters.unit_id || undefined, position_id: filters.position_id || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
const filterPositions = computed(() => props.positions.filter((position) => !filters.unit_id || position.unit_kerja_id === Number(filters.unit_id)));
watch(
    () => filters.unit_id,
    () => {
        if (!filterPositions.value.some((position) => position.id === Number(filters.position_id))) filters.position_id = '';
    },
);
const resetFilters = () => {
    Object.assign(filters, { search: '', unit_id: '', position_id: '' });
    filter();
};
const editing = ref<Employee | null>(null);
const form = useForm({ pegawai_id: 0, minutes: 0, note: '' });
const openBalance = (employee: Employee) => {
    form.clearErrors();
    form.pegawai_id = employee.id;
    form.minutes = employee.available_minutes;
    form.note = '';
    editing.value = employee;
};
const save = () =>
    form.put(`${base}/saldo`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
            form.reset();
        },
    });
const fileInput = ref<HTMLInputElement | null>(null);
const excelOpen = ref(false);
const excelForm = ref<HTMLFormElement | null>(null);
let focusBeforeExcel: HTMLElement | null = null;
const trapExcelFocus = (event: KeyboardEvent) => {
    if (event.key !== 'Tab') return;
    const dialog = excelForm.value?.closest('[role="dialog"]');
    const focusable = Array.from(dialog?.querySelectorAll<HTMLElement>('button, input, select, textarea, a[href], [tabindex="0"]') ?? []).filter(
        (element) => !element.matches(':disabled') && element.offsetParent !== null,
    );
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!first || !last) return;
    if (
        !dialog?.contains(document.activeElement) ||
        (event.shiftKey && document.activeElement === first) ||
        (!event.shiftKey && document.activeElement === last)
    ) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    }
};
watch(excelOpen, async (open) => {
    if (open) {
        focusBeforeExcel = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        document.addEventListener('keydown', trapExcelFocus);
        await nextTick();
        if (excelOpen.value) excelForm.value?.querySelector<HTMLInputElement>('input:checked')?.focus();
    } else {
        document.removeEventListener('keydown', trapExcelFocus);
        focusBeforeExcel?.focus();
    }
});
onBeforeUnmount(() => document.removeEventListener('keydown', trapExcelFocus));
const excelStep = ref<'template' | 'import'>('template');
const optionsLoading = ref(false);
const optionsLoaded = ref(false);
const optionsError = ref('');
const downloading = ref(false);
const downloadError = ref('');
const downloaded = ref(false);
const employeeSearch = ref('');
const importForm = useForm({
    scope: 'all' as 'all' | 'organization' | 'employees',
    unit_id: '' as number | string,
    position_id: '' as number | string,
    pegawai_ids: [] as number[],
    file: null as File | null,
    note: '',
});
const excelBusy = computed(() => importForm.processing || downloading.value);
const excelPositions = computed(() =>
    props.positions.filter((position) => !importForm.unit_id || position.unit_kerja_id === Number(importForm.unit_id)),
);
const unitNames = computed(() => new Map(props.units.map((unit) => [unit.id, unit.nama])));
const positionNames = computed(() => new Map(props.positions.map((position) => [position.id, position.nama])));
const selectedIds = computed(() => new Set(importForm.pegawai_ids));
const matchingEmployees = computed(() => {
    const search = employeeSearch.value.trim().toLocaleLowerCase('id');
    return (props.employeeOptions ?? []).filter((employee) =>
        `${employee.nama} ${employee.nik} ${unitNames.value.get(employee.unit_kerja_id ?? 0) ?? ''} ${positionNames.value.get(employee.jabatan_id ?? 0) ?? ''}`
            .toLocaleLowerCase('id')
            .includes(search),
    );
});
const scopedEmployees = computed(() =>
    (props.employeeOptions ?? []).filter((employee) => {
        if (importForm.scope === 'employees') return selectedIds.value.has(employee.id);
        if (importForm.scope === 'organization')
            return (
                Boolean(importForm.unit_id || importForm.position_id) &&
                (!importForm.unit_id || employee.unit_kerja_id === Number(importForm.unit_id)) &&
                (!importForm.position_id || employee.jabatan_id === Number(importForm.position_id))
            );
        return true;
    }),
);
const selectionReady = computed(
    () => optionsLoaded.value && !optionsLoading.value && scopedEmployees.value.length > 0 && scopedEmployees.value.length <= 1000,
);
const allMatchingSelected = computed(
    () => matchingEmployees.value.length > 0 && matchingEmployees.value.every((employee) => selectedIds.value.has(employee.id)),
);
const someMatchingSelected = computed(
    () => !allMatchingSelected.value && matchingEmployees.value.some((employee) => selectedIds.value.has(employee.id)),
);
const toggleMatching = () => {
    const ids = new Set(importForm.pegawai_ids);
    matchingEmployees.value.forEach((employee) => (allMatchingSelected.value ? ids.delete(employee.id) : ids.add(employee.id)));
    importForm.pegawai_ids = [...ids];
};
watch(
    () => importForm.unit_id,
    () => {
        if (!excelPositions.value.some((position) => position.id === Number(importForm.position_id))) importForm.position_id = '';
    },
);
watch(
    () => [importForm.scope, importForm.unit_id, importForm.position_id, ...importForm.pegawai_ids],
    () => {
        importForm.clearErrors();
        downloaded.value = false;
        downloadError.value = '';
    },
);
const loadEmployees = () => {
    optionsLoaded.value = false;
    optionsLoading.value = true;
    optionsError.value = '';
    router.reload({
        only: ['employeeOptions'],
        onSuccess: () => {
            optionsLoaded.value = true;
        },
        onError: () => {
            optionsError.value = 'Daftar pegawai gagal dimuat. Silakan coba kembali.';
        },
        onFinish: () => {
            optionsLoading.value = false;
        },
    });
};
const openExcel = () => {
    importForm.reset('file');
    importForm.clearErrors();
    downloadError.value = '';
    downloaded.value = false;
    excelOpen.value = true;
    loadEmployees();
};
const closeExcel = () => {
    if (!excelBusy.value) excelOpen.value = false;
};
const downloadTemplate = async () => {
    if (!selectionReady.value || excelBusy.value) return;
    downloading.value = true;
    downloadError.value = '';
    downloaded.value = false;
    try {
        const token = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch(`${base}/template`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
            body: JSON.stringify({
                scope: importForm.scope,
                unit_id: importForm.unit_id || null,
                position_id: importForm.position_id || null,
                pegawai_ids: importForm.pegawai_ids,
            }),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => null);
            throw new Error(data?.errors ? Object.values(data.errors).flat().join(' ') : 'Template gagal diunduh. Silakan coba kembali.');
        }
        if (!response.headers.get('Content-Type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
            throw new Error('Sesi telah berakhir. Muat ulang halaman dan masuk kembali.');
        }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = 'template-saldo-lembur.xlsx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        downloaded.value = true;
    } catch (error) {
        downloadError.value = error instanceof Error ? error.message : 'Template gagal diunduh.';
    } finally {
        downloading.value = false;
    }
};
const chooseFile = (event: Event) => {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    importForm.clearErrors('file');
    if (importForm.file && (!/\.(xlsx|xls)$/i.test(importForm.file.name) || importForm.file.size > 5 * 1024 * 1024)) {
        importForm.setError('file', 'Gunakan file .xlsx atau .xls dengan ukuran maksimal 5 MB.');
        importForm.file = null;
        (event.target as HTMLInputElement).value = '';
    }
};
const importExcel = () => {
    if (!selectionReady.value || excelBusy.value || !importForm.file) return;
    importForm.post(`${base}/import`, {
        preserveScroll: true,
        onSuccess: () => {
            excelOpen.value = false;
            importForm.reset('file', 'note');
            if (fileInput.value) fileInput.value.value = '';
        },
    });
};
</script>

<template>
    <Head title="Master Lembur" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
            { title: 'Master Lembur', href: base },
        ]"
    >
        <div class="al-page">
            <FlashMessage />
            <section class="mb-hero" aria-labelledby="lembur-hero-title">
                <div class="mb-hero-copy">
                    <span class="mb-eyebrow"><Timer aria-hidden="true" /> MASTER DATA / LEMBUR</span>
                    <h1 id="lembur-hero-title">Kelola waktu lebih.<br /><span>Hargai kontribusi tim.</span></h1>
                    <p>
                        Pantau saldo lembur dan atur hak waktu pegawai dalam satu tempat. Lebih mudah untuk satu orang, satu unit, atau seluruh tim.
                    </p>
                    <div class="mb-hero-tags">
                        <span><ShieldCheck /> Pengaturan tercatat</span><span><Users /> {{ employees.total }} pegawai sesuai filter</span>
                    </div>
                </div>
                <div class="mb-hero-side">
                    <div class="mb-hero-visual" aria-hidden="true">
                        <div class="mb-clock"><Timer /></div>
                        <div>
                            <span>SETIAP MENIT BERARTI</span><strong>Waktu yang tercatat,<br />hak yang terjaga.</strong>
                        </div>
                    </div>
                    <button v-if="can.assign" type="button" class="mb-excel-action" @click="openExcel">
                        <FileSpreadsheet /><span><strong>Kelola saldo via Excel</strong><small>Template & impor dalam satu langkah</small></span
                        ><ArrowRight />
                    </button>
                </div>
            </section>
            <section class="al-panel">
                <header class="al-panel-header">
                    <div>
                        <h2>Saldo lembur pegawai</h2>
                        <p>Saldo semua periode. Waktu yang menunggu persetujuan dicadangkan dari saldo tersedia.</p>
                    </div>
                    <span class="mb-count">{{ employees.total }} pegawai</span>
                </header>
                <form class="mb-filters" @submit.prevent="filter">
                    <label class="al-field"
                        ><span>Cari pegawai</span><input v-model="filters.search" type="search" maxlength="150" placeholder="Nama atau NIK pegawai"
                    /></label>
                    <label class="al-field"
                        ><span>Unit kerja</span
                        ><select v-model="filters.unit_id">
                            <option value="">Semua unit</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
                        </select></label
                    >
                    <label class="al-field"
                        ><span>Jabatan</span
                        ><select v-model="filters.position_id">
                            <option value="">Semua jabatan</option>
                            <option v-for="position in filterPositions" :key="position.id" :value="position.id">{{ position.nama }}</option>
                        </select></label
                    >
                    <div class="mb-filter-actions">
                        <button class="btn btn-primary" type="submit">Tampilkan</button
                        ><button
                            v-if="filters.search || filters.unit_id || filters.position_id"
                            class="btn btn-outline-secondary"
                            type="button"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </div>
                </form>
                <div class="mb-table-guide">
                    <span><span class="mb-dot"></span> Tersedia = total saldo − terpakai − dicadangkan</span
                    ><span>Gunakan Set saldo untuk penyesuaian per pegawai</span>
                </div>
                <div v-if="employees.data.length" class="al-table-wrap">
                    <table class="al-table mb-table">
                        <thead>
                            <tr>
                                <th>Pegawai</th>
                                <th>Total saldo</th>
                                <th>Terpakai</th>
                                <th>Dicadangkan</th>
                                <th>Tersedia</th>
                                <th>Pengaturan terakhir</th>
                                <th v-if="can.assign">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="employee in employees.data" :key="employee.id">
                                <td>
                                    <strong>{{ employee.name }}</strong
                                    ><small>{{ employee.nik || 'NIK belum diisi' }} / {{ employee.unit || 'Unit belum diatur' }}</small
                                    ><small>{{ employee.position || '' }}</small>
                                </td>
                                <td>{{ lemburDuration(employee.earned_minutes) }}</td>
                                <td>{{ lemburDuration(employee.used_minutes) }}</td>
                                <td>{{ lemburDuration(employee.reserved_minutes) }}</td>
                                <td>
                                    <strong class="mb-available">{{ lemburDuration(employee.available_minutes) }}</strong>
                                </td>
                                <td class="mb-last">
                                    <template v-if="employee.last_adjustment"
                                        ><strong>{{ employee.last_adjustment.source === 'excel' ? 'Impor Excel' : 'Manual' }}</strong
                                        ><small>{{ employee.last_adjustment.at }} / {{ employee.last_adjustment.by || 'Akun admin dihapus' }}</small>
                                        <details>
                                            <summary>Lihat catatan</summary>
                                            <p>
                                                {{ lemburDuration(employee.last_adjustment.before_minutes) }} menjadi
                                                {{ lemburDuration(employee.last_adjustment.after_minutes) }}
                                            </p>
                                            <p>{{ employee.last_adjustment.note }}</p>
                                        </details></template
                                    ><small v-else>Belum pernah diatur</small>
                                </td>
                                <td v-if="can.assign">
                                    <button
                                        class="btn btn-sm btn-outline-primary al-button"
                                        type="button"
                                        :aria-label="`Set saldo ${employee.name}`"
                                        @click="openBalance(employee)"
                                    >
                                        <Pencil /> Set saldo
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="al-empty">
                    <Timer />
                    <h3>Pegawai tidak ditemukan</h3>
                    <p>Ubah pencarian, unit kerja, atau jabatan untuk melihat pegawai lainnya.</p>
                    <button class="btn btn-outline-primary" type="button" @click="resetFilters">Reset filter</button>
                </div>
                <Pagination :links="employees.links" :from="employees.from" :to="employees.to" :total="employees.total" />
            </section>
        </div>
        <CrudModal
            :open="excelOpen"
            title="Kelola saldo via Excel"
            description="Pilih pegawai, siapkan template, lalu impor saldo lembur."
            @close="closeExcel"
        >
            <form ref="excelForm" class="al-form mb-excel-form" @submit.prevent="excelStep === 'template' ? downloadTemplate() : importExcel()">
                <div class="al-form-body mb-excel-body">
                    <fieldset class="mb-selection" :disabled="excelBusy">
                        <legend><span class="mb-step">1</span> Tentukan cakupan pegawai</legend>
                        <div class="mb-scope-options">
                            <label :class="{ selected: importForm.scope === 'all' }"
                                ><input v-model="importForm.scope" type="radio" name="excel-scope" value="all" /><Users /><strong
                                    >Semua pegawai</strong
                                ><small>Seluruh pegawai dengan NIK</small></label
                            >
                            <label :class="{ selected: importForm.scope === 'organization' }"
                                ><input v-model="importForm.scope" type="radio" name="excel-scope" value="organization" /><Building2 /><strong
                                    >Unit & jabatan</strong
                                ><small>Kelompok sesuai penempatan</small></label
                            >
                            <label :class="{ selected: importForm.scope === 'employees' }"
                                ><input v-model="importForm.scope" type="radio" name="excel-scope" value="employees" /><UserRound /><strong
                                    >Pilih pegawai</strong
                                ><small>Satu atau beberapa pegawai</small></label
                            >
                        </div>
                        <div v-if="importForm.scope === 'organization'" class="al-date-fields mb-organization">
                            <label class="al-field"
                                ><span>Unit kerja</span
                                ><select v-model="importForm.unit_id">
                                    <option value="">Semua unit</option>
                                    <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
                                </select></label
                            >
                            <label class="al-field"
                                ><span>Jabatan</span
                                ><select v-model="importForm.position_id">
                                    <option value="">Semua jabatan</option>
                                    <option v-for="position in excelPositions" :key="position.id" :value="position.id">
                                        {{ position.nama }}{{ !importForm.unit_id ? ` · ${unitNames.get(position.unit_kerja_id) || ''}` : '' }}
                                    </option>
                                </select></label
                            >
                            <p class="al-help">Pilih unit, jabatan, atau keduanya. Jika keduanya dipilih, pegawai harus sesuai dengan keduanya.</p>
                        </div>
                        <div v-if="importForm.scope === 'employees'" class="mb-picker">
                            <label class="mb-picker-search"
                                ><Search aria-hidden="true" /><input
                                    v-model="employeeSearch"
                                    type="search"
                                    placeholder="Cari nama, NIK, unit, atau jabatan..."
                                    aria-label="Cari pegawai untuk Excel"
                            /></label>
                            <label class="mb-picker-all"
                                ><input
                                    type="checkbox"
                                    :checked="allMatchingSelected"
                                    :indeterminate="someMatchingSelected"
                                    :disabled="optionsLoading || !matchingEmployees.length"
                                    @change="toggleMatching"
                                /><span>Pilih semua hasil pencarian</span><strong>{{ importForm.pegawai_ids.length }} dipilih</strong></label
                            >
                            <div class="mb-picker-list" role="group" aria-label="Pilihan pegawai lintas halaman" :aria-busy="optionsLoading">
                                <label v-for="employee in matchingEmployees" :key="employee.id" :class="{ selected: selectedIds.has(employee.id) }"
                                    ><input v-model="importForm.pegawai_ids" type="checkbox" :value="employee.id" :disabled="optionsLoading" /><span
                                        ><strong>{{ employee.nama }}</strong
                                        ><small
                                            >{{ employee.nik }} · {{ unitNames.get(employee.unit_kerja_id ?? 0) || 'Tanpa unit' }} ·
                                            {{ positionNames.get(employee.jabatan_id ?? 0) || 'Tanpa jabatan' }}</small
                                        ></span
                                    ></label
                                >
                                <p v-if="!matchingEmployees.length && !optionsLoading" class="mb-picker-empty">
                                    Tidak ada pegawai yang sesuai pencarian.
                                </p>
                            </div>
                            <small class="al-help"
                                >Pilihan mencakup seluruh halaman. Pegawai yang sudah dipilih tetap tersimpan saat pencarian berubah.</small
                            >
                        </div>
                        <p v-if="optionsLoading" class="mb-selection-summary" role="status"><LoaderCircle class="mb-spin" /> Memuat pegawai...</p>
                        <div v-else-if="optionsError" class="al-error" role="alert">
                            {{ optionsError }}
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="loadEmployees">Coba kembali</button>
                        </div>
                        <p v-else class="mb-selection-summary" aria-live="polite">
                            <Users /><strong>{{ scopedEmployees.length }} pegawai</strong> dalam cakupan template & impor
                        </p>
                        <p v-if="scopedEmployees.length > 1000" class="al-error" role="alert">
                            Maksimal 1.000 pegawai per file. Persempit pilihan pegawai terlebih dahulu.
                        </p>
                    </fieldset>
                    <section class="mb-file-section" aria-labelledby="excel-file-title">
                        <h3 id="excel-file-title"><span class="mb-step">2</span> Siapkan & impor saldo</h3>
                        <div class="mb-excel-tabs" role="group" aria-label="Proses Excel">
                            <button type="button" :aria-pressed="excelStep === 'template'" :disabled="excelBusy" @click="excelStep = 'template'">
                                <Download /> Unduh template
                            </button>
                            <button type="button" :aria-pressed="excelStep === 'import'" :disabled="excelBusy" @click="excelStep = 'import'">
                                <Upload /> Impor saldo
                            </button>
                        </div>
                        <div v-if="excelStep === 'template'" class="mb-template-preview">
                            <div class="mb-template-title">
                                <FileSpreadsheet />
                                <div><strong>Template saldo lembur</strong><small>Berisi pegawai sesuai cakupan yang dipilih di atas.</small></div>
                            </div>
                            <div class="mb-preview-scroll">
                                <table>
                                    <caption class="visually-hidden">
                                        Contoh pengisian template
                                    </caption>
                                    <thead>
                                        <tr>
                                            <th>NIK</th>
                                            <th>Nama Pegawai</th>
                                            <th>Saldo Lembur (Menit)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>0001</td>
                                            <td>Contoh pegawai A</td>
                                            <td>150</td>
                                        </tr>
                                        <tr>
                                            <td>0002</td>
                                            <td>Contoh pegawai B</td>
                                            <td>720</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p>
                                Isi kolom <strong>Saldo Lembur (Menit)</strong> dengan jumlah menit bulat untuk masing-masing pegawai. Kolom input
                                pada file ditandai kuning.
                            </p>
                        </div>
                        <div v-show="excelStep === 'import'" class="mb-upload-fields">
                            <label class="al-field mb-upload"
                                ><span><Upload /> File Excel <b>*</b></span
                                ><input
                                    ref="fileInput"
                                    type="file"
                                    accept=".xlsx,.xls"
                                    :required="excelStep === 'import'"
                                    :disabled="excelBusy"
                                    @change="chooseFile"
                                /><small>{{
                                    importForm.file
                                        ? `${importForm.file.name} · ${(importForm.file.size / 1024).toFixed(1)} KB`
                                        : '.xlsx atau .xls · maksimal 5 MB · 1.000 baris'
                                }}</small></label
                            >
                            <label class="al-field"
                                ><span>Catatan impor <small>(opsional)</small></span
                                ><textarea
                                    v-model="importForm.note"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="Contoh: Penyesuaian saldo awal November"
                                    :disabled="excelBusy"
                                />
                            </label>
                        </div>
                        <div class="mb-conversion">
                            <Timer />
                            <div>
                                <strong>Isi saldo langsung dalam menit</strong>
                                <p>Contoh: 2 jam 30 menit diisi 150; 12 jam diisi 720. Gunakan angka bulat antara 0 dan {{ maxMinutes }} menit.</p>
                            </div>
                        </div>
                        <p class="al-help">
                            Saldo kosong dilewati; angka 0 mengosongkan saldo tersedia. Nilai impor mengganti saldo tersedia. Satu baris tidak valid
                            membatalkan seluruh impor.
                        </p>
                    </section>
                    <p v-if="downloadError" class="al-error" role="alert">{{ downloadError }}</p>
                    <p v-if="downloaded" class="mb-download-success" role="status">
                        <CheckCircle2 /> Template telah diunduh. Lengkapi file, lalu pilih tab Impor saldo.
                    </p>
                    <p v-for="(error, key) in importForm.errors" :key="key" class="al-error" role="alert">{{ error }}</p>
                    <div v-if="importForm.progress" class="mb-progress" role="status">
                        <progress :value="importForm.progress.percentage" max="100" aria-label="Progres unggah Excel" /><small
                            >Mengunggah {{ importForm.progress.percentage }}%</small
                        >
                    </div>
                </div>
                <footer class="al-form-footer mb-excel-footer">
                    <span>{{ scopedEmployees.length }} pegawai dalam cakupan</span>
                    <button class="btn btn-outline-secondary" type="button" :disabled="excelBusy" @click="closeExcel">Tutup</button>
                    <button
                        class="btn btn-primary al-button"
                        type="submit"
                        :disabled="excelBusy || !selectionReady || (excelStep === 'import' && !importForm.file)"
                    >
                        <LoaderCircle v-if="excelBusy" class="mb-spin" /><Download v-else-if="excelStep === 'template'" /><Upload v-else />{{
                            downloading
                                ? 'Menyiapkan...'
                                : importForm.processing
                                  ? 'Memeriksa & mengimpor...'
                                  : excelStep === 'template'
                                    ? 'Unduh template'
                                    : 'Impor saldo'
                        }}
                    </button>
                </footer>
            </form>
        </CrudModal>
        <CrudModal
            :open="editing !== null"
            title="Set saldo lembur pegawai"
            :description="editing?.name"
            @close="!form.processing && (editing = null)"
        >
            <form class="al-form" @submit.prevent="save">
                <div class="al-form-body">
                    <p class="al-info">
                        Saldo tersedia saat ini: <strong>{{ lemburDuration(editing?.available_minutes ?? 0) }}</strong
                        >. Nilai baru mengganti saldo tersedia; penggunaan yang disetujui atau menunggu persetujuan tetap tercatat.
                    </p>
                    <label class="al-field"
                        ><span>Saldo tersedia baru (menit) <b>*</b></span
                        ><input v-model="form.minutes" type="number" min="0" :max="maxMinutes" step="1" required :disabled="form.processing" /><small
                            class="al-help"
                            >{{ lemburDuration(Math.max(0, Number(form.minutes))) }}. Angka 0 mengosongkan saldo tersedia.</small
                        ></label
                    >
                    <label class="al-field"
                        ><span>Catatan <b>*</b></span
                        ><textarea v-model="form.note" rows="3" maxlength="1000" required :disabled="form.processing" />
                    </label>
                    <p v-for="(error, key) in form.errors" :key="key" class="al-error" role="alert">{{ error }}</p>
                </div>
                <footer class="al-form-footer">
                    <button class="btn btn-outline-secondary" type="button" :disabled="form.processing" @click="editing = null">Batal</button
                    ><button class="btn btn-primary" type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Menyimpan...' : 'Simpan saldo' }}
                    </button>
                </footer>
            </form>
        </CrudModal>
    </AppLayout>
</template>

<style scoped>
.mb-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.8fr);
    gap: 36px;
    overflow: hidden;
    padding: 38px;
    border: 1px solid var(--rs-primary-soft);
    border-radius: 24px;
    background: radial-gradient(ellipse at 90% 0%, rgba(105, 184, 159, 0.22), transparent 65%), linear-gradient(120deg, #fafdfb, #edf6f2);
}
.mb-hero-copy,
.mb-hero-side {
    position: relative;
    z-index: 1;
}
.mb-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--rs-primary);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.09em;
}
.mb-eyebrow svg {
    width: 16px;
    height: 16px;
}
.mb-hero h1 {
    margin: 18px 0 15px;
    font-size: clamp(28px, 3vw, 40px);
    line-height: 1.2;
    letter-spacing: -0.045em;
    font-weight: 750;
    color: var(--rs-ink);
}
.mb-hero h1 span {
    color: var(--rs-primary);
}
.mb-hero-copy > p {
    max-width: 560px;
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 13px;
    line-height: 1.8;
}
.mb-hero-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 20px;
    margin-top: 22px;
}
.mb-hero-tags span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--rs-muted);
    font-size: 11px;
}
.mb-hero-tags svg {
    width: 15px;
    height: 15px;
    color: var(--rs-primary);
}
.mb-hero-side {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 18px;
}
.mb-hero-visual {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 22px 10px;
}
.mb-clock {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 86px;
    height: 86px;
    border: 9px solid #fff;
    border-radius: 50%;
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
    box-shadow: 0 12px 32px rgba(8, 127, 120, 0.12);
}
.mb-clock svg {
    width: 40px;
    height: 40px;
    stroke-width: 1.4;
}
.mb-hero-visual span {
    display: block;
    margin-bottom: 8px;
    color: var(--rs-muted);
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.1em;
}
.mb-hero-visual strong {
    font-size: 17px;
    line-height: 1.5;
    font-weight: 650;
    color: var(--rs-ink);
}
.mb-excel-action {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 17px;
    border: 1px solid rgba(8, 127, 120, 0.15);
    border-radius: 14px;
    background: #fff;
    text-align: left;
    color: var(--rs-primary);
    box-shadow: 0 6px 20px rgba(8, 127, 120, 0.04);
    transition:
        transform 0.18s,
        box-shadow 0.18s;
}
.mb-excel-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 26px rgba(8, 127, 120, 0.12);
}
.mb-excel-action > svg:first-child {
    width: 27px;
    height: 27px;
}
.mb-excel-action > svg:last-child {
    width: 18px;
    height: 18px;
    margin-left: auto;
}
.mb-excel-action strong,
.mb-excel-action small {
    display: block;
}
.mb-excel-action strong {
    font-size: 13px;
}
.mb-excel-action small {
    margin-top: 4px;
    font-size: 10px;
    color: var(--rs-muted);
}
.mb-count {
    flex-shrink: 0;
    border-radius: 12px;
    padding: 9px 14px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-size: 12px;
    font-weight: 700;
}
.mb-filters {
    display: grid;
    grid-template-columns: minmax(180px, 1.5fr) 1fr 1fr auto;
    align-items: end;
    gap: 16px;
    padding: 0 24px 24px;
}
.mb-filter-actions {
    display: flex;
    gap: 8px;
}
.mb-table-guide {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px 24px;
    border-top: 1px solid var(--rs-border);
    background: var(--rs-canvas);
    color: var(--rs-muted);
    font-size: 10px;
}
.mb-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    margin-right: 6px;
    border-radius: 50%;
    background: var(--rs-primary);
}
.mb-table {
    min-width: 1040px;
}
.mb-available {
    color: var(--rs-primary);
}
.mb-last {
    max-width: 280px;
    overflow-wrap: anywhere;
}
.mb-last details {
    margin-top: 8px;
    font-size: 11px;
    color: var(--rs-muted);
}
.mb-last details p {
    margin: 8px 0 0;
}
.mb-excel-body {
    gap: 22px;
}
.mb-selection {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.mb-selection legend,
.mb-file-section h3 {
    display: flex;
    align-items: center;
    gap: 8px;
    float: none;
    width: auto;
    margin: 0 0 14px;
    padding: 0;
    font-size: 14px;
    font-weight: 700;
    color: var(--rs-ink);
}
.mb-step {
    display: inline-grid;
    place-items: center;
    width: 24px;
    height: 24px;
    border-radius: 8px;
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
    font-size: 11px;
}
.mb-scope-options {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}
.mb-scope-options label {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    padding: 14px;
    border: 1px solid var(--rs-border);
    border-radius: 12px;
    background: var(--rs-canvas);
    cursor: pointer;
}
.mb-scope-options label.selected {
    border-color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.mb-scope-options input {
    position: absolute;
    top: 13px;
    right: 12px;
    width: 14px;
    height: 14px;
    accent-color: var(--rs-primary);
}
.mb-scope-options svg {
    width: 21px;
    height: 21px;
    color: var(--rs-primary);
    margin-bottom: 4px;
}
.mb-scope-options strong {
    color: var(--rs-ink);
    font-size: 12px;
}
.mb-scope-options small {
    color: var(--rs-muted);
    font-size: 10px;
    line-height: 1.5;
}
.mb-organization {
    margin: 0;
}
.mb-organization > p {
    grid-column: 1 / -1;
    margin: 0;
}
.mb-selection-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
    margin: 0;
    color: var(--rs-muted);
    font-size: 11px;
}
.mb-selection-summary svg {
    width: 15px;
    height: 15px;
    margin-right: 3px;
    color: var(--rs-primary);
}
.mb-selection-summary strong {
    color: var(--rs-primary);
}
.mb-picker {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.mb-picker input[type='checkbox'] {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    accent-color: var(--rs-primary);
}
.mb-picker-search {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 12px;
    border: 1px solid var(--rs-border);
    border-radius: 10px 10px 0 0;
}
.mb-picker-search svg {
    width: 16px;
    height: 16px;
    color: var(--rs-muted);
}
.mb-picker-search input {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font-size: 12px;
    color: var(--rs-ink);
}
.mb-picker-search:focus-within {
    outline: 2px solid var(--rs-primary);
    outline-offset: -2px;
}
.mb-picker-all {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 11px 12px;
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
    font-size: 11px;
}
.mb-picker-all strong {
    margin-left: auto;
    white-space: nowrap;
}
.mb-picker-list {
    max-height: 200px;
    overflow-y: auto;
    overscroll-behavior: contain;
    border: 1px solid var(--rs-border);
    border-radius: 0 0 10px 10px;
}
.mb-picker-list > label {
    display: flex;
    align-items: center;
    gap: 11px;
    margin: 0;
    padding: 10px 13px;
    border-bottom: 1px solid var(--rs-border);
    cursor: pointer;
}
.mb-picker-list > label:last-child {
    border-bottom: 0;
}
.mb-picker-list > label.selected {
    background: var(--rs-primary-soft);
}
.mb-picker-list strong,
.mb-picker-list small {
    display: block;
}
.mb-picker-list strong {
    font-size: 12px;
    color: var(--rs-ink);
}
.mb-picker-list small {
    margin-top: 3px;
    color: var(--rs-muted);
    font-size: 10px;
}
.mb-picker > .al-help {
    margin-top: 8px;
}
.mb-picker-empty {
    margin: 0;
    padding: 20px;
    text-align: center;
    color: var(--rs-muted);
    font-size: 12px;
}
.mb-file-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding-top: 20px;
    border-top: 1px solid var(--rs-border);
}
.mb-file-section h3 {
    margin-bottom: 0;
}
.mb-excel-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5px;
    padding: 5px;
    border-radius: 10px;
    background: var(--rs-canvas);
}
.mb-excel-tabs button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: var(--rs-muted);
    font-size: 12px;
    font-weight: 600;
}
.mb-excel-tabs button[aria-pressed='true'] {
    background: var(--rs-primary);
    color: #fff;
}
.mb-excel-tabs svg {
    width: 15px;
    height: 15px;
}
.mb-template-preview {
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 12px;
}
.mb-template-title {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
}
.mb-template-title svg {
    width: 26px;
    height: 26px;
    color: var(--rs-primary);
}
.mb-template-title strong,
.mb-template-title small {
    display: block;
}
.mb-template-title strong {
    color: var(--rs-ink);
    font-size: 12px;
}
.mb-template-title small {
    margin-top: 3px;
    color: var(--rs-muted);
    font-size: 10px;
}
.mb-preview-scroll {
    overflow-x: auto;
}
.mb-template-preview table {
    width: 100%;
    min-width: 460px;
    font-size: 10px;
    border-collapse: collapse;
}
.mb-template-preview th {
    padding: 9px 10px;
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
    font-weight: 600;
    white-space: nowrap;
}
.mb-template-preview td {
    padding: 9px 10px;
    color: var(--rs-muted);
    border-bottom: 1px solid var(--rs-border);
}
.mb-template-preview td:nth-last-child(-n + 2) {
    background: #fff7df;
    color: #7b5d1f;
}
.mb-template-preview > p {
    margin: 0;
    padding: 11px 14px;
    font-size: 10px;
    color: var(--rs-muted);
    line-height: 1.7;
}
.mb-conversion {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 13px;
    border-radius: 11px;
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
}
.mb-conversion > svg {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
    margin-top: 1px;
}
.mb-conversion strong {
    font-size: 12px;
}
.mb-conversion p {
    margin: 5px 0 0;
    font-size: 11px;
    line-height: 1.7;
    color: var(--rs-text-soft);
}
.mb-upload {
    padding: 16px;
    border: 1px dashed var(--rs-primary);
    border-radius: 12px;
    background: var(--rs-canvas);
}
.mb-upload-fields {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.mb-upload > span {
    display: flex;
    align-items: center;
    gap: 8px;
}
.mb-upload svg {
    width: 17px;
    height: 17px;
    color: var(--rs-primary);
}
.mb-upload small {
    font-size: 10px;
    color: var(--rs-muted);
    overflow-wrap: anywhere;
}
.mb-download-success {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0;
    color: #27805d;
    font-size: 11px;
}
.mb-download-success svg {
    width: 17px;
    height: 17px;
    flex-shrink: 0;
}
.mb-excel-footer {
    flex-wrap: wrap;
}
.mb-excel-footer > span {
    margin-right: auto;
    font-size: 11px;
    color: var(--rs-muted);
}
.mb-progress {
    display: flex;
    flex-direction: column;
    gap: 5px;
    color: var(--rs-muted);
    font-size: 11px;
}
.mb-progress progress {
    width: 100%;
    height: 7px;
    accent-color: var(--rs-primary);
}
.mb-spin {
    animation: mb-spin 1s linear infinite;
}
@keyframes mb-spin {
    to {
        transform: rotate(360deg);
    }
}
:global(.admin-modal-form:has(.mb-excel-form)) {
    width: min(800px, calc(100vw - 32px));
}
:global(.dark) .mb-hero {
    background: radial-gradient(ellipse at 90% 0%, #25473e, transparent 65%), #172b27;
}
:global(.dark) .mb-excel-action,
:global(.dark) .mb-clock {
    background: #203831;
    border-color: #3a5b50;
}
:global(.dark) .mb-template-preview td:nth-last-child(-n + 2) {
    background: #3d3420;
    color: #f1d395;
}
@media (max-width: 1100px) {
    .mb-hero {
        grid-template-columns: 1fr 0.85fr;
        gap: 24px;
        padding: 28px;
    }
    .mb-filters {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 640px) {
    .mb-hero {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 24px;
        border-radius: 18px;
    }
    .mb-hero-visual {
        display: none;
    }
    .mb-hero-side {
        margin-top: 6px;
    }
    .mb-filters {
        grid-template-columns: 1fr;
    }
    .al-panel-header {
        flex-wrap: wrap;
    }
    .mb-scope-options {
        gap: 6px;
    }
    .mb-scope-options label {
        padding: 12px 8px;
    }
    .mb-scope-options input {
        top: 10px;
        right: 7px;
        width: 12px;
        height: 12px;
    }
    .mb-scope-options strong {
        font-size: 10px;
    }
    .mb-scope-options small {
        display: none;
    }
    .mb-scope-options svg {
        width: 18px;
        height: 18px;
    }
    .mb-excel-footer > span {
        flex-basis: 100%;
    }
    .mb-table-guide > span:last-child {
        display: none;
    }
}
@media (prefers-reduced-motion: reduce) {
    .mb-spin {
        animation: none;
    }
    .mb-excel-action {
        transition: none;
    }
}
</style>
