<script setup lang="ts">
import { AlertTriangle, X } from 'lucide-vue-next';

defineProps<{
    open: boolean;
    title: string;
    description: string;
    processing?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    confirm: [];
}>();
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="admin-modal-backdrop" role="presentation" @click.self="emit('close')">
            <section class="admin-modal" role="alertdialog" aria-modal="true" :aria-label="title">
                <button type="button" class="admin-modal-close" aria-label="Tutup" :disabled="processing" @click="emit('close')">
                    <X class="icon-sm" />
                </button>
                <div class="d-flex gap-3">
                    <span class="admin-modal-warning"><AlertTriangle /></span>
                    <div>
                        <h5 class="mb-2">{{ title }}</h5>
                        <p class="mb-0 text-muted">{{ description }}</p>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-4 gap-2">
                    <button type="button" class="btn btn-outline-secondary" :disabled="processing" @click="emit('close')">Batal</button>
                    <button type="button" class="btn btn-danger" :disabled="processing" @click="emit('confirm')">
                        {{ processing ? 'Menghapus…' : 'Ya, hapus' }}
                    </button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
