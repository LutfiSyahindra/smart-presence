<script setup lang="ts">
import { RefreshCw, TriangleAlert } from '@lucide/vue';

defineProps<{ message: string }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="state-card error-state" role="alert">
        <TriangleAlert :size="30" />
        <p>{{ message }}</p>
        <button class="button secondary small" type="button" @click="$emit('retry')">
            <RefreshCw :size="16" /> Coba lagi
        </button>
    </div>
</template>
