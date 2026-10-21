<script setup lang="ts">
import { mobileGoBack } from '@/lib/mobile-navigation';

withDefaults(defineProps<{ href?: string }>(), { href: '/absensi/mobile' });

function goBack(event: MouseEvent, fallback: string) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    mobileGoBack(fallback);
}
</script>

<template>
    <a :href="href" @click="goBack($event, href)"><slot /></a>
</template>
