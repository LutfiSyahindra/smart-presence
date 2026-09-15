<script setup lang="ts">
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    BriefcaseBusiness,
    CalendarDays,
    ChevronDown,
    ContactRound,
    HeartPulse,
    LayoutDashboard,
    MapPinned,
    ShieldCheck,
    SlidersHorizontal,
    Timer,
    UserCog,
    UsersRound,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';

interface NavigationItem {
    title: string;
    icon: Component;
    href?: string;
    children?: NavigationItem[];
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
const can = (permission: string) => isSuperAdmin.value || page.props.auth.permissions.includes(permission);
const roleLabel = computed(() => page.props.auth.roles[0]?.replaceAll('-', ' ') ?? 'Pengguna');

const navigation = computed<NavigationGroup[]>(() => {
    const presensiChildren: NavigationItem[] = [];
    if (can('presensi.view')) {
        presensiChildren.push({ title: 'Presensi Pegawai', href: '/absensi/admin-web/presensi/pegawai', icon: CalendarDays });
    }
    if (can('presensi.manage')) {
        presensiChildren.push({ title: 'Set Presensi Pegawai', href: '/absensi/admin-web/presensi/set-pegawai', icon: SlidersHorizontal });
    }

    const mainItems: NavigationItem[] = [{ title: 'Dashboard', href: '/absensi/admin-web/dashboard', icon: LayoutDashboard }];
    if (presensiChildren.length) {
        mainItems.push({ title: 'Presensi', icon: CalendarDays, children: presensiChildren });
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
            ].filter((item): item is NavigationItem => item !== null),
        },
        {
            category: 'Master data',
            items: [
                can('jabatan.view') ? { title: 'Unit dan Jabatan', href: '/absensi/admin-web/master-data/jabatan', icon: BriefcaseBusiness } : null,
                can('pegawai.view') ? { title: 'Pegawai', href: '/absensi/admin-web/master-data/pegawai', icon: ContactRound } : null,
                can('shift.view') ? { title: 'Shift', href: '/absensi/admin-web/master-data/shift', icon: Timer } : null,
                can('geolocation.view') ? { title: 'Geolokasi', href: '/absensi/admin-web/master-data/geolocation', icon: MapPinned } : null,
            ].filter((item): item is NavigationItem => item !== null),
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

const isActive = (href: string) => currentPath.value === href || currentPath.value.startsWith(`${href}/`);
const isParentActive = (item: NavigationItem) => item.children?.some((child) => child.href && isActive(child.href)) ?? false;
const expandedMenus = ref<string[]>(currentPath.value.startsWith('/absensi/admin-web/presensi') ? ['Presensi'] : []);
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
            <Link href="/absensi/admin-web/dashboard" class="sidebar-brand premium-sidebar-brand" @click="emit('navigate')">
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
                        :class="{ active: item.href && isActive(item.href), 'parent-active': isParentActive(item), expanded: isExpanded(item.title) }"
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
                            :aria-current="isActive(item.href) ? 'page' : undefined"
                            @click="emit('navigate')"
                        >
                            <span class="sidebar-link-icon"><component :is="item.icon" class="link-icon" /></span>
                            <span class="link-title">{{ item.title }}</span>
                            <span v-if="isActive(item.href)" class="sidebar-active-dot" aria-hidden="true"></span>
                        </Link>

                        <ul v-if="item.children && isExpanded(item.title)" class="sidebar-submenu">
                            <li v-for="child in item.children" :key="child.href" :class="{ active: child.href && isActive(child.href) }">
                                <Link
                                    v-if="child.href"
                                    :href="child.href"
                                    :aria-current="isActive(child.href) ? 'page' : undefined"
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
