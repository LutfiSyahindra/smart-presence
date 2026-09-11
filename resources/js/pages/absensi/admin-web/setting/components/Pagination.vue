<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationLink } from '../types';

defineProps<{
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();
</script>

<template>
    <div v-if="total > 0" class="d-flex flex-column flex-md-row align-items-md-center justify-content-between border-top gap-3 px-4 py-3">
        <p class="mb-0 text-muted">Menampilkan {{ from }}–{{ to }} dari {{ total }} data</p>
        <nav aria-label="Navigasi halaman">
            <ul class="pagination mb-0">
                <li v-for="(link, index) in links" :key="index" class="page-item" :class="{ active: link.active, disabled: !link.url }">
                    <Link v-if="link.url" class="page-link" :href="link.url" preserve-scroll v-html="link.label" />
                    <span v-else class="page-link" v-html="link.label"></span>
                </li>
            </ul>
        </nav>
    </div>
</template>
