<script setup lang="ts">
import { Dialog, DialogDescription, DialogPanel, DialogTitle } from '@headlessui/vue';
import { X } from 'lucide-vue-next';

withDefaults(defineProps<{ open: boolean; title: string; description?: string; busy?: boolean }>(), { description: '', busy: false });
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <Dialog :open="open" class="cuti-dialog" @close="!busy && emit('close')">
        <div class="cuti-overlay" aria-hidden="true"></div>
        <div class="cuti-dialog-position">
            <DialogPanel class="cuti-sheet">
                <span class="cuti-sheet-handle" aria-hidden="true"></span>
                <header>
                    <div>
                        <DialogTitle as="h2">{{ title }}</DialogTitle>
                        <DialogDescription v-if="description">{{ description }}</DialogDescription>
                    </div>
                    <button type="button" aria-label="Tutup" :disabled="busy" @click="emit('close')"><X /></button>
                </header>
                <div class="cuti-sheet-content"><slot /></div>
                <footer v-if="$slots.footer" class="cuti-sheet-footer"><slot name="footer" /></footer>
            </DialogPanel>
        </div>
    </Dialog>
</template>

<style scoped>
/* Dialog is teleported outside the component's scoped root. */
:global(.cuti-dialog) {
    position: fixed;
    inset: 0;
    z-index: 100;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    color: #173b3a;
    color-scheme: light;
}
.cuti-overlay {
    position: fixed;
    inset: 0;
    background: #102d36a6;
    backdrop-filter: blur(7px);
}
.cuti-dialog-position {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    height: 100dvh;
    padding-top: calc(12px + env(safe-area-inset-top));
}
.cuti-sheet {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    width: 100%;
    max-width: 500px;
    max-height: 100%;
    overflow: hidden;
    background: #fcfdfb;
    border-radius: 24px 24px 0 0;
    box-shadow: 0 -12px 70px #102d3626;
}
.cuti-sheet-handle {
    display: block;
    flex-shrink: 0;
    width: 38px;
    height: 4px;
    border-radius: 20px;
    margin: 10px auto 0;
    background: #d5dfda;
}
header {
    display: flex;
    flex-shrink: 0;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #e6ece2;
    padding: 16px 20px;
}
header > div {
    min-width: 0;
    flex: 1;
    overflow-wrap: anywhere;
}
h2 {
    margin: 0;
    font-size: 20px;
    line-height: 1.4;
    font-weight: 750;
    letter-spacing: -0.04em;
}
header p {
    margin: 6px 0 0;
    color: #60746b;
    font-size: 13px;
    line-height: 1.6;
}
header button {
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 14px;
    border: 1px solid #e3eae5;
    background: #fff;
    display: grid;
    place-items: center;
    color: #345344;
    touch-action: manipulation;
}
header button svg {
    width: 18px;
    height: 18px;
}
button:focus-visible {
    outline: 3px solid #cfaa6e;
    outline-offset: 3px;
}
button:disabled {
    opacity: 0.5;
}
.cuti-sheet-content {
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    scroll-padding-block: 20px;
    padding: 20px 20px calc(20px + env(safe-area-inset-bottom));
}
.cuti-sheet-content:has(+ .cuti-sheet-footer) {
    padding-bottom: 20px;
}
.cuti-sheet-footer {
    flex-shrink: 0;
    border-top: 1px solid #e1e8dc;
    padding: 12px 20px calc(12px + env(safe-area-inset-bottom));
    background: #fcfdfb;
}
@media (max-width: 380px) {
    header,
    .cuti-sheet-content,
    .cuti-sheet-footer {
        padding-inline: 16px;
    }
}
@media (max-height: 500px) {
    .cuti-sheet-handle {
        display: none;
    }
    header {
        padding-block: 10px;
    }
    header p {
        display: none;
    }
}
@media (min-width: 640px) {
    .cuti-dialog-position {
        align-items: center;
        padding: 24px;
    }
    .cuti-sheet {
        border-radius: 28px;
        max-height: min(88dvh, 860px);
    }
}
</style>
