<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    Check,
    Crown,
    KeyRound,
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
import RoleForm from '../components/RoleForm.vue';
import SettingNavigation from '../components/SettingNavigation.vue';
import type { Paginated, PermissionGroup } from '../types';

interface RoleRow {
    id: number;
    name: string;
    permissions: string[];
    permission_ids: number[];
    permissions_count: number;
    users_count: number;
    is_system: boolean;
    created_at: string | null;
}

interface RoleOverview {
    total: number;
    in_use: number;
    with_permissions: number;
    user_assignments: number;
}

const props = defineProps<{
    roles: Paginated<RoleRow>;
    overview: RoleOverview;
    permissionGroups: PermissionGroup[];
    filters: { search?: string };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Admin Web', href: '/absensi/admin-web/setting/roles' },
    { title: 'Pengaturan', href: '/absensi/admin-web/setting/roles' },
    { title: 'Role', href: '/absensi/admin-web/setting/roles' },
];

const search = ref(props.filters.search ?? '');
const selected = ref<RoleRow | null>(null);
const deleting = ref(false);
const creating = ref(false);
const editing = ref<RoleRow | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const permissionTotal = computed(() => props.permissionGroups.reduce((total, group) => total + group.permissions.length, 0));
const coverageRate = computed(() => (props.overview.total ? Math.round((props.overview.with_permissions / props.overview.total) * 100) : 0));
const rolesNotInUse = computed(() => Math.max(0, props.overview.total - props.overview.in_use));
const rolesWithoutPermissions = computed(() => Math.max(0, props.overview.total - props.overview.with_permissions));
const previewRoles = computed(() => props.roles.data.slice(0, 4));
const remainingRoles = computed(() => Math.max(0, props.overview.total - previewRoles.value.length));
const hasActiveFilters = computed(() => Boolean(search.value.trim()));
const resultSummary = computed(() => {
    if (!props.roles.total) return 'Belum ada hasil';
    return `${props.roles.from}-${props.roles.to} dari ${props.roles.total} role`;
});

const summaryCards = computed(() => [
    {
        label: 'Total role',
        value: props.overview.total.toLocaleString('id-ID'),
        note: 'Kelompok akses terdaftar',
        icon: ShieldCheck,
        tone: 'indigo',
    },
    {
        label: 'Role digunakan',
        value: props.overview.in_use.toLocaleString('id-ID'),
        note: rolesNotInUse.value ? `${rolesNotInUse.value} role belum digunakan` : 'Semua role sudah digunakan',
        icon: UsersRound,
        tone: 'emerald',
    },
    {
        label: 'Role berizin',
        value: props.overview.with_permissions.toLocaleString('id-ID'),
        note: rolesWithoutPermissions.value ? `${rolesWithoutPermissions.value} role perlu ditinjau` : 'Semua role memiliki permission',
        icon: KeyRound,
        tone: 'violet',
    },
    {
        label: 'Penetapan pengguna',
        value: props.overview.user_assignments.toLocaleString('id-ID'),
        note: `${permissionTotal.value} permission tersedia`,
        icon: Check,
        tone: 'cyan',
    },
]);

const roleSubmitUrl = computed(() => (editing.value ? `/absensi/admin-web/setting/roles/${editing.value.id}` : '/absensi/admin-web/setting/roles'));
const formatAccessLabel = (value: string) => value.replaceAll('-', ' ').replaceAll('.', ' / ');
const avatarTone = (id: number) => `tone-${(id % 5) + 1}`;

const openCreate = () => {
    editing.value = null;
    creating.value = true;
};

const openEdit = (role: RoleRow) => {
    creating.value = false;
    editing.value = role;
};

