<script setup lang="ts">
import { HeartPulse, ShieldCheck } from 'lucide-vue-next';

const currentYear = new Date().getFullYear();
</script>

<template>
    <footer class="footer noble-footer d-flex flex-column flex-md-row align-items-center justify-content-between small px-4 py-3">
        <p class="d-flex align-items-center mb-md-0 mb-1 text-muted"><HeartPulse class="icon-sm me-2" /> &copy; {{ currentYear }} RSARSY Presence</p>
        <p class="d-flex align-items-center mb-0 text-muted"><ShieldCheck class="icon-sm me-2" /> Akses aman &amp; terenkripsi</p>
    </footer>
</template>
