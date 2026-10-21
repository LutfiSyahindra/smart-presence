<script setup lang="ts">
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Bell,
    BriefcaseBusiness,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    ContactRound,
    GitPullRequestArrow,
    HeartPulse,
    Inbox,
    LayoutDashboard,
    MapPinned,
    ShieldCheck,
    SlidersHorizontal,
    Timer,
    UserCog,
    UserRoundCheck,
    UsersRound,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';

interface NavigationItem {
    title: string;
    icon: Component;
    href?: string;
    children?: NavigationItem[];
    exact?: boolean;
}

interface NavigationGroup {
    category: string;
    items: NavigationItem[];
}

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
const can = (permission: string) => page.props.portal.can_access_admin && (isSuperAdmin.value || page.props.auth.permissions.includes(permission));
const homeUrl = computed(() =>
    page.props.portal.can_access_admin ? '/absensi/admin-web/dashboard' : '/absensi/admin-web/notifikasi/butuh-approval',
);
const roleLabel = computed(() => page.props.auth.roles[0]?.replaceAll('-', ' ') ?? 'Pengguna');

const navigation = computed<NavigationGroup[]>(() => {
    const presensiChildren: NavigationItem[] = [];
    if (can('presensi.view')) {
        presensiChildren.push({ title: 'Presensi Pegawai', href: '/absensi/admin-web/presensi/pegawai', icon: CalendarDays });
    }
    if (can('presensi.manage')) {
        presensiChildren.push({ title: 'Set Presensi Pegawai', href: '/absensi/admin-web/presensi/set-pegawai', icon: SlidersHorizontal });
    }

    const mainItems: NavigationItem[] = [];
    if (page.props.portal.can_access_admin) {
        mainItems.push({ title: 'Dashboard', href: '/absensi/admin-web/dashboard', icon: LayoutDashboard });
    }
    if (presensiChildren.length) {
        mainItems.push({ title: 'Presensi', icon: CalendarDays, children: presensiChildren });
    }
    if (can('approval-line.view')) {
        mainItems.push({ title: 'Aproval Line', href: '/absensi/admin-web/approval-line', icon: GitPullRequestArrow });
    }
    if (page.props.portal.can_access_cuti) {
        mainItems.push({
            title: 'Notifikasi',
            icon: Bell,
            children: [
                { title: 'Semua Notifikasi', href: '/absensi/admin-web/notifikasi/semua', icon: Bell },
                { title: 'Pengajuan Butuh Approval', href: '/absensi/admin-web/notifikasi/butuh-approval', icon: Inbox },
                { title: 'Pengajuan Disetujui', href: '/absensi/admin-web/notifikasi/disetujui', icon: CheckCircle2 },
            ],
        });
    }

    const shiftChildren: NavigationItem[] = [];
    if (can('shift.view')) {
        shiftChildren.push(
            { title: 'Shift', href: '/absensi/admin-web/master-data/shift', icon: Timer, exact: true },
            { title: 'Assign Shift', href: '/absensi/admin-web/master-data/shift/assign', icon: UserRoundCheck },
        );
    }

    return [
        {
            category: 'Utama',
            items: mainItems,
        },
        {
            category: 'Settings',
            items: [
                can('users.view') ? { title: 'Pengguna', href: '/absensi/admin-web/setting/users', icon: UsersRound } : null,
                can('roles.view') ? { title: 'Role', href: '/absensi/admin-web/setting/roles', icon: ShieldCheck } : null,
                can('permissions.view') ? { title: 'Permission', href: '/absensi/admin-web/setting/permissions', icon: UserCog } : null,
            ].filter((item): item is NonNullable<typeof item> => item !== null),
        },
        {
            category: 'Master data',
            items: [
                can('jabatan.view') ? { title: 'Unit dan Jabatan', href: '/absensi/admin-web/master-data/jabatan', icon: BriefcaseBusiness } : null,
                can('pegawai.view') ? { title: 'Pegawai', href: '/absensi/admin-web/master-data/pegawai', icon: ContactRound } : null,
                can('master-cuti.view') ? { title: 'Master Cuti', href: '/absensi/admin-web/master-data/cuti', icon: CalendarDays } : null,
                shiftChildren.length ? { title: 'Shift', icon: Timer, children: shiftChildren } : null,
                can('geolocation.view') ? { title: 'Geolokasi', href: '/absensi/admin-web/master-data/geolocation', icon: MapPinned } : null,
            ].filter((item): item is NonNullable<typeof item> => item !== null),
        },
        // {
        //     category: 'Pengaturan',
        //     items: [
        //         { title: 'Profil', href: '/settings/profile', icon: UserRound },
        //         { title: 'Kata Sandi', href: '/settings/password', icon: KeyRound },
        //         { title: 'Tampilan', href: '/settings/appearance', icon: Palette },
        //     ],
        // },
    ].filter((group) => group.items.length);
});

