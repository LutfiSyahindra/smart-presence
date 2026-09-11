<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" :class="className" fill="none" v-bind="$attrs">
        <rect x="4" y="4" width="40" height="40" rx="14" fill="currentColor" opacity="0.12" />
        <path d="M24 11.5c7.4 0 13.5 5.8 13.5 13S31.4 38 24 38s-13.5-5.8-13.5-13.5S16.6 11.5 24 11.5Z" stroke="currentColor" stroke-width="2.5" />
        <path d="M24 17v15M16.5 24.5h15" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" />
        <path d="m33.8 34.2 3.7 3.7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
    </svg>
</template>