const closeForm = () => {
    creating.value = false;
    editing.value = null;
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        '/absensi/admin-web/setting/roles',
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
    router.delete(`/absensi/admin-web/setting/roles/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};
</script>

<template>
    <Head title="Manajemen Role" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="user-management-page role-management-page">
            <SettingNavigation />
            <FlashMessage />

            <section class="user-directory-hero" aria-labelledby="role-directory-title">
                <span class="user-hero-orb user-hero-orb-one" aria-hidden="true"></span>
                <span class="user-hero-orb user-hero-orb-two" aria-hidden="true"></span>
                <div class="user-hero-grid" aria-hidden="true"></div>

                <div class="user-hero-copy">
                    <span class="user-hero-eyebrow"><Sparkles /> Arsitektur kewenangan</span>
                    <h1 id="role-directory-title">Role yang tertata.<br /><span>Akses yang terjaga.</span></h1>
                    <p>Susun kelompok kewenangan yang konsisten agar pemberian akses kepada setiap pengguna lebih cepat, aman, dan mudah diaudit.</p>
                    <div class="user-hero-trust">
                        <span><Check /> Permission terstruktur</span>
                        <span><Check /> Role sistem terlindungi</span>
                    </div>
                </div>

                <div class="user-hero-side">
                    <div class="user-hero-team-card">
                        <div class="user-hero-team-head">
                            <span>Role aktif</span>
                            <span class="user-live-status"><i></i> Tersinkron</span>
                        </div>
                        <div class="user-avatar-stack" aria-label="Pratinjau role">
                            <span
                                v-for="previewRole in previewRoles"
                                :key="previewRole.id"
                                class="user-avatar-stack-item role-stack-item"
                                :class="avatarTone(previewRole.id)"
                                :title="formatAccessLabel(previewRole.name)"
                            >
                                <Crown v-if="previewRole.is_system" />
                                <ShieldCheck v-else />
                            </span>
                            <span v-if="remainingRoles" class="user-avatar-stack-more">+{{ remainingRoles }}</span>
                        </div>
                        <div class="user-hero-progress-row">
                            <div class="user-hero-progress-ring" :style="{ '--user-progress': `${coverageRate * 3.6}deg` }">
                                <span>{{ coverageRate }}%</span>
                            </div>
                            <div>
                                <strong>Kesiapan role</strong>
                                <small>{{ overview.with_permissions }} role telah memiliki permission</small>
                            </div>
                        </div>
                    </div>
                    <button v-if="can.create" type="button" class="user-hero-action" @click="openCreate">
                        <span><Plus /></span>
                        <span><strong>Tambah role</strong><small>Buat kelompok dan atur permission</small></span>
                        <ArrowRight />
                    </button>
                </div>
            </section>

            <section class="user-overview-grid" aria-label="Ringkasan role">
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

            <section class="user-directory-panel" aria-labelledby="role-list-title">
                <header class="user-directory-header">
                    <div>
                        <span class="user-section-kicker">Direktori role</span>
                        <h2 id="role-list-title">Kelola kelompok akses</h2>
                        <p>Tinjau penggunaan role dan cakupan permission yang diberikan.</p>
                    </div>
                    <div class="user-directory-count">
                        <span><i></i> Data terbaru</span>
                        <strong>{{ resultSummary }}</strong>
                    </div>
                </header>

                <form class="user-filter-bar role-filter-bar" role="search" @submit.prevent="applyFilters">
                    <label class="user-search-field">
                        <Search />
                        <span class="visually-hidden">Cari role</span>
                        <input v-model="search" type="search" placeholder="Cari nama role..." autocomplete="off" />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="clearSearch"><X /></button>
                    </label>
                    <button type="submit" class="user-filter-submit"><Search /> Cari</button>
                    <button v-if="hasActiveFilters" type="button" class="user-filter-reset" @click="clearSearch">Reset filter</button>
                </form>

                <div v-if="hasActiveFilters" class="user-active-filters" aria-live="polite">
                    <span>Filter aktif</span>
                    <button type="button" @click="clearSearch">Pencarian: “{{ search }}” <X /></button>
                </div>

                <template v-if="roles.data.length">
                    <div class="user-table-view">
                        <table class="user-directory-table role-directory-table">
                            <thead>
                                <tr>
                                    <th>Identitas role</th>
                                    <th>Status</th>
                                    <th>Pengguna</th>
                                    <th>Permission</th>
                                    <th>Dibuat</th>
                                    <th><span class="visually-hidden">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="role in roles.data" :key="role.id">
                                    <td>
                                        <div class="user-identity-cell">
                                            <span class="user-directory-avatar role-directory-avatar" :class="avatarTone(role.id)">
                                                <Crown v-if="role.is_system" />
                                                <ShieldCheck v-else />
                                            </span>
                                            <span>
                                                <strong>{{ formatAccessLabel(role.name) }}</strong>
                                                <small>{{ role.name }}</small>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-status-badge" :class="{ system: role.is_system }">
                                            <Crown v-if="role.is_system" />
                                            <Check v-else />
                                            {{ role.is_system ? 'Role sistem' : 'Role khusus' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="role-user-count" :class="{ empty: !role.users_count }">
                                            <UsersRound />
                                            {{ role.users_count }} pengguna
                                        </span>
                                    </td>
                                    <td>
                                        <details v-if="role.permissions.length" class="user-permission-details">
                                            <summary><KeyRound /> {{ role.permissions_count }} permission</summary>
                                            <div>
                                                <span v-for="permission in role.permissions" :key="permission">{{
                                                    formatAccessLabel(permission)
                                                }}</span>
                                            </div>
                                        </details>
                                        <span v-else class="user-empty-access">Belum ada permission</span>
                                    </td>
                                    <td>
                                        <span class="user-created-date"><CalendarDays /> {{ role.created_at ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <div class="user-row-actions">
                                            <button v-if="can.update" type="button" aria-label="Edit role" title="Edit role" @click="openEdit(role)">
                                                <Pencil />
                                            </button>
                                            <button
                                                v-if="can.delete && !role.is_system"
                                                type="button"
                                                class="danger"
                                                aria-label="Hapus role"
                                                title="Hapus role"
                                                @click="selected = role"
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
                        <article v-for="role in roles.data" :key="role.id" class="user-mobile-card">
                            <header>
                                <div class="user-identity-cell">
                                    <span class="user-directory-avatar role-directory-avatar" :class="avatarTone(role.id)">
                                        <Crown v-if="role.is_system" />
                                        <ShieldCheck v-else />
                                    </span>
                                    <span>
                                        <strong>{{ formatAccessLabel(role.name) }}</strong>
                                        <small>{{ role.name }}</small>
                                    </span>
                                </div>
                                <span class="role-status-badge compact" :class="{ system: role.is_system }">
                                    <Crown v-if="role.is_system" />
                                    <Check v-else />
                                    {{ role.is_system ? 'Sistem' : 'Khusus' }}
                                </span>
                            </header>
                            <div class="user-mobile-meta">
                                <span class="role-user-count" :class="{ empty: !role.users_count }"
                                    ><UsersRound /> {{ role.users_count }} pengguna</span
                                >
                                <span class="user-created-date"><CalendarDays /> {{ role.created_at ?? '-' }}</span>
                            </div>
                            <div class="user-mobile-access">
                                <small>Permission role</small>
                                <details v-if="role.permissions.length" class="user-permission-details">
                                    <summary><KeyRound /> {{ role.permissions_count }} permission</summary>
                                    <div>
                                        <span v-for="permission in role.permissions" :key="permission">{{ formatAccessLabel(permission) }}</span>
                                    </div>
                                </details>
                                <span v-else class="user-empty-access">Belum ada permission</span>
                            </div>
                            <div class="user-mobile-footer">
                                <span><ShieldCheck /> {{ role.is_system ? 'Dilindungi sistem' : 'Dapat disesuaikan' }}</span>
                                <div class="user-row-actions">
                                    <button v-if="can.update" type="button" aria-label="Edit role" @click="openEdit(role)"><Pencil /></button>
                                    <button
                                        v-if="can.delete && !role.is_system"
                                        type="button"
                                        class="danger"
                                        aria-label="Hapus role"
                                        @click="selected = role"
                                    >
                                        <Trash2 />
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                </template>

                <div v-else class="user-empty-state">
                    <span><ShieldCheck /></span>
                    <small>{{ hasActiveFilters ? 'Pencarian selesai' : 'Direktori masih kosong' }}</small>
                    <h3>{{ hasActiveFilters ? 'Role tidak ditemukan' : 'Belum ada role' }}</h3>
                    <p>
                        {{
                            hasActiveFilters
                                ? 'Coba gunakan kata kunci lain atau hapus filter untuk menampilkan seluruh role.'
                                : 'Tambahkan role pertama untuk mulai mengelompokkan permission dan kewenangan pengguna.'
                        }}
                    </p>
                    <button v-if="hasActiveFilters" type="button" @click="clearSearch">Tampilkan semua role</button>
                    <button v-else-if="can.create" type="button" @click="openCreate"><Plus /> Tambah role</button>
                </div>

                <Pagination :links="roles.links" :from="roles.from" :to="roles.to" :total="roles.total" />
            </section>
        </div>

        <CrudModal
            :open="creating || Boolean(editing)"
            :title="editing ? `Edit role: ${formatAccessLabel(editing.name)}` : 'Tambah role baru'"
            :description="
                editing ? 'Perbarui identitas dan cakupan permission role dengan aman.' : 'Buat kelompok akses dan tentukan permission awal role.'
            "
            @close="closeForm"
        >
            <RoleForm
                v-if="creating || editing"
                :key="editing ? `edit-role-${editing.id}` : 'create-role'"
                :permission-groups="permissionGroups"
                :submit-url="roleSubmitUrl"
                :initial="editing ?? undefined"
                :editing="Boolean(editing)"
                embedded
                @cancel="closeForm"
                @saved="closeForm"
            />
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus role?"
            :description="`Role ${formatAccessLabel(selected?.name ?? '')} akan dilepas dari seluruh pengguna dan dihapus permanen.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>
