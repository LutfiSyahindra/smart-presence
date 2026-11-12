<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    CheckCircle2,
    Clock3,
    ContactRound,
    Download,
    FileSpreadsheet,
    LoaderCircle,
    Mail,
    MailX,
    Pencil,
    Phone,
    RefreshCw,
    Search,
    ShieldCheck,
    Sparkles,
    Trash2,
    UploadCloud,
    UserPlus,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmDelete from '../../setting/components/ConfirmDelete.vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import Pagination from '../../setting/components/Pagination.vue';
import type { Paginated } from '../../setting/types';

interface PegawaiRow {
    id: number;
    nik: string | null;
    nama: string;
    profesi: string | null;
    unit_kerja_id: number | null;
    unit_kerja: string | null;
    jabatan_id: number | null;
    jabatan: string | null;
    jenis_kelamin: 'Laki-laki' | 'Perempuan' | null;
    status_pegawai: 'Tetap' | 'Tidak Tetap' | null;
    tanggal_mulai_bekerja: string | null;
    tanggal_mulai_bekerja_label: string | null;
    tanggal_lahir: string | null;
    tanggal_lahir_label: string | null;
    usia: number | null;
    nomor_telepon: string | null;
    email: string | null;
    user_id: number | null;
    account_status: 'none' | 'pending' | 'active';
    created_at: string | null;
    updated_at: string | null;
}

interface PegawaiOverview {
    total: number;
    tetap: number;
    tidak_tetap: number;
    joined_this_year: number;
}

interface UnitOption {
    id: number;
    nama: string;
    jabatans: { id: number; nama: string }[];
}

interface ExportPegawaiOption {
    id: number;
    nik: string | null;
    nama: string;
    unit_kerja: string | null;
}

const props = defineProps<{
    pegawais: Paginated<PegawaiRow>;
    overview: PegawaiOverview;
    unitOptions: UnitOption[];
    filters: { search?: string; status?: string; unit_kerja?: number | string };
    can: { create: boolean; update: boolean; delete: boolean; create_user: boolean; cancel_user_invitation: boolean; export: boolean };
}>();

const baseUrl = '/absensi/admin-web/master-data/pegawai';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Master Data', href: baseUrl },
    { title: 'Pegawai', href: baseUrl },
];

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const unitKerja = ref(Number(props.filters.unit_kerja) || 0);
const createOpen = ref(false);
const importOpen = ref(false);
const excelMode = ref<'import' | 'export'>('import');
const exportEmployees = ref<ExportPegawaiOption[]>([]);
const selectedExportIds = ref<number[]>([]);
const exportSearch = ref('');
const exportLoading = ref(false);
const exporting = ref(false);
const exportError = ref('');
const exportDownloaded = ref(false);
let exportOptionsController: AbortController | undefined;
const dragActive = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const fileInputKey = ref(0);
const editing = ref<PegawaiRow | null>(null);
const selected = ref<PegawaiRow | null>(null);
const inviting = ref<PegawaiRow | null>(null);
const cancellingInvitation = ref<PegawaiRow | null>(null);
const selectedEmployees = ref<PegawaiRow[]>([]);
const batchInvitationOpen = ref(false);
const batchCancellationOpen = ref(false);
const deleting = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const importForm = useForm<{ file: File | null }>({ file: null });
const emptyForm = () => ({
    nik: '',
    nama: '',
    profesi: '',
    unit_kerja_id: 0,
    jabatan_id: 0,
    jenis_kelamin: '',
    status_pegawai: '',
    tanggal_mulai_bekerja: '',
    tanggal_lahir: '',
    nomor_telepon: '',
    email: '',
});
const createForm = useForm(emptyForm());
const editForm = useForm(emptyForm());
const inviteForm = useForm({});
const cancelInviteForm = useForm({});
const batchInviteForm = useForm<{ pegawai_ids: number[] }>({ pegawai_ids: [] });
const batchCancelForm = useForm<{ pegawai_ids: number[] }>({ pegawai_ids: [] });

const hasActiveFilters = computed(() => Boolean(search.value.trim()) || status.value !== 'all' || unitKerja.value !== 0);
const resultSummary = computed(() => {
    if (!props.pegawais.total) return 'Belum ada hasil';
    return `${props.pegawais.from}-${props.pegawais.to} dari ${props.pegawais.total} pegawai`;
});
const previewPegawai = computed(() => props.pegawais.data.slice(0, 4));
const remainingPegawai = computed(() => Math.max(0, props.overview.total - previewPegawai.value.length));
const today = new Date();
const maxDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
const positionsFor = (unitId: number) => props.unitOptions.find((unit) => unit.id === Number(unitId))?.jabatans ?? [];
const createPositions = computed(() => positionsFor(createForm.unit_kerja_id));
const editPositions = computed(() => positionsFor(editForm.unit_kerja_id));
const canSelectEmployees = computed(() => props.can.create_user || props.can.cancel_user_invitation);
const canSelectEmployee = (pegawai: PegawaiRow) =>
    (props.can.create_user && Boolean(pegawai.email) && pegawai.account_status !== 'active') ||
    (props.can.cancel_user_invitation && pegawai.account_status === 'pending');
const selectedEmployeeIds = computed(() => new Set(selectedEmployees.value.map((pegawai) => pegawai.id)));
const eligibleSelectionRows = computed(() => props.pegawais.data.filter(canSelectEmployee));
const selectedInvitationRows = computed(() =>
    selectedEmployees.value.filter((pegawai) => Boolean(pegawai.email) && pegawai.account_status !== 'active'),
);
const selectedCancellationRows = computed(() => selectedEmployees.value.filter((pegawai) => pegawai.account_status === 'pending'));
const selectedOnPageCount = computed(() => eligibleSelectionRows.value.filter((pegawai) => selectedEmployeeIds.value.has(pegawai.id)).length);
const allEligibleInvitationsSelected = computed(
    () => eligibleSelectionRows.value.length > 0 && selectedOnPageCount.value === eligibleSelectionRows.value.length,
);
const someEligibleInvitationsSelected = computed(() => selectedOnPageCount.value > 0 && !allEligibleInvitationsSelected.value);
const batchInviteError = computed(() => Object.values(batchInviteForm.errors)[0]);
const batchCancelError = computed(() => Object.values(batchCancelForm.errors)[0]);
const selectedExportIdSet = computed(() => new Set(selectedExportIds.value));
const allExportEmployeesSelected = computed(
    () => exportEmployees.value.length > 0 && selectedExportIds.value.length === exportEmployees.value.length,
);
const someExportEmployeesSelected = computed(() => selectedExportIds.value.length > 0 && !allExportEmployeesSelected.value);
const matchingExportEmployees = computed(() => {
    const keyword = exportSearch.value.trim().toLocaleLowerCase('id-ID');
    return exportEmployees.value.filter((pegawai) =>
        [pegawai.nama, pegawai.nik, pegawai.unit_kerja].some((value) => value?.toLocaleLowerCase('id-ID').includes(keyword)),
    );
});

const summaryCards = computed(() => [
    {
        label: 'Total pegawai',
        value: props.overview.total.toLocaleString('id-ID'),
        note: 'Profil tersimpan di direktori',
        icon: UsersRound,
        tone: 'teal',
    },
    {
        label: 'Pegawai tetap',
        value: props.overview.tetap.toLocaleString('id-ID'),
        note: 'Status hubungan kerja tetap',
        icon: BadgeCheck,
        tone: 'coral',
    },
    {
        label: 'Pegawai tidak tetap',
        value: props.overview.tidak_tetap.toLocaleString('id-ID'),
        note: 'Status hubungan kerja tidak tetap',
        icon: BriefcaseBusiness,
        tone: 'sky',
    },
    {
        label: `Mulai kerja ${today.getFullYear()}`,
        value: props.overview.joined_this_year.toLocaleString('id-ID'),
        note: 'Bergabung pada tahun berjalan',
        icon: CalendarDays,
        tone: 'violet',
    },
]);

const initials = (name: string) =>
    name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();

const avatarTone = (id: number) => `tone-${(id % 5) + 1}`;

const formatPhone = (phone: string | null) => {
    if (!phone) return '-';
    if (phone.length <= 8) return phone;
    const first = phone.slice(0, 4);
    const middle = phone.slice(4, 8);
    const last = phone.slice(8);
    return [first, middle, last].filter(Boolean).join(' ');
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        baseUrl,
        {
            search: search.value.trim() || undefined,
            status: status.value === 'all' ? undefined : status.value,
            unit_kerja: unitKerja.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = () => {
    search.value = '';
    status.value = 'all';
    unitKerja.value = 0;
    applyFilters();
};

const setStatusFilter = (value: string) => {
    status.value = value;
    applyFilters();
};

watch(search, (value, previousValue) => {
    if (value === previousValue) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 450);
});

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
    exportOptionsController?.abort();
});

watch(
    () => createForm.unit_kerja_id,
    () => {
        if (!createPositions.value.some((jabatan) => jabatan.id === Number(createForm.jabatan_id))) createForm.jabatan_id = 0;
    },
);

watch(
    () => editForm.unit_kerja_id,
    () => {
        if (!editPositions.value.some((jabatan) => jabatan.id === Number(editForm.jabatan_id))) editForm.jabatan_id = 0;
    },
);

watch(
    () => props.pegawais.data,
    (rows) => {
        const visibleEmployees = new Map(rows.map((pegawai) => [pegawai.id, pegawai]));
        selectedEmployees.value = selectedEmployees.value.map((pegawai) => visibleEmployees.get(pegawai.id) ?? pegawai).filter(canSelectEmployee);
    },
);

const openCreate = () => {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
};

const closeCreate = () => {
    if (createForm.processing) return;
    createOpen.value = false;
    createForm.reset();
    createForm.clearErrors();
};

const submitCreate = () => {
    createForm.post(baseUrl, {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
    });
};

const resetImport = () => {
    importForm.reset();
    importForm.clearErrors();
    fileInputKey.value += 1;
    dragActive.value = false;
};

const openImport = () => {
    resetImport();
    exportEmployees.value = [];
    selectedExportIds.value = [];
    exportSearch.value = '';
    exportError.value = '';
    exportDownloaded.value = false;
    importOpen.value = true;
    setExcelMode(props.can.create ? 'import' : 'export');
};

const closeImport = () => {
    if (importForm.processing || exporting.value) return;
    exportOptionsController?.abort();
    exportOptionsController = undefined;
    exportLoading.value = false;
    importOpen.value = false;
    resetImport();
};

const loadExportEmployees = async () => {
    exportOptionsController?.abort();
    const controller = new AbortController();
    exportOptionsController = controller;
    exportLoading.value = true;
    exportError.value = '';

    try {
        const response = await fetch(`${baseUrl}/export-options`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) {
            throw new Error('Daftar pegawai gagal dimuat. Coba kembali atau muat ulang halaman.');
        }
        const data: { data: ExportPegawaiOption[] } = await response.json();
        if (controller.signal.aborted) return;
        exportEmployees.value = data.data;
        const availableIds = new Set(data.data.map((pegawai) => pegawai.id));
        selectedExportIds.value = selectedExportIds.value.filter((id) => availableIds.has(id));
    } catch (error) {
        if (!controller.signal.aborted) {
            exportError.value = error instanceof Error ? error.message : 'Daftar pegawai gagal dimuat.';
        }
    } finally {
        if (exportOptionsController === controller) exportLoading.value = false;
    }
};

const setExcelMode = (mode: 'import' | 'export') => {
    if (importForm.processing || exporting.value) return;
    excelMode.value = mode;
    if (mode === 'export' && !exportEmployees.value.length && !exportLoading.value) void loadExportEmployees();
};

const toggleAllExportEmployees = (event: Event) => {
    selectedExportIds.value = (event.target as HTMLInputElement).checked ? exportEmployees.value.map((pegawai) => pegawai.id) : [];
};

watch(selectedExportIds, () => {
    exportDownloaded.value = false;
    exportError.value = '';
});

const submitExport = async () => {
    if (exporting.value || exportLoading.value || !selectedExportIds.value.length) return;
    exporting.value = true;
    exportError.value = '';
    exportDownloaded.value = false;

    try {
        const token = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch(`${baseUrl}/export`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
            body: JSON.stringify({ all: allExportEmployeesSelected.value, pegawai_ids: selectedExportIds.value }),
        });
        if (!response.ok) {
            const data = await response.json().catch(() => null);
            throw new Error(data?.errors ? Object.values(data.errors).flat().join(' ') : 'Ekspor gagal. Muat ulang halaman dan coba kembali.');
        }
        if (!response.headers.get('Content-Type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
            throw new Error('Sesi telah berakhir. Muat ulang halaman dan masuk kembali untuk mengekspor data.');
        }
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = 'data-pegawai.xlsx';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        exportDownloaded.value = true;
    } catch (error) {
        exportError.value = error instanceof Error ? error.message : 'Data pegawai gagal diekspor.';
    } finally {
        exporting.value = false;
    }
};

