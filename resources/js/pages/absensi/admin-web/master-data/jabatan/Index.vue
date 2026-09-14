<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    BriefcaseBusiness,
    CalendarDays,
    Check,
    CircleOff,
    FileText,
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
    kode: string;
    nama: string;
    deskripsi: string | null;
    aktif: boolean;
    created_at: string | null;
    updated_at: string | null;
}

interface JabatanOverview {
    total: number;
    active: number;
    inactive: number;
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

const props = defineProps<{
    jabatans: Paginated<JabatanRow>;
    overview: JabatanOverview;
    filters: { search?: string; status?: string };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Master Data', href: '/absensi/admin-web/master-data/jabatan' },
    { title: 'Jabatan', href: '/absensi/admin-web/master-data/jabatan' },
];

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? 'all');
const editing = ref<JabatanRow | null>(null);
const creating = ref(false);
const selected = ref<JabatanRow | null>(null);
const deleting = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let nextJabatanInputKey = 0;

const newJabatanInput = (): JabatanInput => ({
    inputKey: ++nextJabatanInputKey,
    kode: '',
    nama: '',
    deskripsi: '',
    aktif: true,
});

const form = useForm<{
    kode: string;
    nama: string;
    deskripsi: string;
    aktif: boolean;
    jabatans: JabatanInput[];
}>({
    kode: '',
    nama: '',
    deskripsi: '',
    aktif: true,
    jabatans: [newJabatanInput()],
});

const activeRate = computed(() => (props.overview.total ? Math.round((props.overview.active / props.overview.total) * 100) : 0));
const hasActiveFilters = computed(() => Boolean(search.value.trim()) || status.value !== 'all');
const resultSummary = computed(() => {
    if (!props.jabatans.total) return 'Belum ada hasil';
    return `${props.jabatans.from}-${props.jabatans.to} dari ${props.jabatans.total} jabatan`;
});
const previewJabatans = computed(() => props.jabatans.data.slice(0, 4));
const remainingJabatans = computed(() => Math.max(0, props.overview.total - previewJabatans.value.length));

const summaryCards = computed(() => [
    {
        label: 'Total jabatan',
        value: props.overview.total.toLocaleString('id-ID'),
        note: 'Struktur jabatan terdaftar',
        icon: BriefcaseBusiness,
        tone: 'indigo',
    },
    {
        label: 'Jabatan aktif',
        value: props.overview.active.toLocaleString('id-ID'),
        note: `${activeRate.value}% siap digunakan`,
        icon: BadgeCheck,
        tone: 'emerald',
    },
    {
        label: 'Nonaktif',
        value: props.overview.inactive.toLocaleString('id-ID'),
        note: props.overview.inactive ? 'Tersimpan dari pilihan aktif' : 'Tidak ada data nonaktif',
        icon: CircleOff,
        tone: 'amber',
    },
    {
        label: 'Baru bulan ini',
        value: props.overview.added_this_month.toLocaleString('id-ID'),
        note: 'Pertumbuhan master data',
        icon: TrendingUp,
        tone: 'cyan',
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

const resetForm = () => {
    form.defaults({ kode: '', nama: '', deskripsi: '', aktif: true, jabatans: [newJabatanInput()] });
    form.reset();
    form.clearErrors();
};

const openCreate = () => {
    editing.value = null;
    resetForm();
    creating.value = true;
};

const openEdit = (jabatan: JabatanRow) => {
    creating.value = false;
    editing.value = jabatan;
    form.clearErrors();
    form.defaults({
        kode: jabatan.kode,
        nama: jabatan.nama,
        deskripsi: jabatan.deskripsi ?? '',
        aktif: jabatan.aktif,
        jabatans: [],
    });
    form.reset();
};

const addJabatanInput = () => {
    if (form.jabatans.length >= 25) return;
    form.jabatans.push(newJabatanInput());
};

const removeJabatanInput = (index: number) => {
    if (form.jabatans.length === 1) return;
    form.jabatans.splice(index, 1);
    form.clearErrors();
};

const inputError = (index: number, field: 'kode' | 'nama' | 'deskripsi' | 'aktif') =>
    (form.errors as Record<string, string | undefined>)[`jabatans.${index}.${field}`];

const closeForm = () => {
    if (form.processing) return;
    creating.value = false;
    editing.value = null;
    resetForm();
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => closeForm(),
    };

    if (editing.value) form.put(`/absensi/admin-web/master-data/jabatan/${editing.value.id}`, options);
    else form.post('/absensi/admin-web/master-data/jabatan', options);
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        '/absensi/admin-web/master-data/jabatan',
        {
            search: search.value.trim() || undefined,
            status: status.value === 'all' ? undefined : status.value,
        },
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

watch(search, (value, previousValue) => {
    if (value === previousValue) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 450);
});

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});

