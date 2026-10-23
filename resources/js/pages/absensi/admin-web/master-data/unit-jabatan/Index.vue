<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    Check,
    CircleOff,
    FileText,
    Layers3,
    LoaderCircle,
    Pencil,
    Plus,
    Save,
    Search,
    Sparkles,
    Trash2,
    TrendingUp,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmDelete from '../../setting/components/ConfirmDelete.vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import Pagination from '../../setting/components/Pagination.vue';
import type { Paginated } from '../../setting/types';

interface JabatanRow {
    id: number;
    unit_kerja_id: number;
    kode: string;
    nama: string;
    deskripsi: string | null;
    aktif: boolean;
    updated_at: string | null;
}

interface UnitRow {
    id: number;
    kode: string;
    nama: string;
    deskripsi: string | null;
    aktif: boolean;
    jabatans_count: number;
    jabatans_aktif_count: number;
    updated_at: string | null;
    jabatans: JabatanRow[];
}

interface UnitOption {
    id: number;
    kode: string;
    nama: string;
    aktif: boolean;
}

interface Overview {
    total_units: number;
    active_units: number;
    total_jabatans: number;
    active_jabatans: number;
    added_this_month: number;
}

interface JabatanInput {
    [key: string]: string | number | boolean;
    inputKey: number;
    kode: string;
    nama: string;
    deskripsi: string;
    aktif: boolean;
}

type DeleteTarget = { type: 'unit'; data: UnitRow } | { type: 'jabatan'; data: JabatanRow };

const props = defineProps<{
    units: Paginated<UnitRow>;
    overview: Overview;
    unitOptions: UnitOption[];
    filters: { search?: string; status?: string };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Master Data', href: '/absensi/admin-web/master-data/jabatan' },
    { title: 'Unit dan Jabatan', href: '/absensi/admin-web/master-data/jabatan' },
];

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const unitMode = ref<'create' | 'edit' | null>(null);
const jabatanMode = ref<'create' | 'edit' | null>(null);
const editingUnit = ref<UnitRow | null>(null);
const editingJabatan = ref<JabatanRow | null>(null);
const deleteTarget = ref<DeleteTarget | null>(null);
const deleting = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let nextInputKey = 0;

const newJabatan = (): JabatanInput => ({ inputKey: ++nextInputKey, kode: '', nama: '', deskripsi: '', aktif: true });
const unitForm = useForm({ kode: '', nama: '', deskripsi: '', aktif: true, jabatans: [newJabatan()] });
const jabatanForm = useForm({ unit_kerja_id: 0, kode: '', nama: '', deskripsi: '', aktif: true });

const activeRate = computed(() =>
    props.overview.total_jabatans ? Math.round((props.overview.active_jabatans / props.overview.total_jabatans) * 100) : 0,
);
const hasFilters = computed(() => Boolean(search.value.trim()) || status.value !== 'all');
const resultSummary = computed(() =>
    props.units.total ? `${props.units.from}-${props.units.to} dari ${props.units.total} unit kerja` : 'Belum ada hasil',
);
const unitModalTitle = computed(() => (unitMode.value === 'edit' ? `Edit unit: ${editingUnit.value?.nama ?? ''}` : 'Tambah unit dan jabatan'));
const deleteTitle = computed(() => (deleteTarget.value?.type === 'unit' ? 'Hapus unit kerja?' : 'Hapus jabatan?'));
const deleteDescription = computed(() => {
    if (!deleteTarget.value) return '';
    if (deleteTarget.value.type === 'unit') {
        const unit = deleteTarget.value.data;
        return `Unit ${unit.nama} beserta ${unit.jabatans_count} jabatan di dalamnya akan dihapus permanen.`;
    }
    const jabatan = deleteTarget.value.data;
    return `Jabatan ${jabatan.nama} (${jabatan.kode}) akan dihapus permanen dari unit kerjanya.`;
});
const summaries = computed(() => [
    {
        label: 'Total unit kerja',
        value: props.overview.total_units,
        note: `${props.overview.active_units} unit aktif`,
        icon: Building2,
        tone: 'indigo',
    },
    {
        label: 'Total jabatan',
        value: props.overview.total_jabatans,
        note: 'Tersusun di dalam unit',
        icon: BriefcaseBusiness,
        tone: 'emerald',
    },
    {
        label: 'Jabatan aktif',
        value: props.overview.active_jabatans,
        note: `${activeRate.value}% siap digunakan`,
        icon: BadgeCheck,
        tone: 'amber',
    },
    {
        label: 'Unit baru bulan ini',
        value: props.overview.added_this_month,
        note: 'Pertumbuhan organisasi',
        icon: TrendingUp,
        tone: 'cyan',
    },
]);

const initials = (name: string) =>
    name
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
const tone = (id: number) => `tone-${(id % 5) + 1}`;

const openCreateUnit = () => {
    editingUnit.value = null;
    unitForm.defaults({ kode: '', nama: '', deskripsi: '', aktif: true, jabatans: [newJabatan()] });
    unitForm.reset();
    unitForm.clearErrors();
    unitMode.value = 'create';
};
const openEditUnit = (unit: UnitRow) => {
    editingUnit.value = unit;
    unitForm.defaults({ kode: unit.kode, nama: unit.nama, deskripsi: unit.deskripsi ?? '', aktif: unit.aktif, jabatans: [] });
    unitForm.reset();
    unitForm.clearErrors();
    unitMode.value = 'edit';
};
const closeUnit = () => {
    if (unitForm.processing) return;
    unitMode.value = null;
    editingUnit.value = null;
    unitForm.clearErrors();
};
const addJabatanInput = () => unitForm.jabatans.length < 25 && unitForm.jabatans.push(newJabatan());
const removeJabatanInput = (index: number) => {
    if (unitForm.jabatans.length > 1) unitForm.jabatans.splice(index, 1);
    unitForm.clearErrors();
};
const inputError = (index: number, field: string) => (unitForm.errors as Record<string, string | undefined>)[`jabatans.${index}.${field}`];
const submitUnit = () => {
    const options = { preserveScroll: true, onSuccess: closeUnit };
    if (unitMode.value === 'edit' && editingUnit.value) {
        unitForm.put(`/absensi/admin-web/master-data/jabatan/units/${editingUnit.value.id}`, options);
    } else {
        unitForm.post('/absensi/admin-web/master-data/jabatan/units', options);
    }
};

