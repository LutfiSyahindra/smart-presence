<script setup lang="ts">
import { Dialog, DialogDescription, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { X } from 'lucide-vue-next';

defineProps<{ open: boolean; title: string; description?: string; busy?: boolean }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="release-dialog" @close="!busy && emit('close')">
            <TransitionChild
                as="template"
                enter="release-dialog-transition"
                enter-from="release-dialog-hidden"
                enter-to="release-dialog-visible"
                leave="release-dialog-transition"
                leave-from="release-dialog-visible"
                leave-to="release-dialog-hidden"
            >
                <div class="admin-modal-backdrop" aria-hidden="true"></div>
            </TransitionChild>
            <div class="release-dialog-position">
                <TransitionChild
                    as="template"
                    enter="release-dialog-transition"
                    enter-from="release-dialog-panel-hidden"
                    enter-to="release-dialog-visible"
                    leave="release-dialog-transition"
                    leave-from="release-dialog-visible"
                    leave-to="release-dialog-panel-hidden"
                >
                    <DialogPanel class="admin-modal admin-modal-form" :aria-busy="Boolean(busy)">
                        <header class="admin-modal-header">
                            <div>
                                <DialogTitle as="h2" class="release-dialog-title">{{ title }}</DialogTitle>
                                <DialogDescription v-if="description" class="release-dialog-description">{{ description }}</DialogDescription>
                            </div>
                            <button type="button" class="admin-modal-close" aria-label="Tutup dialog" :disabled="busy" @click="emit('close')">
                                <X :size="17" />
                            </button>
                        </header>
                        <slot />
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<style scoped>
.release-dialog {
    position: relative;
    z-index: 1100;
}
.release-dialog-position {
    position: fixed;
    z-index: 1101;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.release-dialog-title {
    margin: 0;
    color: var(--rs-ink);
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -0.025em;
}
.release-dialog-description {
    margin: 7px 0 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.6;
}
.admin-modal-close:focus-visible {
    outline: 3px solid #64b9aa;
    outline-offset: 2px;
}
.admin-modal-close:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.release-dialog-transition {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}
.release-dialog-visible {
    opacity: 1;
    transform: translateY(0);
}
.release-dialog-hidden {
    opacity: 0;
}
.release-dialog-panel-hidden {
    opacity: 0;
    transform: translateY(10px);
}
:global(.dark) .release-dialog-title {
    color: #e2eeeb;
}
:global(.dark) .release-dialog-description {
    color: #a2bab5;
}
@media (prefers-reduced-motion: reduce) {
    .release-dialog-transition {
        transition: none;
    }
}
</style>
