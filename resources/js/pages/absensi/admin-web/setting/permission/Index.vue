<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    Check,
    Eye,
    KeyRound,
    Layers3,
    LockKeyhole,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Sparkles,
    Trash2,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmDelete from '../components/ConfirmDelete.vue';
import CrudModal from '../components/CrudModal.vue';
import FlashMessage from '../components/FlashMessage.vue';
import Pagination from '../components/Pagination.vue';
import PermissionForm from '../components/PermissionForm.vue';
import SettingNavigation from '../components/SettingNavigation.vue';
import type { Paginated } from '../types';

interface PermissionRow {
    id: number;
    name: string;
    group: string;
    roles_count: number;
    users_count: number;
    is_system: boolean;
    created_at: string | null;
}

interface PermissionOverview {
    total: number;
    in_use: number;
    system: number;
    role_assignments: number;
    user_assignments: number;
}

const props = defineProps<{
    permissions: Paginated<PermissionRow>;
    overview: PermissionOverview;
    filters: { search?: string };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Admin Web', href: '/absensi/admin-web/setting/permissions' },
    { title: 'Pengaturan', href: '/absensi/admin-web/setting/permissions' },
    { title: 'Permission', href: '/absensi/admin-web/setting/permissions' },
];

const search = ref(props.filters.search ?? '');
const selected = ref<PermissionRow | null>(null);
const deleting = ref(false);
const creating = ref(false);
const editing = ref<PermissionRow | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const assignmentTotal = computed(() => props.overview.role_assignments + props.overview.user_assignments);
const usageRate = computed(() => (props.overview.total ? Math.round((props.overview.in_use / props.overview.total) * 100) : 0));
const unusedPermissions = computed(() => Math.max(0, props.overview.total - props.overview.in_use));
const customPermissions = computed(() => Math.max(0, props.overview.total - props.overview.system));
const previewPermissions = computed(() => props.permissions.data.slice(0, 4));
const remainingPermissions = computed(() => Math.max(0, props.overview.total - previewPermissions.value.length));
const hasActiveFilters = computed(() => Boolean(search.value.trim()));
const resultSummary = computed(() => {
    if (!props.permissions.total) return 'Belum ada hasil';
    return `${props.permissions.from}-${props.permissions.to} dari ${props.permissions.total} permission`;
});

const summaryCards = computed(() => [
    {
        label: 'Total permission',
        value: props.overview.total.toLocaleString('id-ID'),
        note: 'Kewenangan terdaftar',
        icon: KeyRound,
        tone: 'indigo',
    },
    {
        label: 'Permission digunakan',
        value: props.overview.in_use.toLocaleString('id-ID'),
        note: unusedPermissions.value ? `${unusedPermissions.value} permission belum digunakan` : 'Semua permission sudah digunakan',
        icon: ShieldCheck,
        tone: 'emerald',
    },
    {
        label: 'Permission sistem',
        value: props.overview.system.toLocaleString('id-ID'),
        note: `${customPermissions.value} permission khusus`,
        icon: LockKeyhole,
        tone: 'violet',
    },
    {
        label: 'Penetapan akses',
        value: assignmentTotal.value.toLocaleString('id-ID'),
        note: `${props.overview.role_assignments} via role · ${props.overview.user_assignments} langsung`,
        icon: UsersRound,
        tone: 'cyan',
    },
]);

const permissionSubmitUrl = computed(() =>
    editing.value ? `/absensi/admin-web/setting/permissions/${editing.value.id}` : '/absensi/admin-web/setting/permissions',
);
const formatPermissionLabel = (value: string) => value.replaceAll('-', ' ').replaceAll('_', ' ').replaceAll('.', ' / ');
const avatarTone = (id: number) => `tone-${(id % 5) + 1}`;

const openCreate = () => {
    editing.value = null;
    creating.value = true;
};

const openEdit = (permission: PermissionRow) => {
    creating.value = false;
    editing.value = permission;
};

