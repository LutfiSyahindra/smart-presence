<script setup lang="ts">
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BriefcaseBusiness,
    ContactRound,
    HeartPulse,
    KeyRound,
    LayoutDashboard,
    Palette,
    ShieldCheck,
    Timer,
    UserCog,
    UserRound,
    UsersRound,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    folded?: boolean;
}

withDefaults(defineProps<Props>(), {
    folded: false,
});

const emit = defineEmits<{
    toggle: [];
    navigate: [];
    hover: [value: boolean];
}>();

const page = usePage<SharedData>();
const currentPath = computed(() => page.url.split('?')[0]);
const isSuperAdmin = computed(() => page.props.auth.roles.includes('super-admin'));
const can = (permission: string) => isSuperAdmin.value || page.props.auth.permissions.includes(permission);
const roleLabel = computed(() => page.props.auth.roles[0]?.replaceAll('-', ' ') ?? 'Pengguna');

const navigation = computed(() =>
    [
        {
            category: 'Utama',
            items: [{ title: 'Dashboard', href: '/dashboard', icon: LayoutDashboard }],
        },
        {
            category: 'Settings',
            items: [
                can('users.view') ? { title: 'Pengguna', href: '/absensi/admin-web/setting/users', icon: UsersRound } : null,
                can('roles.view') ? { title: 'Role', href: '/absensi/admin-web/setting/roles', icon: ShieldCheck } : null,
                can('permissions.view') ? { title: 'Permission', href: '/absensi/admin-web/setting/permissions', icon: UserCog } : null,
            ].filter((item) => item !== null),
        },
        {
            category: 'Master data',
            items: [
                can('jabatan.view') ? { title: 'Jabatan', href: '/absensi/admin-web/master-data/jabatan', icon: BriefcaseBusiness } : null,
                can('pegawai.view') ? { title: 'Pegawai', href: '/absensi/admin-web/master-data/pegawai', icon: ContactRound } : null,
                can('shift.view') ? { title: 'Shift', href: '/absensi/admin-web/master-data/shift', icon: Timer } : null,
            ].filter((item) => item !== null),
        },
        // {
        //     category: 'Pengaturan',
        //     items: [
        //         { title: 'Profil', href: '/settings/profile', icon: UserRound },
        //         { title: 'Kata Sandi', href: '/settings/password', icon: KeyRound },
        //         { title: 'Tampilan', href: '/settings/appearance', icon: Palette },
        //     ],
        // },
    ].filter((group) => group.items.length),
);

const isActive = (href: string) => currentPath.value === href || currentPath.value.startsWith(`${href}/`);
</script>

<template>
    <nav class="sidebar" aria-label="Navigasi utama">
        <div class="sidebar-header">
            <Link href="/dashboard" class="sidebar-brand premium-sidebar-brand" @click="emit('navigate')">
                <span class="sidebar-brand-mark"><HeartPulse /></span>
                <span class="sidebar-brand-copy">
                    <strong>RSARSY</strong>
                    <small>Smart Presence</small>
                </span>
            </Link>

            <button
                type="button"
                class="sidebar-toggler border-0 bg-transparent p-0"
                :class="folded ? 'active' : 'not-active'"
                :aria-label="folded ? 'Buka sidebar' : 'Tutup sidebar'"
                :aria-expanded="!folded"
                @click="emit('toggle')"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>

        <div class="sidebar-body" @mouseenter="emit('hover', true)" @mouseleave="emit('hover', false)">
            <ul class="nav">
                <template v-for="group in navigation" :key="group.category">
                    <li class="nav-item nav-category">{{ group.category }}</li>
                    <li v-for="item in group.items" :key="item.href" class="nav-item" :class="{ active: isActive(item.href) }">
                        <Link
                            :href="item.href"
                            class="nav-link"
                            :title="folded ? item.title : undefined"
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            @click="emit('navigate')"
                        >
                            <span class="sidebar-link-icon"><component :is="item.icon" class="link-icon" /></span>
                            <span class="link-title">{{ item.title }}</span>
                            <span v-if="isActive(item.href)" class="sidebar-active-dot" aria-hidden="true"></span>
                        </Link>
                    </li>
                </template>
            </ul>

            <div class="sidebar-system-card">
                <div class="sidebar-system-icon"><Activity /></div>
                <div>
                    <strong>Sistem aktif</strong>
                    <span><i></i> Layanan terhubung</span>
                </div>
            </div>

            <div class="sidebar-role-label">
                <span>Masuk sebagai</span>
                <strong>{{ roleLabel }}</strong>
            </div>
        </div>
    </nav>
</template>