const isActive = (href: string, exact = false) => currentPath.value === href || (!exact && currentPath.value.startsWith(`${href}/`));
const isItemActive = (item: NavigationItem) => Boolean(item.href && isActive(item.href, item.exact));
const isParentActive = (item: NavigationItem) => item.children?.some((child) => isItemActive(child)) ?? false;
const expandedMenus = ref<string[]>([
    ...(currentPath.value.startsWith('/absensi/admin-web/presensi') ? ['Presensi'] : []),
    ...(currentPath.value.startsWith('/absensi/admin-web/notifikasi') ? ['Notifikasi'] : []),
    ...(currentPath.value.startsWith('/absensi/admin-web/master-data/shift') ? ['Shift'] : []),
]);
const isExpanded = (title: string) => expandedMenus.value.includes(title);
const toggleMenu = (title: string) => {
    expandedMenus.value = isExpanded(title) ? expandedMenus.value.filter((item) => item !== title) : [...expandedMenus.value, title];
};

watch(currentPath, () => {
    navigation.value.forEach((group) => {
        group.items.forEach((item) => {
            if (isParentActive(item) && !isExpanded(item.title)) expandedMenus.value = [...expandedMenus.value, item.title];
        });
    });
});
</script>

<template>
    <nav class="sidebar" aria-label="Navigasi utama">
        <div class="sidebar-header">
            <Link :href="homeUrl" class="sidebar-brand premium-sidebar-brand" @click="emit('navigate')">
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
                    <li
                        v-for="item in group.items"
                        :key="item.href ?? item.title"
                        class="nav-item"
                        :class="{ active: isItemActive(item), 'parent-active': isParentActive(item), expanded: isExpanded(item.title) }"
                    >
                        <button
                            v-if="item.children"
                            type="button"
                            class="nav-link sidebar-menu-toggle"
                            :title="folded ? item.title : undefined"
                            :aria-expanded="isExpanded(item.title)"
                            @click="toggleMenu(item.title)"
                        >
                            <span class="sidebar-link-icon"><component :is="item.icon" class="link-icon" /></span>
                            <span class="link-title">{{ item.title }}</span>
                            <ChevronDown class="sidebar-menu-arrow" aria-hidden="true" />
                        </button>
                        <Link
                            v-else-if="item.href"
                            :href="item.href"
                            class="nav-link"
                            :title="folded ? item.title : undefined"
                            :aria-current="isItemActive(item) ? 'page' : undefined"
                            @click="emit('navigate')"
                        >
                            <span class="sidebar-link-icon"><component :is="item.icon" class="link-icon" /></span>
                            <span class="link-title">{{ item.title }}</span>
                            <span v-if="isItemActive(item)" class="sidebar-active-dot" aria-hidden="true"></span>
                        </Link>

                        <ul v-if="item.children && isExpanded(item.title)" class="sidebar-submenu">
                            <li v-for="child in item.children" :key="child.href" :class="{ active: isItemActive(child) }">
                                <Link
                                    v-if="child.href"
                                    :href="child.href"
                                    :aria-current="isItemActive(child) ? 'page' : undefined"
                                    @click="emit('navigate')"
                                >
                                    <span class="sidebar-submenu-dot" aria-hidden="true"></span>
                                    <span>{{ child.title }}</span>
                                </Link>
                            </li>
                        </ul>
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
