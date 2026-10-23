<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { useAdminNotificationCounts } from '@/composables/useAdminNotificationCounts';
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
    badge?: number;
    badgeLabel?: string;
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
const { counts } = useAdminNotificationCounts();
const currentPath = computed(() => page.url.split('?')[0]);
const isSuperAdmin = computed(() => page.props.auth.roles.includes('super-admin'));
const can = (permission: string) => page.props.portal.can_access_admin && (isSuperAdmin.value || page.props.auth.permissions.includes(permission));
const homeUrl = computed(() => page.props.portal.admin_home_url);
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
    if (can('dashboard.view')) {
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
            badge: counts.value.unread_count + counts.value.pending_approval_count,
            badgeLabel: `${counts.value.unread_count} notifikasi belum dibaca, ${counts.value.pending_approval_count} pengajuan menunggu approval Anda`,
            children: [
                {
                    title: 'Semua Notifikasi',
                    href: '/absensi/admin-web/notifikasi/semua',
                    icon: Bell,
                    badge: counts.value.unread_count,
                    badgeLabel: 'notifikasi belum dibaca',
                },
                {
                    title: 'Pengajuan Butuh Approval',
                    href: '/absensi/admin-web/notifikasi/butuh-approval',
                    icon: Inbox,
                    badge: counts.value.pending_approval_count,
                    badgeLabel: 'pengajuan menunggu approval Anda',
                },
                { title: 'Pengajuan Disetujui', href: '/absensi/admin-web/notifikasi/disetujui', icon: CheckCircle2 },
            ].filter(
                (item) =>
                    isSuperAdmin.value ||
                    page.props.auth.permissions.includes(item.title === 'Semua Notifikasi' ? 'notifications.view' : 'cuti.view'),
            ),
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
                <span class="sidebar-brand-mark"><AppLogoIcon /></span>
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
                            :title="item.badge !== undefined ? `${item.title}: ${item.badgeLabel}` : folded ? item.title : undefined"
                            :aria-expanded="isExpanded(item.title)"
                            @click="toggleMenu(item.title)"
                        >
                            <span class="sidebar-link-icon"><component :is="item.icon" class="link-icon" /></span>
                            <span class="link-title">{{ item.title }}</span>
                            <span
                                v-if="item.badge !== undefined"
                                class="sidebar-notification-badge sidebar-parent-notification-badge"
                                :class="{ 'is-empty': item.badge === 0 }"
                                :aria-label="item.badgeLabel"
                                >{{ item.badge > 99 ? '99+' : item.badge }}</span
                            >
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
                                    <span
                                        v-if="child.badge !== undefined"
                                        class="sidebar-notification-badge"
                                        :class="{ 'is-empty': child.badge === 0 }"
                                        :aria-label="`${child.badge} ${child.badgeLabel}`"
                                        :title="`${child.badge} ${child.badgeLabel}`"
                                        >{{ child.badge > 99 ? '99+' : child.badge }}</span
                                    >
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
