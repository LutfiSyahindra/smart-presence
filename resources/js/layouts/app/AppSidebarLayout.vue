<script setup lang="ts">
import NobleFooter from '@/components/noble/NobleFooter.vue';
import NobleNavbar from '@/components/noble/NobleNavbar.vue';
import NobleSidebar from '@/components/noble/NobleSidebar.vue';
import type { BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const sidebarFolded = ref(false);
const sidebarOpen = ref(false);
const sidebarHovered = ref(false);
const sidebarStorageKey = 'rsarsy-sidebar-folded';

const pageTitle = computed(() => props.breadcrumbs.at(-1)?.title);

const syncBodyClasses = () => {
    document.body.classList.toggle('sidebar-folded', sidebarFolded.value);
    document.body.classList.toggle('sidebar-open', sidebarOpen.value);
    document.body.classList.toggle('open-sidebar-folded', sidebarFolded.value && sidebarHovered.value);
};

const toggleSidebar = () => {
    if (window.matchMedia('(max-width: 991px)').matches) {
        sidebarOpen.value = !sidebarOpen.value;
    } else {
        sidebarFolded.value = !sidebarFolded.value;
    }
};

const closeMobileSidebar = () => {
    sidebarOpen.value = false;
};

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && sidebarOpen.value) closeMobileSidebar();
};

watch([sidebarFolded, sidebarOpen, sidebarHovered], syncBodyClasses);
watch(sidebarFolded, (folded) => window.localStorage.setItem(sidebarStorageKey, folded ? '1' : '0'));

onMounted(() => {
    sidebarFolded.value = window.localStorage.getItem(sidebarStorageKey) === '1';
    document.body.classList.add('noble-layout-active');
    window.addEventListener('keydown', handleKeydown);
    syncBodyClasses();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
    document.body.classList.remove('noble-layout-active', 'sidebar-folded', 'sidebar-open', 'open-sidebar-folded');
});
</script>

<template>
    <div class="main-wrapper">
        <NobleSidebar :folded="sidebarFolded" @toggle="toggleSidebar" @navigate="closeMobileSidebar" @hover="sidebarHovered = $event" />

        <button v-if="sidebarOpen" type="button" class="noble-sidebar-backdrop" aria-label="Tutup navigasi" @click="closeMobileSidebar"></button>

        <div class="page-wrapper">
            <NobleNavbar @toggle-sidebar="toggleSidebar" />

            <main class="page-content">
                <div v-if="pageTitle" class="noble-page-heading mb-4">
                    <div class="noble-page-title-row">
                        <div>
                            <span class="noble-page-eyebrow">Workspace</span>
                            <h4 class="mb-0">{{ pageTitle }}</h4>
                        </div>
                        <span class="noble-page-secure"><i></i> Sesi aman</span>
                    </div>
                    <nav v-if="breadcrumbs.length > 1" aria-label="Breadcrumb">
                        <ol class="breadcrumb">
                            <li
                                v-for="(item, index) in breadcrumbs"
                                :key="item.href"
                                class="breadcrumb-item"
                                :class="{ active: index === breadcrumbs.length - 1 }"
                            >
                                <span v-if="index === breadcrumbs.length - 1" aria-current="page">{{ item.title }}</span>
                                <Link v-else :href="item.href">{{ item.title }}</Link>
                            </li>
                        </ol>
                    </nav>
                </div>

                <slot />
            </main>

            <NobleFooter />
        </div>
    </div>
</template>