const destroy = () => {
    if (!selected.value) return;
    deleting.value = true;
    router.delete(`/absensi/admin-web/master-data/jabatan/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};
</script>

<template>
    <Head title="Master Jabatan" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="job-master-page">
            <FlashMessage />

            <section class="job-hero" aria-labelledby="job-hero-title">
                <span class="job-hero-orb job-hero-orb-one" aria-hidden="true"></span>
                <span class="job-hero-orb job-hero-orb-two" aria-hidden="true"></span>
                <div class="job-hero-grid" aria-hidden="true"></div>

                <div class="job-hero-copy">
                    <span class="job-eyebrow"><Sparkles /> Fondasi organisasi</span>
                    <h1 id="job-hero-title">Struktur yang jelas.<br /><span>Tim yang selaras.</span></h1>
                    <p>Kelola seluruh nomenklatur jabatan dari satu ruang kerja yang ringkas, konsisten, dan siap menjadi fondasi data pegawai.</p>
                    <div class="job-hero-trust">
                        <span><Check /> Kode terstandar</span>
                        <span><Check /> Status terkendali</span>
                    </div>
                </div>

                <div class="job-hero-side">
                    <div class="job-health-card">
                        <div class="job-health-head">
                            <span>Kesiapan data</span>
                            <span class="job-live"><i></i> Tersinkron</span>
                        </div>
                        <div class="job-avatar-stack" aria-label="Pratinjau jabatan">
                            <span
                                v-for="jabatan in previewJabatans"
                                :key="jabatan.id"
                                class="job-avatar"
                                :class="avatarTone(jabatan.id)"
                                :title="jabatan.nama"
                            >
                                {{ initials(jabatan.nama) }}
                            </span>
                            <span v-if="remainingJabatans" class="job-avatar-more">+{{ remainingJabatans }}</span>
                        </div>
                        <div class="job-progress-row">
                            <div class="job-progress-ring" :style="{ '--job-progress': `${activeRate * 3.6}deg` }">
                                <span>{{ activeRate }}%</span>
                            </div>
                            <div>
                                <strong>Siap digunakan</strong>
                                <small>{{ overview.active }} dari {{ overview.total }} jabatan berstatus aktif</small>
                            </div>
                        </div>
                    </div>
                    <button v-if="can.create" type="button" class="job-hero-action" @click="openCreate">
                        <span><Plus /></span>
                        <span><strong>Tambah jabatan</strong><small>Lengkapi struktur organisasi</small></span>
                        <ArrowRight />
                    </button>
                </div>
            </section>

            <section class="job-overview-grid" aria-label="Ringkasan jabatan">
                <article v-for="card in summaryCards" :key="card.label" class="job-overview-card" :class="`tone-${card.tone}`">
                    <span class="job-overview-icon"><component :is="card.icon" /></span>
                    <span class="job-overview-copy">
                        <small>{{ card.label }}</small>
                        <strong>{{ card.value }}</strong>
                        <span>{{ card.note }}</span>
                    </span>
                    <i aria-hidden="true"></i>
                </article>
            </section>

            <section class="job-directory" aria-labelledby="job-directory-title">
                <header class="job-directory-header">
                    <div>
                        <span class="job-section-kicker">Direktori jabatan</span>
                        <h2 id="job-directory-title">Master jabatan</h2>
                        <p>Temukan, perbarui, dan kendalikan ketersediaan jabatan.</p>
                    </div>
                    <div class="job-directory-count">
                        <span><i></i> Data terbaru</span>
                        <strong>{{ resultSummary }}</strong>
                    </div>
                </header>

                <form class="job-filter-bar" role="search" @submit.prevent="applyFilters">
                    <label class="job-search-field">
                        <Search />
                        <span class="visually-hidden">Cari jabatan</span>
                        <input v-model="search" type="search" placeholder="Cari kode, nama, atau deskripsi..." autocomplete="off" />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="search = ''"><X /></button>
                    </label>
                    <div class="job-status-filter" aria-label="Filter status">
                        <button type="button" :class="{ active: status === 'all' }" @click="setStatus('all')">Semua</button>
                        <button type="button" :class="{ active: status === 'active' }" @click="setStatus('active')">Aktif</button>
                        <button type="button" :class="{ active: status === 'inactive' }" @click="setStatus('inactive')">Nonaktif</button>
                    </div>
                    <button type="submit" class="job-filter-submit"><Search /> Cari</button>
                </form>

                <div v-if="hasActiveFilters" class="job-active-filter" aria-live="polite">
                    <span>Filter aktif</span>
                    <small v-if="search">Kata kunci: “{{ search }}”</small>
                    <small v-if="status !== 'all'">Status: {{ status === 'active' ? 'Aktif' : 'Nonaktif' }}</small>
                    <button type="button" @click="clearFilters">Hapus semua <X /></button>
                </div>

                <template v-if="jabatans.data.length">
                    <div class="job-table-view">
                        <table class="job-table">
                            <thead>
                                <tr>
                                    <th>Identitas jabatan</th>
                                    <th>Kode</th>
                                    <th>Deskripsi</th>
                                    <th>Status</th>
                                    <th>Diperbarui</th>
                                    <th><span class="visually-hidden">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="jabatan in jabatans.data" :key="jabatan.id">
                                    <td>
                                        <div class="job-identity">
                                            <span class="job-identity-avatar" :class="avatarTone(jabatan.id)">{{ initials(jabatan.nama) }}</span>
                                            <span>
                                                <strong>{{ jabatan.nama }}</strong>
                                                <small>ID #{{ String(jabatan.id).padStart(4, '0') }}</small>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="job-code">{{ jabatan.kode }}</span>
                                    </td>
                                    <td>
                                        <p class="job-description">{{ jabatan.deskripsi || 'Belum ada deskripsi' }}</p>
                                    </td>
                                    <td>
                                        <span class="job-status" :class="jabatan.aktif ? 'active' : 'inactive'">
                                            <i></i>{{ jabatan.aktif ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="job-updated"><CalendarDays /> {{ jabatan.updated_at ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <div class="job-actions">
                                            <button
                                                v-if="can.update"
                                                type="button"
                                                aria-label="Edit jabatan"
                                                title="Edit jabatan"
                                                @click="openEdit(jabatan)"
                                            >
                                                <Pencil />
                                            </button>
                                            <button
                                                v-if="can.delete"
                                                type="button"
                                                class="danger"
                                                aria-label="Hapus jabatan"
                                                title="Hapus jabatan"
                                                @click="selected = jabatan"
                                            >
                                                <Trash2 />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="job-mobile-list">
                        <article v-for="jabatan in jabatans.data" :key="jabatan.id" class="job-mobile-card">
                            <header>
                                <div class="job-identity">
                                    <span class="job-identity-avatar" :class="avatarTone(jabatan.id)">{{ initials(jabatan.nama) }}</span>
                                    <span
                                        ><strong>{{ jabatan.nama }}</strong
                                        ><small>{{ jabatan.kode }}</small></span
                                    >
                                </div>
                                <span class="job-status" :class="jabatan.aktif ? 'active' : 'inactive'"
                                    ><i></i>{{ jabatan.aktif ? 'Aktif' : 'Nonaktif' }}</span
                                >
                            </header>
                            <p>{{ jabatan.deskripsi || 'Belum ada deskripsi untuk jabatan ini.' }}</p>
                            <footer>
                                <span><CalendarDays /> {{ jabatan.updated_at ?? '-' }}</span>
                                <div class="job-actions">
                                    <button v-if="can.update" type="button" aria-label="Edit jabatan" @click="openEdit(jabatan)"><Pencil /></button>
                                    <button v-if="can.delete" type="button" class="danger" aria-label="Hapus jabatan" @click="selected = jabatan">
                                        <Trash2 />
                                    </button>
                                </div>
                            </footer>
                        </article>
                    </div>
                </template>

                <div v-else class="job-empty-state">
                    <span><BriefcaseBusiness /></span>
                    <small>{{ hasActiveFilters ? 'Pencarian selesai' : 'Direktori masih kosong' }}</small>
                    <h3>{{ hasActiveFilters ? 'Jabatan tidak ditemukan' : 'Belum ada jabatan' }}</h3>
                    <p>
                        {{
                            hasActiveFilters
                                ? 'Coba kata kunci atau status lain untuk menemukan data yang dicari.'
                                : 'Tambahkan jabatan pertama untuk mulai membangun struktur organisasi.'
                        }}
                    </p>
                    <button v-if="hasActiveFilters" type="button" @click="clearFilters">Tampilkan semua jabatan</button>
                    <button v-else-if="can.create" type="button" @click="openCreate"><Plus /> Tambah jabatan</button>
                </div>

                <Pagination :links="jabatans.links" :from="jabatans.from" :to="jabatans.to" :total="jabatans.total" />
            </section>
        </div>

        <CrudModal
            :open="creating || Boolean(editing)"
            :title="editing ? `Edit jabatan: ${editing.nama}` : 'Tambah jabatan baru'"
            :description="
                editing ? 'Perbarui identitas dan ketersediaan jabatan.' : 'Lengkapi identitas jabatan untuk menambahkannya ke master data.'
            "
            @close="closeForm"
        >
            <form class="job-form" @submit.prevent="submit">
                <div class="admin-modal-body job-form-body">
                    <div v-if="editing" class="job-form-preview">
                        <span><BriefcaseBusiness /></span>
                        <div>
                            <small><Sparkles /> Pratinjau jabatan</small>
                            <strong>{{ form.nama.trim() || 'Nama jabatan' }}</strong>
                            <em>{{ form.kode.trim().toUpperCase() || 'KODE-JABATAN' }}</em>
                        </div>
                        <span class="job-status" :class="form.aktif ? 'active' : 'inactive'"><i></i>{{ form.aktif ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>
                    <div v-else class="job-form-preview job-dynamic-summary">
                        <span><BriefcaseBusiness /></span>
                        <div>
                            <small><Sparkles /> Input dinamis</small>
                            <strong>{{ form.jabatans.length }} jabatan siap dilengkapi</strong>
                            <em>Tambah atau hapus input sesuai kebutuhan</em>
                        </div>
                        <span class="job-input-count">{{ form.jabatans.length }}/25 input</span>
                    </div>

                    <template v-if="editing">
                        <section class="job-form-section" aria-labelledby="job-identity-form-title">
                            <header>
                                <span><BriefcaseBusiness /></span>
                                <div>
                                    <small>Informasi utama</small>
                                    <h3 id="job-identity-form-title">Identitas jabatan</h3>
                                    <p>Gunakan penamaan resmi yang mudah dikenali oleh seluruh tim.</p>
                                </div>
                            </header>

                            <div class="job-form-grid">
                                <div class="job-form-field">
                                    <label for="kode">Kode jabatan <b>*</b></label>
                                    <div class="job-form-input" :class="{ invalid: form.errors.kode }">
                                        <BadgeCheck />
                                        <input
                                            id="kode"
                                            v-model="form.kode"
                                            maxlength="30"
                                            placeholder="Contoh: KEP-UNIT"
                                            autocomplete="off"
                                            autofocus
                                        />
                                    </div>
                                    <small>Huruf, angka, dan tanda hubung; maksimal 30 karakter.</small>
                                    <p v-if="form.errors.kode" class="job-form-error">{{ form.errors.kode }}</p>
                                </div>

                                <div class="job-form-field">
                                    <label for="nama">Nama jabatan <b>*</b></label>
                                    <div class="job-form-input" :class="{ invalid: form.errors.nama }">
                                        <BriefcaseBusiness />
                                        <input id="nama" v-model="form.nama" maxlength="150" placeholder="Contoh: Kepala Unit" autocomplete="off" />
                                    </div>
                                    <small>Gunakan nama lengkap sesuai struktur organisasi.</small>
                                    <p v-if="form.errors.nama" class="job-form-error">{{ form.errors.nama }}</p>
                                </div>

                                <div class="job-form-field full">
                                    <label for="deskripsi">Deskripsi</label>
                                    <div class="job-form-textarea" :class="{ invalid: form.errors.deskripsi }">
                                        <FileText />
                                        <textarea
                                            id="deskripsi"
                                            v-model="form.deskripsi"
                                            rows="3"
                                            maxlength="500"
                                            placeholder="Ringkas tanggung jawab atau cakupan jabatan..."
                                        ></textarea>
                                    </div>
                                    <div class="job-field-meta">
                                        <small>Opsional, membantu tim memahami fungsi jabatan.</small>
                                        <small>{{ form.deskripsi.length }}/500</small>
                                    </div>
                                    <p v-if="form.errors.deskripsi" class="job-form-error">{{ form.errors.deskripsi }}</p>
                                </div>
                            </div>
                        </section>

                        <section class="job-form-section compact" aria-labelledby="job-status-form-title">
                            <header>
                                <span class="cyan"><BadgeCheck /></span>
                                <div>
                                    <small>Ketersediaan data</small>
                                    <h3 id="job-status-form-title">Status jabatan</h3>
                                    <p>Jabatan aktif dapat digunakan pada proses operasional berikutnya.</p>
                                </div>
                            </header>
                            <button
                                type="button"
                                class="job-status-switch"
                                :class="{ checked: form.aktif }"
                                role="switch"
                                :aria-checked="form.aktif"
                                @click="form.aktif = !form.aktif"
                            >
                                <span><i></i></span>
                                <span>
                                    <strong>{{ form.aktif ? 'Jabatan aktif' : 'Jabatan nonaktif' }}</strong>
                                    <small>{{ form.aktif ? 'Tersedia untuk digunakan' : 'Disimpan, tetapi tidak ditawarkan' }}</small>
                                </span>
                                <Check v-if="form.aktif" />
                                <CircleOff v-else />
                            </button>
                            <p v-if="form.errors.aktif" class="job-form-error">{{ form.errors.aktif }}</p>
                        </section>
                    </template>

                    <section v-else class="job-form-section job-dynamic-section" aria-labelledby="job-dynamic-form-title">
                        <header>
                            <span><BriefcaseBusiness /></span>
                            <div>
                                <small>Daftar input</small>
                                <h3 id="job-dynamic-form-title">Identitas jabatan</h3>
                                <p>Setiap input akan dibuat menjadi satu data jabatan.</p>
                            </div>
                            <button type="button" class="job-add-input" :disabled="form.jabatans.length >= 25" @click="addJabatanInput">
                                <Plus /> Tambah input
                            </button>
                        </header>

                        <p v-if="form.errors.jabatans" class="job-form-error job-dynamic-root-error">{{ form.errors.jabatans }}</p>

                        <div class="job-dynamic-list">
                            <article v-for="(jabatanInput, index) in form.jabatans" :key="jabatanInput.inputKey" class="job-dynamic-item">
                                <header>
                                    <span class="job-input-number">{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div>
                                        <strong>Jabatan {{ index + 1 }}</strong>
                                        <small>{{ jabatanInput.nama.trim() || 'Input jabatan baru' }}</small>
                                    </div>
                                    <button
                                        type="button"
                                        class="job-remove-input"
                                        :disabled="form.jabatans.length === 1"
                                        :aria-label="`Hapus input jabatan ${index + 1}`"
                                        title="Hapus input"
                                        @click="removeJabatanInput(index)"
                                    >
                                        <Trash2 />
                                    </button>
                                </header>

                                <div class="job-form-grid">
                                    <div class="job-form-field">
                                        <label :for="`kode-${jabatanInput.inputKey}`">Kode jabatan <b>*</b></label>
                                        <div class="job-form-input" :class="{ invalid: inputError(index, 'kode') }">
                                            <BadgeCheck />
                                            <input
                                                :id="`kode-${jabatanInput.inputKey}`"
                                                v-model="jabatanInput.kode"
                                                maxlength="30"
                                                placeholder="Contoh: KEP-UNIT"
                                                autocomplete="off"
                                                :autofocus="index === 0"
                                            />
                                        </div>
                                        <small>Huruf, angka, dan tanda hubung.</small>
                                        <p v-if="inputError(index, 'kode')" class="job-form-error">{{ inputError(index, 'kode') }}</p>
                                    </div>

                                    <div class="job-form-field">
                                        <label :for="`nama-${jabatanInput.inputKey}`">Nama jabatan <b>*</b></label>
                                        <div class="job-form-input" :class="{ invalid: inputError(index, 'nama') }">
                                            <BriefcaseBusiness />
                                            <input
                                                :id="`nama-${jabatanInput.inputKey}`"
                                                v-model="jabatanInput.nama"
                                                maxlength="150"
                                                placeholder="Contoh: Kepala Unit"
                                                autocomplete="off"
                                            />
                                        </div>
                                        <small>Nama resmi dalam struktur organisasi.</small>
                                        <p v-if="inputError(index, 'nama')" class="job-form-error">{{ inputError(index, 'nama') }}</p>
                                    </div>

                                    <div class="job-form-field full">
                                        <label :for="`deskripsi-${jabatanInput.inputKey}`">Deskripsi</label>
                                        <div class="job-form-textarea" :class="{ invalid: inputError(index, 'deskripsi') }">
                                            <FileText />
                                            <textarea
                                                :id="`deskripsi-${jabatanInput.inputKey}`"
                                                v-model="jabatanInput.deskripsi"
                                                rows="2"
                                                maxlength="500"
                                                placeholder="Ringkas tanggung jawab atau cakupan jabatan..."
                                            ></textarea>
                                        </div>
                                        <div class="job-field-meta">
                                            <small>Opsional</small>
                                            <small>{{ jabatanInput.deskripsi.length }}/500</small>
                                        </div>
                                        <p v-if="inputError(index, 'deskripsi')" class="job-form-error">{{ inputError(index, 'deskripsi') }}</p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="job-status-switch job-status-switch-small"
                                    :class="{ checked: jabatanInput.aktif }"
                                    role="switch"
                                    :aria-checked="jabatanInput.aktif"
                                    @click="jabatanInput.aktif = !jabatanInput.aktif"
                                >
                                    <span><i></i></span>
                                    <span>
                                        <strong>{{ jabatanInput.aktif ? 'Jabatan aktif' : 'Jabatan nonaktif' }}</strong>
                                        <small>{{ jabatanInput.aktif ? 'Tersedia untuk digunakan' : 'Tidak ditawarkan untuk sementara' }}</small>
                                    </span>
                                    <Check v-if="jabatanInput.aktif" />
                                    <CircleOff v-else />
                                </button>
                                <p v-if="inputError(index, 'aktif')" class="job-form-error">{{ inputError(index, 'aktif') }}</p>
                            </article>
                        </div>

                        <button
                            type="button"
                            class="job-add-input job-add-input-bottom"
                            :disabled="form.jabatans.length >= 25"
                            @click="addJabatanInput"
                        >
                            <Plus /> Tambah input jabatan lainnya
                        </button>
                    </section>
                </div>

                <div class="admin-modal-footer job-form-footer">
                    <div>
                        <BadgeCheck />
                        <span
                            ><strong>{{ editing ? 'Perbarui dengan aman' : `${form.jabatans.length} jabatan siap disimpan` }}</strong
                            ><small>{{ editing ? 'Kolom bertanda * wajib diisi' : 'Semua input disimpan sekaligus' }}</small></span
                        >
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="closeForm">Batal</button>
                        <button type="submit" class="btn btn-primary job-submit" :disabled="form.processing || (Boolean(editing) && !form.isDirty)">
                            <LoaderCircle v-if="form.processing" class="job-spinner" />
                            <Save v-else />
                            {{ form.processing ? 'Menyimpan...' : editing ? 'Simpan perubahan' : `Simpan ${form.jabatans.length} jabatan` }}
                        </button>
                    </div>
                </div>
            </form>
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus jabatan?"
            :description="`Jabatan ${selected?.nama ?? ''} (${selected?.kode ?? ''}) akan dihapus permanen dari master data.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>

<style scoped>
.job-master-page,
.job-form {
    --job-ink: #172033;
    --job-muted: #6b7589;
    --job-line: #e8ebf2;
    --job-indigo: #6571e8;
}

.job-master-page {
    display: grid;
    gap: 20px;
    animation: job-page-enter 0.45s ease both;
}

.job-hero {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.65fr);
    min-height: 310px;
    overflow: hidden;
    padding: 42px 46px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 22px;
    color: #fff;
    background: linear-gradient(125deg, #141b33 0%, #232b56 52%, #3841a5 100%);
    box-shadow: 0 24px 55px rgba(22, 30, 72, 0.2);
}

.job-hero-orb {
    position: absolute;
    border-radius: 999px;
    filter: blur(3px);
    pointer-events: none;
}
.job-hero-orb-one {
    width: 290px;
    height: 290px;
    right: -75px;
    top: -125px;
    background: rgba(93, 224, 216, 0.16);
}
.job-hero-orb-two {
    width: 230px;
    height: 230px;
    left: 46%;
    bottom: -170px;
    background: rgba(139, 111, 255, 0.24);
}
.job-hero-grid {
    position: absolute;
    inset: 0;
    opacity: 0.1;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.25) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.25) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: linear-gradient(90deg, transparent, #000 65%);
}
.job-hero-copy,
.job-hero-side {
    position: relative;
    z-index: 1;
}
.job-hero-copy {
    align-self: center;
    max-width: 660px;
}
.job-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 15px;
    padding: 7px 11px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 999px;
    color: #cbd0ff;
    background: rgba(255, 255, 255, 0.07);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}
