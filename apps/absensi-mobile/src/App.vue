<script setup lang="ts">
import { RouterView } from 'vue-router';
</script>

<template>
    <RouterView v-slot="{ Component }">
        <Suspense>
            <component :is="Component" />
            <template #fallback>
                <main class="splash-screen">
                    <img src="/app-icon.svg" alt="RSARSY" class="splash-logo" />
                    <span class="spinner" aria-label="Memuat"></span>
                </main>
            </template>
        </Suspense>
    </RouterView>
</template>
