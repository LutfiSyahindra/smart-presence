<script setup lang="ts">
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/vue';
import { Check, ChevronDown, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Option {
    value: number;
    label: string;
    description?: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: number[];
        options: Option[];
        label: string;
        placeholder?: string;
        disabled?: boolean;
    }>(),
    { placeholder: 'Pilih satu atau lebih...', disabled: false },
);
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>();
const query = ref('');
const selectedOptions = computed(() => props.options.filter((option) => props.modelValue.includes(option.value)));
const filteredOptions = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('id-ID');
    return props.options.filter((option) => `${option.label} ${option.description ?? ''}`.toLocaleLowerCase('id-ID').includes(needle));
});
const update = (value: number[]) => {
    if (props.disabled) return;
    emit('update:modelValue', value);
    query.value = '';
};
</script>

<template>
    <div class="shift-multi">
        <Combobox as="div" :model-value="modelValue" multiple :disabled="disabled" @update:model-value="update">
            <div class="shift-multi-control">
                <div v-if="selectedOptions.length" class="shift-multi-tags">
                    <span v-for="option in selectedOptions" :key="option.value" class="shift-multi-tag">
                        <span>{{ option.label }}{{ option.description ? ` (${option.description})` : '' }}</span>
                        <button
                            type="button"
                            :disabled="disabled"
                            :aria-label="`Hapus ${option.label}${option.description ? ` (${option.description})` : ''}`"
                            @click="update(modelValue.filter((id) => id !== option.value))"
                        >
                            <X aria-hidden="true" />
                        </button>
                    </span>
                </div>
                <div class="shift-multi-search">
                    <ComboboxInput
                        :value="query"
                        :aria-label="label"
                        :placeholder="selectedOptions.length ? 'Cari atau tambah pilihan...' : placeholder"
                        autocomplete="off"
                        @change="query = ($event.target as HTMLInputElement).value"
                    />
                    <ComboboxButton :aria-label="`Buka pilihan ${label}`"><ChevronDown aria-hidden="true" /></ComboboxButton>
                </div>
            </div>
            <ComboboxOptions class="shift-multi-options" :aria-label="label">
                <ComboboxOption
                    v-for="option in filteredOptions"
                    :key="option.value"
                    v-slot="{ active, selected }"
                    as="template"
                    :value="option.value"
                >
                    <li class="shift-multi-option" :class="{ active, selected }">
                        <span
                            ><strong>{{ option.label }}</strong
                            ><small v-if="option.description">{{ option.description }}</small></span
                        >
                        <Check v-if="selected" aria-hidden="true" />
                    </li>
                </ComboboxOption>
                <li v-if="!filteredOptions.length" class="shift-multi-empty">Pilihan tidak ditemukan.</li>
            </ComboboxOptions>
        </Combobox>
    </div>
</template>

<style scoped>
.shift-multi {
    position: relative;
    width: 100%;
    min-width: 0;
}
.shift-multi-control {
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    background: white;
}
.shift-multi-control:focus-within {
    border-color: var(--rs-primary);
    box-shadow: 0 0 0 2px var(--rs-primary-soft);
}
.shift-multi-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    padding: 8px 8px 0;
}
.shift-multi-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    max-width: 100%;
    padding: 4px 5px 4px 8px;
    border-radius: 5px;
    color: var(--rs-primary-deep);
    background: var(--rs-primary-soft);
    font-size: 12px;
}
.shift-multi-tag > span {
    min-width: 0;
    overflow-wrap: anywhere;
}
.shift-multi-tag button,
.shift-multi-search button {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    padding: 2px;
    border: 0;
    border-radius: 4px;
    color: var(--rs-primary);
    background: transparent;
}
.shift-multi-tag button:focus-visible,
.shift-multi-search button:focus-visible {
    outline: 2px solid var(--rs-primary);
}
.shift-multi-tag svg {
    width: 14px;
    height: 14px;
}
.shift-multi-search {
    display: flex;
    align-items: center;
    padding-right: 8px;
}
.shift-multi-search input {
    width: 100%;
    min-width: 0;
    height: 40px;
    padding: 0 10px;
    border: 0;
    border-radius: 8px;
    outline: none;
    color: var(--rs-ink);
    background: transparent;
    box-shadow: none;
    font-size: 12px;
}
.shift-multi-search svg {
    width: 18px;
    height: 18px;
}
.shift-multi-options {
    position: absolute;
    z-index: 20;
    top: calc(100% + 5px);
    right: 0;
    left: 0;
    width: 100%;
    max-width: 100%;
    max-height: 230px;
    overflow-y: auto;
    overscroll-behavior: contain;
    margin: 0;
    padding: 5px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    background: white;
    box-shadow: 0 8px 24px rgb(15 23 42 / 12%);
    list-style: none;
}
.shift-multi-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 9px;
    border-radius: 5px;
    color: var(--rs-ink);
    cursor: pointer;
}
.shift-multi-option.active,
.shift-multi-option.selected {
    color: var(--rs-primary-deep);
    background: var(--rs-primary-soft);
}
.shift-multi-option > span {
    display: grid;
    gap: 3px;
    min-width: 0;
    overflow-wrap: anywhere;
}
.shift-multi-option strong {
    font-size: 12px;
    font-weight: 600;
}
.shift-multi-option small,
.shift-multi-empty {
    color: var(--rs-muted);
    font-size: 11px;
}
.shift-multi-option > svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.shift-multi-empty {
    padding: 12px 8px;
}
</style>
