<script setup lang="ts">
import { Dialog, DialogDescription, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    Building2,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Download,
    FileSpreadsheet,
    Layers3,
    LoaderCircle,
    Search,
    Upload,
    UserRoundCheck,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref, useId, watch } from 'vue';
import SearchableSelect from '../../presensi/components/SearchableSelect.vue';
import type { BulkShiftOptions } from './bulk-shift';

const props = defineProps<{ open: boolean; options: BulkShiftOptions }>();
const emit = defineEmits<{ close: [] }>();
const step = ref(1);
const furthestStep = ref(1);
const steps = ['Periode', 'Pegawai', 'Template', 'Import'];
const stepDescriptions = ['Atur tanggal', 'Tentukan penerima', 'Isi jadwal Excel', 'Unggah & simpan'];
const stepTips = [
    'Pilih sebulan penuh atau rentang tanggal tertentu dalam satu bulan.',
    'Pilihan pegawai tertentu tetap tersimpan saat pencarian atau filter berubah.',
    'Gunakan kode shift dari dropdown Excel. Sel kosong akan dilewati saat import.',
    'Periode dan pegawai di file harus sesuai dengan pilihan pada batch ini.',
];
const stepHeading = ref<HTMLElement | null>(null);
const modalBody = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const errorSummary = ref<HTMLElement | null>(null);
const employeePage = ref(1);
const fileId = useId();
const dragging = ref(false);
const baseUrl = '/absensi/admin-web/master-data/shift/assign';
const endOfMonth = (month: string) => {
    const [year, number] = month.split('-').map(Number);
    return `${month}-${new Date(year, number, 0).getDate()}`;
};
const form = useForm({
    month: props.options.month,
    date_from: `${props.options.month}-01`,
    date_to: endOfMonth(props.options.month),
    selection: 'all',
    unit_kerja_id: '' as number | string,
    jabatan_id: '' as number | string,
    pegawai_ids: [] as number[],
    file: null as File | null,
});
const employeeSearch = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const downloading = ref(false);
const downloadError = ref('');
const downloaded = ref(false);
const busy = computed(() => downloading.value || form.processing);
const positions = computed(() => props.options.positions.filter((item) => !form.unit_kerja_id || item.unit_kerja_id === Number(form.unit_kerja_id)));
const unitOptions = computed(() => props.options.units.map((item) => ({ value: item.id, label: item.nama })));
const positionOptions = computed(() => positions.value.map((item) => ({ value: item.id, label: item.nama })));
const matchingEmployees = computed(() =>
    props.options.employees.filter(
        (item) =>
            (!form.unit_kerja_id || item.unit_kerja_id === Number(form.unit_kerja_id)) &&
            (!form.jabatan_id || item.jabatan_id === Number(form.jabatan_id)) &&
            `${item.nama} ${item.nik ?? ''}`.toLocaleLowerCase('id-ID').includes(employeeSearch.value.trim().toLocaleLowerCase('id-ID')),
    ),
);
const selectedCount = computed(() =>
    form.selection === 'all'
        ? props.options.employees.length
        : form.selection === 'filtered'
          ? props.options.employees.filter(
                (item) =>
                    (!form.unit_kerja_id || item.unit_kerja_id === Number(form.unit_kerja_id)) &&
                    (!form.jabatan_id || item.jabatan_id === Number(form.jabatan_id)),
            ).length
          : form.pegawai_ids.length,
);
const employeePages = computed(() => Math.max(1, Math.ceil(matchingEmployees.value.length / 12)));
const visibleEmployees = computed(() => matchingEmployees.value.slice((employeePage.value - 1) * 12, employeePage.value * 12));
const someMatchingSelected = computed(() => !allMatchingSelected.value && matchingEmployees.value.some((item) => form.pegawai_ids.includes(item.id)));
const formatDate = (value: string) => {
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(date);
};
const periodLabel = computed(() => (validPeriod.value ? `${formatDate(form.date_from)} – ${formatDate(form.date_to)}` : 'Lengkapi periode jadwal'));
const dayCount = computed(() => (validPeriod.value ? Math.round((Date.parse(form.date_to) - Date.parse(form.date_from)) / 86400000) + 1 : 0));
const allMatchingSelected = computed(
    () => matchingEmployees.value.length > 0 && matchingEmployees.value.every((item) => form.pegawai_ids.includes(item.id)),
);
const validPeriod = computed(
    () =>
        /^\d{4}-\d{2}$/.test(form.month) &&
        /^\d{4}-\d{2}-\d{2}$/.test(form.date_from) &&
        /^\d{4}-\d{2}-\d{2}$/.test(form.date_to) &&
        form.date_from.startsWith(`${form.month}-`) &&
        form.date_to.startsWith(`${form.month}-`) &&
        form.date_from <= form.date_to &&
        form.date_to <= endOfMonth(form.month),
);
const validSelection = computed(
    () => selectedCount.value > 0 && selectedCount.value <= 5000 && (form.selection !== 'filtered' || !!form.unit_kerja_id || !!form.jabatan_id),
);
const errors = computed(() => [...new Set(Object.values(form.errors))]);
watch([downloadError, errors], async () => {
    if (!downloadError.value && !errors.value.length) return;
    await nextTick();
    errorSummary.value?.focus({ preventScroll: true });
    errorSummary.value?.scrollIntoView({ block: 'nearest' });
});
const goToStep = async (target: number) => {
    if (busy.value || (target > 1 && !validPeriod.value) || (target > 2 && !validSelection.value)) return;
    step.value = target;
    furthestStep.value = Math.max(furthestStep.value, target);
    await nextTick();
    modalBody.value?.scrollTo({ top: 0 });
    stepHeading.value?.focus({ preventScroll: true });
};
const close = () => {
    if (!busy.value) emit('close');
};
watch([employeeSearch, () => form.unit_kerja_id, () => form.jabatan_id], () => {
    employeePage.value = 1;
});