const openCreateJabatan = (unit: UnitRow) => {
    editingJabatan.value = null;
    jabatanForm.defaults({ unit_kerja_id: unit.id, kode: '', nama: '', deskripsi: '', aktif: true });
    jabatanForm.reset();
    jabatanForm.clearErrors();
    jabatanMode.value = 'create';
};
const openEditJabatan = (jabatan: JabatanRow) => {
    editingJabatan.value = jabatan;
    jabatanForm.defaults({
        unit_kerja_id: jabatan.unit_kerja_id,
        kode: jabatan.kode,
        nama: jabatan.nama,
        deskripsi: jabatan.deskripsi ?? '',
        aktif: jabatan.aktif,
    });
    jabatanForm.reset();
    jabatanForm.clearErrors();
    jabatanMode.value = 'edit';
};
const closeJabatan = () => {
    if (jabatanForm.processing) return;
    jabatanMode.value = null;
    editingJabatan.value = null;
    jabatanForm.clearErrors();
};
const submitJabatan = () => {
    const options = { preserveScroll: true, onSuccess: closeJabatan };
    if (jabatanMode.value === 'edit' && editingJabatan.value) {
        jabatanForm.put(`/absensi/admin-web/master-data/jabatan/${editingJabatan.value.id}`, options);
    } else {
        jabatanForm.post('/absensi/admin-web/master-data/jabatan', options);
    }
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        '/absensi/admin-web/master-data/jabatan',
        { search: search.value.trim() || undefined, status: status.value === 'all' ? undefined : status.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const clearFilters = () => {
    search.value = '';
    status.value = 'all';
    applyFilters();
};
const setStatus = (value: string) => {
    status.value = value;
    applyFilters();
};
watch(search, (value, oldValue) => {
    if (value === oldValue) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 450);
});
onBeforeUnmount(() => searchTimer && clearTimeout(searchTimer));

const destroy = () => {
    if (!deleteTarget.value) return;
    deleting.value = true;
    const url =
        deleteTarget.value.type === 'unit'
            ? `/absensi/admin-web/master-data/jabatan/units/${deleteTarget.value.data.id}`
            : `/absensi/admin-web/master-data/jabatan/${deleteTarget.value.data.id}`;
    router.delete(url, {
        preserveScroll: true,
        onSuccess: () => (deleteTarget.value = null),
        onFinish: () => (deleting.value = false),
    });
};
</script>

<template>
    <Head title="Unit dan Jabatan" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="structure-page">
            <FlashMessage />

            <section class="hero">
                <div class="hero-glow"></div>
                <div class="hero-copy">
                    <span class="eyebrow"><Sparkles /> Struktur organisasi</span>
                    <h1>Unit yang tertata.<br /><span>Jabatan yang jelas.</span></h1>
                    <p>Kelola unit kerja beserta jabatan yang berada di dalamnya dalam satu direktori organisasi.</p>
                    <div class="hero-points">
                        <span><Check /> Relasi unit terstruktur</span><span><Check /> Jabatan mudah dilacak</span>
                    </div>
                </div>
                <aside class="hero-side">
                    <div>
                        <small>Kesiapan jabatan</small><strong>{{ activeRate }}%</strong
                        ><span>{{ overview.active_jabatans }} dari {{ overview.total_jabatans }} aktif</span>
                    </div>
                    <button v-if="can.create" type="button" @click="openCreateUnit">
                        <Plus /><span><strong>Tambah unit dan jabatan</strong><small>Bangun struktur dalam satu langkah</small></span>
                    </button>
                </aside>
            </section>

            <section class="summary-grid" aria-label="Ringkasan unit dan jabatan">
                <article v-for="item in summaries" :key="item.label" :class="`summary-card ${item.tone}`">
                    <span class="summary-icon"><component :is="item.icon" /></span>
                    <div>
                        <small>{{ item.label }}</small
                        ><strong>{{ item.value.toLocaleString('id-ID') }}</strong
                        ><span>{{ item.note }}</span>
                    </div>
                </article>
            </section>

            <section class="directory">
                <header class="directory-heading">
                    <div>
                        <span>Direktori organisasi</span>
                        <h2>Unit dan Jabatan</h2>
                        <p>Lihat setiap unit kerja dan seluruh jabatan yang ada di dalamnya.</p>
                    </div>
                    <div>
                        <small>Data terbaru</small><strong>{{ resultSummary }}</strong>
                    </div>
                </header>

                <form class="filters" role="search" @submit.prevent="applyFilters">
                    <label class="search-field"
                        ><Search /><span class="sr-only">Cari unit atau jabatan</span
                        ><input v-model="search" type="search" placeholder="Cari unit kerja atau jabatan..." /><button
                            v-if="search"
                            type="button"
                            aria-label="Hapus pencarian"
                            @click="search = ''"
                        >
                            <X /></button
                    ></label>
                    <div class="status-filter">
                        <button type="button" :class="{ active: status === 'all' }" @click="setStatus('all')">Semua unit</button
                        ><button type="button" :class="{ active: status === 'active' }" @click="setStatus('active')">Aktif</button
                        ><button type="button" :class="{ active: status === 'inactive' }" @click="setStatus('inactive')">Nonaktif</button>
                    </div>
                    <button type="submit" class="search-button"><Search /> Cari</button>
                </form>

                <div v-if="hasFilters" class="active-filter">
                    <span>Filter aktif</span><small v-if="search">Kata kunci: “{{ search }}”</small
                    ><small v-if="status !== 'all'">Status unit: {{ status === 'active' ? 'Aktif' : 'Nonaktif' }}</small
                    ><button type="button" @click="clearFilters">Hapus semua <X /></button>
                </div>

                <div v-if="units.data.length" class="unit-list">
                    <article v-for="unit in units.data" :key="unit.id" class="unit-card">
                        <header class="unit-header">
                            <div class="unit-identity">
                                <span class="unit-avatar" :class="tone(unit.id)"><Building2 /></span>
                                <div>
                                    <div class="unit-title">
                                        <h3>{{ unit.nama }}</h3>
                                        <span class="code">{{ unit.kode }}</span
                                        ><span class="status" :class="unit.aktif ? 'active' : 'inactive'"
                                            ><i></i>{{ unit.aktif ? 'Aktif' : 'Nonaktif' }}</span
                                        >
                                    </div>
                                    <p>{{ unit.deskripsi || 'Belum ada deskripsi unit kerja.' }}</p>
                                    <small><Layers3 /> {{ unit.jabatans_count }} jabatan · {{ unit.jabatans_aktif_count }} aktif</small>
                                </div>
                            </div>
                            <div class="unit-actions">
                                <button v-if="can.create" type="button" class="add-position" @click="openCreateJabatan(unit)">
                                    <Plus /> Tambah jabatan
                                </button>
                                <button v-if="can.update" type="button" class="icon-button" aria-label="Edit unit" @click="openEditUnit(unit)">
                                    <Pencil />
                                </button>
                                <button
                                    v-if="can.delete"
                                    type="button"
                                    class="icon-button danger"
                                    aria-label="Hapus unit"
                                    @click="deleteTarget = { type: 'unit', data: unit }"
                                >
                                    <Trash2 />
                                </button>
                            </div>
                        </header>

                        <div v-if="unit.jabatans.length" class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Jabatan</th>
                                        <th>Kode</th>
                                        <th>Deskripsi</th>
                                        <th>Status</th>
                                        <th>Diperbarui</th>
                                        <th><span class="sr-only">Aksi</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="jabatan in unit.jabatans" :key="jabatan.id">
                                        <td>
                                            <div class="position-name">
                                                <span :class="tone(jabatan.id)">{{ initials(jabatan.nama) }}</span>
                                                <div>
                                                    <strong>{{ jabatan.nama }}</strong
                                                    ><small>Di dalam {{ unit.nama }}</small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="code">{{ jabatan.kode }}</span>
                                        </td>
                                        <td>
                                            <p class="description">{{ jabatan.deskripsi || 'Belum ada deskripsi' }}</p>
                                        </td>
                                        <td>
                                            <span class="status" :class="jabatan.aktif ? 'active' : 'inactive'"
                                                ><i></i>{{ jabatan.aktif ? 'Aktif' : 'Nonaktif' }}</span
                                            >
                                        </td>
                                        <td>
                                            <span class="updated"><CalendarDays /> {{ jabatan.updated_at ?? '-' }}</span>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <button v-if="can.update" type="button" aria-label="Edit jabatan" @click="openEditJabatan(jabatan)">
                                                    <Pencil /></button
                                                ><button
                                                    v-if="can.delete"
                                                    type="button"
                                                    class="danger"
                                                    aria-label="Hapus jabatan"
                                                    @click="deleteTarget = { type: 'jabatan', data: jabatan }"
                                                >
                                                    <Trash2 />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="empty-positions">
                            <BriefcaseBusiness /><span
                                ><strong>Belum ada jabatan</strong><small>Tambahkan jabatan pertama untuk unit ini.</small></span
                            ><button v-if="can.create" type="button" @click="openCreateJabatan(unit)"><Plus /> Tambah jabatan</button>
                        </div>
                    </article>
                </div>

                <div v-else class="empty-state">
                    <span><Building2 /></span><small>{{ hasFilters ? 'Pencarian selesai' : 'Struktur masih kosong' }}</small>
                    <h3>{{ hasFilters ? 'Unit atau jabatan tidak ditemukan' : 'Belum ada unit kerja' }}</h3>
                    <p>{{ hasFilters ? 'Coba kata kunci atau status lain.' : 'Tambahkan unit kerja pertama beserta jabatan di dalamnya.' }}</p>
                    <button v-if="hasFilters" type="button" @click="clearFilters">Tampilkan semua unit</button
                    ><button v-else-if="can.create" type="button" @click="openCreateUnit"><Plus /> Tambah unit dan jabatan</button>
                </div>

                <Pagination :links="units.links" :from="units.from" :to="units.to" :total="units.total" />
            </section>
        </div>

        <CrudModal
            :open="Boolean(unitMode)"
            :title="unitModalTitle"
            :description="unitMode === 'edit' ? 'Perbarui identitas dan status unit kerja.' : 'Buat satu unit kerja beserta jabatan di dalamnya.'"
            @close="closeUnit"
        >
            <form class="structure-form" @submit.prevent="submitUnit">
                <div class="admin-modal-body form-body">
                    <div class="form-preview">
                        <span><Building2 /></span>
                        <div>
                            <small><Sparkles /> Unit kerja</small><strong>{{ unitForm.nama.trim() || 'Nama unit kerja' }}</strong
                            ><em>{{ unitForm.kode.trim().toUpperCase() || 'KODE-UNIT' }}</em>
                        </div>
                        <span class="status" :class="unitForm.aktif ? 'active' : 'inactive'"><i></i>{{ unitForm.aktif ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>

                    <section class="form-section">
                        <header>
                            <span><Building2 /></span>
                            <div>
                                <small>Informasi utama</small>
                                <h3>Identitas unit kerja</h3>
                                <p>Gunakan nama resmi yang dikenali di lingkungan rumah sakit.</p>
                            </div>
                        </header>
                        <div class="form-grid">
                            <div class="field">
                                <label for="unit-kode">Kode unit <b>*</b></label>
                                <div class="input" :class="{ invalid: unitForm.errors.kode }">
                                    <BadgeCheck /><input id="unit-kode" v-model="unitForm.kode" maxlength="30" placeholder="Contoh: IGD" autofocus />
                                </div>
                                <small>Huruf, angka, dan tanda hubung.</small>
                                <p v-if="unitForm.errors.kode" class="error">{{ unitForm.errors.kode }}</p>
                            </div>
                            <div class="field">
                                <label for="unit-nama">Nama unit kerja <b>*</b></label>
                                <div class="input" :class="{ invalid: unitForm.errors.nama }">
                                    <Building2 /><input
                                        id="unit-nama"
                                        v-model="unitForm.nama"
                                        maxlength="150"
                                        placeholder="Contoh: Instalasi Gawat Darurat"
                                    />
                                </div>
                                <small>Nama sesuai struktur organisasi.</small>
                                <p v-if="unitForm.errors.nama" class="error">{{ unitForm.errors.nama }}</p>
                            </div>
                            <div class="field full">
                                <label for="unit-deskripsi">Deskripsi unit</label>
                                <div class="textarea" :class="{ invalid: unitForm.errors.deskripsi }">
                                    <FileText /><textarea
                                        id="unit-deskripsi"
                                        v-model="unitForm.deskripsi"
                                        rows="2"
                                        maxlength="500"
                                        placeholder="Ringkas fungsi unit kerja..."
                                    ></textarea>
                                </div>
                                <div class="field-meta">
                                    <small>Opsional</small><small>{{ unitForm.deskripsi.length }}/500</small>
                                </div>
                                <p v-if="unitForm.errors.deskripsi" class="error">{{ unitForm.errors.deskripsi }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="status-switch"
                            :class="{ checked: unitForm.aktif }"
                            role="switch"
                            :aria-checked="unitForm.aktif"
                            @click="unitForm.aktif = !unitForm.aktif"
                        >
                            <span><i></i></span
                            ><span
                                ><strong>{{ unitForm.aktif ? 'Unit aktif' : 'Unit nonaktif' }}</strong
                                ><small>{{ unitForm.aktif ? 'Tersedia untuk proses operasional' : 'Disimpan tetapi tidak digunakan' }}</small></span
                            ><Check v-if="unitForm.aktif" /><CircleOff v-else />
                        </button>
                    </section>

                    <section v-if="unitMode === 'create'" class="form-section positions-section">
                        <header>
                            <span><BriefcaseBusiness /></span>
                            <div>
                                <small>Isi unit kerja</small>
                                <h3>Jabatan di dalam unit</h3>
                                <p>Tambahkan minimal satu jabatan.</p>
                            </div>
                            <button type="button" class="add-input" :disabled="unitForm.jabatans.length >= 25" @click="addJabatanInput">
                                <Plus /> Tambah
                            </button>
                        </header>
                        <p v-if="unitForm.errors.jabatans" class="error">{{ unitForm.errors.jabatans }}</p>
                        <div class="input-list">
                            <article v-for="(input, index) in unitForm.jabatans" :key="input.inputKey" class="position-input">
                                <header>
                                    <span>{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div>
                                        <strong>Jabatan {{ index + 1 }}</strong
                                        ><small>{{ input.nama.trim() || 'Input jabatan baru' }}</small>
                                    </div>
                                    <button
                                        type="button"
                                        :disabled="unitForm.jabatans.length === 1"
                                        :aria-label="`Hapus jabatan ${index + 1}`"
                                        @click="removeJabatanInput(index)"
                                    >
                                        <Trash2 />
                                    </button>
                                </header>
                                <div class="form-grid">
                                    <div class="field">
                                        <label :for="`kode-${input.inputKey}`">Kode jabatan <b>*</b></label>
                                        <div class="input" :class="{ invalid: inputError(index, 'kode') }">
                                            <BadgeCheck /><input
                                                :id="`kode-${input.inputKey}`"
                                                v-model="input.kode"
                                                maxlength="30"
                                                placeholder="Contoh: KEP-IGD"
                                            />
                                        </div>
                                        <p v-if="inputError(index, 'kode')" class="error">{{ inputError(index, 'kode') }}</p>
                                    </div>
                                    <div class="field">
                                        <label :for="`nama-${input.inputKey}`">Nama jabatan <b>*</b></label>
                                        <div class="input" :class="{ invalid: inputError(index, 'nama') }">
                                            <BriefcaseBusiness /><input
                                                :id="`nama-${input.inputKey}`"
                                                v-model="input.nama"
                                                maxlength="150"
                                                placeholder="Contoh: Kepala IGD"
                                            />
                                        </div>
                                        <p v-if="inputError(index, 'nama')" class="error">{{ inputError(index, 'nama') }}</p>
                                    </div>
                                    <div class="field full">
                                        <label :for="`deskripsi-${input.inputKey}`">Deskripsi</label>
                                        <div class="textarea" :class="{ invalid: inputError(index, 'deskripsi') }">
                                            <FileText /><textarea
                                                :id="`deskripsi-${input.inputKey}`"
                                                v-model="input.deskripsi"
                                                rows="2"
                                                maxlength="500"
                                                placeholder="Ringkas tanggung jawab..."
                                            ></textarea>
                                        </div>
                                        <p v-if="inputError(index, 'deskripsi')" class="error">{{ inputError(index, 'deskripsi') }}</p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="status-switch compact"
                                    :class="{ checked: input.aktif }"
                                    role="switch"
                                    :aria-checked="input.aktif"
                                    @click="input.aktif = !input.aktif"
                                >
                                    <span><i></i></span
                                    ><span
                                        ><strong>{{ input.aktif ? 'Jabatan aktif' : 'Jabatan nonaktif' }}</strong
                                        ><small>{{ input.aktif ? 'Tersedia digunakan' : 'Tidak digunakan sementara' }}</small></span
                                    ><Check v-if="input.aktif" /><CircleOff v-else />
                                </button>
                            </article>
                        </div>
                        <button type="button" class="add-more" :disabled="unitForm.jabatans.length >= 25" @click="addJabatanInput">
                            <Plus /> Tambah jabatan lainnya
                        </button>
                    </section>
                </div>
                <div class="admin-modal-footer form-footer">
                    <div>
                        <BadgeCheck /><span
                            ><strong>{{ unitMode === 'edit' ? 'Perbarui unit kerja' : `${unitForm.jabatans.length} jabatan siap disimpan` }}</strong
                            ><small>Kolom bertanda * wajib diisi</small></span
                        >
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" :disabled="unitForm.processing" @click="closeUnit">Batal</button
                        ><button
                            type="submit"
                            class="btn btn-primary submit"
                            :disabled="unitForm.processing || (unitMode === 'edit' && !unitForm.isDirty)"
                        >
                            <LoaderCircle v-if="unitForm.processing" class="spinner" /><Save v-else />{{
                                unitForm.processing ? 'Menyimpan...' : 'Simpan'
                            }}
                        </button>
                    </div>
                </div>
            </form>
        </CrudModal>

        <CrudModal
            :open="Boolean(jabatanMode)"
            :title="jabatanMode === 'edit' ? `Edit jabatan: ${editingJabatan?.nama ?? ''}` : 'Tambah jabatan'"
            description="Lengkapi identitas jabatan dan tentukan unit kerjanya."
            @close="closeJabatan"
        >
            <form class="structure-form" @submit.prevent="submitJabatan">
                <div class="admin-modal-body form-body">
                    <div class="form-preview">
                        <span><BriefcaseBusiness /></span>
                        <div>
                            <small><Sparkles /> Jabatan</small><strong>{{ jabatanForm.nama.trim() || 'Nama jabatan' }}</strong
                            ><em>{{ jabatanForm.kode.trim().toUpperCase() || 'KODE-JABATAN' }}</em>
                        </div>
                        <span class="status" :class="jabatanForm.aktif ? 'active' : 'inactive'"
                            ><i></i>{{ jabatanForm.aktif ? 'Aktif' : 'Nonaktif' }}</span
                        >
                    </div>
                    <section class="form-section">
                        <header>
                            <span><BriefcaseBusiness /></span>
                            <div>
                                <small>Informasi utama</small>
                                <h3>Identitas dan unit kerja</h3>
                                <p>Setiap jabatan harus berada di satu unit kerja.</p>
                            </div>
                        </header>
                        <div class="form-grid">
                            <div class="field full">
                                <label for="jabatan-unit">Unit kerja <b>*</b></label>
                                <div class="input" :class="{ invalid: jabatanForm.errors.unit_kerja_id }">
                                    <Building2 /><select id="jabatan-unit" v-model="jabatanForm.unit_kerja_id">
                                        <option :value="0" disabled>Pilih unit kerja</option>
                                        <option v-for="unit in unitOptions" :key="unit.id" :value="unit.id">
                                            {{ unit.nama }} ({{ unit.kode }}){{ unit.aktif ? '' : ' — Nonaktif' }}
                                        </option>
                                    </select>
                                </div>
                                <p v-if="jabatanForm.errors.unit_kerja_id" class="error">{{ jabatanForm.errors.unit_kerja_id }}</p>
                            </div>
                            <div class="field">
                                <label for="jabatan-kode">Kode jabatan <b>*</b></label>
                                <div class="input" :class="{ invalid: jabatanForm.errors.kode }">
                                    <BadgeCheck /><input
                                        id="jabatan-kode"
                                        v-model="jabatanForm.kode"
                                        maxlength="30"
                                        placeholder="Contoh: KEP-UNIT"
                                        autofocus
                                    />
                                </div>
                                <small>Unik di dalam unit terpilih.</small>
                                <p v-if="jabatanForm.errors.kode" class="error">{{ jabatanForm.errors.kode }}</p>
                            </div>
                            <div class="field">
                                <label for="jabatan-nama">Nama jabatan <b>*</b></label>
                                <div class="input" :class="{ invalid: jabatanForm.errors.nama }">
                                    <BriefcaseBusiness /><input
                                        id="jabatan-nama"
                                        v-model="jabatanForm.nama"
                                        maxlength="150"
                                        placeholder="Contoh: Kepala Unit"
                                    />
                                </div>
                                <small>Unik di dalam unit terpilih.</small>
                                <p v-if="jabatanForm.errors.nama" class="error">{{ jabatanForm.errors.nama }}</p>
                            </div>
                            <div class="field full">
                                <label for="jabatan-deskripsi">Deskripsi</label>
                                <div class="textarea" :class="{ invalid: jabatanForm.errors.deskripsi }">
                                    <FileText /><textarea
                                        id="jabatan-deskripsi"
                                        v-model="jabatanForm.deskripsi"
                                        rows="3"
                                        maxlength="500"
                                        placeholder="Ringkas tanggung jawab jabatan..."
                                    ></textarea>
                                </div>
                                <div class="field-meta">
                                    <small>Opsional</small><small>{{ jabatanForm.deskripsi.length }}/500</small>
                                </div>
                                <p v-if="jabatanForm.errors.deskripsi" class="error">{{ jabatanForm.errors.deskripsi }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="status-switch"
                            :class="{ checked: jabatanForm.aktif }"
                            role="switch"
                            :aria-checked="jabatanForm.aktif"
                            @click="jabatanForm.aktif = !jabatanForm.aktif"
                        >
                            <span><i></i></span
                            ><span
                                ><strong>{{ jabatanForm.aktif ? 'Jabatan aktif' : 'Jabatan nonaktif' }}</strong
                                ><small>{{ jabatanForm.aktif ? 'Tersedia untuk digunakan' : 'Disimpan tetapi tidak digunakan' }}</small></span
                            ><Check v-if="jabatanForm.aktif" /><CircleOff v-else />
                        </button>
                    </section>
                </div>
                <div class="admin-modal-footer form-footer">
                    <div>
                        <BadgeCheck /><span
                            ><strong>{{ jabatanMode === 'edit' ? 'Perbarui jabatan' : 'Jabatan baru' }}</strong
                            ><small>Relasi unit wajib ditentukan</small></span
                        >
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" :disabled="jabatanForm.processing" @click="closeJabatan">Batal</button
                        ><button
                            type="submit"
                            class="btn btn-primary submit"
                            :disabled="jabatanForm.processing || (jabatanMode === 'edit' && !jabatanForm.isDirty)"
                        >
                            <LoaderCircle v-if="jabatanForm.processing" class="spinner" /><Save v-else />{{
                                jabatanForm.processing ? 'Menyimpan...' : 'Simpan jabatan'
                            }}
                        </button>
                    </div>
                </div>
            </form>
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(deleteTarget)"
            :title="deleteTitle"
            :description="deleteDescription"
            :processing="deleting"
            @close="deleteTarget = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>

<style scoped>
.structure-page,
.structure-form {
    --ink: var(--rs-primary-deep);
    --muted: var(--rs-text-soft);
    --line: var(--rs-surface-muted);
    --indigo: var(--rs-primary);
}
.structure-page {
    display: grid;
    gap: 20px;
}
.hero {
    position: relative;
    display: grid;
    grid-template-columns: 1.35fr 0.65fr;
    overflow: hidden;
    min-height: 280px;
    padding: 40px 44px;
    border-radius: 22px;
    color: #fff;
    background: linear-gradient(125deg, var(--rs-primary-deep), var(--rs-primary-hover) 55%, var(--rs-primary));
    box-shadow: 0 22px 50px rgba(var(--rs-primary-deep-rgb), 0.2);
}
.hero-glow {
    position: absolute;
    width: 340px;
    height: 340px;
    right: -100px;
    top: -160px;
    border-radius: 50%;
    background: rgba(87, 225, 210, 0.17);
    filter: blur(4px);
}
.hero-copy,
.hero-side {
    position: relative;
    z-index: 1;
}
.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 11px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 99px;
    color: #b8fff7;
    background: rgba(255, 255, 255, 0.07);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}
.eyebrow svg,
.hero-points svg {
    width: 14px;
    height: 14px;
}
.hero h1 {
    margin: 19px 0 10px;
    font-size: clamp(32px, 4vw, 50px);
    line-height: 1.04;
    letter-spacing: -0.045em;
}
.hero h1 span {
    color: var(--rs-gold-light);
}
.hero-copy > p {
    max-width: 650px;
    margin: 0;
    color: var(--rs-primary-pale);
    font-size: 15px;
    line-height: 1.75;
}
.hero-points {
    display: flex;
    gap: 20px;
    margin-top: 23px;
    color: var(--rs-primary-soft);
    font-size: 12px;
    font-weight: 700;
}
.hero-points span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.hero-side {
    display: grid;
    align-content: center;
    gap: 12px;
}
.hero-side > div {
    display: grid;
    padding: 19px 20px;
    border: 1px solid rgba(255, 255, 255, 0.13);
    border-radius: 16px;
    background: rgba(var(--rs-primary-deep-rgb), 0.35);
    backdrop-filter: blur(12px);
}
.hero-side > div small {
    color: var(--rs-primary-border);
}
.hero-side > div strong {
    margin: 4px 0;
    color: #a9fff4;
    font-size: 36px;
}
.hero-side > div span {
    color: #fff;
    font-size: 12px;
}
.hero-side > button {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 14px 16px;
    border: 0;
    border-radius: 15px;
    color: var(--rs-primary-deep);
    background: #fff;
    text-align: left;
}
.hero-side > button > svg {
    width: 20px;
}
.hero-side > button span {
    display: grid;
}
.hero-side > button small {
    color: var(--rs-muted);
    font-size: 10px;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.summary-card {
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 116px;
    padding: 18px;
    border: 1px solid var(--line);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(var(--rs-primary-deep-rgb), 0.05);
}
.summary-icon {
    display: grid;
    width: 43px;
    height: 43px;
    flex: 0 0 43px;
    place-items: center;
    border-radius: 13px;
}
.summary-icon svg {
    width: 20px;
}
.summary-card.indigo .summary-icon {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.summary-card.emerald .summary-icon {
    color: #139775;
    background: #e7faf4;
}
.summary-card.amber .summary-icon {
    color: #c68120;
    background: #fff5df;
}
.summary-card.cyan .summary-icon {
    color: var(--rs-primary-hover);
    background: var(--rs-primary-soft);
}
.summary-card > div {
    display: grid;
}
.summary-card small {
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.summary-card strong {
    color: var(--ink);
    font-size: 27px;
}
.summary-card span {
    color: var(--rs-muted);
    font-size: 11px;
}
.directory {
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 12px 34px rgba(var(--rs-primary-deep-rgb), 0.06);
}
.directory-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 24px 26px 18px;
}
.directory-heading > div:first-child > span {
    color: var(--indigo);
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}
.directory-heading h2 {
    margin: 4px 0 2px;
    color: var(--ink);
    font-size: 23px;
}
.directory-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}
.directory-heading > div:last-child {
    display: grid;
    text-align: right;
}
.directory-heading > div:last-child small {
    color: #24a281;
    font-weight: 800;
}
.directory-heading > div:last-child strong {
    color: var(--ink);
    font-size: 12px;
}
.filters {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto auto;
    gap: 10px;
    padding: 15px 26px;
    border-block: 1px solid var(--line);
    background: var(--rs-canvas);
}
.search-field {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 0 12px;
    border: 1px solid var(--rs-border);
    border-radius: 11px;
    background: #fff;
}
.search-field > svg {
    width: 17px;
    color: var(--rs-muted);
}
.search-field input {
    width: 100%;
    border: 0;
    outline: 0;
    box-shadow: none;
    font-size: 13px;
}
.search-field button,
.row-actions button {
    border: 0;
    color: var(--rs-muted);
    background: transparent;
}
.search-field button svg {
    width: 15px;
}
.status-filter {
    display: flex;
    padding: 3px;
    border: 1px solid var(--rs-border);
    border-radius: 11px;
    background: #fff;
}
.status-filter button {
    padding: 7px 12px;
    border: 0;
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: transparent;
    font-size: 12px;
    font-weight: 700;
}
.status-filter button.active {
    color: #fff;
    background: var(--rs-primary);
}
.search-button,
.add-position,
.empty-positions button,
.empty-state button,
.add-input,
.add-more {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 0;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 800;
}
.search-button {
    padding: 0 16px;
    color: #fff;
    background: var(--rs-primary-deep);
}
.search-button svg,
.add-position svg,
.empty-positions button svg,
.empty-state button svg,
.add-input svg,
.add-more svg {
    width: 15px;
}
.active-filter {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 26px;
    border-bottom: 1px solid var(--line);
    color: var(--muted);
    font-size: 11px;
}
.active-filter > span {
    color: var(--indigo);
    font-weight: 900;
    text-transform: uppercase;
}
.active-filter button {
    margin-left: auto;
    border: 0;
    color: #d25c65;
    background: transparent;
    font-weight: 800;
}
.active-filter button svg {
    width: 13px;
}
.unit-list {
    display: grid;
    gap: 15px;
    padding: 20px 26px 25px;
    background: var(--rs-canvas);
}
.unit-card {
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 7px 20px rgba(var(--rs-primary-deep-rgb), 0.04);
}
.unit-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px;
}
.unit-identity {
    display: flex;
    align-items: center;
    min-width: 0;
    gap: 13px;
}
.unit-avatar {
    display: grid;
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    place-items: center;
    border-radius: 13px;
}
.unit-avatar svg {
    width: 21px;
}
.tone-1 {
    color: var(--rs-primary) !important;
    background: var(--rs-primary-soft) !important;
}
.tone-2 {
    color: #168d71 !important;
    background: #e7f9f3 !important;
}
.tone-3 {
    color: #b77b25 !important;
    background: #fff4dc !important;
}
.tone-4 {
    color: var(--rs-primary-hover) !important;
    background: var(--rs-primary-soft) !important;
}
.tone-5 {
    color: #a35b99 !important;
    background: #f8eefa !important;
}
.unit-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.unit-title h3 {
    margin: 0;
    color: var(--ink);
    font-size: 17px;
}
.unit-identity p {
    margin: 3px 0;
    overflow: hidden;
    color: var(--muted);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.unit-identity > div > small {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--rs-muted);
    font-size: 11px;
}
.unit-identity > div > small svg {
    width: 13px;
}
.code {
    display: inline-flex;
    padding: 4px 8px;
    border-radius: 7px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-family: ui-monospace, monospace;
    font-size: 10px;
    font-weight: 900;
}
.status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    width: max-content;
    padding: 4px 8px;
    border-radius: 99px;
    font-size: 10px;
    font-weight: 800;
}
.status i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.status.active {
    color: #168d71;
    background: #e7f9f3;
}
.status.inactive {
    color: #a56f20;
    background: #fff3dc;
}
.unit-actions {
    display: flex;
    align-items: center;
    gap: 7px;
}
.add-position {
    padding: 9px 12px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.icon-button,
.row-actions button {
    display: grid;
    width: 33px;
    height: 33px;
    place-items: center;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    color: var(--rs-text-soft);
    background: #fff;
}
.icon-button svg,
.row-actions button svg {
    width: 15px;
}
.danger {
    color: #d45d68 !important;
}
.table-wrap {
    overflow-x: auto;
    border-top: 1px solid var(--line);
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    padding: 10px 16px;
    color: var(--rs-muted);
    background: var(--rs-canvas);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-align: left;
    text-transform: uppercase;
}
td {
    padding: 12px 16px;
    border-top: 1px solid var(--rs-surface-muted);
    color: var(--muted);
    font-size: 11px;
    vertical-align: middle;
}
.position-name {
    display: flex;
    align-items: center;
    min-width: 190px;
    gap: 9px;
}
.position-name > span {
    display: grid;
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    place-items: center;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 900;
}
.position-name > div {
    display: grid;
}
.position-name strong {
    color: var(--ink);
    font-size: 12px;
}
.position-name small {
    color: var(--rs-muted);
    font-size: 9px;
}
.description {
    max-width: 250px;
    margin: 0;
    line-height: 1.5;
}
.updated {
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.updated svg {
    width: 13px;
}
.row-actions {
    display: flex;
    gap: 5px;
    justify-content: flex-end;
}
.empty-positions {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 22px;
    border-top: 1px dashed var(--rs-border);
    color: var(--rs-muted);
    background: var(--rs-canvas);
}
.empty-positions > svg {
    width: 22px;
}
.empty-positions > span {
    display: grid;
}
.empty-positions strong {
    color: var(--ink);
    font-size: 12px;
}
.empty-positions small {
    font-size: 10px;
}
.empty-positions button {
    padding: 8px 10px;
    color: #fff;
    background: var(--rs-primary);
}
.empty-state {
    display: grid;
    justify-items: center;
    padding: 60px 20px;
    text-align: center;
}
.empty-state > span {
    display: grid;
    width: 58px;
    height: 58px;
    place-items: center;
    border-radius: 18px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.empty-state h3 {
    margin: 12px 0 4px;
    color: var(--ink);
}
.empty-state p {
    color: var(--muted);
}
.empty-state button {
    padding: 9px 13px;
    color: #fff;
    background: var(--rs-primary);
}
.form-body {
    display: grid;
    gap: 15px;
    padding: 19px 21px;
    background: var(--rs-canvas);
}
.form-preview {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(120deg, var(--rs-primary-deep), var(--rs-primary));
}
.form-preview > span:first-child {
    display: grid;
    width: 39px;
    height: 39px;
    place-items: center;
    border-radius: 11px;
    background: rgba(255, 255, 255, 0.13);
}
.form-preview svg {
    width: 18px;
}
.form-preview > div {
    display: grid;
    flex: 1;
}
.form-preview small {
    color: var(--rs-primary-border);
    font-size: 9px;
    text-transform: uppercase;
}
.form-preview strong {
    font-size: 14px;
}
.form-preview em {
    color: #a9fff4;
    font-size: 10px;
    font-style: normal;
}
.form-section {
    display: grid;
    gap: 15px;
    padding: 17px;
    border: 1px solid var(--line);
    border-radius: 15px;
    background: #fff;
}
.form-section > header {
    display: flex;
    align-items: center;
    gap: 10px;
}
.form-section > header > span {
    display: grid;
    width: 36px;
    height: 36px;
    flex: 0 0 36px;
    place-items: center;
    border-radius: 10px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.form-section > header svg {
    width: 17px;
}
.form-section > header > div {
    flex: 1;
}
.form-section > header small {
    color: var(--rs-primary);
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
}
.form-section h3 {
    margin: 0;
    color: var(--ink);
    font-size: 15px;
}
.form-section header p {
    margin: 2px 0 0;
    color: var(--muted);
    font-size: 10px;
}
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 13px;
}
.field.full {
    grid-column: 1/-1;
}
.field label {
    display: block;
    margin-bottom: 6px;
    color: var(--rs-ink);
    font-size: 11px;
    font-weight: 800;
}
.field label b {
    color: #d65a65;
}
.field > small,
.field-meta small {
    color: var(--rs-muted);
    font-size: 9px;
}
.input,
.textarea {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    background: #fff;
}
.input {
    padding: 0 10px;
}
.textarea {
    align-items: flex-start;
    padding: 9px 10px;
}
.input > svg,
.textarea > svg {
    width: 15px;
    flex: 0 0 15px;
    color: var(--rs-muted);
}
.input input,
.input select,
.textarea textarea {
    width: 100%;
    border: 0;
    outline: 0;
    box-shadow: none;
    color: var(--ink);
    background: transparent;
    font-size: 12px;
}
.input input,
.input select {
    min-height: 39px;
}
.textarea textarea {
    resize: vertical;
}
.input.invalid,
.textarea.invalid {
    border-color: #dc6872;
    box-shadow: 0 0 0 3px rgba(220, 104, 114, 0.08);
}
.field-meta {
    display: flex;
    justify-content: space-between;
    margin-top: 4px;
}
.error {
    margin: 4px 0 0;
    color: #cf505c;
    font-size: 10px;
    font-weight: 700;
}
.status-switch {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 11px 13px;
    border: 1px solid var(--rs-border);
    border-radius: 11px;
    color: var(--ink);
    background: var(--rs-canvas);
    text-align: left;
}
.status-switch > span:first-child {
    width: 34px;
    height: 19px;
    padding: 2px;
    border-radius: 99px;
    background: var(--rs-primary-border);
}
.status-switch > span:first-child i {
    display: block;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.2s;
}
.status-switch.checked > span:first-child {
    background: #25a47f;
}
.status-switch.checked > span:first-child i {
    transform: translateX(15px);
}
.status-switch > span:nth-child(2) {
    display: grid;
}
.status-switch strong {
    font-size: 11px;
}
.status-switch small {
    color: var(--muted);
    font-size: 9px;
}
.status-switch > svg {
    width: 16px;
}
.status-switch.compact {
    margin-top: 12px;
}
.positions-section > header .add-input {
    margin-left: auto;
}
.add-input,
.add-more {
    padding: 8px 10px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.input-list {
    display: grid;
    gap: 11px;
}
.position-input {
    padding: 13px;
    border: 1px solid var(--rs-border);
    border-radius: 12px;
    background: var(--rs-canvas);
}
.position-input > header {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 12px;
}
.position-input > header > span {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border-radius: 9px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-size: 10px;
    font-weight: 900;
}
.position-input > header > div {
    display: grid;
    flex: 1;
}
.position-input > header strong {
    color: var(--ink);
    font-size: 11px;
}
.position-input > header small {
    color: var(--muted);
    font-size: 9px;
}
.position-input > header button {
    display: grid;
    width: 29px;
    height: 29px;
    place-items: center;
    border: 1px solid #ebdce0;
    border-radius: 8px;
    color: #d45d68;
    background: #fff;
}
.position-input > header button:disabled {
    opacity: 0.35;
}
.position-input > header svg {
    width: 14px;
}
.add-more {
    width: 100%;
    border: 1px dashed var(--rs-primary-pale);
    background: var(--rs-canvas);
}
.form-footer {
    display: flex;
    justify-content: space-between;
    gap: 15px;
}
.form-footer > div {
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-footer > div:first-child > svg {
    width: 18px;
    color: #27a47f;
}
.form-footer > div:first-child span {
    display: grid;
}
.form-footer strong {
    color: var(--ink);
    font-size: 11px;
}
.form-footer small {
    color: var(--muted);
    font-size: 9px;
}
.submit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.submit svg {
    width: 15px;
}
.spinner {
    animation: spin 0.7s linear infinite;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 1100px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .hero {
        grid-template-columns: 1fr 0.8fr;
    }
    .unit-header {
        align-items: flex-start;
    }
    .unit-actions {
        flex-wrap: wrap;
        justify-content: flex-end;
    }
}
@media (max-width: 760px) {
    .hero {
        grid-template-columns: 1fr;
        gap: 25px;
        padding: 29px 24px;
    }
    .hero-points {
        flex-direction: column;
        gap: 8px;
    }
    .summary-grid {
        grid-template-columns: 1fr 1fr;
    }
    .filters {
        grid-template-columns: 1fr;
    }
    .search-field {
        min-height: 43px;
    }
    .search-button {
        min-height: 40px;
    }
    .directory-heading,
    .unit-header {
        align-items: flex-start;
        flex-direction: column;
    }
    .directory-heading > div:last-child {
        text-align: left;
    }
    .unit-actions {
        width: 100%;
        justify-content: flex-start;
    }
    .table-wrap {
        margin: 0;
    }
    .table-wrap table {
        min-width: 820px;
    }
    .form-grid {
        grid-template-columns: 1fr;
    }
    .field.full {
        grid-column: auto;
    }
    .form-footer {
        align-items: stretch;
        flex-direction: column;
    }
    .form-footer > div:last-child {
        justify-content: flex-end;
    }
}
@media (max-width: 480px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
    .directory-heading,
    .filters,
    .unit-list {
        padding-inline: 15px;
    }
    .unit-identity {
        align-items: flex-start;
    }
    .unit-title {
        align-items: flex-start;
    }
    .add-position {
        flex: 1;
    }
    .active-filter {
        align-items: flex-start;
        flex-wrap: wrap;
        padding-inline: 15px;
    }
    .active-filter button {
        margin-left: 0;
    }
    .empty-positions {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