.job-eyebrow svg {
    width: 14px;
    height: 14px;
    color: #83e5dd;
}
.job-hero-copy h1 {
    margin: 0 0 15px;
    color: #fff;
    font-size: clamp(32px, 4vw, 49px);
    font-weight: 750;
    letter-spacing: -0.045em;
    line-height: 1.04;
}
.job-hero-copy h1 span {
    color: #8ae5df;
}
.job-hero-copy > p {
    max-width: 590px;
    margin: 0;
    color: #b9c0d8;
    font-size: 14px;
    line-height: 1.75;
}
.job-hero-trust {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-top: 22px;
}
.job-hero-trust span {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #d9dded;
    font-size: 12px;
    font-weight: 600;
}
.job-hero-trust svg {
    width: 15px;
    height: 15px;
    padding: 3px;
    border-radius: 50%;
    color: #12233c;
    background: #82e6de;
    stroke-width: 3;
}
.job-hero-side {
    display: flex;
    align-self: center;
    flex-direction: column;
    gap: 12px;
    margin-left: auto;
    width: min(100%, 355px);
}
.job-health-card {
    padding: 20px;
    border: 1px solid rgba(255, 255, 255, 0.13);
    border-radius: 17px;
    background: rgba(255, 255, 255, 0.09);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(15px);
}
.job-health-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #d9def0;
    font-size: 12px;
    font-weight: 700;
}
.job-live {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #9ce8df;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.job-live i,
.job-directory-count i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #5ce0bd;
    box-shadow: 0 0 0 4px rgba(92, 224, 189, 0.13);
}
.job-avatar-stack {
    display: flex;
    min-height: 44px;
    align-items: center;
    margin: 20px 0;
    padding-left: 6px;
}
.job-avatar,
.job-avatar-more {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    margin-left: -6px;
    border: 3px solid #303968;
    border-radius: 50%;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
}
.job-avatar.tone-1,
.job-identity-avatar.tone-1 {
    background: linear-gradient(135deg, #6577ef, #8f64d9);
}
.job-avatar.tone-2,
.job-identity-avatar.tone-2 {
    background: linear-gradient(135deg, #20b9aa, #48d0a0);
}
.job-avatar.tone-3,
.job-identity-avatar.tone-3 {
    background: linear-gradient(135deg, #e58358, #efb24f);
}
.job-avatar.tone-4,
.job-identity-avatar.tone-4 {
    background: linear-gradient(135deg, #d55c89, #a65cd8);
}
.job-avatar.tone-5,
.job-identity-avatar.tone-5 {
    background: linear-gradient(135deg, #438cd7, #4fb6cf);
}
.job-avatar-more {
    background: #fff;
    color: #4a5570;
}
.job-progress-row {
    display: flex;
    align-items: center;
    gap: 13px;
}
.job-progress-ring {
    --job-progress: 0deg;
    display: grid;
    width: 52px;
    height: 52px;
    flex: 0 0 52px;
    place-items: center;
    border-radius: 50%;
    background: conic-gradient(#72e0d4 var(--job-progress), rgba(255, 255, 255, 0.12) 0);
}
.job-progress-ring::before {
    content: '';
    grid-area: 1/1;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #303865;
}
.job-progress-ring span {
    z-index: 1;
    grid-area: 1/1;
    font-size: 10px;
    font-weight: 800;
}
.job-progress-row strong,
.job-progress-row small {
    display: block;
}
.job-progress-row strong {
    margin-bottom: 3px;
    color: #fff;
    font-size: 12px;
}
.job-progress-row small {
    color: #abb3d0;
    font-size: 10px;
}
.job-hero-action {
    display: grid;
    grid-template-columns: 39px 1fr auto;
    align-items: center;
    gap: 11px;
    width: 100%;
    padding: 12px 14px;
    border: 0;
    border-radius: 15px;
    color: #202744;
    background: #fff;
    box-shadow: 0 12px 25px rgba(4, 8, 28, 0.2);
    text-align: left;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}
.job-hero-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 17px 30px rgba(4, 8, 28, 0.27);
}
.job-hero-action > span:first-child {
    display: grid;
    width: 39px;
    height: 39px;
    place-items: center;
    border-radius: 11px;
    color: #fff;
    background: linear-gradient(135deg, #6875ea, #4fbec8);
}
.job-hero-action svg {
    width: 17px;
    height: 17px;
}
.job-hero-action > svg {
    color: #8490a7;
}
.job-hero-action strong,
.job-hero-action small {
    display: block;
}
.job-hero-action strong {
    margin-bottom: 2px;
    font-size: 12px;
}
.job-hero-action small {
    color: #7b8497;
    font-size: 10px;
}

.job-overview-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.job-overview-card {
    position: relative;
    display: flex;
    min-height: 118px;
    align-items: center;
    gap: 14px;
    overflow: hidden;
    padding: 19px;
    border: 1px solid var(--job-line);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 9px 25px rgba(30, 38, 69, 0.045);
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}
.job-overview-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 30px rgba(30, 38, 69, 0.09);
}
.job-overview-card > i {
    position: absolute;
    right: -21px;
    bottom: -27px;
    width: 82px;
    height: 82px;
    border-radius: 50%;
    opacity: 0.08;
    background: currentColor;
}
.job-overview-icon {
    display: grid;
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    place-items: center;
    border-radius: 14px;
}
.job-overview-icon svg {
    width: 21px;
    height: 21px;
}
.job-overview-copy small,
.job-overview-copy strong,
.job-overview-copy span {
    display: block;
}
.job-overview-copy small {
    color: var(--job-muted);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.055em;
    text-transform: uppercase;
}
.job-overview-copy strong {
    margin: 2px 0;
    color: var(--job-ink);
    font-size: 25px;
    line-height: 1.2;
}
.job-overview-copy span {
    color: #9299a8;
    font-size: 10px;
}
.job-overview-card.tone-indigo {
    color: #6571e8;
}
.job-overview-card.tone-indigo .job-overview-icon {
    background: #eef0ff;
}
.job-overview-card.tone-emerald {
    color: #27a687;
}
.job-overview-card.tone-emerald .job-overview-icon {
    background: #eaf9f4;
}
.job-overview-card.tone-amber {
    color: #da8a34;
}
.job-overview-card.tone-amber .job-overview-icon {
    background: #fff5e8;
}
.job-overview-card.tone-cyan {
    color: #2a9eb5;
}
.job-overview-card.tone-cyan .job-overview-icon {
    background: #eaf8fb;
}

.job-directory {
    overflow: hidden;
    border: 1px solid var(--job-line);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 13px 34px rgba(30, 38, 69, 0.055);
}
.job-directory-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    padding: 25px 27px 20px;
}
.job-section-kicker {
    color: var(--job-indigo);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}
.job-directory-header h2 {
    margin: 3px 0 4px;
    color: var(--job-ink);
    font-size: 21px;
    font-weight: 750;
    letter-spacing: -0.025em;
}
.job-directory-header p {
    margin: 0;
    color: var(--job-muted);
    font-size: 12px;
}
.job-directory-count {
    display: flex;
    align-items: flex-end;
    flex-direction: column;
    gap: 4px;
}
.job-directory-count span {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #29a183;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.job-directory-count strong {
    color: #596275;
    font-size: 11px;
}
.job-filter-bar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto auto;
    align-items: center;
    gap: 10px;
    margin: 0 27px 18px;
    padding: 8px;
    border: 1px solid #e7eaf1;
    border-radius: 14px;
    background: #f8f9fc;
}
.job-search-field {
    display: flex;
    min-height: 40px;
    align-items: center;
    gap: 9px;
    padding: 0 11px;
    color: #8992a5;
    background: #fff;
    border-radius: 10px;
    box-shadow: inset 0 0 0 1px #e9ebf1;
}
.job-search-field > svg {
    width: 17px;
    height: 17px;
}
.job-search-field input {
    width: 100%;
    border: 0;
    outline: 0;
    color: var(--job-ink);
    background: transparent;
    font-size: 12px;
    box-shadow: none;
}
.job-search-field button {
    display: grid;
    width: 25px;
    height: 25px;
    place-items: center;
    border: 0;
    border-radius: 7px;
    color: #8992a5;
    background: #f0f2f7;
}
.job-search-field button svg {
    width: 13px;
    height: 13px;
}
.job-status-filter {
    display: flex;
    gap: 3px;
    padding: 3px;
    border-radius: 10px;
    background: #eceff5;
}
.job-status-filter button {
    min-height: 34px;
    padding: 0 12px;
    border: 0;
    border-radius: 8px;
    color: #70798c;
    background: transparent;
    font-size: 10px;
    font-weight: 700;
    transition: all 0.18s ease;
}
.job-status-filter button.active {
    color: #3f4ca9;
    background: #fff;
    box-shadow: 0 3px 9px rgba(45, 54, 88, 0.1);
}
.job-filter-submit {
    display: inline-flex;
    min-height: 40px;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 0 16px;
    border: 0;
    border-radius: 10px;
    color: #fff;
    background: #5663d9;
    font-size: 11px;
    font-weight: 700;
}
.job-filter-submit svg {
    width: 14px;
    height: 14px;
}
.job-active-filter {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: -5px 27px 17px;
    padding: 9px 11px;
    border: 1px solid #e4e7fb;
    border-radius: 10px;
    background: #f7f7ff;
    color: #6b7489;
    font-size: 10px;
}
.job-active-filter > span {
    color: #5360cc;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.job-active-filter small {
    padding: 4px 7px;
    border-radius: 6px;
    background: #fff;
}
.job-active-filter button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-left: auto;
    border: 0;
    color: #5b66c9;
    background: transparent;
    font-size: 10px;
    font-weight: 700;
}
.job-active-filter button svg {
    width: 12px;
    height: 12px;
}
.job-table-view {
    overflow-x: auto;
    border-top: 1px solid #eef0f4;
}
.job-table {
    width: 100%;
    border-collapse: collapse;
}
.job-table th {
    padding: 12px 18px;
    color: #8a93a6;
    background: #fafbfc;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}
.job-table td {
    padding: 15px 18px;
    border-top: 1px solid #f0f1f5;
    color: #596276;
    font-size: 11px;
    vertical-align: middle;
}
.job-table tbody tr {
    transition: background 0.17s ease;
}
.job-table tbody tr:hover {
    background: #fafbff;
}
.job-identity {
    display: flex;
    min-width: 190px;
    align-items: center;
    gap: 11px;
}
.job-identity-avatar {
    display: grid;
    width: 39px;
    height: 39px;
    flex: 0 0 39px;
    place-items: center;
    border-radius: 12px;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    box-shadow: 0 6px 13px rgba(48, 57, 101, 0.13);
}
.job-identity strong,
.job-identity small {
    display: block;
}
.job-identity strong {
    max-width: 220px;
    overflow: hidden;
    color: var(--job-ink);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.job-identity small {
    margin-top: 3px;
    color: #a0a7b6;
    font-size: 9px;
}
.job-code {
    display: inline-flex;
    padding: 6px 9px;
    border: 1px solid #e3e6fb;
    border-radius: 7px;
    color: #5360c6;
    background: #f6f7ff;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.035em;
    white-space: nowrap;
}
.job-description {
    display: -webkit-box;
    min-width: 180px;
    max-width: 315px;
    margin: 0;
    overflow: hidden;
    color: #798195;
    font-size: 10px;
    line-height: 1.55;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}
.job-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
}
.job-status i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.job-status.active {
    color: #178068;
    background: #eaf8f3;
}
.job-status.active i {
    background: #2bb995;
    box-shadow: 0 0 0 3px rgba(43, 185, 149, 0.12);
}
.job-status.inactive {
    color: #7a8292;
    background: #f0f2f5;
}
.job-status.inactive i {
    background: #9ca3b1;
}
.job-updated {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #858da0;
    font-size: 9px;
    white-space: nowrap;
}
.job-updated svg {
    width: 13px;
    height: 13px;
    color: #9da5b5;
}
.job-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}
.job-actions button {
    display: grid;
    width: 31px;
    height: 31px;
    place-items: center;
    border: 1px solid #e2e5ee;
    border-radius: 9px;
    color: #6370cf;
    background: #fff;
    transition: all 0.18s ease;
}
.job-actions button:hover {
    color: #fff;
    border-color: #6370cf;
    background: #6370cf;
    transform: translateY(-1px);
}
.job-actions button.danger {
    color: #d45b70;
}
.job-actions button.danger:hover {
    color: #fff;
    border-color: #d85a70;
    background: #d85a70;
}
.job-actions svg {
    width: 14px;
    height: 14px;
}
.job-mobile-list {
    display: none;
}
.job-empty-state {
    display: grid;
    min-height: 330px;
    place-items: center;
    align-content: center;
    padding: 35px;
    border-top: 1px solid #eef0f4;
    text-align: center;
}
.job-empty-state > span {
    display: grid;
    width: 62px;
    height: 62px;
    place-items: center;
    margin-bottom: 13px;
    border-radius: 19px;
    color: #6471df;
    background: #f0f2ff;
}
.job-empty-state > span svg {
    width: 26px;
    height: 26px;
}
.job-empty-state > small {
    color: #6672db;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.job-empty-state h3 {
    margin: 5px 0;
    color: var(--job-ink);
    font-size: 18px;
}
.job-empty-state p {
    max-width: 410px;
    margin: 0 0 16px;
    color: #7c8496;
    font-size: 11px;
    line-height: 1.65;
}
.job-empty-state button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 14px;
    border: 0;
    border-radius: 10px;
    color: #fff;
    background: #5e6bdd;
    font-size: 10px;
    font-weight: 700;
}
.job-empty-state button svg {
    width: 14px;
    height: 14px;
}

.job-form-body {
    display: grid;
    gap: 15px;
    padding: 20px !important;
    background: #f7f8fb;
}
.job-form-preview {
    display: grid;
    grid-template-columns: 48px 1fr auto;
    align-items: center;
    gap: 13px;
    padding: 15px;
    border: 1px solid #e7e9f2;
    border-radius: 15px;
    background: linear-gradient(135deg, #fff, #f8f9ff);
    box-shadow: 0 7px 20px rgba(33, 42, 79, 0.055);
}
.job-form-preview > span:first-child {
    display: grid;
    width: 48px;
    height: 48px;
    place-items: center;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(135deg, #6874e8, #4db8c2);
}
.job-form-preview > span:first-child svg {
    width: 21px;
    height: 21px;
}
.job-form-preview div > small,
.job-form-preview strong,
.job-form-preview em {
    display: block;
}
.job-form-preview div > small {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #6772d9;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}
.job-form-preview div > small svg {
    width: 11px;
    height: 11px;
}
.job-form-preview strong {
    margin: 3px 0 1px;
    color: var(--job-ink);
    font-size: 13px;
}
.job-form-preview em {
    color: #979eae;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 9px;
    font-style: normal;
}
.job-input-count {
    padding: 6px 9px;
    border: 1px solid #dce1fb;
    border-radius: 999px;
    color: #5966d1;
    background: #f0f2ff;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
}
.job-form-section {
    padding: 18px;
    border: 1px solid #e7e9f1;
    border-radius: 15px;
    background: #fff;
}
.job-form-section > header {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    margin-bottom: 17px;
}
.job-form-section > header > span {
    display: grid;
    width: 37px;
    height: 37px;
    flex: 0 0 37px;
    place-items: center;
    border-radius: 11px;
    color: #6370d9;
    background: #eff1ff;
}
.job-form-section > header > span.cyan {
    color: #2799a8;
    background: #eaf8f8;
}
.job-form-section > header svg {
    width: 17px;
    height: 17px;
}
.job-form-section header small {
    color: #6873d5;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.job-form-section header h3 {
    margin: 2px 0;
    color: var(--job-ink);
    font-size: 14px;
}
.job-form-section header p {
    margin: 0;
    color: #8991a2;
    font-size: 9px;
}
.job-dynamic-section > header > div {
    flex: 1;
}
.job-add-input {
    display: inline-flex;
    min-height: 35px;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-left: auto;
    padding: 8px 11px;
    border: 1px solid #d9def7;
    border-radius: 9px;
    color: #5865cf;
    background: #f4f5ff;
    font-size: 9px;
    font-weight: 800;
    white-space: nowrap;
    transition: all 0.18s ease;
}
.job-add-input:hover:not(:disabled) {
    border-color: #aeb7ef;
    background: #e9ecff;
}
.job-add-input:disabled,
.job-remove-input:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
.job-add-input svg,
.job-remove-input svg {
    width: 14px;
    height: 14px;
}
.job-dynamic-root-error {
    margin: -7px 0 12px;
}
.job-dynamic-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 13px;
}
.job-dynamic-item {
    padding: 15px;
    border: 1px solid #e2e5ee;
    border-radius: 13px;
    background: #fafbfe;
}
.job-dynamic-item:only-child {
    grid-column: 1 / -1;
}
.job-dynamic-item > header {
    display: grid;
    grid-template-columns: 34px 1fr auto;
    align-items: center;
    gap: 9px;
    margin-bottom: 14px;
    padding-bottom: 11px;
    border-bottom: 1px solid #e7eaf1;
}
.job-input-number {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border-radius: 10px;
    color: #fff;
    background: linear-gradient(135deg, #6571e8, #4aafb9);
    font-size: 9px;
    font-weight: 900;
}
.job-dynamic-item > header strong,
.job-dynamic-item > header small {
    display: block;
}
.job-dynamic-item > header strong {
    color: var(--job-ink);
    font-size: 10px;
}
.job-dynamic-item > header small {
    max-width: 280px;
    overflow: hidden;
    margin-top: 2px;
    color: #9299a8;
    font-size: 8px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.job-remove-input {
    display: grid;
    width: 31px;
    height: 31px;
    place-items: center;
    border: 1px solid #f0dce1;
    border-radius: 9px;
    color: #d35d73;
    background: #fff7f8;
}
.job-remove-input:hover:not(:disabled) {
    border-color: #e9bdc7;
    background: #ffedef;
}
.job-status-switch-small {
    margin-top: 13px;
    padding: 9px 11px;
}
.job-add-input-bottom {
    width: 100%;
    margin: 13px 0 0;
    border-style: dashed;
}
.job-form-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.7fr) minmax(0, 1.3fr);
    gap: 16px;
}
.job-form-field.full {
    grid-column: 1 / -1;
}
.job-form-field label {
    display: block;
    margin-bottom: 7px;
    color: #40495d;
    font-size: 10px;
    font-weight: 750;
}
.job-form-field label b {
    color: #db6176;
}
.job-form-input,
.job-form-textarea {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #e0e4ec;
    border-radius: 10px;
    color: #9099ab;
    background: #fbfcfd;
    transition:
        border-color 0.18s ease,
        box-shadow 0.18s ease;
}
.job-form-input {
    min-height: 42px;
    padding: 0 11px;
}
.job-form-textarea {
    align-items: flex-start;
    padding: 11px;
}
.job-form-input:focus-within,
.job-form-textarea:focus-within {
    border-color: #7a84e8;
    box-shadow: 0 0 0 3px rgba(101, 113, 232, 0.11);
    background: #fff;
}
.job-form-input.invalid,
.job-form-textarea.invalid {
    border-color: #e17082;
}
.job-form-input > svg,
.job-form-textarea > svg {
    width: 15px;
    height: 15px;
    flex: 0 0 15px;
}
.job-form-input input,
.job-form-textarea textarea {
    width: 100%;
    padding: 0;
    border: 0;
    outline: 0;
    color: var(--job-ink);
    background: transparent;
    font-size: 11px;
    box-shadow: none;
    resize: vertical;
}
.job-form-textarea textarea {
    min-height: 67px;
    line-height: 1.55;
}
.job-form-field > small,
.job-field-meta small {
    display: block;
    margin-top: 6px;
    color: #9aa1b0;
    font-size: 8px;
}
.job-field-meta {
    display: flex;
    justify-content: space-between;
}
.job-form-error {
    margin: 5px 0 0;
    color: #d95069;
    font-size: 9px;
}
.job-form-section.compact > header {
    margin-bottom: 12px;
}
.job-status-switch {
    display: grid;
    grid-template-columns: 40px 1fr auto;
    align-items: center;
    gap: 11px;
    width: 100%;
    padding: 12px;
    border: 1px solid #e4e7ed;
    border-radius: 12px;
    color: #7d8595;
    background: #f8f9fb;
    text-align: left;
    transition: all 0.2s ease;
}
.job-status-switch.checked {
    color: #218f75;
    border-color: #cdece2;
    background: #f0faf6;
}
.job-status-switch > span:first-child {
    position: relative;
    width: 37px;
    height: 21px;
    border-radius: 99px;
    background: #c7ccd5;
    transition: background 0.2s ease;
}
.job-status-switch.checked > span:first-child {
    background: #36b596;
}
.job-status-switch > span:first-child i {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 15px;
    height: 15px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.16);
    transition: transform 0.2s ease;
}
.job-status-switch.checked > span:first-child i {
    transform: translateX(16px);
}
.job-status-switch strong,
.job-status-switch small {
    display: block;
}
.job-status-switch strong {
    color: var(--job-ink);
    font-size: 10px;
}
.job-status-switch small {
    margin-top: 2px;
    color: #8d95a4;
    font-size: 8px;
}
.job-status-switch > svg {
    width: 17px;
    height: 17px;
}
.job-form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 14px 20px !important;
}
.job-form-footer > div {
    display: flex;
    align-items: center;
    gap: 9px;
}
.job-form-footer > div:first-child > svg {
    width: 21px;
    height: 21px;
    color: #35a787;
}
.job-form-footer strong,
.job-form-footer small {
    display: block;
}
.job-form-footer strong {
    color: #4b5364;
    font-size: 9px;
}
.job-form-footer small {
    color: #9aa1ae;
    font-size: 8px;
}
.job-form-footer .btn {
    display: inline-flex;
    min-height: 37px;
    align-items: center;
    gap: 7px;
    border-radius: 9px;
    font-size: 10px;
    font-weight: 700;
}
.job-form-footer .btn svg {
    width: 14px;
    height: 14px;
}
.job-submit {
    border-color: #5d69db;
    background: #5d69db;
}
.job-spinner {
    animation: job-spin 0.8s linear infinite;
}

:global(.dark) .job-overview-card,
:global(.dark) .job-directory,
:global(.dark) .job-form-section,
:global(.dark) .job-form-preview {
    border-color: #30384a;
    background: #222938;
}
:global(.dark) .job-dynamic-item {
    border-color: #384154;
    background: #282f3f;
}
:global(.dark) .job-dynamic-item > header {
    border-color: #3a4355;
}
:global(.dark) .job-dynamic-item > header strong {
    color: #edf0f7;
}
:global(.dark) .job-add-input,
:global(.dark) .job-input-count {
    border-color: #424d70;
    color: #b9c0ff;
    background: #303851;
}
:global(.dark) .job-remove-input {
    border-color: #65404a;
    color: #f08da0;
    background: #422e37;
}
:global(.dark) .job-overview-copy strong,
:global(.dark) .job-directory-header h2,
:global(.dark) .job-identity strong,
:global(.dark) .job-form-preview strong,
:global(.dark) .job-form-section header h3,
:global(.dark) .job-form-field label,
:global(.dark) .job-status-switch strong {
    color: #edf0f7;
}
:global(.dark) .job-filter-bar,
:global(.dark) .job-form-body {
    border-color: #30384a;
    background: #1c2230;
}
:global(.dark) .job-search-field,
:global(.dark) .job-status-filter,
:global(.dark) .job-form-input,
:global(.dark) .job-form-textarea,
:global(.dark) .job-status-switch {
    border-color: #353d50;
    background: #282f3f;
}
:global(.dark) .job-search-field input,
:global(.dark) .job-form-input input,
:global(.dark) .job-form-textarea textarea {
    color: #eef1f7;
}
:global(.dark) .job-table th {
    background: #262d3c;
}
:global(.dark) .job-table td,
:global(.dark) .job-table-view {
    border-color: #303747;
}
:global(.dark) .job-table tbody tr:hover {
    background: #272e3e;
}

@media (max-width: 1199px) {
    .job-overview-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .job-hero {
        grid-template-columns: minmax(0, 1fr) 320px;
        padding: 35px;
    }
}

@media (max-width: 991px) {
    .job-hero {
        grid-template-columns: 1fr;
        gap: 28px;
    }
    .job-hero-side {
        margin-left: 0;
    }
    .job-filter-bar {
        grid-template-columns: 1fr auto;
    }
    .job-search-field {
        grid-column: 1 / -1;
    }
    .job-table-view {
        display: none;
    }
    .job-mobile-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        padding: 0 18px 18px;
        border-top: 1px solid #eef0f4;
    }
    .job-dynamic-list {
        grid-template-columns: 1fr;
    }
    .job-mobile-card {
        padding: 15px;
        border: 1px solid #e8eaf0;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 6px 16px rgba(30, 38, 69, 0.045);
    }
    .job-mobile-card header,
    .job-mobile-card footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .job-mobile-card p {
        min-height: 45px;
        margin: 14px 0;
        padding: 11px 0;
        border-top: 1px solid #eef0f4;
        border-bottom: 1px solid #eef0f4;
        color: #7e8697;
        font-size: 10px;
        line-height: 1.6;
    }
    .job-mobile-card footer > span {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #949baa;
        font-size: 8px;
    }
    .job-mobile-card footer > span svg {
        width: 12px;
        height: 12px;
    }
    :global(.dark) .job-mobile-card {
        border-color: #30384a;
        background: #222938;
    }
}