watch(
    () => form.month,
    (month) => {
        if (/^\d{4}-\d{2}$/.test(month)) {
            form.date_from = `${month}-01`;
            form.date_to = endOfMonth(month);
        }
    },
);
watch(
    () => form.unit_kerja_id,
    () => {
        if (!positions.value.some((item) => item.id === Number(form.jabatan_id))) form.jabatan_id = '';
    },
);
watch(
    () => [form.month, form.date_from, form.date_to, form.selection, form.unit_kerja_id, form.jabatan_id, ...form.pegawai_ids],
    () => {
        downloaded.value = false;
        downloadError.value = '';
        form.clearErrors();
        form.file = null;
        if (fileInput.value) fileInput.value.value = '';
        furthestStep.value = Math.min(furthestStep.value, 2);
    },
);
const toggleMatching = () => {
    const ids = matchingEmployees.value.map((item) => item.id);
    form.pegawai_ids = allMatchingSelected.value ? form.pegawai_ids.filter((id) => !ids.includes(id)) : [...new Set([...form.pegawai_ids, ...ids])];
};
const download = async () => {
    if (busy.value || !validPeriod.value || !validSelection.value) return;
    downloading.value = true;
    downloadError.value = '';
    form.clearErrors();
    try {
        const token = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch(`${baseUrl}/template`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
            body: JSON.stringify({ ...form.data(), file: undefined }),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => null);
            throw new Error(
                data?.errors ? Object.values(data.errors).flat().join(' ') : 'Template gagal diunduh. Muat ulang halaman dan coba kembali.',
            );
        }
        if (!response.headers.get('Content-Type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
            throw new Error('Sesi telah berakhir. Muat ulang halaman dan masuk kembali untuk mengunduh template.');
        }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = `template-assign-shift-${form.date_from}_${form.date_to}.xlsx`;
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
const setFile = (file: File | null) => {
    form.clearErrors('file');
    form.file = null;
    if (!file) return;
    if (!/\.(xlsx|xls)$/i.test(file.name)) return form.setError('file', 'File harus berupa Excel .xlsx atau .xls.');
    if (file.size > 5 * 1024 * 1024) return form.setError('file', 'Ukuran file Excel maksimal 5 MB.');
    form.file = file;
};
const chooseFile = (event: Event) => {
    setFile((event.target as HTMLInputElement).files?.[0] ?? null);
    (event.target as HTMLInputElement).value = '';
};
const dropFile = (event: DragEvent) => {
    dragging.value = false;
    if (!busy.value) setFile(event.dataTransfer?.files[0] ?? null);
};
const importFile = () => {
    if (step.value !== 4 || busy.value || !form.file || !validPeriod.value || !validSelection.value) return;
    form.post(`${baseUrl}/import`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.file = null;
            if (fileInput.value) fileInput.value.value = '';
            emit('close');
            step.value = 1;
            furthestStep.value = 1;
            downloaded.value = false;
        },
    });
};
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="batch-dialog" :initial-focus="closeButton" @close="close">
            <TransitionChild
                as="template"
                enter="batch-transition"
                enter-from="batch-hidden"
                enter-to="batch-visible"
                leave="batch-transition"
                leave-from="batch-visible"
                leave-to="batch-hidden"
                ><div class="batch-backdrop" aria-hidden="true"
            /></TransitionChild>
            <div class="batch-position">
                <TransitionChild
                    as="template"
                    enter="batch-transition"
                    enter-from="batch-panel-hidden"
                    enter-to="batch-visible"
                    leave="batch-transition"
                    leave-from="batch-visible"
                    leave-to="batch-panel-hidden"
                >
                    <DialogPanel class="bulk-schedule" :aria-busy="busy">
                        <header class="batch-header">
                            <span class="schedule-icon"><Layers3 /></span>
                            <div>
                                <span class="batch-eyebrow">PENJADWALAN PEGAWAI</span>
                                <DialogTitle as="h2">Assign Batch</DialogTitle>
                                <DialogDescription>Atur jadwal banyak pegawai sekaligus melalui Excel.</DialogDescription>
                            </div>
                            <button
                                ref="closeButton"
                                type="button"
                                class="batch-close"
                                aria-label="Tutup Assign Batch"
                                :disabled="busy"
                                @click="close"
                            >
                                <X />
                            </button>
                        </header>
                        <nav class="batch-stepper" aria-label="Langkah assign batch">
                            <ol>
                                <li
                                    v-for="(label, index) in steps"
                                    :key="label"
                                    :class="{ current: step === index + 1, completed: step > index + 1 }"
                                >
                                    <button
                                        type="button"
                                        :disabled="busy || index + 1 > furthestStep"
                                        :aria-current="step === index + 1 ? 'step' : undefined"
                                        @click="goToStep(index + 1)"
                                    >
                                        <span class="step-number"
                                            ><Check v-if="step > index + 1" /><template v-else>{{ index + 1 }}</template></span
                                        ><span class="step-copy"
                                            ><strong>{{ label }}</strong
                                            ><small>{{ stepDescriptions[index] }}</small></span
                                        >
                                    </button>
                                </li>
                            </ol>
                        </nav>
                        <form class="batch-form" @submit.prevent="importFile">
                            <div class="batch-workspace">
                                <div ref="modalBody" class="batch-body">
                                    <div class="batch-step-heading">
                                        <h3 ref="stepHeading" tabindex="-1">
                                            {{
                                                [
                                                    'Tentukan periode jadwal',
                                                    'Pilih pegawai yang dijadwalkan',
                                                    'Download dan isi template',
                                                    'Import jadwal shift',
                                                ][step - 1]
                                            }}
                                        </h3>
                                        <p>
                                            {{
                                                [
                                                    'Pilih bulan dan rentang tanggal yang ingin Anda atur.',
                                                    'Pilih semua pegawai atau sesuaikan berdasarkan kebutuhan.',
                                                    'Template disiapkan sesuai periode dan pegawai pilihan Anda.',
                                                    'Unggah template yang sudah diisi untuk menyimpan jadwal.',
                                                ][step - 1]
                                            }}
                                        </p>
                                    </div>
                                    <fieldset v-if="step === 1" :disabled="busy">
                                        <legend class="sr-only">Atur bulan dan tanggal</legend>
                                        <div class="date-range-label">
                                            <span><CalendarDays /> Rentang tanggal</span
                                            ><button
                                                type="button"
                                                class="text-button"
                                                :disabled="!form.month"
                                                @click="
                                                    form.date_from = `${form.month}-01`;
                                                    form.date_to = endOfMonth(form.month);
                                                "
                                            >
                                                Gunakan sebulan penuh
                                            </button>
                                        </div>
                                        <div class="schedule-fields">
                                            <label>Bulan<input v-model="form.month" type="month" required /></label>
                                            <label
                                                >Tanggal awal<input
                                                    v-model="form.date_from"
                                                    type="date"
                                                    :min="`${form.month}-01`"
                                                    :max="endOfMonth(form.month)"
                                                    required
                                            /></label>
                                            <label
                                                >Tanggal akhir<input
                                                    v-model="form.date_to"
                                                    type="date"
                                                    :min="form.date_from"
                                                    :max="endOfMonth(form.month)"
                                                    required
                                            /></label>
                                        </div>
                                        <p class="schedule-help">Tanggal awal dan akhir harus berada dalam bulan yang dipilih.</p>
                                        <div v-if="validPeriod" class="period-preview">
                                            <CalendarDays />
                                            <div>
                                                <strong>{{ periodLabel }}</strong
                                                ><span>{{ dayCount }} hari penjadwalan dalam bulan yang sama</span>
                                            </div>
                                            <span class="period-days">{{ dayCount }} hari</span>
                                        </div>
                                        <p v-else class="schedule-help error-text">Lengkapi rentang tanggal dalam bulan yang dipilih.</p>
                                    </fieldset>
                                    <fieldset v-else-if="step === 2" :disabled="busy">
                                        <legend class="sr-only">Pilih pegawai</legend>
                                        <div class="selection-modes">
                                            <label :class="{ active: form.selection === 'all' }"
                                                ><input v-model="form.selection" type="radio" value="all" /><UsersRound /><strong
                                                    >Semua pegawai</strong
                                                ><span>{{ options.employees.length }} pegawai berakun</span></label
                                            >
                                            <label :class="{ active: form.selection === 'filtered' }"
                                                ><input v-model="form.selection" type="radio" value="filtered" /><Building2 /><strong
                                                    >Unit / jabatan</strong
                                                ><span>Sesuai kelompok kerja</span></label
                                            >
                                            <label :class="{ active: form.selection === 'custom' }"
                                                ><input v-model="form.selection" type="radio" value="custom" /><UserRoundCheck /><strong
                                                    >Pegawai tertentu</strong
                                                ><span>Pilih nama satu per satu</span></label
                                            >
                                        </div>
                                        <div v-if="form.selection === 'all'" class="all-employees-note">
                                            <CheckCircle2 />
                                            <div>
                                                <strong>Seluruh pegawai berakun akan disertakan</strong>
                                                <p>Termasuk pegawai di halaman direktori lainnya.</p>
                                            </div>
                                        </div>
                                        <div v-if="form.selection !== 'all'" class="schedule-fields organization-fields">
                                            <div>
                                                <label>Unit kerja</label
                                                ><SearchableSelect
                                                    v-model="form.unit_kerja_id"
                                                    label="Unit kerja jadwal massal"
                                                    :options="unitOptions"
                                                    placeholder="Semua unit"
                                                    :disabled="busy"
                                                />
                                            </div>
                                            <div>
                                                <label>Jabatan</label
                                                ><SearchableSelect
                                                    v-model="form.jabatan_id"
                                                    label="Jabatan jadwal massal"
                                                    :options="positionOptions"
                                                    placeholder="Semua jabatan"
                                                    :disabled="busy"
                                                />
                                            </div>
                                        </div>
                                        <div v-if="form.selection === 'custom'" class="employee-picker">
                                            <label class="employee-search"
                                                ><Search />
                                                <input
                                                    v-model="employeeSearch"
                                                    type="search"
                                                    placeholder="Cari nama atau NIK pegawai..."
                                                    aria-label="Cari pegawai jadwal massal"
                                                />
                                            </label>
                                            <label class="select-matching"
                                                ><input
                                                    type="checkbox"
                                                    :checked="allMatchingSelected"
                                                    :indeterminate="someMatchingSelected"
                                                    :disabled="!matchingEmployees.length"
                                                    @change="toggleMatching"
                                                />
                                                Pilih semua hasil ({{ matchingEmployees.length }})</label
                                            >
                                            <div class="employee-options">
                                                <label
                                                    v-for="employee in visibleEmployees"
                                                    :key="employee.id"
                                                    :class="{ selected: form.pegawai_ids.includes(employee.id) }"
                                                    ><input v-model="form.pegawai_ids" type="checkbox" :value="employee.id" /><span
                                                        >{{ employee.nama }}<small>{{ employee.nik || 'Tanpa NIK' }}</small></span
                                                    ></label
                                                >
                                                <p v-if="!matchingEmployees.length">Pegawai tidak ditemukan.</p>
                                            </div>
                                            <div v-if="employeePages > 1" class="employee-pagination">
                                                <span>Halaman {{ employeePage }} dari {{ employeePages }}</span>
                                                <div>
                                                    <button
                                                        type="button"
                                                        aria-label="Halaman pegawai sebelumnya"
                                                        :disabled="busy || employeePage === 1"
                                                        @click="employeePage--"
                                                    >
                                                        <ChevronLeft /></button
                                                    ><button
                                                        type="button"
                                                        aria-label="Halaman pegawai berikutnya"
                                                        :disabled="busy || employeePage === employeePages"
                                                        @click="employeePage++"
                                                    >
                                                        <ChevronRight />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="selection-summary"><UsersRound /> {{ selectedCount }} pegawai dipilih</p>
                                        <button
                                            v-if="form.selection === 'custom' && selectedCount"
                                            type="button"
                                            class="text-button clear-selection"
                                            @click="form.pegawai_ids = []"
                                        >
                                            Bersihkan semua pilihan
                                        </button>
                                        <p v-if="selectedCount > 5000" class="schedule-help error-text">
                                            Maksimal 5.000 pegawai. Gunakan filter unit atau jabatan untuk memperkecil pilihan.
                                        </p>
                                        <p v-else-if="!validSelection" class="schedule-help error-text">
                                            Pilih minimal satu pegawai serta unit atau jabatan jika menggunakan filter.
                                        </p>
                                    </fieldset>
                                    <div v-else class="schedule-actions">
                                        <div v-if="step === 3" class="schedule-step">
                                            <div class="template-card">
                                                <span class="template-file-icon"><FileSpreadsheet /></span>
                                                <div>
                                                    <h4>Template jadwal shift</h4>
                                                    <p>{{ selectedCount }} pegawai · {{ dayCount }} kolom tanggal · Format .xlsx</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    class="download-template"
                                                    :disabled="busy || !validPeriod || !validSelection"
                                                    @click="download"
                                                >
                                                    <LoaderCircle v-if="downloading" class="spinner" /><Download v-else />
                                                    {{ downloading ? 'Menyiapkan template...' : 'Download template Excel' }}
                                                </button>
                                            </div>
                                            <small v-if="downloaded" class="download-success"
                                                ><CheckCircle2 />Template berhasil diunduh. Isi dan simpan sebelum import.</small
                                            >
                                            <ol class="template-instructions">
                                                <li>
                                                    <span>1</span>
                                                    <div>
                                                        <strong>Buka file di Excel</strong>
                                                        <p>Nama pegawai dan kolom tanggal sudah disiapkan.</p>
                                                    </div>
                                                </li>
                                                <li>
                                                    <span>2</span>
                                                    <div>
                                                        <strong>Isi shift pada tanggal yang diinginkan</strong>
                                                        <p>
                                                            Pilih kode lewat dropdown. Daftar kode dan jam kerja ada di sheet Referensi Shift. Gunakan
                                                            shift OFF untuk hari libur.
                                                        </p>
                                                    </div>
                                                </li>
                                                <li>
                                                    <span>3</span>
                                                    <div>
                                                        <strong>Simpan, lalu lanjutkan ke import</strong>
                                                        <p>Sel kosong dilewati. Pertahankan identitas pegawai dan header template.</p>
                                                    </div>
                                                </li>
                                            </ol>
                                            <p class="schedule-help">Sudah punya template untuk periode ini? Langsung lanjut ke import.</p>
                                        </div>
                                        <div v-else class="schedule-step">
                                            <div
                                                v-if="!form.file"
                                                class="file-drop-zone"
                                                :class="{ dragging, invalid: form.errors.file }"
                                                @dragover.prevent="!busy && (dragging = true)"
                                                @dragleave.prevent="dragging = false"
                                                @drop.prevent="dropFile"
                                            >
                                                <span class="upload-icon"><Upload /></span><strong>Tarik file Excel ke sini</strong
                                                ><span>atau pilih file dari komputer Anda</span
                                                ><label :for="fileId" class="choose-file">Pilih file Excel</label>
                                                <input
                                                    :id="fileId"
                                                    ref="fileInput"
                                                    class="file-control"
                                                    type="file"
                                                    accept=".xlsx,.xls"
                                                    :disabled="busy"
                                                    aria-label="Pilih template shift yang sudah diisi"
                                                    @change="chooseFile"
                                                />
                                                <small>Format .xlsx / .xls, maksimal 5 MB.</small>
                                            </div>
                                            <div v-else class="selected-file">
                                                <span class="template-file-icon"><FileSpreadsheet /></span>
                                                <div>
                                                    <strong>{{ form.file.name }}</strong
                                                    ><span
                                                        >{{ (form.file.size / 1024).toLocaleString('id-ID', { maximumFractionDigits: 1 }) }} KB · Siap
                                                        diimpor</span
                                                    >
                                                </div>
                                                <button
                                                    type="button"
                                                    class="batch-close"
                                                    aria-label="Hapus file pilihan"
                                                    :disabled="busy"
                                                    @click="
                                                        form.file = null;
                                                        form.clearErrors('file');
                                                    "
                                                >
                                                    <X />
                                                </button>
                                            </div>
                                            <div class="all-employees-note">
                                                <FileSpreadsheet />
                                                <div>
                                                    <strong>Periksa pilihan sebelum import</strong>
                                                    <p>
                                                        Gunakan template dengan periode dan pegawai yang sesuai. Seluruh file diperiksa sebelum jadwal
                                                        disimpan.
                                                    </p>
                                                </div>
                                            </div>
                                            <p v-if="form.processing" class="selection-summary" role="status">
                                                <LoaderCircle class="spinner" />Mengunggah dan memeriksa jadwal{{
                                                    form.progress ? ` (${form.progress.percentage}%)` : ''
                                                }}...
                                            </p>
                                        </div>
                                    </div>
                                    <div v-if="downloadError || errors.length" ref="errorSummary" class="schedule-errors" role="alert" tabindex="-1">
                                        <strong
                                            ><AlertCircle /> {{ step === 3 ? 'Template belum dapat diunduh' : 'Jadwal belum dapat diimpor' }}</strong
                                        >
                                        <p v-if="downloadError">{{ downloadError }}</p>
                                        <p v-for="error in errors" :key="error">{{ error }}</p>
                                    </div>
                                </div>
                                <aside class="batch-context" aria-label="Ringkasan assign batch" aria-live="polite">
                                    <h3>Ringkasan batch</h3>
                                    <div class="context-item">
                                        <CalendarDays />
                                        <div>
                                            <span>Periode jadwal</span><strong>{{ periodLabel }}</strong
                                            ><small v-if="validPeriod">{{ dayCount }} hari penjadwalan</small>
                                        </div>
                                    </div>
                                    <div class="context-item">
                                        <UsersRound />
                                        <div>
                                            <span>Pegawai dipilih</span><strong>{{ selectedCount.toLocaleString('id-ID') }} pegawai</strong
                                            ><small>{{
                                                form.selection === 'all'
                                                    ? 'Seluruh pegawai berakun'
                                                    : form.selection === 'filtered'
                                                      ? 'Berdasarkan unit / jabatan'
                                                      : 'Pilihan pegawai tertentu'
                                            }}</small>
                                        </div>
                                    </div>
                                    <div class="context-tip">
                                        <span>Tips langkah {{ step }}</span>
                                        <p>{{ stepTips[step - 1] }}</p>
                                    </div>
                                    <div class="context-note">
                                        <Layers3 />
                                        <p>
                                            Batch menyimpan jadwal per tanggal. Buka Lihat jadwal pada pegawai untuk memeriksa hasilnya. Shift default
                                            pegawai tetap.
                                        </p>
                                    </div>
                                </aside>
                            </div>
                            <footer class="batch-footer">
                                <button type="button" class="secondary" :disabled="busy" @click="step === 1 ? close() : goToStep(step - 1)">
                                    <ArrowLeft v-if="step > 1" />{{ step === 1 ? 'Batal' : 'Kembali' }}</button
                                ><span>{{ step }} dari 4 langkah</span
                                ><button
                                    v-if="step < 4"
                                    type="button"
                                    :disabled="busy || (step === 1 && !validPeriod) || (step === 2 && !validSelection)"
                                    @click="goToStep(step + 1)"
                                >
                                    {{ ['Pilih pegawai', 'Siapkan template', 'Lanjut ke import'][step - 1] }}<ArrowRight /></button
                                ><button v-else type="submit" :disabled="busy || !form.file || !validPeriod || !validSelection">
                                    <LoaderCircle v-if="form.processing" class="spinner" /><Upload v-else />{{
                                        form.processing ? 'Mengimpor...' : 'Import jadwal'
                                    }}
                                </button>
                            </footer>
                        </form>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<style scoped>