const closeForm = () => {
    creating.value = false;
    editing.value = null;
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        '/absensi/admin-web/setting/permissions',
        { search: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearSearch = () => {
    search.value = '';
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
    router.delete(`/absensi/admin-web/setting/permissions/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};
</script>

<template>
    <Head title="Manajemen Permission" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="user-management-page permission-management-page">
            <SettingNavigation />
            <FlashMessage />

            <section class="user-directory-hero" aria-labelledby="permission-directory-title">
                <span class="user-hero-orb user-hero-orb-one" aria-hidden="true"></span>
                <span class="user-hero-orb user-hero-orb-two" aria-hidden="true"></span>
                <div class="user-hero-grid" aria-hidden="true"></div>

                <div class="user-hero-copy">
                    <span class="user-hero-eyebrow"><Sparkles /> Fondasi kewenangan</span>
                    <h1 id="permission-directory-title">Permission yang jelas.<br /><span>Kontrol yang presisi.</span></h1>
                    <p>
                        Definisikan setiap kewenangan secara konsisten agar akses melalui role maupun pengguna tetap aman, mudah dipahami, dan mudah
                        diaudit.
                    </p>
                    <div class="user-hero-trust">
                        <span><Check /> Pola nama terstruktur</span>
                        <span><Check /> Permission sistem terlindungi</span>
                    </div>
                </div>

                <div class="user-hero-side">
                    <div class="user-hero-team-card">
                        <div class="user-hero-team-head">
                            <span>Permission aktif</span>
                            <span class="user-live-status"><i></i> Tersinkron</span>
                        </div>
                        <div class="user-avatar-stack" aria-label="Pratinjau permission">
                            <span
                                v-for="previewPermission in previewPermissions"
                                :key="previewPermission.id"
                                class="user-avatar-stack-item permission-stack-item"
                                :class="avatarTone(previewPermission.id)"
                                :title="formatPermissionLabel(previewPermission.name)"
                            >
                                <LockKeyhole v-if="previewPermission.is_system" />
                                <KeyRound v-else />
                            </span>
                            <span v-if="remainingPermissions" class="user-avatar-stack-more">+{{ remainingPermissions }}</span>
                        </div>
                        <div class="user-hero-progress-row">
                            <div class="user-hero-progress-ring" :style="{ '--user-progress': `${usageRate * 3.6}deg` }">
                                <span>{{ usageRate }}%</span>
                            </div>
                            <div>
                                <strong>Pemakaian permission</strong>
                                <small>{{ overview.in_use }} permission telah ditetapkan</small>
                            </div>
                        </div>
                    </div>
                    <button v-if="can.create" type="button" class="user-hero-action" @click="openCreate">
                        <span><Plus /></span>
                        <span><strong>Tambah permission</strong><small>Definisikan kewenangan baru</small></span>
                        <ArrowRight />
                    </button>
                </div>
            </section>

            <section class="user-overview-grid" aria-label="Ringkasan permission">
                <article v-for="card in summaryCards" :key="card.label" class="user-overview-card" :class="`tone-${card.tone}`">
                    <span class="user-overview-icon"><component :is="card.icon" /></span>
                    <span class="user-overview-copy">
                        <small>{{ card.label }}</small>
                        <strong>{{ card.value }}</strong>
                        <span>{{ card.note }}</span>
                    </span>
                    <i aria-hidden="true"></i>
                </article>
            </section>

            <section class="user-directory-panel" aria-labelledby="permission-list-title">
                <header class="user-directory-header">
                    <div>
                        <span class="user-section-kicker">Direktori permission</span>
                        <h2 id="permission-list-title">Kelola unit kewenangan</h2>
                        <p>Tinjau status, kelompok, dan pemakaian setiap permission.</p>
                    </div>
                    <div class="user-directory-count">
                        <span><i></i> Data terbaru</span>
                        <strong>{{ resultSummary }}</strong>
                    </div>
                </header>

                <form class="user-filter-bar permission-filter-bar" role="search" @submit.prevent="applyFilters">
                    <label class="user-search-field">
                        <Search />
                        <span class="visually-hidden">Cari permission</span>
                        <input v-model="search" type="search" placeholder="Cari nama permission..." autocomplete="off" />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="clearSearch"><X /></button>
                    </label>
                    <button type="submit" class="user-filter-submit"><Search /> Cari</button>
                    <button v-if="hasActiveFilters" type="button" class="user-filter-reset" @click="clearSearch">Reset filter</button>
                </form>

                <div v-if="hasActiveFilters" class="user-active-filters" aria-live="polite">
                    <span>Filter aktif</span>
                    <button type="button" @click="clearSearch">Pencarian: “{{ search }}” <X /></button>
                </div>

                <template v-if="permissions.data.length">
                    <div class="user-table-view">
                        <table class="user-directory-table permission-directory-table">
                            <thead>
                                <tr>
                                    <th>Identitas permission</th>
                                    <th>Status</th>
                                    <th>Grup</th>
                                    <th>Digunakan</th>
                                    <th>Dibuat</th>
                                    <th><span class="visually-hidden">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="permission in permissions.data" :key="permission.id">
                                    <td>
                                        <div class="user-identity-cell">
                                            <span class="user-directory-avatar permission-directory-avatar" :class="avatarTone(permission.id)">
                                                <LockKeyhole v-if="permission.is_system" />
                                                <KeyRound v-else />
                                            </span>
                                            <span>
                                                <strong>{{ formatPermissionLabel(permission.name) }}</strong>
                                                <small>{{ permission.name }}</small>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-status-badge" :class="{ system: permission.is_system }">
                                            <LockKeyhole v-if="permission.is_system" />
                                            <Check v-else />
                                            {{ permission.is_system ? 'Permission sistem' : 'Permission khusus' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="permission-group-badge"><Layers3 /> {{ permission.group }}</span>
                                    </td>
                                    <td>
                                        <div class="permission-assignment-list">
                                            <span class="role-user-count" :class="{ empty: !permission.roles_count }">
                                                <ShieldCheck /> {{ permission.roles_count }} role
                                            </span>
                                            <span class="role-user-count" :class="{ empty: !permission.users_count }">
                                                <UsersRound /> {{ permission.users_count }} pengguna
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="user-created-date"><CalendarDays /> {{ permission.created_at ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <div class="user-row-actions">
                                            <button
                                                v-if="can.update"
                                                type="button"
                                                :aria-label="permission.is_system ? 'Lihat permission' : 'Edit permission'"
                                                :title="permission.is_system ? 'Lihat permission' : 'Edit permission'"
                                                @click="openEdit(permission)"
                                            >
                                                <Eye v-if="permission.is_system" />
                                                <Pencil v-else />
                                            </button>
                                            <button
                                                v-if="can.delete && !permission.is_system"
                                                type="button"
                                                class="danger"
                                                aria-label="Hapus permission"
                                                title="Hapus permission"
                                                @click="selected = permission"
                                            >
                                                <Trash2 />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="user-mobile-list">
                        <article v-for="permission in permissions.data" :key="permission.id" class="user-mobile-card">
                            <header>
                                <div class="user-identity-cell">
                                    <span class="user-directory-avatar permission-directory-avatar" :class="avatarTone(permission.id)">
                                        <LockKeyhole v-if="permission.is_system" />
                                        <KeyRound v-else />
                                    </span>
                                    <span>
                                        <strong>{{ formatPermissionLabel(permission.name) }}</strong>
                                        <small>{{ permission.name }}</small>
                                    </span>
                                </div>
                                <span class="role-status-badge compact" :class="{ system: permission.is_system }">
                                    <LockKeyhole v-if="permission.is_system" />
                                    <Check v-else />
                                    {{ permission.is_system ? 'Sistem' : 'Khusus' }}
                                </span>
                            </header>
                            <div class="user-mobile-meta">
                                <span class="permission-group-badge"><Layers3 /> {{ permission.group }}</span>
                                <span class="user-created-date"><CalendarDays /> {{ permission.created_at ?? '-' }}</span>
                            </div>
                            <div class="user-mobile-access">
                                <small>Pemakaian permission</small>
                                <div class="permission-assignment-list">
                                    <span class="role-user-count" :class="{ empty: !permission.roles_count }">
                                        <ShieldCheck /> {{ permission.roles_count }} role
                                    </span>
                                    <span class="role-user-count" :class="{ empty: !permission.users_count }">
                                        <UsersRound /> {{ permission.users_count }} pengguna
                                    </span>
                                </div>
                            </div>
                            <div class="user-mobile-footer">
                                <span><KeyRound /> {{ permission.is_system ? 'Dilindungi sistem' : 'Dapat disesuaikan' }}</span>
                                <div class="user-row-actions">
                                    <button
                                        v-if="can.update"
                                        type="button"
                                        :aria-label="permission.is_system ? 'Lihat permission' : 'Edit permission'"
                                        @click="openEdit(permission)"
                                    >
                                        <Eye v-if="permission.is_system" />
                                        <Pencil v-else />
                                    </button>
                                    <button
                                        v-if="can.delete && !permission.is_system"
                                        type="button"
                                        class="danger"
                                        aria-label="Hapus permission"
                                        @click="selected = permission"
                                    >
                                        <Trash2 />
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                </template>

                <div v-else class="user-empty-state">
                    <span><KeyRound /></span>
                    <small>{{ hasActiveFilters ? 'Pencarian selesai' : 'Direktori masih kosong' }}</small>
                    <h3>{{ hasActiveFilters ? 'Permission tidak ditemukan' : 'Belum ada permission' }}</h3>
                    <p>
                        {{
                            hasActiveFilters
                                ? 'Coba gunakan kata kunci lain atau hapus filter untuk menampilkan seluruh permission.'
                                : 'Tambahkan permission pertama untuk mulai menyusun unit kewenangan aplikasi.'
                        }}
                    </p>
                    <button v-if="hasActiveFilters" type="button" @click="clearSearch">Tampilkan semua permission</button>
                    <button v-else-if="can.create" type="button" @click="openCreate"><Plus /> Tambah permission</button>
                </div>

                <Pagination :links="permissions.links" :from="permissions.from" :to="permissions.to" :total="permissions.total" />
            </section>
        </div>

        <CrudModal
            :open="creating || Boolean(editing)"
            :title="
                editing ? `${editing.is_system ? 'Detail' : 'Edit'} permission: ${formatPermissionLabel(editing.name)}` : 'Tambah permission baru'
            "
            :description="
                editing
                    ? editing.is_system
                        ? 'Tinjau identitas permission inti yang dilindungi sistem.'
                        : 'Perbarui identitas permission dengan pola penamaan yang konsisten.'
                    : 'Definisikan unit kewenangan baru dengan pola resource.aksi.'
            "
            @close="closeForm"
        >
            <PermissionForm
                v-if="creating || editing"
                :key="editing ? `edit-permission-${editing.id}` : 'create-permission'"
                :submit-url="permissionSubmitUrl"
                :initial="editing ?? undefined"
                :editing="Boolean(editing)"
                embedded
                @cancel="closeForm"
                @saved="closeForm"
            />
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus permission?"
            :description="`Permission ${formatPermissionLabel(selected?.name ?? '')} akan dilepas dari seluruh role dan pengguna, lalu dihapus permanen.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>
