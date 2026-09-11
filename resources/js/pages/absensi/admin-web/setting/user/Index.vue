<script setup lang="ts">
import { getInitials } from '@/composables/useInitials';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    CalendarDays,
    Check,
    KeyRound,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Sparkles,
    Trash2,
    UserCheck,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmDelete from '../components/ConfirmDelete.vue';
import CrudModal from '../components/CrudModal.vue';
import FlashMessage from '../components/FlashMessage.vue';
import Pagination from '../components/Pagination.vue';
import SettingNavigation from '../components/SettingNavigation.vue';
import UserForm from '../components/UserForm.vue';
import type { Paginated, PermissionGroup, SelectOption } from '../types';

interface UserRow {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    roles: string[];
    direct_permissions: string[];
    role_ids: number[];
    permission_ids: number[];
    created_at: string | null;
}

interface UserOverview {
    total: number;
    verified: number;
    with_roles: number;
    with_direct_permissions: number;
}

const props = defineProps<{
    users: Paginated<UserRow>;
    overview: UserOverview;
    roles: SelectOption[];
    permissionGroups: PermissionGroup[];
    filters: { search?: string; role?: string };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Admin Web', href: '/absensi/admin-web/setting/users' },
    { title: 'Pengaturan', href: '/absensi/admin-web/setting/users' },
    { title: 'Pengguna', href: '/absensi/admin-web/setting/users' },
];

const page = usePage<SharedData>();
const search = ref(props.filters.search ?? '');
const role = ref(props.filters.role ?? '');
const selected = ref<UserRow | null>(null);
const deleting = ref(false);
const creating = ref(false);
const editing = ref<UserRow | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const verifiedRate = computed(() => (props.overview.total ? Math.round((props.overview.verified / props.overview.total) * 100) : 0));
const usersWithoutRole = computed(() => Math.max(0, props.overview.total - props.overview.with_roles));
const previewUsers = computed(() => props.users.data.slice(0, 4));
const remainingUsers = computed(() => Math.max(0, props.overview.total - previewUsers.value.length));
const permissionTotal = computed(() => props.permissionGroups.reduce((total, group) => total + group.permissions.length, 0));
const hasActiveFilters = computed(() => Boolean(search.value.trim() || role.value));
const resultSummary = computed(() => {
    if (!props.users.total) return 'Belum ada hasil';
    return `${props.users.from}-${props.users.to} dari ${props.users.total} pengguna`;
});

const summaryCards = computed(() => [
    {
        label: 'Total pengguna',
        value: props.overview.total.toLocaleString('id-ID'),
        note: 'Akun terdaftar di sistem',
        icon: UsersRound,
        tone: 'indigo',
    },
    {
        label: 'Email terverifikasi',
        value: props.overview.verified.toLocaleString('id-ID'),
        note: `${verifiedRate.value}% dari seluruh akun`,
        icon: BadgeCheck,
        tone: 'emerald',
    },
    {
        label: 'Memiliki role',
        value: props.overview.with_roles.toLocaleString('id-ID'),
        note: usersWithoutRole.value ? `${usersWithoutRole.value} akun perlu ditinjau` : 'Semua akun memiliki role',
        icon: ShieldCheck,
        tone: 'violet',
    },
    {
        label: 'Akses langsung',
        value: props.overview.with_direct_permissions.toLocaleString('id-ID'),
        note: `${permissionTotal.value} permission tersedia`,
        icon: KeyRound,
        tone: 'cyan',
    },
]);

const userFormInitial = computed(() =>
    editing.value
        ? {
              name: editing.value.name,
              email: editing.value.email,
              role_ids: editing.value.role_ids,
              permission_ids: editing.value.permission_ids,
          }
        : undefined,
);
const userSubmitUrl = computed(() => (editing.value ? `/absensi/admin-web/setting/users/${editing.value.id}` : '/absensi/admin-web/setting/users'));

const formatAccessLabel = (value: string) => value.replaceAll('-', ' ').replaceAll('.', ' / ');
const avatarTone = (id: number) => `tone-${(id % 5) + 1}`;

const openCreate = () => {
    editing.value = null;
    creating.value = true;
};

const openEdit = (user: UserRow) => {
    creating.value = false;
    editing.value = user;
};

