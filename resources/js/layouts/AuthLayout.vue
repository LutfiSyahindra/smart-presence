<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

defineProps<{
    title?: string;
    description?: string;
    compact?: boolean;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description" :compact="compact">
        <slot />
    </AuthLayout>
</template>