const chooseFile = (file?: File) => {
    if (!file) return;
    importForm.clearErrors('file');
    importForm.file = null;

    if (!/\.(xlsx|xls)$/i.test(file.name)) {
        importForm.setError('file', 'Format file harus .xlsx atau .xls.');
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        importForm.setError('file', 'Ukuran file Excel maksimal 5 MB.');
        return;
    }

    importForm.file = file;
};

const handleFileChange = (event: Event) => {
    chooseFile((event.target as HTMLInputElement).files?.[0]);
};

const handleDrop = (event: DragEvent) => {
    dragActive.value = false;
    chooseFile(event.dataTransfer?.files?.[0]);
};

const submitImport = () => {
    if (!importForm.file) {
        importForm.setError('file', 'Pilih file Excel yang akan diimpor.');
        return;
    }

    importForm.post(`${baseUrl}/import`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => closeImport(),
    });
};

const openEdit = (pegawai: PegawaiRow) => {
    editing.value = pegawai;
    editForm.clearErrors();
    editForm.defaults({
        nik: pegawai.nik ?? '',
        nama: pegawai.nama,
        profesi: pegawai.profesi ?? '',
        unit_kerja_id: pegawai.unit_kerja_id ?? 0,
        jabatan_id: pegawai.jabatan_id ?? 0,
        jenis_kelamin: pegawai.jenis_kelamin ?? '',
        status_pegawai: pegawai.status_pegawai ?? '',
        tanggal_mulai_bekerja: pegawai.tanggal_mulai_bekerja ?? '',
        tanggal_lahir: pegawai.tanggal_lahir ?? '',
        nomor_telepon: pegawai.nomor_telepon ?? '',
        email: pegawai.email ?? '',
    });
    editForm.reset();
};