.batch-dialog {
    position: relative;
    z-index: 1100;
}
.batch-backdrop {
    position: fixed;
    z-index: 1100;
    inset: 0;
    background: rgba(13, 35, 38, 0.5);
    backdrop-filter: blur(3px);
}
.batch-position {
    position: fixed;
    z-index: 1101;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.bulk-schedule {
    display: flex;
    flex-direction: column;
    width: 1040px;
    max-width: 100%;
    height: min(760px, calc(100dvh - 48px));
    min-height: 0;
    overflow: hidden;
    padding: 0;
    border: 1px solid var(--rs-border);
    border-radius: 18px;
    color: var(--rs-ink);
    background: white;
    box-shadow: 0 24px 80px rgba(8, 30, 33, 0.24);
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.batch-header {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
    padding: 22px 28px;
    border-bottom: 1px solid var(--rs-border);
}
.schedule-icon {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    flex-shrink: 0;
    border: 1px solid var(--rs-primary-border);
    border-radius: 12px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.schedule-icon svg {
    width: 22px;
    height: 22px;
}
.batch-eyebrow {
    display: block;
    margin-bottom: 5px;
    color: var(--rs-primary);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
}
.batch-header h2 {
    margin: 0;
    color: var(--rs-primary-deep);
    font-size: 22px;
    font-weight: 750;
    letter-spacing: -0.035em;
    line-height: 1.3;
}
.batch-header p {
    margin: 5px 0 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.6;
}
button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 10px 16px;
    border: 1px solid var(--rs-primary);
    border-radius: 9px;
    color: white;
    background: var(--rs-primary);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.5;
    cursor: pointer;
    transition:
        background 150ms,
        border-color 150ms;
}
button:hover:not(:disabled) {
    background: var(--rs-primary-hover);
}
button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
button svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
input:focus-visible,
button:focus-visible,
.choose-file:focus-within {
    outline: 3px solid var(--rs-primary-pale);
    outline-offset: 3px;
}
.batch-close {
    display: grid;
    place-items: center;
    width: 34px;
    min-height: 34px;
    height: 34px;
    flex-shrink: 0;
    margin-left: auto;
    padding: 0;
    border: 0;
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: transparent;
}
.batch-close:hover:not(:disabled) {
    background: var(--rs-surface-muted);
}
.batch-close svg {
    width: 19px;
    height: 19px;
}
.batch-stepper {
    flex-shrink: 0;
    padding: 16px 24px;
    border-bottom: 1px solid var(--rs-border);
}
.batch-stepper ol {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.batch-stepper button {
    justify-content: flex-start;
    gap: 10px;
    width: 100%;
    padding: 10px;
    border-color: transparent;
    border-radius: 9px;
    color: var(--rs-text-soft);
    background: transparent;
    text-align: left;
}
.batch-stepper button:hover:not(:disabled) {
    background: var(--rs-canvas);
}
.batch-stepper button:disabled {
    opacity: 0.65;
    cursor: default;
}
.step-number {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    background: var(--rs-canvas);
    font-size: 12px;
    font-weight: 700;
}
.step-number svg {
    width: 16px;
    height: 16px;
}
.step-copy {
    display: grid;
    gap: 4px;
    min-width: 0;
}
.step-copy strong {
    font-size: 12px;
    font-weight: 700;
}
.step-copy small {
    font-size: 10px;
    font-weight: 400;
    white-space: nowrap;
}
.current button,
.current button:hover:not(:disabled) {
    border-color: var(--rs-primary-border);
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.current .step-number {
    border-color: var(--rs-primary);
    color: white;
    background: var(--rs-primary);
}
.completed .step-number {
    border-color: var(--rs-primary-border);
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.batch-form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    margin: 0;
}
.batch-workspace {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 248px;
    flex: 1;
    min-height: 0;
}
.batch-body {
    min-width: 0;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 28px;
    scrollbar-width: thin;
}
.batch-step-heading {
    margin-bottom: 24px;
}
.batch-step-heading h3 {
    margin: 0 0 8px;
    color: var(--rs-primary-deep);
    font-size: 19px;
    font-weight: 750;
    letter-spacing: -0.025em;
    line-height: 1.5;
}
.batch-step-heading h3:focus {
    outline: none;
}
.batch-step-heading p {
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.7;
}
fieldset {
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.date-range-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 16px;
}
.date-range-label > span {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    font-weight: 650;
}
.date-range-label svg {
    width: 15px;
    height: 15px;
    color: var(--rs-muted);
}
.text-button {
    min-height: 30px;
    padding: 4px 0;
    border: 0;
    border-radius: 4px;
    color: var(--rs-primary);
    background: transparent;
    font-size: 11px;
    font-weight: 650;
}
.text-button:hover:not(:disabled) {
    color: var(--rs-primary-hover);
    background: transparent;
    text-decoration: underline;
}
.schedule-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px 14px;
}
.schedule-fields:not(.organization-fields) > label:first-child {
    grid-column: 1 / -1;
}
.schedule-fields label {
    display: grid;
    gap: 8px;
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    font-weight: 650;
}
.organization-fields label {
    margin-bottom: 8px;
}
input[type='month'],
input[type='date'],
input[type='search'] {
    width: 100%;
    min-width: 0;
    height: 44px;
    padding: 0 12px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    color: var(--rs-ink);
    background: white;
    font-size: 13px;
}
input[type='checkbox'],
input[type='radio'] {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    border: 1px solid var(--rs-primary-border);
    color: var(--rs-primary);
    accent-color: var(--rs-primary);
    cursor: pointer;
}
input[type='checkbox'] {
    border-radius: 4px;
}
.period-preview {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 22px;
    padding: 16px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 10px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.period-preview > svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
.period-preview > div {
    display: grid;
    gap: 5px;
}
.period-preview strong {
    font-size: 12px;
    font-weight: 650;
    line-height: 1.5;
}
.period-preview div span {
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.6;
}
.period-days {
    flex-shrink: 0;
    margin-left: auto;
    padding: 5px 8px;
    border-radius: 6px;
    color: var(--rs-primary);
    background: white;
    font-size: 11px;
    font-weight: 650;
}
.schedule-help {
    margin: 10px 0 0;
    color: var(--rs-muted);
    font-size: 11px;
    line-height: 1.7;
}
.error-text {
    color: #b23d50;
}
.selection-modes {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 20px;
}
.selection-modes > label {
    position: relative;
    display: grid;
    align-content: start;
    gap: 8px;
    min-width: 0;
    margin: 0;
    padding: 16px 12px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    background: white;
    cursor: pointer;
    transition:
        background 150ms,
        border-color 150ms;
}
.selection-modes > label:hover {
    border-color: var(--rs-primary-pale);
    background: var(--rs-canvas);
}
.selection-modes > label.active {
    border-color: var(--rs-primary);
    background: var(--rs-primary-soft);
    box-shadow: 0 0 0 1px var(--rs-primary);
}
.selection-modes input {
    position: absolute;
    top: 15px;
    right: 11px;
}
.selection-modes svg {
    width: 20px;
    height: 20px;
    margin-bottom: 4px;
    color: var(--rs-primary);
}
.selection-modes strong {
    font-size: 12px;
    font-weight: 650;
    line-height: 1.5;
}
.selection-modes label > span {
    color: var(--rs-text-soft);
    font-size: 10px;
    line-height: 1.6;
}
.all-employees-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 16px;
    padding: 14px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    background: var(--rs-canvas);
}
.all-employees-note > svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    margin-top: 2px;
    color: var(--rs-primary);
}
.all-employees-note strong {
    font-size: 12px;
    font-weight: 650;
}
.all-employees-note p {
    margin: 5px 0 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.7;
}
.employee-picker {
    display: grid;
    gap: 14px;
    margin-top: 18px;
}
.employee-search {
    position: relative;
    display: block;
    margin: 0;
}
.employee-search svg {
    position: absolute;
    left: 12px;
    top: 14px;
    width: 16px;
    height: 16px;
    color: var(--rs-muted);
}
.employee-search input {
    padding-left: 36px;
    font-size: 12px;
}
.select-matching {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    cursor: pointer;
}
.employee-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.employee-options label {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
    margin: 0;
    padding: 11px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    font-size: 11px;
    line-height: 1.6;
    cursor: pointer;
}
.employee-options label.selected {
    border-color: var(--rs-primary-border);
    background: var(--rs-primary-soft);
}
.employee-options label > span {
    min-width: 0;
    overflow-wrap: anywhere;
}
.employee-options small {
    display: block;
    margin-top: 3px;
    color: var(--rs-muted);
    font-size: 10px;
}
.employee-options > p {
    grid-column: 1 / -1;
    margin: 0;
    padding: 24px;
    color: var(--rs-muted);
    background: var(--rs-canvas);
    text-align: center;
    font-size: 12px;
}
.employee-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    color: var(--rs-text-soft);
    font-size: 11px;
}
.employee-pagination > div {
    display: flex;
    gap: 6px;
}
.employee-pagination button {
    width: 32px;
    min-height: 32px;
    height: 32px;
    padding: 0;
    border-color: var(--rs-border);
    color: var(--rs-primary);
    background: white;
}
.employee-pagination button:hover:not(:disabled) {
    background: var(--rs-canvas);
}
.selection-summary {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 18px 0 0;
    color: var(--rs-primary);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.7;
}
.selection-summary svg {
    width: 16px;
    height: 16px;
}
.clear-selection {
    margin-top: 6px;
}
.schedule-step {
    display: grid;
    gap: 20px;
}
.template-card {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    padding: 18px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 12px;
    background: var(--rs-primary-soft);
}
.template-file-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 48px;
    flex-shrink: 0;
    border: 1px solid var(--rs-primary-border);
    border-radius: 9px;
    color: var(--rs-primary);
    background: white;
}
.template-file-icon svg {
    width: 23px;
    height: 23px;
}
.template-card > div {
    flex: 1;
    min-width: 150px;
}
.template-card h4 {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 700;
}
.template-card p {
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.7;
}
button.download-template {
    width: 100%;
    border-color: var(--rs-primary-border);
    color: var(--rs-primary);
    background: white;
}
button.download-template:hover:not(:disabled) {
    border-color: var(--rs-primary);
    background: #f8fcfa;
}
.download-success {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--rs-primary) !important;
}
.download-success svg {
    width: 15px;
    height: 15px;
}
.template-instructions {
    display: grid;
    gap: 18px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.template-instructions li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.template-instructions li > span {
    display: grid;
    place-items: center;
    width: 25px;
    height: 25px;
    flex-shrink: 0;
    border: 1px solid var(--rs-border);
    border-radius: 7px;
    color: var(--rs-text-soft);
    background: var(--rs-canvas);
    font-size: 11px;
}
.template-instructions strong {
    font-size: 12px;
    font-weight: 650;
}
.template-instructions p {
    margin: 5px 0 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.7;
}
.file-drop-zone {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 30px 18px;
    border: 1.5px dashed var(--rs-primary-pale);
    border-radius: 12px;
    background: var(--rs-canvas);
    text-align: center;
    transition:
        background 150ms,
        border-color 150ms;
}
.file-drop-zone.dragging {
    border-color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.file-drop-zone.invalid {
    border-color: #b23d50;
}
.upload-icon {
    display: grid;
    place-items: center;
    width: 48px;
    height: 48px;
    margin-bottom: 4px;
    border: 1px solid var(--rs-border);
    border-radius: 12px;
    color: var(--rs-primary);
    background: white;
}
.upload-icon svg {
    width: 23px;
    height: 23px;
}
.file-drop-zone > strong {
    font-size: 15px;
    font-weight: 700;
}
.file-drop-zone > span:not(.upload-icon),
.file-drop-zone > small {
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.7;
}
.choose-file {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    margin: 4px 0;
    padding: 9px 14px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    color: var(--rs-primary);
    background: white;
    font-size: 12px;
    font-weight: 650;
    cursor: pointer;
}
.file-control {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
}
.file-drop-zone:focus-within {
    outline: 3px solid var(--rs-primary-pale);
    outline-offset: 3px;
}
.selected-file {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 10px;
    background: var(--rs-primary-soft);
}
.selected-file > div {
    display: grid;
    gap: 6px;
    min-width: 0;
}
.selected-file strong {
    font-size: 12px;
    font-weight: 650;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.selected-file div > span {
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.5;
}
.schedule-errors {
    margin-top: 20px;
    padding: 14px;
    border: 1px solid #f1ccd2;
    border-radius: 9px;
    color: #a52c3e;
    background: #fff4f5;
    font-size: 12px;
    line-height: 1.8;
}
.schedule-errors > strong {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 6px;
    font-weight: 700;
}
.schedule-errors > strong svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.schedule-errors p {
    margin: 0;
    overflow-wrap: anywhere;
}
.batch-context {
    display: flex;
    flex-direction: column;
    gap: 22px;
    min-width: 0;
    min-height: 0;
    overflow-y: auto;
    padding: 28px 22px;
    border-left: 1px solid var(--rs-border);
    background: var(--rs-canvas);
}
.batch-context > h3 {
    margin: 0;
    color: var(--rs-ink);
    font-size: 12px;
    font-weight: 750;
}
.context-item {
    display: flex;
    align-items: flex-start;
    gap: 9px;
}
.context-item > svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin-top: 2px;
    color: var(--rs-primary);
}
.context-item > div {
    display: grid;
    gap: 7px;
    min-width: 0;
}
.context-item span {
    color: var(--rs-text-soft);
    font-size: 11px;
}
.context-item strong {
    color: var(--rs-primary-deep);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.7;
}
.context-item small {
    color: var(--rs-muted);
    font-size: 10px;
    line-height: 1.6;
}
.context-tip {
    padding: 14px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    background: white;
}
.context-tip > span {
    color: var(--rs-primary);
    font-size: 11px;
    font-weight: 700;
}
.context-tip p {
    margin: 7px 0 0;
    color: var(--rs-text-soft);
    font-size: 11px;
    line-height: 1.8;
}
.context-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: auto;
    padding-top: 18px;
    border-top: 1px solid var(--rs-border);
}
.context-note > svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    margin-top: 3px;
    color: var(--rs-muted);
}
.context-note p {
    margin: 0;
    color: var(--rs-muted);
    font-size: 10px;
    line-height: 1.8;
}
.batch-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-shrink: 0;
    padding: 18px 28px;
    border-top: 1px solid var(--rs-border);
    background: white;
}
.batch-footer > span {
    color: var(--rs-muted);
    font-size: 11px;
}
.batch-footer button.secondary {
    border-color: var(--rs-border);
    color: var(--rs-text-soft);
    background: white;
}
.batch-footer button.secondary:hover:not(:disabled) {
    background: var(--rs-canvas);
}
.spinner {
    animation: schedule-spin 0.75s linear infinite;
}
.batch-transition {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}
.batch-visible {
    opacity: 1;
    transform: translateY(0) scale(1);
}
.batch-hidden {
    opacity: 0;
}
.batch-panel-hidden {
    opacity: 0;
    transform: translateY(12px) scale(0.98);
}
@keyframes schedule-spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 850px) {
    .batch-workspace {
        grid-template-columns: minmax(0, 1fr) 210px;
    }
    .batch-body {
        padding: 24px;
    }
    .batch-context {
        padding: 24px 16px;
    }
    .step-copy small {
        display: none;
    }
    .selection-modes {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .selection-modes > label {
        grid-template-columns: 24px 1fr;
        gap: 4px 10px;
        padding: 12px;
    }
    .selection-modes svg {
        grid-row: 1 / 3;
        align-self: center;
        margin: 0;
    }
    .selection-modes strong,
    .selection-modes label > span {
        padding-right: 22px;
    }
    .selection-modes input {
        top: 22px;
        right: 14px;
    }
}
@media (max-width: 720px) {
    .batch-workspace {
        display: flex;
        flex-direction: column;
    }
    .batch-body {
        flex: 1;
        padding: 22px;
    }
    .batch-context {
        display: grid;
        grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
        gap: 12px;
        flex-shrink: 0;
        padding: 12px 22px;
        overflow: visible;
        border-top: 1px solid var(--rs-border);
        border-left: 0;
    }
    .batch-context > h3,
    .context-tip,
    .context-note,
    .context-item small {
        display: none;
    }
    .context-item {
        gap: 7px;
    }
    .context-item > div {
        gap: 3px;
    }
    .context-item span {
        font-size: 10px;
    }
    .context-item strong {
        font-size: 10px;
    }
    .context-item > svg {
        width: 14px;
        height: 14px;
    }
    .selection-modes {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
    }
    .selection-modes > label {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
        padding: 16px 12px;
    }
    .selection-modes svg {
        grid-row: auto;
        margin-bottom: 4px;
    }
    .selection-modes strong,
    .selection-modes label > span {
        padding: 0;
    }
    .selection-modes input {
        top: 15px;
        right: 11px;
    }
}
@media (max-width: 540px) {
    .batch-position {
        align-items: flex-end;
        padding: 0;
    }
    .bulk-schedule {
        height: calc(100dvh - 12px);
        max-height: calc(100dvh - 12px);
        border-radius: 18px 18px 0 0;
        border-bottom: 0;
    }
    .batch-header {
        gap: 10px;
        padding: 18px;
    }
    .batch-header .schedule-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
    }
    .batch-eyebrow {
        display: none;
    }
    .batch-header h2 {
        font-size: 19px;
    }
    .batch-header p {
        max-width: 245px;
        font-size: 11px;
    }
    .batch-stepper {
        padding: 12px;
    }
    .batch-stepper ol {
        gap: 4px;
    }
    .batch-stepper button {
        flex-direction: column;
        gap: 6px;
        padding: 8px 4px;
    }
    .step-number {
        width: 27px;
        height: 27px;
        border-radius: 7px;
    }
    .step-copy strong {
        font-size: 10px;
    }
    .batch-body {
        padding: 20px 18px;
    }
    .batch-step-heading {
        margin-bottom: 20px;
    }
    .batch-step-heading h3 {
        font-size: 17px;
    }
    .batch-step-heading p {
        font-size: 12px;
    }
    .date-range-label {
        flex-wrap: wrap;
        gap: 8px;
    }
    .schedule-fields {
        gap: 14px 10px;
    }
    input[type='month'],
    input[type='date'] {
        font-size: 12px;
        padding-inline: 9px;
    }
    .period-preview {
        gap: 10px;
        padding: 13px;
    }
    .period-days {
        display: none;
    }
    .selection-modes {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .selection-modes > label {
        grid-template-columns: 24px 1fr;
        gap: 4px 10px;
        padding: 12px;
    }
    .selection-modes svg {
        grid-row: 1 / 3;
        align-self: center;
        margin: 0;
    }
    .selection-modes strong,
    .selection-modes label > span {
        padding-right: 22px;
    }
    .selection-modes input {
        top: 22px;
        right: 14px;
    }
    .employee-options {
        grid-template-columns: 1fr;
    }
    .template-card {
        padding: 15px;
        gap: 12px;
    }
    .batch-context {
        padding: 12px 18px;
        gap: 8px;
    }
    .batch-footer {
        gap: 10px;
        padding: 14px 18px max(14px, env(safe-area-inset-bottom));
    }
    .batch-footer > span {
        display: none;
    }
    .batch-footer button {
        padding-inline: 12px;
        font-size: 11px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .batch-transition,
    button,
    .selection-modes > label,
    .file-drop-zone {
        transition: none;
    }
}
</style>
