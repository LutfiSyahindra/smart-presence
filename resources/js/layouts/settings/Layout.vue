<script setup lang="ts">
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { KeyRound, Palette, Settings2, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

const sidebarNavItems: (NavItem & { description: string })[] = [
    {
        title: 'Profil',
        href: '/settings/profile',
        icon: UserRound,
        description: 'Identitas akun',
    },
    {
        title: 'Kata sandi',
        href: '/settings/password',
        icon: KeyRound,
        description: 'Keamanan akses',
    },
    {
        title: 'Tampilan',
        href: '/settings/appearance',
        icon: Palette,
        description: 'Tema antarmuka',
    },
];

const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <div class="settings-premium-card">
        <header class="settings-premium-header">
            <span><Settings2 /></span>
            <div>
                <h2>Pengaturan akun</h2>
                <p>Kelola profil, keamanan, dan preferensi tampilan Anda.</p>
            </div>
        </header>

        <div class="settings-premium-layout">
            <aside class="settings-premium-sidebar">
                <nav aria-label="Navigasi pengaturan">
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        :href="item.href"
                        class="settings-premium-link"
                        :class="{ active: currentPath === item.href }"
                        :aria-current="currentPath === item.href ? 'page' : undefined"
                    >
                        <span><component :is="item.icon" /></span>
                        <div>
                            <strong>{{ item.title }}</strong
                            ><small>{{ item.description }}</small>
                        </div>
                    </Link>
                </nav>
            </aside>

            <div class="settings-premium-content">
                <section>
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
