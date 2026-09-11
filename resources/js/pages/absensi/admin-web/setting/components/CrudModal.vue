<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
    }>(),
    { description: undefined },
);

const emit = defineEmits<{
    close: [];
}>();

const titleId = useId();
let previousOverflow = '';

const close = () => {
    if (props.open) emit('close');
};

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') close();
};

watch(
    () => props.open,
    (open) => {
        if (open) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = previousOverflow;
        }
    },
);

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown);
    document.body.style.overflow = previousOverflow;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="admin-modal-fade">
            <div v-if="open" class="admin-modal-backdrop" role="presentation" @click.self="close">
                <section class="admin-modal admin-modal-form" role="dialog" aria-modal="true" :aria-labelledby="titleId">
                    <header class="admin-modal-header">
                        <div>
                            <h5 :id="titleId" class="mb-1">{{ title }}</h5>
                            <p v-if="description" class="mb-0 text-muted">{{ description }}</p>
                        </div>
                        <button type="button" class="admin-modal-close" aria-label="Tutup modal" @click="close">
                            <X class="icon-sm" />
                        </button>
                    </header>
                    <slot />
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
