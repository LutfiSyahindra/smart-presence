<script setup lang="ts">
import { Combobox, ComboboxButton, ComboboxInput, ComboboxLabel, ComboboxOption, ComboboxOptions, TransitionRoot } from '@headlessui/vue';
import { Check, ChevronDown, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{ modelValue: string; label: string; allLabel: string; options: string[]; disabled?: boolean; mobile?: boolean }>(),
    { disabled: false, mobile: false },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const search = ref('');
const optionsButton = ref<{ el: HTMLButtonElement | null } | null>(null);
const filteredOptions = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('id-ID');
    return props.options.filter((option) => option.toLocaleLowerCase('id-ID').includes(query));
});
const displayValue = (value: unknown) => (typeof value === 'string' ? value : '');
const searchOptions = (event: Event) => {
    search.value = (event.target as HTMLInputElement).value;
};
const selectValue = (value: string) => {
    if (!props.disabled) emit('update:modelValue', value);
};
const selectSearchText = (event: FocusEvent) => (event.target as HTMLInputElement).select();
const openOptions = (isOpen: boolean) => {
    if (!props.disabled && !isOpen) optionsButton.value?.el?.click();
};
</script>

<template>
    <Combobox
        v-slot="{ open }"
        :model-value="modelValue"
        :disabled="disabled"
        as="div"
        class="filter-select"
        :class="{ 'is-mobile': mobile }"
        @update:model-value="selectValue"
    >
        <ComboboxLabel class="select-label">{{ label }}</ComboboxLabel>
        <div class="select-control" :class="{ 'is-open': open }">
            <Search class="search-icon" aria-hidden="true" />
            <ComboboxInput
                class="select-input"
                :display-value="displayValue"
                :placeholder="open ? `Cari ${label.toLowerCase()}...` : modelValue || allLabel"
                :aria-description="`Ketik untuk mencari ${label.toLowerCase()}.`"
                autocomplete="off"
                :spellcheck="false"
                @change="searchOptions"
                @focus="selectSearchText"
                @click="openOptions(open)"
            />
            <ComboboxButton ref="optionsButton" type="button" class="select-toggle" :aria-label="`Buka pilihan ${label.toLowerCase()}`">
                <ChevronDown aria-hidden="true" />
            </ComboboxButton>
        </div>
        <TransitionRoot leave="select-leave" leave-from="select-visible" leave-to="select-hidden" @after-leave="search = ''">
            <ComboboxOptions class="select-options">
                <ComboboxOption v-slot="{ active, selected }" as="template" value="">
                    <li class="select-option all-option" :class="{ 'is-active': active, 'is-selected': selected }">
                        <span>{{ allLabel }}</span
                        ><Check v-if="selected" aria-hidden="true" />
                    </li>
                </ComboboxOption>
                <li v-if="!filteredOptions.length" class="select-empty" role="presentation">{{ label }} tidak ditemukan.</li>
                <ComboboxOption v-for="option in filteredOptions" :key="option" v-slot="{ active, selected }" as="template" :value="option">
                    <li class="select-option" :class="{ 'is-active': active, 'is-selected': selected }">
                        <span>{{ option }}</span
                        ><Check v-if="selected" aria-hidden="true" />
                    </li>
                </ComboboxOption>
            </ComboboxOptions>
        </TransitionRoot>
    </Combobox>
</template>

<style scoped>
.filter-select {
    min-width: 0;
    color: #253e30;
}
.select-label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 600;
}
.select-control {
    position: relative;
    display: flex;
    align-items: center;
    border: 1px solid var(--rs-border, #dfe7dc);
    border-radius: 8px;
    background: #fff;
}
.select-control:focus-within {
    outline: 2px solid var(--rs-primary, #275e43);
    outline-offset: 2px;
}
.search-icon {
    position: absolute;
    left: 10px;
    width: 16px;
    height: 16px;
    color: #71836a;
    pointer-events: none;
}
.select-input {
    width: 100%;
    min-width: 0;
    min-height: 42px;
    box-sizing: border-box;
    padding: 9px 0 9px 34px;
    border: 0;
    border-radius: inherit;
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 12px;
    font-weight: 400;
    outline: none;
    box-shadow: none;
}
.select-input::placeholder {
    color: #647460;
    opacity: 1;
}
.select-toggle {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 44px;
    min-height: 44px;
    padding: 0;
    border: 0;
    border-radius: inherit;
    background: transparent;
    color: #647460;
    touch-action: manipulation;
}
.select-toggle svg {
    width: 17px;
    height: 17px;
}
.select-toggle:focus-visible {
    outline: 2px solid #275e43;
    outline-offset: -4px;
}
.select-options {
    max-height: min(200px, 35dvh);
    overflow-y: auto;
    overscroll-behavior: contain;
    margin: 8px 0 0;
    padding: 4px;
    border: 1px solid var(--rs-border, #dfe7dc);
    border-radius: 10px;
    background: #fff;
    list-style: none;
    font-size: 12px;
    scroll-padding-block: 4px;
}
.select-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    min-height: 44px;
    padding: 10px;
    border-radius: 6px;
    line-height: 1.5;
    cursor: pointer;
    overflow-wrap: anywhere;
    touch-action: manipulation;
}
.select-option span {
    min-width: 0;
}
.select-option.is-active {
    background: #edf4e8;
}
.select-option.is-selected {
    color: #275e43;
    font-weight: 700;
}
.select-option svg {
    flex-shrink: 0;
    width: 17px;
    height: 17px;
}
.all-option {
    border-bottom: 1px solid #edf0e9;
}
.select-empty {
    padding: 14px 10px;
    color: #647460;
    line-height: 1.6;
}
.is-mobile .select-label {
    margin-bottom: 8px;
    font-size: 13px;
}
.is-mobile .select-control {
    border-radius: 12px;
}
.is-mobile .select-input {
    min-height: 46px;
    font-size: 16px;
}
.is-mobile .select-toggle,
.is-mobile .select-option {
    min-height: 48px;
}
.is-mobile .select-options {
    font-size: 14px;
}
.select-leave {
    transition: opacity 100ms ease;
}
.select-visible {
    opacity: 1;
}
.select-hidden {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .select-leave {
        transition-duration: 0.01ms;
    }
}
</style>
