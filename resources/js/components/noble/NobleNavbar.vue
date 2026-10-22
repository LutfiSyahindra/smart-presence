<script setup lang="ts">
import NotificationBell from '@/components/noble/NotificationBell.vue';
import { getInitials } from '@/composables/useInitials';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, ChevronDown, HeartPulse, LogOut, Menu, Settings, ShieldCheck, Smartphone, UserRound } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const emit = defineEmits<{
    toggleSidebar: [];
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const homeUrl = computed(() => page.props.portal.admin_home_url);
const roleLabel = computed(() => page.props.auth.roles[0]?.replaceAll('-', ' ') ?? 'Pengguna');
const profileOpen = ref(false);
const profileMenu = ref<HTMLElement | null>(null);

const currentDate = new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
}).format(new Date());

const closeWhenClickingOutside = (event: MouseEvent) => {
    if (profileMenu.value && !profileMenu.value.contains(event.target as Node)) {
        profileOpen.value = false;
    }
};

onMounted(() => document.addEventListener('click', closeWhenClickingOutside));
onBeforeUnmount(() => document.removeEventListener('click', closeWhenClickingOutside));
</script>

<template>
    <nav class="navbar">
        <button
            type="button"
            class="sidebar-toggler noble-icon-button premium-sidebar-toggle"
            aria-label="Buka navigasi"
            @click="emit('toggleSidebar')"
        >
            <Menu />
        </button>

        <div class="navbar-content">
            <Link :href="homeUrl" class="noble-mobile-brand" aria-label="RSARSY Beranda">
                <HeartPulse />
                <span>RSARSY</span>
            </Link>

            <div class="noble-navbar-context d-none d-md-flex">
                <span class="noble-navbar-context-icon"><HeartPulse /></span>
                <div>
                    <strong>RSARSY Presence</strong>
                    <span><i></i> Sistem operasional aktif</span>
                </div>
            </div>

            <ul class="navbar-nav">
                <li class="nav-item noble-date-item d-none d-lg-flex">
                    <CalendarDays />
                    <span>{{ currentDate }}</span>
                </li>
                <NotificationBell
                    v-if="
                        page.props.portal.can_access_cuti &&
                        (page.props.auth.roles.includes('super-admin') || page.props.auth.permissions.includes('notifications.view'))
                    "
                />
                <li ref="profileMenu" class="nav-item dropdown">
                    <button
                        type="button"
                        class="nav-link noble-icon-button noble-user-trigger"
                        aria-haspopup="menu"
                        :aria-expanded="profileOpen"
                        aria-label="Menu pengguna"
                        @click.stop="profileOpen = !profileOpen"
                    >
                        <span class="noble-avatar">
                            <img v-if="user.avatar" :src="user.avatar" :alt="user.name" />
                            <span v-else>{{ getInitials(user.name) }}</span>
                        </span>
                        <span class="noble-user-summary d-none d-sm-flex">
                            <strong>{{ user.name }}</strong>
                            <small>{{ roleLabel }}</small>
                        </span>
                        <ChevronDown class="noble-user-chevron d-none d-sm-block" />
                    </button>

                    <div class="dropdown-menu noble-profile-menu p-0" :class="{ show: profileOpen }" role="menu">
                        <div class="noble-profile-cover">
                            <span>Profil pengguna</span>
                            <ShieldCheck />
                        </div>
                        <div class="d-flex flex-column align-items-center border-bottom px-5 pb-3">
                            <span class="noble-avatar noble-avatar-lg mb-3">
                                <img v-if="user.avatar" :src="user.avatar" :alt="user.name" />
                                <span v-else>{{ getInitials(user.name) }}</span>
                            </span>
                            <div class="text-center">
                                <p class="tx-16 fw-bolder mb-0">{{ user.name }}</p>
                                <p class="tx-12 mb-0 text-muted">{{ user.email }}</p>
                                <span class="noble-profile-role">{{ roleLabel }}</span>
                            </div>
                        </div>

                        <ul class="list-unstyled mb-0 p-1">
                            <li class="dropdown-item py-2">
                                <Link
                                    :href="route('portal.switch', { portal: 'presensi' })"
                                    method="post"
                                    as="button"
                                    class="text-body d-flex align-items-center ms-0"
                                    @click="profileOpen = false"
                                >
                                    <Smartphone class="icon-md me-2" />
                                    <span>Aplikasi presensi</span>
                                </Link>
                            </li>
                            <li class="dropdown-item py-2">
                                <Link :href="route('profile.edit')" class="text-body d-flex align-items-center ms-0" @click="profileOpen = false">
                                    <UserRound class="icon-md me-2" />
                                    <span>Profil</span>
                                </Link>
                            </li>
                            <li class="dropdown-item py-2">
                                <Link :href="route('appearance')" class="text-body d-flex align-items-center ms-0" @click="profileOpen = false">
                                    <Settings class="icon-md me-2" />
                                    <span>Pengaturan</span>
                                </Link>
                            </li>
                            <li class="dropdown-item py-2">
                                <Link
                                    as="button"
                                    method="post"
                                    :href="route('logout')"
                                    class="noble-dropdown-action text-body d-flex align-items-center ms-0"
                                >
                                    <LogOut class="icon-md me-2" />
                                    <span>Keluar</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </div>
    </nav>
</template>