const closeEdit = () => {
    if (editForm.processing) return;
    editing.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const submitEdit = () => {
    if (!editing.value) return;
    editForm.put(`${baseUrl}/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};

const openInvitation = (pegawai: PegawaiRow) => {
    inviteForm.clearErrors();
    inviting.value = pegawai;
};

const closeInvitation = () => {
    if (inviteForm.processing) return;
    inviting.value = null;
    inviteForm.clearErrors();
};

const submitInvitation = () => {
    if (!inviting.value) return;
    inviteForm.post(`${baseUrl}/${inviting.value.id}/user-invitation`, {
        preserveScroll: true,
        onSuccess: () => closeInvitation(),
    });
};

const openCancelInvitation = (pegawai: PegawaiRow) => {
    cancelInviteForm.clearErrors();
    cancellingInvitation.value = pegawai;
};

const closeCancelInvitation = () => {
    if (cancelInviteForm.processing) return;
    cancellingInvitation.value = null;
    cancelInviteForm.clearErrors();
};

const submitCancelInvitation = () => {
    if (!cancellingInvitation.value || cancelInviteForm.processing) return;
    const pegawaiId = cancellingInvitation.value.id;
    cancelInviteForm.delete(`${baseUrl}/${pegawaiId}/user-invitation`, {
        preserveScroll: true,
        onSuccess: () => {
            cancellingInvitation.value = null;
            removeSelectedEmployees([pegawaiId]);
        },
    });
};

const removeSelectedEmployees = (ids: number[]) => {
    const removedIds = new Set(ids);
    selectedEmployees.value = selectedEmployees.value.filter((pegawai) => !removedIds.has(pegawai.id));
};

const selectionDisabled = (pegawai: PegawaiRow) =>
    !canSelectEmployee(pegawai) || (selectedEmployees.value.length >= 100 && !selectedEmployeeIds.value.has(pegawai.id));

const toggleEmployeeSelection = (pegawai: PegawaiRow, event: Event) => {
    if ((event.target as HTMLInputElement).checked) {
        if (!selectionDisabled(pegawai) && !selectedEmployeeIds.value.has(pegawai.id)) selectedEmployees.value.push(pegawai);
    } else {
        removeSelectedEmployees([pegawai.id]);
    }
};

const toggleAllInvitations = (event: Event) => {
    if ((event.target as HTMLInputElement).checked) {
        const additions = eligibleSelectionRows.value.filter((pegawai) => !selectedEmployeeIds.value.has(pegawai.id));
        selectedEmployees.value = [...selectedEmployees.value, ...additions].slice(0, 100);
    } else {
        removeSelectedEmployees(eligibleSelectionRows.value.map((pegawai) => pegawai.id));
    }
};

const openBatchInvitation = () => {
    if (!selectedInvitationRows.value.length) return;
    batchInviteForm.clearErrors();
    batchInviteForm.pegawai_ids = selectedInvitationRows.value.map((pegawai) => pegawai.id);
    batchInvitationOpen.value = true;
};

const closeBatchInvitation = () => {
    if (batchInviteForm.processing) return;
    batchInvitationOpen.value = false;
    batchInviteForm.clearErrors();
};

const submitBatchInvitation = () => {
    if (batchInviteForm.processing) return;
    batchInviteForm.pegawai_ids = selectedInvitationRows.value.map((pegawai) => pegawai.id);
    if (!batchInviteForm.pegawai_ids.length) return;
    const submittedIds = [...batchInviteForm.pegawai_ids];

    batchInviteForm.post(`${baseUrl}/user-invitations/batch`, {
        preserveScroll: true,
        onSuccess: () => {
            batchInvitationOpen.value = false;
            removeSelectedEmployees(submittedIds);
            batchInviteForm.reset();
        },
    });
};

const openBatchCancellation = () => {
    if (!selectedCancellationRows.value.length) return;
    batchCancelForm.clearErrors();
    batchCancellationOpen.value = true;
};

const closeBatchCancellation = () => {
    if (batchCancelForm.processing) return;
    batchCancellationOpen.value = false;
    batchCancelForm.clearErrors();
};

const submitBatchCancellation = () => {
    if (batchCancelForm.processing) return;
    batchCancelForm.pegawai_ids = selectedCancellationRows.value.map((pegawai) => pegawai.id);
    if (!batchCancelForm.pegawai_ids.length) return;
    const submittedIds = [...batchCancelForm.pegawai_ids];
    batchCancelForm.delete(`${baseUrl}/user-invitations/batch`, {
        preserveScroll: true,
        onSuccess: () => {
            batchCancellationOpen.value = false;
            removeSelectedEmployees(submittedIds);
            batchCancelForm.reset();
        },
    });
};

const destroy = () => {
    if (!selected.value) return;
    deleting.value = true;
    router.delete(`${baseUrl}/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};
</script>

<template>
    <Head title="Master Pegawai" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="employee-master-page">
            <FlashMessage />

            <section class="employee-hero" aria-labelledby="employee-hero-title">
                <span class="employee-orb employee-orb-one" aria-hidden="true"></span>
                <span class="employee-orb employee-orb-two" aria-hidden="true"></span>
                <div class="employee-hero-grid" aria-hidden="true"></div>

                <div class="employee-hero-copy">
                    <span class="employee-eyebrow"><Sparkles /> People directory</span>
                    <h1 id="employee-hero-title">Kenali setiap insan.<br /><span>Rawat koneksi tim.</span></h1>
                    <p>Kelola identitas, kontak, penempatan unit dan jabatan, status kepegawaian, serta masa kerja dalam satu direktori.</p>
                    <div class="employee-hero-trust">
                        <span><CheckCircle2 /> Impor tervalidasi</span>
                        <span><CheckCircle2 /> Aktivasi akun via email</span>
                    </div>
                </div>

                <div class="employee-hero-side">
                    <div class="employee-people-card">
                        <div class="employee-people-head">
                            <div>
                                <small>Direktori aktif</small>
                                <strong>{{ overview.total.toLocaleString('id-ID') }} pegawai</strong>
                            </div>
                            <span><ContactRound /></span>
                        </div>
                        <div class="employee-avatar-stack" aria-label="Pratinjau pegawai">
                            <span
                                v-for="pegawai in previewPegawai"
                                :key="pegawai.id"
                                class="employee-avatar"
                                :class="avatarTone(pegawai.id)"
                                :title="pegawai.nama"
                            >
                                {{ initials(pegawai.nama) }}
                            </span>
                            <span v-if="remainingPegawai" class="employee-avatar-more">+{{ remainingPegawai }}</span>
                            <span v-if="!overview.total" class="employee-avatar-empty"><UsersRound /> Siap diisi</span>
                        </div>
                        <div class="employee-flow">
                            <span><Download /></span>
                            <i></i>
                            <span><FileSpreadsheet /></span>
                            <i></i>
                            <span><UploadCloud /></span>
                        </div>
                        <p>Unduh template, lengkapi profil dan penempatan, lalu impor.</p>
                    </div>

                    <button v-if="can.create" type="button" class="employee-hero-action" @click="openCreate">
                        <span><UserPlus /></span>
                        <span><strong>Tambah pegawai</strong><small>Isi data pegawai secara manual</small></span>
                        <ArrowRight />
                    </button>
                </div>
            </section>

            <section class="employee-overview-grid" aria-label="Ringkasan pegawai">
                <article v-for="card in summaryCards" :key="card.label" class="employee-stat-card" :class="`is-${card.tone}`">
                    <span class="employee-stat-icon"><component :is="card.icon" /></span>
                    <div>
                        <small>{{ card.label }}</small
                        ><strong>{{ card.value }}</strong>
                        <p>{{ card.note }}</p>
                    </div>
                </article>
            </section>

            <section class="employee-directory" aria-labelledby="employee-directory-title">
                <header class="employee-directory-header">
                    <div>
                        <span class="employee-section-kicker">Direktori pegawai</span>
                        <h2 id="employee-directory-title">Master pegawai</h2>
                        <p>Temukan profil, unit, jabatan, status, dan masa kerja setiap pegawai.</p>
                    </div>
                    <div class="employee-directory-count">
                        <span><i></i> Data tersinkron</span>
                        <strong>{{ resultSummary }}</strong>
                    </div>
                </header>

                <div class="employee-toolbar">
                    <label class="employee-search" :class="{ filled: search }">
                        <span class="visually-hidden">Cari pegawai</span>
                        <Search />
                        <input v-model="search" type="search" placeholder="Cari nama, NIK, telepon, email, unit, atau jabatan..." />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="search = ''"><X /></button>
                    </label>
                    <div class="employee-filter-pills" aria-label="Filter pegawai">
                        <button type="button" :class="{ active: status === 'all' }" @click="setStatusFilter('all')">Semua</button>
                        <button type="button" :class="{ active: status === 'Tetap' }" @click="setStatusFilter('Tetap')">Tetap</button>
                        <button type="button" :class="{ active: status === 'Tidak Tetap' }" @click="setStatusFilter('Tidak Tetap')">
                            Tidak tetap
                        </button>
                    </div>
                    <label class="employee-unit-filter">
                        <Building2 />
                        <select v-model.number="unitKerja" aria-label="Filter unit kerja" @change="applyFilters">
                            <option :value="0">Semua unit</option>
                            <option v-for="unit in unitOptions" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
                        </select>
                    </label>
                    <div v-if="can.create || can.export || canSelectEmployees" class="employee-toolbar-actions">
                        <button
                            v-if="can.create_user"
                            type="button"
                            class="employee-batch-button"
                            :disabled="!selectedInvitationRows.length"
                            @click="openBatchInvitation"
                        >
                            <Mail /> Kirim aktivasi
                            <span v-if="selectedInvitationRows.length">{{ selectedInvitationRows.length }}</span>
                        </button>
                        <button
                            v-if="can.cancel_user_invitation"
                            type="button"
                            class="employee-batch-button"
                            :disabled="!selectedCancellationRows.length"
                            @click="openBatchCancellation"
                        >
                            <MailX /> Batal aktivasi
                            <span v-if="selectedCancellationRows.length">{{ selectedCancellationRows.length }}</span>
                        </button>
                        <button v-if="can.create" type="button" class="employee-create-button" @click="openCreate"><UserPlus /> Tambah manual</button>
                        <button v-if="can.create || can.export" type="button" class="employee-import-button" @click="openImport">
                            <FileSpreadsheet /> {{ can.create ? 'Import / Ekspor Excel' : 'Ekspor Excel' }}
                        </button>
                    </div>
                </div>

                <div v-if="selectedEmployees.length" class="employee-selection-summary" aria-label="Pegawai terpilih">
                    <div class="employee-selection-summary-header">
                        <div>
                            <strong aria-live="polite">{{ selectedEmployees.length }} pegawai dipilih</strong>
                            <p>Pilihan tetap tersimpan saat mencari atau berpindah halaman. Maksimal 100 pegawai.</p>
                        </div>
                        <button type="button" @click="selectedEmployees = []">Hapus semua pilihan</button>
                    </div>
                    <div class="employee-selection-chips">
                        <button
                            v-for="pegawai in selectedEmployees"
                            :key="pegawai.id"
                            type="button"
                            :aria-label="`Batalkan pilihan ${pegawai.nama}`"
                            @click="removeSelectedEmployees([pegawai.id])"
                        >
                            <span>{{ pegawai.nama }}</span
                            ><X />
                        </button>
                    </div>
                </div>

                <template v-if="pegawais.data.length">
                    <div class="employee-table-wrap">
                        <table class="employee-table">
                            <thead>
                                <tr>
                                    <th v-if="canSelectEmployees" class="employee-selection-cell">
                                        <input
                                            type="checkbox"
                                            :checked="allEligibleInvitationsSelected"
                                            :indeterminate.prop="someEligibleInvitationsSelected"
                                            :disabled="!eligibleSelectionRows.length"
                                            aria-label="Pilih semua pegawai yang dapat diproses pada halaman ini"
                                            @change="toggleAllInvitations"
                                        />
                                    </th>
                                    <th>Identitas pegawai</th>
                                    <th>Unit & jabatan</th>
                                    <th>Jenis kelamin</th>
                                    <th>Status</th>
                                    <th>Mulai bekerja</th>
                                    <th>Kontak</th>
                                    <th
                                        v-if="can.create_user || can.cancel_user_invitation || can.update || can.delete"
                                        class="employee-actions-heading"
                                    >
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="pegawai in pegawais.data" :key="pegawai.id">
                                    <td v-if="canSelectEmployees" class="employee-selection-cell">
                                        <input
                                            type="checkbox"
                                            :value="pegawai.id"
                                            :checked="selectedEmployeeIds.has(pegawai.id)"
                                            :disabled="selectionDisabled(pegawai)"
                                            :aria-label="`Pilih ${pegawai.nama} untuk pengelolaan aktivasi`"
                                            :title="
                                                pegawai.account_status === 'active'
                                                    ? 'Akun pegawai sudah aktif'
                                                    : selectionDisabled(pegawai)
                                                      ? 'Pegawai tidak dapat dipilih atau batas 100 pilihan tercapai'
                                                      : 'Pilih pegawai'
                                            "
                                            @change="toggleEmployeeSelection(pegawai, $event)"
                                        />
                                    </td>
                                    <td>
                                        <div class="employee-identity">
                                            <span class="employee-identity-avatar" :class="avatarTone(pegawai.id)">{{ initials(pegawai.nama) }}</span>
                                            <span
                                                ><strong>{{ pegawai.nama }}</strong
                                                ><small>NIK {{ pegawai.nik ?? '-' }} · {{ pegawai.profesi ?? 'Profesi belum diisi' }}</small
                                                ><em class="employee-account-badge" :class="pegawai.account_status">
                                                    <ShieldCheck v-if="pegawai.account_status === 'active'" />
                                                    <Clock3 v-else-if="pegawai.account_status === 'pending'" />
                                                    <UserPlus v-else />
                                                    {{
                                                        pegawai.account_status === 'active'
                                                            ? 'Akun aktif'
                                                            : pegawai.account_status === 'pending'
                                                              ? 'Menunggu aktivasi'
                                                              : 'Belum punya akun'
                                                    }}
                                                </em></span
                                            >
                                        </div>
                                    </td>
                                    <td>
                                        <span class="employee-org"
                                            ><strong>{{ pegawai.unit_kerja ?? '-' }}</strong
                                            ><small>{{ pegawai.jabatan ?? '-' }}</small></span
                                        >
                                    </td>
                                    <td>
                                        <span class="employee-gender">{{ pegawai.jenis_kelamin ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="employee-status" :class="pegawai.status_pegawai === 'Tetap' ? 'permanent' : 'temporary'">
                                            {{ pegawai.status_pegawai ?? '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="employee-date"><CalendarDays /> {{ pegawai.tanggal_mulai_bekerja_label ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="employee-contact">
                                            <a v-if="pegawai.nomor_telepon" class="employee-phone" :href="`tel:${pegawai.nomor_telepon}`"
                                                ><Phone /> {{ formatPhone(pegawai.nomor_telepon) }}</a
                                            >
                                            <a v-if="pegawai.email" class="employee-email" :href="`mailto:${pegawai.email}`"
                                                ><Mail /> {{ pegawai.email }}</a
                                            >
                                            <small v-if="!pegawai.nomor_telepon && !pegawai.email" class="employee-updated">Belum diisi</small>
                                        </span>
                                    </td>
                                    <td v-if="can.create_user || can.cancel_user_invitation || can.update || can.delete">
                                        <div class="employee-row-actions">
                                            <button
                                                v-if="can.create_user && pegawai.account_status !== 'active'"
                                                type="button"
                                                class="account"
                                                :disabled="!pegawai.email"
                                                :aria-label="pegawai.account_status === 'pending' ? 'Kirim ulang undangan' : 'Jadikan user'"
                                                :title="
                                                    !pegawai.email
                                                        ? 'Isi email pegawai terlebih dahulu'
                                                        : pegawai.account_status === 'pending'
                                                          ? 'Kirim ulang undangan aktivasi'
                                                          : 'Jadikan user'
                                                "
                                                @click="openInvitation(pegawai)"
                                            >
                                                <RefreshCw v-if="pegawai.account_status === 'pending'" />
                                                <UserPlus v-else />
                                            </button>
                                            <button
                                                v-if="can.cancel_user_invitation && pegawai.account_status === 'pending'"
                                                type="button"
                                                class="danger"
                                                aria-label="Batal Aktivasi"
                                                title="Batal Aktivasi"
                                                @click="openCancelInvitation(pegawai)"
                                            >
                                                <MailX />
                                            </button>
                                            <button
                                                v-if="can.update"
                                                type="button"
                                                aria-label="Edit pegawai"
                                                title="Edit pegawai"
                                                @click="openEdit(pegawai)"
                                            >
                                                <Pencil />
                                            </button>
                                            <button
                                                v-if="can.delete"
                                                type="button"
                                                class="danger"
                                                :disabled="pegawai.account_status !== 'none'"
                                                aria-label="Hapus pegawai"
                                                :title="pegawai.account_status !== 'none' ? 'Hapus akun terkait terlebih dahulu' : 'Hapus pegawai'"
                                                @click="selected = pegawai"
                                            >
                                                <Trash2 />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="employee-mobile-list">
                        <article v-for="pegawai in pegawais.data" :key="pegawai.id" class="employee-mobile-card">
                            <header>
                                <input
                                    v-if="canSelectEmployees"
                                    class="employee-mobile-selection"
                                    type="checkbox"
                                    :value="pegawai.id"
                                    :checked="selectedEmployeeIds.has(pegawai.id)"
                                    :disabled="selectionDisabled(pegawai)"
                                    :aria-label="`Pilih ${pegawai.nama} untuk pengelolaan aktivasi`"
                                    @change="toggleEmployeeSelection(pegawai, $event)"
                                />
                                <div class="employee-identity">
                                    <span class="employee-identity-avatar" :class="avatarTone(pegawai.id)">{{ initials(pegawai.nama) }}</span>
                                    <span
                                        ><strong>{{ pegawai.nama }}</strong
                                        ><small>NIK {{ pegawai.nik ?? '-' }} · {{ pegawai.status_pegawai ?? '-' }}</small></span
                                    >
                                </div>
                                <div v-if="can.create_user || can.cancel_user_invitation || can.update || can.delete" class="employee-row-actions">
                                    <button
                                        v-if="can.create_user && pegawai.account_status !== 'active'"
                                        type="button"
                                        class="account"
                                        :disabled="!pegawai.email"
                                        :aria-label="pegawai.account_status === 'pending' ? 'Kirim ulang undangan' : 'Jadikan user'"
                                        @click="openInvitation(pegawai)"
                                    >
                                        <RefreshCw v-if="pegawai.account_status === 'pending'" />
                                        <UserPlus v-else />
                                    </button>
                                    <button
                                        v-if="can.cancel_user_invitation && pegawai.account_status === 'pending'"
                                        type="button"
                                        class="danger"
                                        aria-label="Batal Aktivasi"
                                        title="Batal Aktivasi"
                                        @click="openCancelInvitation(pegawai)"
                                    >
                                        <MailX />
                                    </button>
                                    <button v-if="can.update" type="button" aria-label="Edit pegawai" @click="openEdit(pegawai)"><Pencil /></button>
                                    <button
                                        v-if="can.delete"
                                        type="button"
                                        class="danger"
                                        :disabled="pegawai.account_status !== 'none'"
                                        aria-label="Hapus pegawai"
                                        @click="selected = pegawai"
                                    >
                                        <Trash2 />
                                    </button>
                                </div>
                            </header>
                            <div class="employee-mobile-details">
                                <span><Building2 /> {{ pegawai.unit_kerja ?? '-' }}</span>
                                <span><BriefcaseBusiness /> {{ pegawai.jabatan ?? '-' }}</span>
                                <span><CalendarDays /> {{ pegawai.tanggal_mulai_bekerja_label ?? '-' }}</span>
                                <a v-if="pegawai.nomor_telepon" :href="`tel:${pegawai.nomor_telepon}`"
                                    ><Phone /> {{ formatPhone(pegawai.nomor_telepon) }}</a
                                >
                                <a v-if="pegawai.email" :href="`mailto:${pegawai.email}`"><Mail /> {{ pegawai.email }}</a>
                                <span class="employee-mobile-account" :class="pegawai.account_status">
                                    <ShieldCheck v-if="pegawai.account_status === 'active'" />
                                    <Clock3 v-else-if="pegawai.account_status === 'pending'" />
                                    <UserPlus v-else />
                                    {{
                                        pegawai.account_status === 'active'
                                            ? 'Akun aktif'
                                            : pegawai.account_status === 'pending'
                                              ? 'Menunggu aktivasi'
                                              : 'Belum punya akun'
                                    }}
                                </span>
                            </div>
                        </article>
                    </div>
                </template>

                <div v-else class="employee-empty-state">
                    <span><ContactRound /></span>
                    <h3>{{ hasActiveFilters ? 'Pegawai tidak ditemukan' : 'Direktori masih kosong' }}</h3>
                    <p>
                        {{
                            hasActiveFilters
                                ? 'Coba ubah kata kunci atau tampilkan seluruh pegawai.'
                                : 'Tambahkan data pegawai secara manual atau gunakan template Excel.'
                        }}
                    </p>
                    <button v-if="hasActiveFilters" type="button" @click="clearFilters">Tampilkan semua pegawai</button>
                    <button v-else-if="can.create" type="button" @click="openCreate"><UserPlus /> Tambah pegawai pertama</button>
                </div>

                <Pagination :links="pegawais.links" :from="pegawais.from" :to="pegawais.to" :total="pegawais.total" preserve-state />
            </section>
        </div>

        <CrudModal
            :open="createOpen"
            title="Tambah pegawai baru"
            description="Isi identitas, penempatan, status kerja, dan email aktif pegawai."
            @close="closeCreate"
        >
            <form class="employee-create-form" @submit.prevent="submitCreate">
                <div class="admin-modal-body employee-modal-body">
                    <div class="employee-profile-preview">
                        <span>{{ initials(createForm.nama || 'Pegawai') }}</span>
                        <div>
                            <small>Profil baru</small><strong>{{ createForm.nama || 'Nama pegawai' }}</strong
                            ><em>{{ createForm.nik || 'NIK belum diisi' }} · profil pegawai</em>
                        </div>
                    </div>

                    <div class="employee-form-grid">
                        <label class="employee-form-field full">
                            <span>Nama pegawai <b>*</b></span>
                            <input
                                v-model="createForm.nama"
                                type="text"
                                maxlength="150"
                                autocomplete="name"
                                autofocus
                                placeholder="Nama lengkap pegawai"
                            />
                            <small v-if="createForm.errors.nama" class="employee-field-error">{{ createForm.errors.nama }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>NIK <b>*</b></span>
                            <input v-model="createForm.nik" type="text" maxlength="50" placeholder="157.101.019" />
                            <small v-if="createForm.errors.nik" class="employee-field-error">{{ createForm.errors.nik }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Profesi</span>
                            <input v-model="createForm.profesi" type="text" maxlength="150" placeholder="Contoh: Ners" />
                            <small v-if="createForm.errors.profesi" class="employee-field-error">{{ createForm.errors.profesi }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Unit kerja <b>*</b></span>
                            <select v-model.number="createForm.unit_kerja_id">
                                <option :value="0" disabled>Pilih unit kerja</option>
                                <option v-for="unit in unitOptions" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
                            </select>
                            <small v-if="createForm.errors.unit_kerja_id" class="employee-field-error">{{ createForm.errors.unit_kerja_id }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Jabatan <b>*</b></span>
                            <select v-model.number="createForm.jabatan_id" :disabled="!createForm.unit_kerja_id">
                                <option :value="0" disabled>Pilih jabatan</option>
                                <option v-for="jabatan in createPositions" :key="jabatan.id" :value="jabatan.id">{{ jabatan.nama }}</option>
                            </select>
                            <small v-if="createForm.errors.jabatan_id" class="employee-field-error">{{ createForm.errors.jabatan_id }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Jenis kelamin <b>*</b></span>
                            <select v-model="createForm.jenis_kelamin">
                                <option value="" disabled>Pilih jenis kelamin</option>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                            <small v-if="createForm.errors.jenis_kelamin" class="employee-field-error">{{ createForm.errors.jenis_kelamin }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Status pegawai <b>*</b></span>
                            <select v-model="createForm.status_pegawai">
                                <option value="" disabled>Pilih status</option>
                                <option value="Tetap">Tetap</option>
                                <option value="Tidak Tetap">Tidak Tetap</option>
                            </select>
                            <small v-if="createForm.errors.status_pegawai" class="employee-field-error">{{ createForm.errors.status_pegawai }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Tanggal mulai bekerja <b>*</b></span>
                            <input v-model="createForm.tanggal_mulai_bekerja" type="date" min="1900-01-01" :max="maxDate" />
                            <small v-if="createForm.errors.tanggal_mulai_bekerja" class="employee-field-error">{{
                                createForm.errors.tanggal_mulai_bekerja
                            }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Tanggal lahir</span>
                            <input v-model="createForm.tanggal_lahir" type="date" min="1900-01-01" :max="maxDate" />
                            <small v-if="createForm.errors.tanggal_lahir" class="employee-field-error">{{ createForm.errors.tanggal_lahir }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Nomor telepon</span>
                            <input v-model="createForm.nomor_telepon" type="tel" maxlength="20" autocomplete="tel" placeholder="081234567890" />
                            <small v-if="createForm.errors.nomor_telepon" class="employee-field-error">{{ createForm.errors.nomor_telepon }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Email</span>
                            <input v-model="createForm.email" type="email" maxlength="255" autocomplete="email" placeholder="nama@rsarsy.co.id" />
                            <small v-if="createForm.errors.email" class="employee-field-error">{{ createForm.errors.email }}</small>
                        </label>
                    </div>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="createForm.processing" @click="closeCreate">Batal</button>
                    <button type="submit" class="employee-button primary" :disabled="createForm.processing">
                        <LoaderCircle v-if="createForm.processing" class="employee-spin" /><UserPlus v-else />
                        {{ createForm.processing ? 'Menyimpan...' : 'Simpan pegawai' }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="importOpen"
            title="Excel data pegawai"
            description="Impor data dari template atau pilih pegawai yang ingin diekspor ke Excel."
            @close="closeImport"
        >
            <form class="employee-import-form" @submit.prevent="excelMode === 'import' ? submitImport() : submitExport()">
                <div class="admin-modal-body employee-modal-body">
                    <div v-if="can.create && can.export" class="employee-excel-tabs" role="group" aria-label="Pilih proses Excel">
                        <button
                            type="button"
                            :aria-pressed="excelMode === 'import'"
                            :disabled="importForm.processing || exporting"
                            @click="setExcelMode('import')"
                        >
                            <UploadCloud /> Import Excel
                        </button>
                        <button
                            type="button"
                            :aria-pressed="excelMode === 'export'"
                            :disabled="importForm.processing || exporting"
                            @click="setExcelMode('export')"
                        >
                            <Download /> Ekspor Excel
                        </button>
                    </div>

                    <template v-if="excelMode === 'import'">
                        <p class="employee-export-hint">
                            NIK yang sama akan memperbarui pegawai terdaftar; data pegawai baru akan ditambahkan. Untuk format lama tanpa NIK,
                            pencocokan memakai nomor telepon atau email.
                        </p>
                        <a :href="`${baseUrl}/template`" class="employee-template-card">
                            <span class="employee-template-icon"><FileSpreadsheet /></span>
                            <span>
                                <small>Langkah 1</small>
                                <strong>Unduh template Excel</strong>
                                <em>NIK · Nama · Unit · Jabatan · Status · Telepon · Email</em>
                            </span>
                            <Download />
                        </a>

                        <div class="employee-import-divider"><span>kemudian</span></div>

                        <input
                            :key="fileInputKey"
                            ref="fileInput"
                            type="file"
                            class="visually-hidden"
                            accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                            @change="handleFileChange"
                        />
                        <button
                            type="button"
                            class="employee-dropzone"
                            :class="{ active: dragActive, selected: importForm.file, invalid: importForm.errors.file }"
                            @click="fileInput?.click()"
                            @dragenter.prevent="dragActive = true"
                            @dragover.prevent="dragActive = true"
                            @dragleave.prevent="dragActive = false"
                            @drop.prevent="handleDrop"
                        >
                            <span class="employee-drop-icon"><CheckCircle2 v-if="importForm.file" /><UploadCloud v-else /></span>
                            <strong>{{ importForm.file ? importForm.file.name : 'Tarik file Excel ke area ini' }}</strong>
                            <small v-if="importForm.file"
                                >{{ (importForm.file.size / 1024).toLocaleString('id-ID', { maximumFractionDigits: 1 }) }} KB · Klik untuk
                                mengganti</small
                            >
                            <small v-else>atau klik untuk memilih file .xlsx / .xls (maks. 5 MB)</small>
                        </button>
                        <p v-if="importForm.errors.file" class="employee-field-error">{{ importForm.errors.file }}</p>

                        <div class="employee-import-notes">
                            <span><strong>1.000</strong><small>Maks. baris</small></span>
                            <span><strong>11 kolom</strong><small>Profil lengkap</small></span>
                            <span><strong>Transaksi aman</strong><small>Tanpa data separuh</small></span>
                        </div>
                    </template>

                    <template v-else>
                        <div class="employee-export-heading">
                            <strong>Pilih pegawai untuk diekspor</strong>
                            <p>Semua 11 kolom profil pegawai akan disertakan. Pilihan mencakup seluruh direktori, termasuk halaman lainnya.</p>
                        </div>
                        <label class="employee-export-search">
                            <Search aria-hidden="true" />
                            <input
                                v-model="exportSearch"
                                type="search"
                                placeholder="Cari nama, NIK, atau unit kerja..."
                                aria-label="Cari pegawai untuk ekspor"
                                :disabled="exportLoading || exporting"
                            />
                        </label>
                        <label class="employee-export-select-all">
                            <input
                                type="checkbox"
                                :checked="allExportEmployeesSelected"
                                :indeterminate="someExportEmployeesSelected"
                                :disabled="exportLoading || exporting || !exportEmployees.length"
                                @change="toggleAllExportEmployees"
                            />
                            <span
                                >Pilih semua pegawai <small>({{ exportEmployees.length }})</small></span
                            >
                            <strong aria-live="polite">{{ selectedExportIds.length }} dipilih</strong>
                        </label>
                        <div class="employee-export-list" :aria-busy="exportLoading" role="group" aria-label="Daftar pegawai untuk ekspor">
                            <p v-if="exportLoading" class="employee-export-empty"><LoaderCircle class="employee-spin" /> Memuat daftar pegawai...</p>
                            <template v-else>
                                <label
                                    v-for="pegawai in matchingExportEmployees"
                                    :key="pegawai.id"
                                    class="employee-export-row"
                                    :class="{ selected: selectedExportIdSet.has(pegawai.id) }"
                                >
                                    <input v-model="selectedExportIds" type="checkbox" :value="pegawai.id" :disabled="exporting" />
                                    <span>
                                        <strong>{{ pegawai.nama }}</strong>
                                        <small>{{ pegawai.nik || 'Tanpa NIK' }} · {{ pegawai.unit_kerja || 'Tanpa unit kerja' }}</small>
                                    </span>
                                </label>
                                <p v-if="!matchingExportEmployees.length && !exportError" class="employee-export-empty">
                                    {{
                                        exportSearch.trim()
                                            ? 'Tidak ada pegawai yang sesuai pencarian.'
                                            : 'Belum ada data pegawai yang dapat diekspor.'
                                    }}
                                </p>
                            </template>
                        </div>
                        <p v-if="exportSearch.trim() && !exportLoading" class="employee-export-hint">
                            {{ matchingExportEmployees.length }} hasil pencarian. Pilihan di luar hasil pencarian tetap disertakan.
                        </p>
                        <div v-if="exportError" role="alert" class="employee-export-error">
                            <p class="employee-field-error">{{ exportError }}</p>
                            <button
                                v-if="!exportEmployees.length"
                                type="button"
                                class="employee-button ghost"
                                :disabled="exportLoading"
                                @click="loadExportEmployees"
                            >
                                Coba muat kembali
                            </button>
                        </div>
                        <p v-if="exportDownloaded" class="employee-export-success" role="status">
                            <CheckCircle2 /> File Excel berhasil disiapkan dan diunduh.
                        </p>
                    </template>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="importForm.processing || exporting" @click="closeImport">
                        Tutup
                    </button>
                    <button
                        v-if="excelMode === 'import'"
                        type="submit"
                        class="employee-button primary"
                        :disabled="importForm.processing || !importForm.file"
                    >
                        <LoaderCircle v-if="importForm.processing" class="employee-spin" />
                        <UploadCloud v-else />
                        {{ importForm.processing ? 'Memeriksa & mengimpor...' : 'Import pegawai' }}
                    </button>
                    <button v-else type="submit" class="employee-button primary" :disabled="exporting || exportLoading || !selectedExportIds.length">
                        <LoaderCircle v-if="exporting" class="employee-spin" />
                        <Download v-else />
                        {{ exporting ? 'Menyiapkan Excel...' : `Ekspor ${selectedExportIds.length} pegawai` }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="Boolean(editing)"
            :title="`Edit pegawai: ${editing?.nama ?? ''}`"
            description="Perbarui identitas, penempatan, dan status kerja pegawai."
            @close="closeEdit"
        >
            <form class="employee-edit-form" @submit.prevent="submitEdit">
                <div class="admin-modal-body employee-modal-body">
                    <div class="employee-profile-preview">
                        <span>{{ initials(editForm.nama || 'Pegawai') }}</span>
                        <div>
                            <small>Profil pegawai</small><strong>{{ editForm.nama || 'Nama pegawai' }}</strong
                            ><em
                                >{{ editForm.nik || 'NIK belum diisi' }} ·
                                {{ editing?.account_status === 'active' ? 'akun aktif' : 'profil pegawai' }}</em
                            >
                        </div>
                    </div>

                    <div class="employee-form-grid">
                        <label class="employee-form-field full">
                            <span>Nama pegawai <b>*</b></span>
                            <input v-model="editForm.nama" type="text" maxlength="150" autocomplete="name" placeholder="Nama lengkap pegawai" />
                            <small v-if="editForm.errors.nama" class="employee-field-error">{{ editForm.errors.nama }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>NIK <b>*</b></span>
                            <input v-model="editForm.nik" type="text" maxlength="50" placeholder="157.101.019" />
                            <small v-if="editForm.errors.nik" class="employee-field-error">{{ editForm.errors.nik }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Profesi</span>
                            <input v-model="editForm.profesi" type="text" maxlength="150" placeholder="Contoh: Ners" />
                            <small v-if="editForm.errors.profesi" class="employee-field-error">{{ editForm.errors.profesi }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Unit kerja <b>*</b></span>
                            <select v-model.number="editForm.unit_kerja_id">
                                <option :value="0" disabled>Pilih unit kerja</option>
                                <option v-for="unit in unitOptions" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
                            </select>
                            <small v-if="editForm.errors.unit_kerja_id" class="employee-field-error">{{ editForm.errors.unit_kerja_id }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Jabatan <b>*</b></span>
                            <select v-model.number="editForm.jabatan_id" :disabled="!editForm.unit_kerja_id">
                                <option :value="0" disabled>Pilih jabatan</option>
                                <option v-for="jabatan in editPositions" :key="jabatan.id" :value="jabatan.id">{{ jabatan.nama }}</option>
                            </select>
                            <small v-if="editForm.errors.jabatan_id" class="employee-field-error">{{ editForm.errors.jabatan_id }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Jenis kelamin <b>*</b></span>
                            <select v-model="editForm.jenis_kelamin">
                                <option value="" disabled>Pilih jenis kelamin</option>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                            <small v-if="editForm.errors.jenis_kelamin" class="employee-field-error">{{ editForm.errors.jenis_kelamin }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Status pegawai <b>*</b></span>
                            <select v-model="editForm.status_pegawai">
                                <option value="" disabled>Pilih status</option>
                                <option value="Tetap">Tetap</option>
                                <option value="Tidak Tetap">Tidak Tetap</option>
                            </select>
                            <small v-if="editForm.errors.status_pegawai" class="employee-field-error">{{ editForm.errors.status_pegawai }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Tanggal mulai bekerja <b>*</b></span>
                            <input v-model="editForm.tanggal_mulai_bekerja" type="date" min="1900-01-01" :max="maxDate" />
                            <small v-if="editForm.errors.tanggal_mulai_bekerja" class="employee-field-error">{{
                                editForm.errors.tanggal_mulai_bekerja
                            }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Tanggal lahir</span>
                            <input v-model="editForm.tanggal_lahir" type="date" min="1900-01-01" :max="maxDate" />
                            <small v-if="editForm.errors.tanggal_lahir" class="employee-field-error">{{ editForm.errors.tanggal_lahir }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Nomor telepon</span>
                            <input v-model="editForm.nomor_telepon" type="tel" maxlength="20" autocomplete="tel" placeholder="081234567890" />
                            <small v-if="editForm.errors.nomor_telepon" class="employee-field-error">{{ editForm.errors.nomor_telepon }}</small>
                        </label>
                        <label class="employee-form-field">
                            <span>Email</span>
                            <input
                                v-model="editForm.email"
                                type="email"
                                maxlength="255"
                                autocomplete="email"
                                placeholder="nama@rsarsy.co.id"
                                :readonly="editing?.account_status !== 'none'"
                            />
                            <small v-if="editing?.account_status !== 'none'" class="employee-field-hint">
                                Email terkunci selama masih terhubung dengan akun pengguna.
                            </small>
                            <small v-if="editForm.errors.email" class="employee-field-error">{{ editForm.errors.email }}</small>
                        </label>
                    </div>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="editForm.processing" @click="closeEdit">Batal</button>
                    <button type="submit" class="employee-button primary" :disabled="editForm.processing">
                        <LoaderCircle v-if="editForm.processing" class="employee-spin" /><Pencil v-else />
                        {{ editForm.processing ? 'Menyimpan...' : 'Simpan perubahan' }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="Boolean(inviting)"
            :title="inviting?.account_status === 'pending' ? 'Kirim ulang undangan?' : 'Jadikan pegawai sebagai user?'"
            :description="
                inviting?.account_status === 'pending'
                    ? 'Tautan sebelumnya akan diganti dengan tautan aktivasi terbaru.'
                    : 'Sistem akan membuat akun dengan role pegawai dan mengirim tautan aktivasi.'
            "
            @close="closeInvitation"
        >
            <form class="employee-invite-form" @submit.prevent="submitInvitation">
                <div class="admin-modal-body employee-modal-body">
                    <div class="employee-profile-preview">
                        <span>{{ initials(inviting?.nama || 'Pegawai') }}</span>
                        <div>
                            <small>Penerima undangan</small>
                            <strong>{{ inviting?.nama }}</strong>
                            <em>{{ inviting?.email || 'Email belum diisi' }}</em>
                        </div>
                    </div>
                    <div class="employee-invite-summary">
                        <span><ShieldCheck /></span>
                        <div>
                            <strong>Role pegawai diberikan otomatis</strong>
                            <p>Pegawai membuat password melalui email. Setelah berhasil, email terverifikasi dan akun siap dipakai untuk presensi.</p>
                        </div>
                    </div>
                    <small v-if="inviteForm.errors.pegawai" class="employee-field-error">{{ inviteForm.errors.pegawai }}</small>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="inviteForm.processing" @click="closeInvitation">Batal</button>
                    <button type="submit" class="employee-button primary" :disabled="inviteForm.processing || !inviting?.email">
                        <LoaderCircle v-if="inviteForm.processing" class="employee-spin" />
                        <RefreshCw v-else-if="inviting?.account_status === 'pending'" />
                        <Mail v-else />
                        {{ inviteForm.processing ? 'Mengirim...' : inviting?.account_status === 'pending' ? 'Kirim ulang' : 'Buat akun & kirim' }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="Boolean(cancellingInvitation)"
            title="Batalkan aktivasi akun pegawai?"
            description="Akun yang belum aktif akan dihapus dan tautan aktivasi sebelumnya tidak dapat digunakan lagi. Data pegawai tetap tersimpan."
            @close="closeCancelInvitation"
        >
            <form class="employee-invite-form" @submit.prevent="submitCancelInvitation">
                <div class="admin-modal-body employee-modal-body">
                    <div class="employee-profile-preview">
                        <span>{{ initials(cancellingInvitation?.nama || 'Pegawai') }}</span>
                        <div>
                            <small>Pembatalan aktivasi</small>
                            <strong>{{ cancellingInvitation?.nama }}</strong>
                            <em>{{ cancellingInvitation?.email }}</em>
                        </div>
                    </div>
                    <p class="employee-form-hint">Status akan kembali menjadi Belum punya akun. Anda dapat mengirim undangan aktivasi baru nanti.</p>
                    <small v-if="cancelInviteForm.errors.pegawai" class="employee-field-error">{{ cancelInviteForm.errors.pegawai }}</small>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="cancelInviteForm.processing" @click="closeCancelInvitation">
                        Kembali
                    </button>
                    <button type="submit" class="employee-button danger" :disabled="cancelInviteForm.processing">
                        <LoaderCircle v-if="cancelInviteForm.processing" class="employee-spin" />
                        <MailX v-else />
                        {{ cancelInviteForm.processing ? 'Membatalkan...' : 'Batal Aktivasi' }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="batchInvitationOpen"
            :title="`Kirim ${selectedInvitationRows.length} email aktivasi?`"
            description="Akun yang belum tersedia akan dibuat otomatis. Pegawai yang masih menunggu aktivasi akan menerima tautan baru."
            @close="closeBatchInvitation"
        >
            <form class="employee-invite-form" @submit.prevent="submitBatchInvitation">
                <div class="admin-modal-body employee-modal-body">
                    <div class="employee-batch-summary">
                        <span><Mail /></span>
                        <div>
                            <strong>{{ selectedInvitationRows.length }} penerima dipilih</strong>
                            <p>Setiap tautan bersifat pribadi, hanya dapat digunakan satu kali, dan dikirim ke email masing-masing pegawai.</p>
                        </div>
                    </div>
                    <div class="employee-batch-recipients" aria-label="Daftar penerima email aktivasi">
                        <div v-for="pegawai in selectedInvitationRows" :key="pegawai.id">
                            <span class="employee-identity-avatar" :class="avatarTone(pegawai.id)">{{ initials(pegawai.nama) }}</span>
                            <span>
                                <strong>{{ pegawai.nama }}</strong>
                                <small>{{ pegawai.email }}</small>
                            </span>
                            <em :class="pegawai.account_status">
                                {{ pegawai.account_status === 'pending' ? 'Kirim ulang' : 'Akun baru' }}
                            </em>
                        </div>
                    </div>
                    <small v-if="batchInviteError" class="employee-field-error">
                        {{ batchInviteError }}
                    </small>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="batchInviteForm.processing" @click="closeBatchInvitation">
                        Batal
                    </button>
                    <button type="submit" class="employee-button primary" :disabled="batchInviteForm.processing || !selectedInvitationRows.length">
                        <LoaderCircle v-if="batchInviteForm.processing" class="employee-spin" />
                        <Mail v-else />
                        {{ batchInviteForm.processing ? 'Mengirim...' : `Kirim ke ${selectedInvitationRows.length} pegawai` }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="batchCancellationOpen"
            :title="`Batalkan aktivasi ${selectedCancellationRows.length} akun pegawai?`"
            description="Akun yang belum aktif akan dihapus dan tautan aktivasi lama tidak dapat digunakan lagi. Data pegawai tetap tersimpan."
            @close="closeBatchCancellation"
        >
            <form class="employee-invite-form" @submit.prevent="submitBatchCancellation">
                <div class="admin-modal-body employee-modal-body">
                    <div class="employee-batch-summary">
                        <span><MailX /></span>
                        <div>
                            <strong>{{ selectedCancellationRows.length }} akun menunggu aktivasi</strong>
                            <p>Hanya pilihan yang sedang menunggu aktivasi akan dibatalkan. Pegawai tanpa akun tetap berada dalam daftar pilihan.</p>
                        </div>
                    </div>
                    <div class="employee-batch-recipients" aria-label="Daftar pembatalan aktivasi">
                        <div v-for="pegawai in selectedCancellationRows" :key="pegawai.id">
                            <span class="employee-identity-avatar" :class="avatarTone(pegawai.id)">{{ initials(pegawai.nama) }}</span>
                            <span>
                                <strong>{{ pegawai.nama }}</strong>
                                <small>{{ pegawai.email }}</small>
                            </span>
                            <em class="pending">Menunggu aktivasi</em>
                        </div>
                    </div>
                    <small v-if="batchCancelError" class="employee-field-error">{{ batchCancelError }}</small>
                </div>
                <div class="admin-modal-footer employee-modal-footer">
                    <button type="button" class="employee-button ghost" :disabled="batchCancelForm.processing" @click="closeBatchCancellation">
                        Kembali
                    </button>
                    <button type="submit" class="employee-button danger" :disabled="batchCancelForm.processing || !selectedCancellationRows.length">
                        <LoaderCircle v-if="batchCancelForm.processing" class="employee-spin" />
                        <MailX v-else />
                        {{ batchCancelForm.processing ? 'Membatalkan...' : `Batalkan ${selectedCancellationRows.length} aktivasi` }}
                    </button>
                </div>
            </form>
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus data pegawai?"
            :description="`Data ${selected?.nama ?? ''} dengan NIK ${selected?.nik ?? '-'} akan dihapus permanen dari master data.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>

<style scoped>
.employee-master-page,
.employee-master-page * {
    box-sizing: border-box;
}

.employee-master-page {
    --employee-ink: var(--rs-primary-deep);
    --employee-muted: #66807d;
    --employee-line: #dfeae8;
    --employee-teal: var(--rs-primary-hover);
    --employee-mint: #14b8a6;
    --employee-coral: #fb7185;
    container: employee-master / inline-size;
    width: 100%;
    min-width: 0;
    background:
        radial-gradient(circle at 6% 3%, rgba(20, 184, 166, 0.08), transparent 25rem),
        radial-gradient(circle at 95% 35%, rgba(251, 113, 133, 0.06), transparent 24rem), #f7faf9;
    min-height: calc(100vh - 76px);
    color: var(--employee-ink);
}

.employee-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(22rem, 0.75fr);
    gap: 2.5rem;
    min-height: 25rem;
    padding: 3.25rem;
    overflow: hidden;
    border-radius: 2rem;
    color: #fff;
    background: linear-gradient(125deg, var(--rs-primary-deep) 0%, var(--rs-primary-hover) 55%, var(--rs-primary-deep) 100%);
    box-shadow: 0 28px 60px -36px rgba(var(--rs-primary-hover-rgb), 0.85);
}

.employee-orb {
    position: absolute;
    border-radius: 999px;
    filter: blur(1px);
    pointer-events: none;
}

.employee-orb-one {
    width: 24rem;
    height: 24rem;
    right: -8rem;
    top: -12rem;
    background: rgba(251, 113, 133, 0.2);
}

.employee-orb-two {
    width: 15rem;
    height: 15rem;
    left: 38%;
    bottom: -11rem;
    background: rgba(45, 212, 191, 0.22);
}

.employee-hero-grid {
    position: absolute;
    inset: 0;
    opacity: 0.1;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.42) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.42) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: linear-gradient(to right, #000, transparent 78%);
}

.employee-hero-copy,
.employee-hero-side {
    position: relative;
    z-index: 1;
}

.employee-hero-copy {
    display: flex;
    flex-direction: column;
    justify-content: center;
    max-width: 45rem;
}

.employee-eyebrow,
.employee-section-kicker {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.employee-eyebrow {
    width: fit-content;
    padding: 0.5rem 0.75rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 999px;
    color: #ccfbf1;
    background: rgba(255, 255, 255, 0.08);
}

.employee-eyebrow svg {
    width: 0.9rem;
}

.employee-hero-copy h1 {
    margin: 1.25rem 0 1rem;
    font-size: clamp(2.25rem, 4vw, 4rem);
    line-height: 1.03;
    letter-spacing: -0.055em;
    font-weight: 800;
}

.employee-hero-copy h1 span {
    color: #fda4af;
}

.employee-hero-copy > p {
    max-width: 38rem;
    margin: 0;
    color: rgba(var(--rs-primary-soft-rgb), 0.76);
    font-size: 1rem;
    line-height: 1.75;
}

.employee-hero-trust {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1.25rem;
    margin-top: 1.5rem;
}

.employee-hero-trust span {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    color: #d5fbf5;
    font-size: 0.78rem;
    font-weight: 700;
}

.employee-hero-trust svg {
    width: 1rem;
}

.employee-hero-side {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0.85rem;
}

.employee-people-card {
    padding: 1.35rem;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 1.35rem;
    background: rgba(var(--rs-primary-deep-rgb), 0.45);
    backdrop-filter: blur(14px);
}

.employee-people-head,
.employee-hero-action,
.employee-directory-header,
.employee-toolbar,
.employee-identity,
.employee-mobile-card header,
.employee-template-card,
.employee-profile-preview {
    display: flex;
    align-items: center;
}

.employee-people-head {
    justify-content: space-between;
}

.employee-people-head small,
.employee-people-head strong {
    display: block;
}

.employee-people-head small {
    color: rgba(204, 251, 241, 0.7);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}

.employee-people-head strong {
    margin-top: 0.2rem;
    font-size: 1.1rem;
}

.employee-people-head > span {
    display: grid;
    width: 2.5rem;
    height: 2.5rem;
    place-items: center;
    border-radius: 0.85rem;
    color: #99f6e4;
    background: rgba(255, 255, 255, 0.1);
}

.employee-people-head svg {
    width: 1.25rem;
}

.employee-avatar-stack {
    display: flex;
    align-items: center;
    min-height: 3rem;
    margin: 1.25rem 0;
}

.employee-avatar,
.employee-avatar-more {
    display: grid;
    width: 2.8rem;
    height: 2.8rem;
    margin-right: -0.55rem;
    place-items: center;
    border: 3px solid var(--rs-primary-deep);
    border-radius: 50%;
    color: #fff;
    font-size: 0.72rem;
    font-weight: 800;
}

.employee-avatar-more {
    color: var(--rs-primary-deep);
    background: var(--rs-primary-border);
}

.employee-avatar-empty {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #ccfbf1;
    font-size: 0.78rem;
}

.employee-avatar-empty svg {
    width: 1.15rem;
}

.tone-1 {
    background: linear-gradient(135deg, #14b8a6, var(--rs-primary-hover));
}
.tone-2 {
    background: linear-gradient(135deg, #fb7185, #e11d48);
}
.tone-3 {
    background: linear-gradient(135deg, var(--rs-primary), var(--rs-primary-hover));
}
.tone-4 {
    background: linear-gradient(135deg, var(--rs-primary-pale), var(--rs-primary));
}
.tone-5 {
    background: linear-gradient(135deg, #fbbf24, #d97706);
}

.employee-flow {
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.employee-flow span {
    display: grid;
    width: 2rem;
    height: 2rem;
    place-items: center;
    border-radius: 0.65rem;
    color: #99f6e4;
    background: rgba(255, 255, 255, 0.1);
}

.employee-flow span:last-child {
    color: #fecdd3;
}

.employee-flow svg {
    width: 1rem;
}

.employee-flow i {
    flex: 1;
    height: 1px;
    border-top: 1px dashed rgba(255, 255, 255, 0.26);
}

.employee-people-card > p {
    margin: 0.8rem 0 0;
    color: rgba(204, 251, 241, 0.65);
    font-size: 0.72rem;
}

.employee-hero-action {
    width: 100%;
    gap: 0.8rem;
    padding: 0.85rem 1rem;
    border: 0;
    border-radius: 1.15rem;
    color: var(--rs-primary-deep);
    text-align: left;
    background: #fff;
    box-shadow: 0 15px 30px -22px var(--rs-primary-deep);
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.employee-hero-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 20px 35px -22px var(--rs-primary-deep);
}

.employee-hero-action > span:first-child {
    display: grid;
    width: 2.6rem;
    height: 2.6rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 0.85rem;
    color: #fff;
    background: linear-gradient(135deg, var(--employee-mint), var(--employee-teal));
}

.employee-hero-action svg {
    width: 1.15rem;
}

.employee-hero-action > span:nth-child(2) {
    flex: 1;
}

.employee-hero-action strong,
.employee-hero-action small {
    display: block;
}

.employee-hero-action strong {
    font-size: 0.86rem;
}
.employee-hero-action small {
    margin-top: 0.15rem;
    color: #6b8481;
    font-size: 0.68rem;
}

.employee-overview-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin: 1.25rem 0;
}

.employee-stat-card {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    min-width: 0;
    padding: 1.25rem;
    border: 1px solid var(--employee-line);
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.88);
    box-shadow: 0 12px 28px -28px rgba(var(--rs-primary-deep-rgb), 0.6);
}

.employee-stat-icon {
    display: grid;
    width: 2.75rem;
    height: 2.75rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 0.9rem;
}

.employee-stat-icon svg {
    width: 1.3rem;
}
.employee-stat-card.is-teal .employee-stat-icon {
    color: var(--rs-primary-hover);
    background: #ccfbf1;
}
.employee-stat-card.is-coral .employee-stat-icon {
    color: #be123c;
    background: #ffe4e6;
}
.employee-stat-card.is-sky .employee-stat-icon {
    color: var(--rs-primary-hover);
    background: var(--rs-primary-soft);
}
.employee-stat-card.is-violet .employee-stat-icon {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}

.employee-stat-card small,
.employee-stat-card strong {
    display: block;
}

.employee-stat-card small {
    color: var(--employee-muted);
    font-size: 0.72rem;
    font-weight: 700;
}
.employee-stat-card strong {
    margin: 0.2rem 0;
    color: var(--employee-ink);
    font-size: 1.55rem;
    line-height: 1.1;
}
.employee-stat-card p {
    margin: 0;
    overflow: hidden;
    color: #839794;
    font-size: 0.68rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.employee-directory {
    overflow: hidden;
    border: 1px solid var(--employee-line);
    border-radius: 1.5rem;
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 20px 45px -40px rgba(var(--rs-primary-deep-rgb), 0.55);
}

.employee-directory-header {
    justify-content: space-between;
    gap: 1.5rem;
    padding: 1.6rem 1.75rem 1.3rem;
}

.employee-section-kicker {
    color: var(--employee-teal);
}
.employee-directory-header h2 {
    margin: 0.35rem 0 0.2rem;
    color: var(--employee-ink);
    font-size: 1.4rem;
    letter-spacing: -0.03em;
}
.employee-directory-header p {
    margin: 0;
    color: var(--employee-muted);
    font-size: 0.78rem;
}
.employee-directory-count {
    text-align: right;
}
.employee-directory-count span {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.4rem;
    color: #7c928f;
    font-size: 0.68rem;
}
.employee-directory-count i {
    width: 0.42rem;
    height: 0.42rem;
    border-radius: 50%;
    background: var(--employee-mint);
    box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.12);
}
.employee-directory-count strong {
    display: block;
    margin-top: 0.3rem;
    color: var(--employee-ink);
    font-size: 0.78rem;
}

.employee-toolbar {
    flex-wrap: wrap;
    gap: 0.85rem;
    padding: 0 1.75rem 1.35rem;
    border-bottom: 1px solid var(--employee-line);
}

.employee-search {
    display: flex;
    flex: 1;
    min-width: 14rem;
    align-items: center;
    gap: 0.65rem;
    height: 2.75rem;
    padding: 0 0.85rem;
    border: 1px solid #d8e6e3;
    border-radius: 0.85rem;
    background: #f8fbfa;
    transition:
        border-color 0.2s,
        box-shadow 0.2s;
}

.employee-search:focus-within {
    border-color: var(--employee-mint);
    box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.12);
}
.employee-search > svg {
    width: 1rem;
    color: #8aa09d;
}
.employee-search input {
    width: 100%;
    border: 0;
    outline: 0;
    color: var(--employee-ink);
    background: transparent;
    font-size: 0.78rem;
}
.employee-search input::placeholder {
    color: #9aadaa;
}
.employee-search button {
    display: grid;
    padding: 0.2rem;
    border: 0;
    place-items: center;
    color: #78908d;
    background: transparent;
}
.employee-search button svg {
    width: 0.9rem;
}

.employee-filter-pills {
    display: flex;
    gap: 0.3rem;
    padding: 0.25rem;
    border-radius: 0.85rem;
    background: #edf5f3;
}

.employee-filter-pills button {
    display: inline-flex;
    height: 2.25rem;
    align-items: center;
    gap: 0.4rem;
    padding: 0 0.8rem;
    border: 0;
    border-radius: 0.65rem;
    color: #66807d;
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
    background: transparent;
}

.employee-filter-pills button.active {
    color: var(--rs-primary-hover);
    background: #fff;
    box-shadow: 0 3px 9px rgba(var(--rs-primary-hover-rgb), 0.1);
}
.employee-filter-pills svg {
    width: 0.9rem;
}

.employee-unit-filter {
    display: flex;
    height: 2.75rem;
    min-width: 11rem;
    align-items: center;
    gap: 0.45rem;
    padding: 0 0.7rem;
    border: 1px solid #d8e6e3;
    border-radius: 0.85rem;
    color: #66807d;
    background: #f8fbfa;
}
.employee-unit-filter svg {
    width: 0.95rem;
    flex: 0 0 auto;
}
.employee-unit-filter select {
    width: 100%;
    border: 0;
    outline: 0;
    color: var(--employee-ink);
    background: transparent;
    font-size: 0.72rem;
}

.employee-create-button,
.employee-import-button,
.employee-batch-button,
.employee-empty-state button,
.employee-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    border: 0;
    font-weight: 800;
}

.employee-toolbar-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.employee-selection-summary {
    display: grid;
    gap: 0.75rem;
    margin: 0 1rem 1rem;
    padding: 1rem;
    border: 1px solid var(--employee-line);
    border-radius: 1rem;
    color: var(--employee-ink);
    background: var(--rs-canvas);
}
.employee-selection-summary-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}
.employee-selection-summary strong {
    font-size: 0.8rem;
}
.employee-selection-summary p {
    margin: 0.25rem 0 0;
    color: var(--employee-muted);
    font-size: 0.7rem;
}
.employee-selection-summary-header > button {
    padding: 0;
    border: 0;
    color: var(--employee-teal);
    background: transparent;
    font-size: 0.7rem;
    font-weight: 700;
}
.employee-selection-chips {
    display: flex;
    max-height: 10rem;
    flex-wrap: wrap;
    gap: 0.4rem;
    overflow-y: auto;
}
.employee-selection-chips button {
    display: inline-flex;
    max-width: 100%;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.6rem;
    border: 1px solid var(--employee-line);
    border-radius: 0.65rem;
    color: var(--employee-ink);
    background: var(--rs-surface, #fff);
    font-size: 0.7rem;
}
.employee-selection-chips span {
    min-width: 0;
    overflow-wrap: anywhere;
}
.employee-selection-chips svg {
    width: 0.85rem;
    flex-shrink: 0;
}

.employee-create-button,
.employee-import-button,
.employee-batch-button {
    height: 2.75rem;
    padding: 0 1rem;
    border-radius: 0.85rem;
    font-size: 0.72rem;
    white-space: nowrap;
}

.employee-create-button {
    border: 1px solid #b9ddd7;
    color: var(--employee-teal);
    background: #f0fdfa;
}

.employee-import-button {
    color: #fff;
    background: var(--employee-teal);
}

.employee-batch-button {
    border: 1px solid #fbcfe8;
    color: #be185d;
    background: #fdf2f8;
}

.employee-batch-button > span {
    display: grid;
    min-width: 1.2rem;
    height: 1.2rem;
    padding: 0 0.28rem;
    border-radius: 999px;
    place-items: center;
    color: #fff;
    background: #db2777;
    font-size: 0.62rem;
}

.employee-batch-button:disabled {
    cursor: not-allowed;
    border-color: var(--rs-border);
    color: var(--rs-muted);
    background: var(--rs-canvas);
}

.employee-create-button svg,
.employee-import-button svg,
.employee-batch-button svg {
    width: 1rem;
}
.employee-table-wrap {
    max-width: 100%;
    overflow-x: auto;
}
.employee-table {
    width: 100%;
    min-width: 76rem;
    border-collapse: collapse;
}
.employee-table th {
    padding: 0.85rem 1rem;
    color: #7b918e;
    text-align: left;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    background: #f7faf9;
}
.employee-table th:first-child,
.employee-table td:first-child {
    padding-left: 1.75rem;
}
.employee-table th:last-child,
.employee-table td:last-child {
    padding-right: 1.75rem;
}
.employee-table td {
    padding: 1rem;
    border-top: 1px solid #edf3f2;
    color: #385753;
    font-size: 0.76rem;
    vertical-align: middle;
}
.employee-table tbody tr {
    transition: background 0.15s;
}
.employee-table tbody tr:hover {
    background: #fbfdfc;
}

.employee-selection-cell {
    width: 3rem;
    text-align: center !important;
}

.employee-selection-cell input,
.employee-mobile-selection {
    width: 1rem;
    height: 1rem;
    cursor: pointer;
    border: 1px solid #b9cfcb;
    border-radius: 0.3rem;
    accent-color: var(--employee-teal);
}

.employee-selection-cell input:disabled,
.employee-mobile-selection:disabled {
    cursor: not-allowed;
    opacity: 0.38;
}

.employee-identity {
    gap: 0.75rem;
}
.employee-identity-avatar {
    display: grid;
    width: 2.55rem;
    height: 2.55rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 0.85rem;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 800;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
}

.employee-identity strong,
.employee-identity small {
    display: block;
}
.employee-account-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    width: fit-content;
    margin-top: 0.3rem;
    padding: 0.18rem 0.42rem;
    border-radius: 999px;
    color: var(--rs-text-soft);
    background: var(--rs-primary-soft);
    font-size: 0.56rem;
    font-style: normal;
    font-weight: 800;
    text-transform: uppercase;
}
.employee-account-badge svg {
    width: 0.68rem;
    height: 0.68rem;
}
.employee-account-badge.pending,
.employee-mobile-account.pending {
    color: #a16207;
    background: #fef9c3;
}
.employee-account-badge.active,
.employee-mobile-account.active {
    color: var(--rs-primary-hover);
    background: #ccfbf1;
}
.employee-org {
    display: grid;
    min-width: 11rem;
    gap: 0.18rem;
}
.employee-org strong {
    color: var(--employee-ink);
    font-size: 0.72rem;
}
.employee-org small {
    max-width: 15rem;
    color: #829692;
    font-size: 0.62rem;
    line-height: 1.35;
}
.employee-gender {
    white-space: nowrap;
}
.employee-status {
    display: inline-flex;
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
    font-size: 0.66rem;
    font-weight: 800;
    white-space: nowrap;
}
.employee-status.permanent {
    color: #047857;
    background: #d1fae5;
}
.employee-status.temporary {
    color: #9f1239;
    background: #ffe4e6;
}
.employee-identity strong {
    max-width: 15rem;
    overflow: hidden;
    color: var(--employee-ink);
    font-size: 0.78rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.employee-identity small {
    margin-top: 0.2rem;
    color: #91a29f;
    font-size: 0.62rem;
}
.employee-date,
.employee-phone,
.employee-email {
    display: inline-flex;
    align-items: center;
    gap: 0.42rem;
    white-space: nowrap;
}
.employee-date svg,
.employee-phone svg,
.employee-email svg {
    width: 0.95rem;
    color: #14b8a6;
}
.employee-phone,
.employee-email {
    color: var(--rs-primary-hover);
    text-decoration: none;
    font-weight: 700;
}
.employee-phone:hover,
.employee-email:hover {
    text-decoration: underline;
}
.employee-contact {
    display: grid;
    min-width: 11rem;
    gap: 0.35rem;
}
.employee-email {
    max-width: 16rem;
    overflow: hidden;
    text-overflow: ellipsis;
}
.employee-age {
    display: inline-flex;
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
    color: #9f1239;
    font-size: 0.68rem;
    font-weight: 800;
    background: #fff1f2;
}
.employee-updated {
    color: #7d918e;
    font-size: 0.68rem;
    white-space: nowrap;
}
.employee-actions-heading {
    text-align: right !important;
}
.employee-row-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.35rem;
}
.employee-row-actions button {
    display: grid;
    width: 2rem;
    height: 2rem;
    border: 1px solid #dce8e6;
    border-radius: 0.65rem;
    place-items: center;
    color: #51706c;
    background: #fff;
    transition: 0.18s ease;
}
.employee-row-actions button:hover {
    border-color: #99f6e4;
    color: var(--rs-primary-hover);
    background: #f0fdfa;
}
.employee-row-actions button.account {
    color: var(--rs-primary-hover);
    background: #f0fdfa;
}
.employee-row-actions button.account:hover {
    border-color: #5eead4;
    color: #0d9488;
}
.employee-row-actions button.danger:hover {
    border-color: #fecdd3;
    color: #e11d48;
    background: #fff1f2;
}
.employee-row-actions button:disabled,
.employee-row-actions button:disabled:hover {
    cursor: not-allowed;
    border-color: var(--rs-border);
    color: var(--rs-muted);
    background: var(--rs-canvas);
    opacity: 0.65;
}
.employee-row-actions svg {
    width: 0.9rem;
}
.employee-mobile-list {
    display: none;
}

.employee-mobile-selection {
    flex: 0 0 auto;
}

.employee-empty-state {
    display: flex;
    min-height: 20rem;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 1.25rem;
    text-align: center;
}
.employee-empty-state > span {
    display: grid;
    width: 4rem;
    height: 4rem;
    place-items: center;
    border-radius: 1.25rem;
    color: var(--rs-primary-hover);
    background: linear-gradient(135deg, #ccfbf1, #f0fdfa);
}
.employee-empty-state svg {
    width: 1.8rem;
}
.employee-empty-state h3 {
    margin: 1rem 0 0.4rem;
    color: var(--employee-ink);
    font-size: 1.05rem;
}
.employee-empty-state p {
    max-width: 27rem;
    margin: 0 0 1.1rem;
    color: var(--employee-muted);
    font-size: 0.76rem;
    line-height: 1.6;
}
.employee-empty-state button {
    min-height: 2.5rem;
    padding: 0 1rem;
    border-radius: 0.75rem;
    color: #fff;
    font-size: 0.72rem;
    background: var(--employee-teal);
}
.employee-empty-state button svg {
    width: 1rem;
}

.employee-import-form,
.employee-create-form,
.employee-edit-form {
    display: flex;
    min-height: 0;
    flex: 1;
    flex-direction: column;
}
.employee-modal-body {
    display: grid;
    gap: 1rem;
    padding: 1.4rem 1.5rem;
    background: #f8fbfa;
}
.employee-modal-footer {
    gap: 0.65rem;
}
.employee-excel-tabs {
    display: flex;
    gap: 0.35rem;
    padding: 0.3rem;
    border: 1px solid var(--rs-border);
    border-radius: 0.85rem;
    background: var(--rs-surface);
}
.employee-excel-tabs button {
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 0.65rem 0.4rem;
    border: 0;
    border-radius: 0.6rem;
    color: var(--rs-text-soft);
    background: transparent;
    font-size: 0.75rem;
    font-weight: 700;
}
.employee-excel-tabs button[aria-pressed='true'] {
    color: var(--rs-primary-hover);
    background: var(--rs-primary-soft);
}
.employee-excel-tabs svg,
.employee-export-search svg,
.employee-export-empty svg,
.employee-export-success svg {
    width: 1rem;
    height: 1rem;
    flex-shrink: 0;
}
.employee-export-heading strong {
    color: var(--rs-text-soft);
    font-size: 0.85rem;
}
.employee-export-heading p,
.employee-export-hint {
    margin: 0.35rem 0 0;
    color: var(--rs-muted);
    font-size: 0.72rem;
    line-height: 1.6;
}
.employee-export-search {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0 0.85rem;
    border: 1px solid var(--rs-border);
    border-radius: 0.75rem;
    color: var(--rs-muted);
    background: var(--rs-surface);
}
.employee-export-search:focus-within {
    outline: 2px solid var(--rs-primary);
    outline-offset: 2px;
}
.employee-export-search input {
    width: 100%;
    min-width: 0;
    padding: 0.75rem 0;
    border: 0;
    outline: 0;
    box-shadow: none;
    color: var(--rs-text-soft);
    background: transparent;
    font-size: 0.75rem;
}
.employee-export-select-all,
.employee-export-row {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin: 0;
    padding: 0.85rem;
    color: var(--rs-text-soft);
    font-size: 0.75rem;
    cursor: pointer;
}
.employee-export-select-all {
    flex-wrap: wrap;
    border: 1px solid var(--rs-primary-border);
    border-radius: 0.75rem;
    background: var(--rs-primary-soft);
}
.employee-export-select-all > strong {
    margin-left: auto;
    color: var(--rs-primary-hover);
    font-size: 0.7rem;
}
.employee-export-select-all input,
.employee-export-row input {
    width: 1rem;
    height: 1rem;
    flex: 0 0 auto;
    accent-color: var(--rs-primary);
}
.employee-export-list {
    max-height: 17rem;
    overflow-y: auto;
    overscroll-behavior: contain;
    border: 1px solid var(--rs-border);
    border-radius: 0.85rem;
    background: var(--rs-surface);
}
.employee-export-row + .employee-export-row {
    border-top: 1px solid var(--rs-border);
}
.employee-export-row.selected {
    background: var(--rs-primary-soft);
}
.employee-export-row span {
    min-width: 0;
    overflow-wrap: anywhere;
}
.employee-export-row strong,
.employee-export-row small {
    display: block;
}
.employee-export-row small {
    margin-top: 0.2rem;
    color: var(--rs-muted);
    font-size: 0.67rem;
}
.employee-export-empty,
.employee-export-success {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin: 0;
    padding: 1rem;
    color: var(--rs-muted);
    font-size: 0.75rem;
    text-align: center;
}
.employee-export-success {
    color: var(--rs-primary-hover);
}
.employee-export-error .employee-field-error {
    margin: 0 0 0.5rem;
}
.employee-template-card {
    gap: 0.85rem;
    padding: 1rem;
    border: 1px solid #b9e9e1;
    border-radius: 1rem;
    color: inherit;
    text-decoration: none;
    background: linear-gradient(135deg, #f0fdfa, var(--rs-primary-soft));
    transition:
        transform 0.18s,
        border-color 0.18s;
}

.employee-template-card:hover {
    border-color: var(--employee-mint);
    transform: translateY(-1px);
}
.employee-template-icon {
    display: grid;
    width: 2.75rem;
    height: 2.75rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 0.85rem;
    color: #fff;
    background: linear-gradient(135deg, #14b8a6, var(--rs-primary-hover));
}
.employee-template-icon svg {
    width: 1.25rem;
}
.employee-template-card > span:nth-child(2) {
    flex: 1;
    min-width: 0;
}
.employee-template-card small,
.employee-template-card strong,
.employee-template-card em {
    display: block;
}
.employee-template-card small {
    color: var(--rs-primary-hover);
    font-size: 0.62rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.employee-template-card strong {
    margin: 0.15rem 0;
    color: var(--employee-ink);
    font-size: 0.82rem;
}
.employee-template-card em {
    overflow: hidden;
    color: #718b87;
    font-size: 0.66rem;
    font-style: normal;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.employee-template-card > svg {
    width: 1.1rem;
    color: var(--rs-primary-hover);
}
.employee-import-divider {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    color: #94a7a4;
    font-size: 0.62rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}
.employee-import-divider::before,
.employee-import-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #dfeae8;
}

.employee-dropzone {
    display: flex;
    min-height: 11rem;
    width: 100%;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 1.25rem;
    border: 1.5px dashed #a8c8c3;
    border-radius: 1rem;
    color: var(--employee-ink);
    background: #fff;
    transition:
        border-color 0.2s,
        background 0.2s,
        transform 0.2s;
}

.employee-dropzone:hover,
.employee-dropzone.active {
    border-color: var(--employee-mint);
    background: #f0fdfa;
    transform: scale(1.005);
}
.employee-dropzone.selected {
    border-style: solid;
    border-color: #5eead4;
    background: #f0fdfa;
}
.employee-dropzone.invalid {
    border-color: #fda4af;
    background: #fff1f2;
}
.employee-drop-icon {
    display: grid;
    width: 3.2rem;
    height: 3.2rem;
    margin-bottom: 0.25rem;
    place-items: center;
    border-radius: 1rem;
    color: var(--rs-primary-hover);
    background: #ccfbf1;
}
.employee-dropzone.selected .employee-drop-icon {
    color: #fff;
    background: #14b8a6;
}
.employee-drop-icon svg {
    width: 1.5rem;
}
.employee-dropzone strong {
    max-width: 100%;
    overflow: hidden;
    font-size: 0.8rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.employee-dropzone small {
    color: #809692;
    font-size: 0.68rem;
}
.employee-field-error {
    display: block;
    margin: -0.45rem 0 0;
    color: #e11d48 !important;
    font-size: 0.68rem !important;
    font-weight: 700;
}
.employee-import-notes {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    overflow: hidden;
    border: 1px solid #e0eae8;
    border-radius: 0.9rem;
    background: #fff;
}
.employee-import-notes span {
    padding: 0.75rem;
    text-align: center;
}
.employee-import-notes span + span {
    border-left: 1px solid #e0eae8;
}
.employee-import-notes strong,
.employee-import-notes small {
    display: block;
}
.employee-import-notes strong {
    color: var(--employee-ink);
    font-size: 0.7rem;
}
.employee-import-notes small {
    margin-top: 0.15rem;
    color: #8ca09d;
    font-size: 0.58rem;
}

.employee-button {
    min-height: 2.65rem;
    padding: 0 1rem;
    border-radius: 0.8rem;
    font-size: 0.72rem;
}
.employee-button svg {
    width: 1rem;
}
.employee-button.ghost {
    border: 1px solid #d8e5e3;
    color: #5f7774;
    background: #fff;
}
.employee-button.primary {
    color: #fff;
    background: linear-gradient(135deg, #14b8a6, var(--rs-primary-hover));
    box-shadow: 0 10px 20px -14px var(--rs-primary-hover);
}
.employee-button.danger {
    color: #fff;
    background: #e11d48;
}
.employee-form-hint {
    margin: 1rem 0 0;
    color: var(--rs-muted);
    font-size: 0.8rem;
}
.employee-button:disabled {
    cursor: not-allowed;
    opacity: 0.58;
}
.employee-spin {
    animation: employee-spin 0.8s linear infinite;
}

.employee-profile-preview {
    gap: 0.85rem;
    padding: 1rem;
    border: 1px solid #bfe9e2;
    border-radius: 1rem;
    background: linear-gradient(135deg, #f0fdfa, #fff1f2);
}
.employee-profile-preview > span {
    display: grid;
    width: 3.2rem;
    height: 3.2rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 1rem;
    color: #fff;
    font-size: 0.82rem;
    font-weight: 800;
    background: linear-gradient(135deg, #14b8a6, var(--rs-primary-hover));
}
.employee-profile-preview small,
.employee-profile-preview strong,
.employee-profile-preview em {
    display: block;
}
.employee-profile-preview small {
    color: var(--rs-primary-hover);
    font-size: 0.6rem;
    font-weight: 800;
    text-transform: uppercase;
}
.employee-profile-preview strong {
    margin: 0.12rem 0;
    color: var(--employee-ink);
    font-size: 0.85rem;
}
.employee-profile-preview em {
    color: #758e8a;
    font-size: 0.66rem;
    font-style: normal;
}
.employee-invite-summary,
.employee-batch-summary {
    display: flex;
    align-items: flex-start;
    gap: 0.8rem;
    padding: 1rem;
    border: 1px solid #d8e8e5;
    border-radius: 1rem;
    background: #f8fbfa;
}
.employee-invite-summary > span,
.employee-batch-summary > span {
    display: grid;
    width: 2.3rem;
    height: 2.3rem;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 0.75rem;
    color: var(--rs-primary-hover);
    background: #ccfbf1;
}
.employee-invite-summary svg,
.employee-batch-summary svg {
    width: 1rem;
}
.employee-invite-summary strong,
.employee-batch-summary strong {
    color: var(--employee-ink);
    font-size: 0.74rem;
}
.employee-invite-summary p,
.employee-batch-summary p {
    margin: 0.2rem 0 0;
    color: var(--employee-muted);
    font-size: 0.66rem;
    line-height: 1.6;
}

.employee-batch-recipients {
    display: grid;
    max-height: 17rem;
    overflow-y: auto;
    border: 1px solid #d8e8e5;
    border-radius: 1rem;
}

.employee-batch-recipients > div {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
}

.employee-batch-recipients > div + div {
    border-top: 1px solid #e6efed;
}

.employee-batch-recipients .employee-identity-avatar {
    width: 2.25rem;
    height: 2.25rem;
}

.employee-batch-recipients strong,
.employee-batch-recipients small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.employee-batch-recipients strong {
    color: var(--employee-ink);
    font-size: 0.72rem;
}

.employee-batch-recipients small {
    margin-top: 0.15rem;
    color: var(--employee-muted);
    font-size: 0.62rem;
}

.employee-batch-recipients em {
    padding: 0.25rem 0.48rem;
    border-radius: 999px;
    color: #047857;
    background: #d1fae5;
    font-size: 0.58rem;
    font-style: normal;
    font-weight: 800;
    white-space: nowrap;
}

.employee-batch-recipients em.pending {
    color: #a16207;
    background: #fef9c3;
}
.employee-field-hint {
    color: #a16207;
    font-size: 0.6rem;
}
.employee-form-field input:read-only {
    cursor: not-allowed;
    color: var(--rs-text-soft);
    background: var(--rs-canvas);
}
.employee-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.employee-form-field {
    display: grid;
    gap: 0.42rem;
}
.employee-form-field.full {
    grid-column: 1 / -1;
}
.employee-form-field > span {
    color: #395b56;
    font-size: 0.7rem;
    font-weight: 800;
}
.employee-form-field b {
    color: #fb7185;
}
.employee-form-field input,
.employee-form-field select {
    width: 100%;
    height: 2.8rem;
    padding: 0 0.8rem;
    border: 1px solid #d6e5e2;
    border-radius: 0.8rem;
    outline: none;
    color: var(--employee-ink);
    background: #fff;
    font-size: 0.75rem;
    transition:
        border-color 0.18s,
        box-shadow 0.18s;
}
.employee-form-field select:disabled {
    cursor: not-allowed;
    opacity: 0.58;
}
.employee-form-field input:focus,
.employee-form-field select:focus {
    border-color: var(--employee-mint);
    box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.1);
}

@keyframes employee-spin {
    to {
        transform: rotate(360deg);
    }
}

:global(.dark) .employee-master-page {
    --employee-ink: #e5f4f1;
    --employee-muted: #89a6a1;
    --employee-line: #26433f;
    background: radial-gradient(circle at 8% 0, rgba(20, 184, 166, 0.09), transparent 24rem), #0d1716;
}

:global(.dark) .employee-stat-card,
:global(.dark) .employee-directory {
    background: rgba(18, 31, 29, 0.94);
}
:global(.dark) .employee-toolbar,
:global(.dark) .employee-table td {
    border-color: #243e3a;
}
:global(.dark) .employee-search,
:global(.dark) .employee-filter-pills,
:global(.dark) .employee-table th {
    border-color: #294641;
    background: #152522;
}
:global(.dark) .employee-search input {
    color: #e5f4f1;
}
:global(.dark) .employee-filter-pills button.active,
:global(.dark) .employee-row-actions button {
    color: #99d7cd;
    background: #203632;
}
:global(.dark) .employee-table tbody tr:hover {
    background: #142320;
}
:global(.dark) .employee-modal-body {
    background: #13211f;
}
:global(.dark) .employee-template-card,
:global(.dark) .employee-dropzone,
:global(.dark) .employee-import-notes,
:global(.dark) .employee-invite-summary,
:global(.dark) .employee-batch-summary,
:global(.dark) .employee-batch-recipients,
:global(.dark) .employee-profile-preview,
:global(.dark) .employee-form-field input,
:global(.dark) .employee-form-field select,
:global(.dark) .employee-unit-filter,
:global(.dark) .employee-create-button,
:global(.dark) .employee-button.ghost {
    border-color: #31514c;
    color: #d8ece8;
    background: #1a2c29;
}
:global(.dark) .employee-batch-recipients > div + div {
    border-color: #31514c;
}
:global(.dark) .employee-import-divider::before,
:global(.dark) .employee-import-divider::after,
:global(.dark) .employee-import-notes span + span {
    border-color: #31514c;
}
:global(.dark) .employee-template-card strong,
:global(.dark) .employee-dropzone strong,
:global(.dark) .employee-import-notes strong,
:global(.dark) .employee-profile-preview strong,
:global(.dark) .employee-form-field input {
    color: #e5f4f1;
}
:global(.dark) .employee-form-field select,
:global(.dark) .employee-unit-filter select {
    color: #e5f4f1;
}
:global(.dark) .employee-dropzone:hover,
:global(.dark) .employee-dropzone.active,
:global(.dark) .employee-dropzone.selected {
    background: #15332e;
}

@container employee-master (max-width: 1120px) {
    .employee-hero {
        grid-template-columns: minmax(0, 1fr) 20rem;
        padding: 2.5rem;
    }
    .employee-overview-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .employee-search {
        flex-basis: 100%;
    }
    .employee-filter-pills {
        flex: 1;
    }
}

@container employee-master (max-width: 820px) {
    .employee-hero {
        grid-template-columns: 1fr;
        gap: 1.5rem;
        min-height: unset;
        padding: 2rem;
    }
    .employee-hero-side {
        display: grid;
        grid-template-columns: 1fr 1fr;
        align-items: stretch;
    }
    .employee-hero-action {
        align-self: stretch;
    }
    .employee-table-wrap {
        display: none;
    }
    .employee-mobile-list {
        display: grid;
        gap: 0.75rem;
        padding: 0 1rem 1rem;
    }
    .employee-mobile-card {
        padding: 1rem;
        border: 1px solid var(--employee-line);
        border-radius: 1rem;
        background: #fff;
    }
    .employee-mobile-card header {
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .employee-mobile-card .employee-identity {
        min-width: 0;
        flex: 1 1 10rem;
    }
    .employee-mobile-card .employee-identity > span:last-child {
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .employee-mobile-card .employee-row-actions {
        flex: 0 0 auto;
        margin-left: auto;
    }
    .employee-mobile-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.65rem;
        margin-top: 0.9rem;
        padding-top: 0.8rem;
        border-top: 1px solid var(--employee-line);
    }
    .employee-mobile-details span,
    .employee-mobile-details a {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 0.4rem;
        color: var(--employee-muted);
        font-size: 0.68rem;
        text-decoration: none;
        overflow-wrap: anywhere;
    }
    .employee-mobile-details svg {
        width: 0.9rem;
        flex-shrink: 0;
        color: var(--employee-mint);
    }
    .employee-mobile-account {
        width: fit-content;
        padding: 0.25rem 0.45rem;
        border-radius: 999px;
        color: var(--rs-text-soft);
        background: var(--rs-primary-soft);
        font-weight: 800;
    }
    :global(.dark) .employee-mobile-card {
        background: #142320;
    }
}

@container employee-master (max-width: 600px) {
    .employee-hero {
        padding: 1.5rem;
        border-radius: 1.35rem;
    }
    .employee-hero-copy h1 {
        font-size: 2.15rem;
    }
    .employee-hero-side {
        display: flex;
    }
    .employee-overview-grid {
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }
    .employee-stat-card {
        padding: 1rem;
    }
    .employee-directory-header {
        align-items: flex-start;
        padding: 1.25rem;
    }
    .employee-directory-count {
        display: none;
    }
    .employee-toolbar {
        align-items: stretch;
        padding: 0 1rem 1rem;
    }
    .employee-search {
        min-width: 0;
    }
    .employee-filter-pills {
        overflow-x: auto;
    }
    .employee-import-button {
        width: 100%;
    }
    .employee-toolbar-actions {
        width: 100%;
    }
    .employee-create-button,
    .employee-import-button {
        flex: 1;
    }
    .employee-mobile-details {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .employee-form-grid {
        grid-template-columns: 1fr;
    }
    .employee-form-field.full {
        grid-column: auto;
    }
    .employee-import-notes {
        grid-template-columns: 1fr;
    }
    .employee-import-notes span + span {
        border-top: 1px solid #e0eae8;
        border-left: 0;
    }
    .employee-modal-footer {
        flex-direction: column-reverse;
    }
    .employee-button {
        width: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .employee-master-page *,
    .employee-master-page *::before,
    .employee-master-page *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
    }
}
</style>
