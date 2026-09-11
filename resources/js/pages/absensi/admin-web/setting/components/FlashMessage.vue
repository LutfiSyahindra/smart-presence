<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const page = usePage<SharedData>();
const toast = ref<{ id: number; type: 'success' | 'error'; message: string } | null>(null);
let dismissTimer: ReturnType<typeof setTimeout> | undefined;
let toastId = 0;

const show = (type: 'success' | 'error', message: string) => {
    if (dismissTimer) clearTimeout(dismissTimer);
    toast.value = { id: ++toastId, type, message };
    dismissTimer = setTimeout(() => (toast.value = null), 4500);
};

watch(
    () => [page.props.flash, page.props.errors] as const,
    ([flash, errors]) => {
        const firstError = Object.values(errors ?? {})[0] as string | undefined;

        if (flash?.success) show('success', flash.success);
        else if (flash?.error || firstError) show('error', flash?.error || firstError || 'Terjadi kesalahan.');
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (dismissTimer) clearTimeout(dismissTimer);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="admin-toast">
            <div
                v-if="toast"
                :key="toast.id"
                class="admin-toast"
                :class="`admin-toast-${toast.type}`"
                :role="toast.type === 'error' ? 'alert' : 'status'"
                aria-live="polite"
            >
                <CheckCircle2 v-if="toast.type === 'success'" class="admin-toast-icon" />
                <AlertCircle v-else class="admin-toast-icon" />
                <span class="flex-grow-1">{{ toast.message }}</span>
                <button type="button" class="admin-toast-close" aria-label="Tutup notifikasi" @click="toast = null">
                    <X class="icon-sm" />
                </button>
            </div>
        </Transition>
    </Teleport>
</template>