@media (max-width: 767px) {
    .job-hero {
        padding: 28px 24px;
        border-radius: 18px;
    }
    .job-directory-header {
        align-items: flex-start;
        flex-direction: column;
    }
    .job-directory-count {
        align-items: flex-start;
    }
    .job-mobile-list {
        grid-template-columns: 1fr;
    }
    .job-form-grid {
        grid-template-columns: 1fr;
    }
    .job-form-field.full {
        grid-column: auto;
    }
    .job-form-footer > div:first-child {
        display: none;
    }
    .job-form-footer {
        justify-content: flex-end;
    }
}

@media (max-width: 575px) {
    .job-overview-grid {
        grid-template-columns: 1fr;
    }
    .job-overview-card {
        min-height: 102px;
    }
    .job-filter-bar {
        grid-template-columns: 1fr;
        margin-right: 17px;
        margin-left: 17px;
    }
    .job-status-filter {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }
    .job-active-filter {
        align-items: flex-start;
        flex-wrap: wrap;
        margin-right: 17px;
        margin-left: 17px;
    }
    .job-active-filter button {
        margin-left: 0;
    }
    .job-form-preview {
        grid-template-columns: 44px 1fr;
    }
    .job-form-preview > .job-status {
        grid-column: 1 / -1;
        justify-self: flex-start;
    }
    .job-dynamic-summary .job-input-count {
        grid-column: 1 / -1;
        justify-self: flex-start;
    }
    .job-dynamic-section > header {
        flex-wrap: wrap;
    }
    .job-dynamic-section > header .job-add-input {
        width: 100%;
        margin-left: 48px;
    }
    .job-form-footer > div:last-child,
    .job-form-footer .btn {
        flex: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .job-master-page {
        animation: none;
    }
    .job-overview-card,
    .job-hero-action,
    .job-actions button {
        transition: none;
    }
}

@keyframes job-page-enter {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
@keyframes job-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
