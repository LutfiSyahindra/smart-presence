<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
    variant?: 'mark' | 'full';
}

withDefaults(defineProps<Props>(), {
    variant: 'mark',
});
</script>

<template>
    <img
        :src="variant === 'full' ? '/images/arsy-presensi-logo.png' : '/images/arsy-presensi-mark.png'"
        alt="ARSY Presensi"
        :width="variant === 'full' ? 500 : 512"
        :height="variant === 'full' ? 500 : 512"
        :class="className"
        class="app-logo-image"
        decoding="async"
        v-bind="$attrs"
    />
</template>

<style scoped>
.app-logo-image {
    display: block;
    flex-shrink: 0;
    object-fit: contain;
}
</style>
