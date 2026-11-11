<script setup lang="ts">
import { Dialog, DialogDescription, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { AlarmClock, X } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{ open: boolean; title: string; description: string; busy: boolean }>();
const emit = defineEmits<{ close: [] }>();
const closeButton = ref<HTMLButtonElement | null>(null);

const close = () => {
    if (!props.busy) emit('close');
};
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="shift-modal" :initial-focus="closeButton" @close="close">
            <TransitionChild
                as="template"
                enter="shift-modal-transition"
                enter-from="shift-modal-hidden"
                enter-to="shift-modal-visible"
                leave="shift-modal-transition"
                leave-from="shift-modal-visible"
                leave-to="shift-modal-hidden"
            >
                <div class="shift-modal-backdrop" aria-hidden="true" />
            </TransitionChild>
            <div class="shift-modal-position">
                <TransitionChild
                    as="template"
                    enter="shift-modal-transition"
                    enter-from="shift-modal-panel-hidden"
                    enter-to="shift-modal-visible"
                    leave="shift-modal-transition"
                    leave-from="shift-modal-visible"
                    leave-to="shift-modal-panel-hidden"
                >
                    <DialogPanel class="shift-modal-panel" :aria-busy="busy">
                        <header class="shift-modal-header">
                            <span class="shift-modal-icon" aria-hidden="true"><AlarmClock /></span>
                            <div>
                                <DialogTitle as="h2">{{ title }}</DialogTitle>
                                <DialogDescription>{{ description }}</DialogDescription>
                            </div>
                            <button ref="closeButton" type="button" :disabled="busy" aria-label="Tutup modal shift" @click="close">
                                <X :size="20" />
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
.shift-modal {
    position: relative;
    z-index: 1100;
}
.shift-modal-backdrop {
    position: fixed;
    z-index: 1100;
    inset: 0;
    background: rgba(var(--rs-primary-deep-rgb), 0.62);
    backdrop-filter: blur(7px);
}
.shift-modal-position {
    position: fixed;
    z-index: 1101;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100vw;
    width: 100dvw;
    height: 100vh;
    height: 100dvh;
    padding: 16px;
}
.shift-modal-panel {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: min(62rem, 100%);
    max-height: 100%;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 22px;
    background: var(--rs-canvas);
    box-shadow: 0 28px 80px rgba(var(--rs-primary-deep-rgb), 0.28);
}
.shift-modal-header {
    display: flex;
    flex: 0 0 auto;
    align-items: flex-start;
    gap: 14px;
    padding: 22px;
    border-bottom: 1px solid var(--rs-border);
    background: var(--rs-surface-subtle);
}
.shift-modal-icon {
    display: grid;
    flex: 0 0 auto;
    width: 44px;
    height: 44px;
    place-items: center;
    border-radius: 13px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.shift-modal-header > div {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}
.shift-modal-header h2 {
    margin: 0;
    color: var(--rs-ink);
    font-size: 19px;
    font-weight: 750;
    line-height: 1.4;
}
.shift-modal-header p {
    margin: 5px 0 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.6;
}
.shift-modal-header button {
    display: grid;
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    place-items: center;
    border: 0;
    border-radius: 11px;
    color: var(--rs-text-soft);
    background: var(--rs-surface-muted);
}
.shift-modal-header button:hover {
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
}
.shift-modal-header button:focus-visible {
    outline: 3px solid var(--rs-primary);
    outline-offset: 2px;
}
.shift-modal-header button:disabled {
    cursor: wait;
    opacity: 0.5;
}
.shift-modal-transition {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}
.shift-modal-visible {
    opacity: 1;
    transform: translateY(0);
}
.shift-modal-hidden {
    opacity: 0;
}
.shift-modal-panel-hidden {
    opacity: 0;
    transform: translateY(12px);
}
@media (max-width: 640px) {
    .shift-modal-position {
        padding: max(8px, env(safe-area-inset-top)) 8px max(8px, env(safe-area-inset-bottom));
    }
    .shift-modal-panel {
        border-radius: 16px;
    }
    .shift-modal-header {
        gap: 10px;
        padding: 14px;
    }
    .shift-modal-icon {
        display: none;
    }
    .shift-modal-header h2 {
        font-size: 17px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .shift-modal-transition {
        transition: none;
    }
}
@media (max-height: 480px) {
    .shift-modal-position {
        padding: 8px;
    }
    .shift-modal-header {
        padding: 12px;
    }
    .shift-modal-header p {
        display: none;
    }
}
</style>