const closeForm = () => {
    creating.value = false;
    editing.value = null;
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(
        '/absensi/admin-web/setting/users',
        { search: search.value.trim() || undefined, role: role.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearSearch = () => {
    search.value = '';
    applyFilters();
};

const clearFilters = () => {
    search.value = '';
    role.value = '';
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
    router.delete(`/absensi/admin-web/setting/users/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};
</script>

<template>
    <Head title="Manajemen Pengguna" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="user-management-page">
            <SettingNavigation />
            <FlashMessage />

            <section class="user-directory-hero" aria-labelledby="user-directory-title">
                <span class="user-hero-orb user-hero-orb-one" aria-hidden="true"></span>
                <span class="user-hero-orb user-hero-orb-two" aria-hidden="true"></span>
                <div class="user-hero-grid" aria-hidden="true"></div>

                <div class="user-hero-copy">
                    <span class="user-hero-eyebrow"><Sparkles /> Pusat kontrol akses</span>
                    <h1 id="user-directory-title">Tim yang tepat.<br /><span>Akses yang presisi.</span></h1>
                    <p>Kelola identitas, role, dan permission setiap anggota dari satu direktori yang aman dan mudah dipantau.</p>
                    <div class="user-hero-trust">
                        <span><Check /> Akses berbasis role</span>
                        <span><Check /> Status akun transparan</span>
                    </div>
                </div>

                <div class="user-hero-side">
                    <div class="user-hero-team-card">
                        <div class="user-hero-team-head">
                            <span>Direktori aktif</span>
                            <span class="user-live-status"><i></i> Terhubung</span>
                        </div>
                        <div class="user-avatar-stack" aria-label="Pratinjau pengguna">
                            <span
                                v-for="previewUser in previewUsers"
                                :key="previewUser.id"
                                class="user-avatar-stack-item"
                                :class="avatarTone(previewUser.id)"
                                :title="previewUser.name"
                            >
                                {{ getInitials(previewUser.name) }}
                            </span>
                            <span v-if="remainingUsers" class="user-avatar-stack-more">+{{ remainingUsers }}</span>
                        </div>
                        <div class="user-hero-progress-row">
                            <div class="user-hero-progress-ring" :style="{ '--user-progress': `${verifiedRate * 3.6}deg` }">
                                <span>{{ verifiedRate }}%</span>
                            </div>
                            <div>
                                <strong>Kesiapan identitas</strong>
                                <small>{{ overview.verified }} akun telah memverifikasi email</small>
                            </div>
                        </div>
                    </div>
                    <button v-if="can.create" type="button" class="user-hero-action" @click="openCreate">
                        <span><Plus /></span>
                        <span><strong>Tambah pengguna</strong><small>Buat akun dan atur akses</small></span>
                        <ArrowRight />
                    </button>
                </div>
            </section>

            <section class="user-overview-grid" aria-label="Ringkasan pengguna">
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

            <section class="user-directory-panel" aria-labelledby="user-list-title">
                <header class="user-directory-header">
                    <div>
                        <span class="user-section-kicker">Direktori pengguna</span>
                        <h2 id="user-list-title">Kelola anggota tim</h2>
                        <p>Tinjau identitas dan kewenangan setiap pengguna.</p>
                    </div>
                    <div class="user-directory-count">
                        <span><i></i> Data terbaru</span>
                        <strong>{{ resultSummary }}</strong>
                    </div>
                </header>

                <form class="user-filter-bar" role="search" @submit.prevent="applyFilters">
                    <label class="user-search-field">
                        <Search />
                        <span class="visually-hidden">Cari pengguna</span>
                        <input v-model="search" type="search" placeholder="Cari nama atau alamat email..." autocomplete="off" />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="clearSearch"><X /></button>
                    </label>
                    <label class="user-role-filter">
                        <ShieldCheck />
                        <span class="visually-hidden">Filter berdasarkan role</span>
                        <select v-model="role" @change="applyFilters">
                            <option value="">Semua role</option>
                            <option v-for="option in roles" :key="option.id" :value="option.name">{{ formatAccessLabel(option.name) }}</option>
                        </select>
                    </label>
                    <button type="submit" class="user-filter-submit"><Search /> Cari</button>
                    <button v-if="hasActiveFilters" type="button" class="user-filter-reset" @click="clearFilters">Reset filter</button>
                </form>

                <div v-if="hasActiveFilters" class="user-active-filters" aria-live="polite">
                    <span>Filter aktif</span>
                    <button v-if="search" type="button" @click="clearSearch">Pencarian: “{{ search }}” <X /></button>
                    <button
                        v-if="role"
                        type="button"
                        @click="
                            role = '';
                            applyFilters();
                        "
                    >
                        Role: {{ formatAccessLabel(role) }} <X />
                    </button>
                </div>

                <template v-if="users.data.length">
                    <div class="user-table-view">
                        <table class="user-directory-table">
                            <thead>
                                <tr>
                                    <th>Identitas pengguna</th>
                                    <th>Status</th>
                                    <th>Role</th>
                                    <th>Akses langsung</th>
                                    <th>Bergabung</th>
                                    <th><span class="visually-hidden">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in users.data" :key="user.id">
                                    <td>
                                        <div class="user-identity-cell">
                                            <span class="user-directory-avatar" :class="avatarTone(user.id)">{{ getInitials(user.name) }}</span>
                                            <span>
                                                <strong>{{ user.name }}</strong>
                                                <small>{{ user.email }}</small>
                                            </span>
                                            <span v-if="user.id === page.props.auth.user.id" class="user-self-badge">Anda</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="user-verification-badge" :class="{ verified: user.email_verified_at }">
                                            <BadgeCheck v-if="user.email_verified_at" />
                                            <span v-else class="user-status-dot"></span>
                                            {{ user.email_verified_at ? 'Terverifikasi' : 'Belum verifikasi' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div v-if="user.roles.length" class="user-role-list">
                                            <span v-for="item in user.roles" :key="item"><ShieldCheck /> {{ formatAccessLabel(item) }}</span>
                                        </div>
                                        <span v-else class="user-empty-access">Belum ada role</span>
                                    </td>
                                    <td>
                                        <details v-if="user.direct_permissions.length" class="user-permission-details">
                                            <summary><KeyRound /> {{ user.direct_permissions.length }} permission</summary>
                                            <div>
                                                <span v-for="item in user.direct_permissions" :key="item">{{ formatAccessLabel(item) }}</span>
                                            </div>
                                        </details>
                                        <span v-else class="user-inherited-access"><Check /> Mengikuti role</span>
                                    </td>
                                    <td>
                                        <span class="user-created-date"><CalendarDays /> {{ user.created_at ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <div class="user-row-actions">
                                            <button
                                                v-if="can.update"
                                                type="button"
                                                aria-label="Edit pengguna"
                                                title="Edit pengguna"
                                                @click="openEdit(user)"
                                            >
                                                <Pencil />
                                            </button>
                                            <button
                                                v-if="can.delete && user.id !== page.props.auth.user.id"
                                                type="button"
                                                class="danger"
                                                aria-label="Hapus pengguna"
                                                title="Hapus pengguna"
                                                @click="selected = user"
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
                        <article v-for="user in users.data" :key="user.id" class="user-mobile-card">
                            <header>
                                <div class="user-identity-cell">
                                    <span class="user-directory-avatar" :class="avatarTone(user.id)">{{ getInitials(user.name) }}</span>
                                    <span>
                                        <strong>{{ user.name }}</strong>
                                        <small>{{ user.email }}</small>
                                    </span>
                                </div>
                                <span v-if="user.id === page.props.auth.user.id" class="user-self-badge">Anda</span>
                            </header>
                            <div class="user-mobile-meta">
                                <span class="user-verification-badge" :class="{ verified: user.email_verified_at }">
                                    <BadgeCheck v-if="user.email_verified_at" />
                                    <span v-else class="user-status-dot"></span>
                                    {{ user.email_verified_at ? 'Terverifikasi' : 'Belum verifikasi' }}
                                </span>
                                <span class="user-created-date"><CalendarDays /> {{ user.created_at ?? '-' }}</span>
                            </div>
                            <div class="user-mobile-access">
                                <small>Role pengguna</small>
                                <div v-if="user.roles.length" class="user-role-list">
                                    <span v-for="item in user.roles" :key="item"><ShieldCheck /> {{ formatAccessLabel(item) }}</span>
                                </div>
                                <span v-else class="user-empty-access">Belum ada role</span>
                            </div>
                            <div class="user-mobile-footer">
                                <span><KeyRound /> {{ user.direct_permissions.length }} akses langsung</span>
                                <div class="user-row-actions">
                                    <button v-if="can.update" type="button" aria-label="Edit pengguna" @click="openEdit(user)"><Pencil /></button>
                                    <button
                                        v-if="can.delete && user.id !== page.props.auth.user.id"
                                        type="button"
                                        class="danger"
                                        aria-label="Hapus pengguna"
                                        @click="selected = user"
                                    >
                                        <Trash2 />
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                </template>

                <div v-else class="user-empty-state">
                    <span><UserCheck /></span>
                    <small>{{ hasActiveFilters ? 'Pencarian selesai' : 'Direktori masih kosong' }}</small>
                    <h3>{{ hasActiveFilters ? 'Pengguna tidak ditemukan' : 'Belum ada pengguna' }}</h3>
                    <p>
                        {{
                            hasActiveFilters
                                ? 'Coba gunakan kata kunci lain atau hapus filter untuk menampilkan seluruh pengguna.'
                                : 'Tambahkan akun pertama untuk mulai mengatur anggota dan hak akses.'
                        }}
                    </p>
                    <button v-if="hasActiveFilters" type="button" @click="clearFilters">Tampilkan semua pengguna</button>
                    <button v-else-if="can.create" type="button" @click="openCreate"><Plus /> Tambah pengguna</button>
                </div>

                <Pagination :links="users.links" :from="users.from" :to="users.to" :total="users.total" />
            </section>
        </div>

        <CrudModal
            :open="creating || Boolean(editing)"
            :title="editing ? `Edit pengguna: ${editing.name}` : 'Tambah pengguna baru'"
            :description="editing ? 'Perbarui identitas dan kewenangan akun dengan aman.' : 'Buat identitas akun dan tentukan akses awal pengguna.'"
            @close="closeForm"
        >
            <UserForm
                v-if="creating || editing"
                :key="editing ? `edit-user-${editing.id}` : 'create-user'"
                :roles="roles"
                :permission-groups="permissionGroups"
                :submit-url="userSubmitUrl"
                :initial="userFormInitial"
                :editing="Boolean(editing)"
                embedded
                @cancel="closeForm"
                @saved="closeForm"
            />
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus pengguna?"
            :description="`Akun ${selected?.name ?? ''} akan dihapus permanen beserta seluruh relasi aksesnya.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>
