<script setup lang="ts">
import { CalendarDays, CircleUserRound, History, House } from '@lucide/vue';
import { useRoute } from 'vue-router';

withDefaults(defineProps<{ title?: string; subtitle?: string; showHeader?: boolean }>(), {
    title: 'RSARSY Presence',
    subtitle: '',
    showHeader: true,
});

const route = useRoute();
const items = [
    { name: 'home', label: 'Beranda', icon: House },
    { name: 'attendance', label: 'Presensi', icon: CalendarDays },
    { name: 'history', label: 'Riwayat', icon: History },
    { name: 'account', label: 'Akun', icon: CircleUserRound },
];
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

        <nav class="bottom-nav" aria-label="Navigasi utama">
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
