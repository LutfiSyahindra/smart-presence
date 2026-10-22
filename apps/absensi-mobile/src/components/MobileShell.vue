<script setup lang="ts">
import { useAuth } from '@/composables/useAuth';
import { CalendarDays, CircleUserRound, History, House } from '@lucide/vue';
import { computed } from 'vue';
import { useRoute } from 'vue-router';

withDefaults(defineProps<{ title?: string; subtitle?: string; showHeader?: boolean }>(), {
    title: 'RSARSY Presence',
    subtitle: '',
    showHeader: true,
});

const route = useRoute();
const { can } = useAuth();
const items = computed(() =>
    [
        { name: 'home', label: 'Beranda', icon: House },
        { name: 'attendance', label: 'Presensi', icon: CalendarDays, permission: 'mobile.attendance' },
        { name: 'history', label: 'Riwayat', icon: History, permission: 'mobile.history' },
        { name: 'account', label: 'Akun', icon: CircleUserRound, permission: 'mobile.account' },
    ].filter((item) => !item.permission || can(item.permission)),
);
</script>

<template>
    <div class="app-shell">
        <header v-if="showHeader" class="app-header">
            <div>
                <p class="app-eyebrow">RSARSY Presence</p>
                <h1>{{ title }}</h1>
                <p v-if="subtitle" class="header-subtitle">{{ subtitle }}</p>
            </div>
            <slot name="header-action" />
        </header>

        <main class="app-content">
            <slot />
        </main>

        <nav class="bottom-nav" :style="{ gridTemplateColumns: `repeat(${items.length}, 1fr)` }" aria-label="Navigasi utama">
            <RouterLink
                v-for="item in items"
                :key="item.name"
                :to="{ name: item.name }"
                class="nav-item"
                :class="{ active: route.name === item.name }"
            >
                <component :is="item.icon" :size="22" :stroke-width="2" />
                <span>{{ item.label }}</span>
            </RouterLink>
        </nav>
    </div>
</template>
