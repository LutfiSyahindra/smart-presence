<script setup lang="ts">
import { Search } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';

interface MultiSelectOption {
    value: number;
    label: string;
    description?: string | null;
    disabled?: boolean;
}

const props = withDefaults(
    defineProps<{
        modelValue: number[];
        options: MultiSelectOption[];
        label: string;
        searchPlaceholder?: string;
        maxSelected?: number;
        showOrder?: boolean;
        disabled?: boolean;
    }>(),
    { searchPlaceholder: 'Cari pilihan...', maxSelected: undefined, showOrder: false, disabled: false },
);
const emit = defineEmits<{
    'update:modelValue': [value: number[]];
}>();
const query = ref('');
const limitId = useId();
const selected = computed(() => new Set(props.modelValue));
const filteredOptions = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('id-ID');
    return props.options.filter((option) => [option.label, option.description].filter(Boolean).join(' ').toLocaleLowerCase('id-ID').includes(needle));
});
const unselectedOptions = computed(() => filteredOptions.value.filter((option) => !option.disabled && !selected.value.has(option.value)));
const atLimit = computed(() => props.maxSelected !== undefined && props.modelValue.length >= props.maxSelected);
const canSelectAll = computed(
    () =>
        unselectedOptions.value.length > 0 &&
        (props.maxSelected === undefined || props.modelValue.length + unselectedOptions.value.length <= props.maxSelected),
);
const selectAllHint = computed(() =>
    !canSelectAll.value && unselectedOptions.value.length && props.maxSelected !== undefined
        ? `Maksimal ${props.maxSelected} pilihan. Persempit pencarian untuk memilih semua hasil.`
        : 'Pilih semua hasil pencarian yang tersedia',
);
const optionDisabled = (option: MultiSelectOption) => props.disabled || (!selected.value.has(option.value) && (option.disabled || atLimit.value));
const toggle = (option: MultiSelectOption) => {
    if (optionDisabled(option)) return;
    emit(
        'update:modelValue',
        selected.value.has(option.value) ? props.modelValue.filter((id) => id !== option.value) : [...props.modelValue, option.value],
    );
};
const selectAll = () => {
    if (props.disabled || !canSelectAll.value) return;
    emit('update:modelValue', [...props.modelValue, ...unselectedOptions.value.map((option) => option.value)]);
};
const clear = () => {
    if (!props.disabled) emit('update:modelValue', []);
};
</script>

<template>
    <fieldset class="al-multi-select" :disabled="disabled" :aria-label="label" :aria-describedby="maxSelected ? limitId : undefined">
        <legend class="al-multi-legend">{{ label }}</legend>
        <div class="al-multi-toolbar">
            <strong>{{ modelValue.length }}{{ maxSelected ? ` / ${maxSelected}` : '' }} dipilih</strong>
            <div>
                <button type="button" :disabled="disabled || !canSelectAll" :title="selectAllHint" @click="selectAll">Pilih semua hasil</button>
                <button type="button" :disabled="disabled || !modelValue.length" @click="clear">Kosongkan</button>
            </div>
        </div>
        <label class="al-multi-search">
            <Search aria-hidden="true" />
            <input
                v-model="query"
                type="search"
                :placeholder="searchPlaceholder"
                :aria-label="searchPlaceholder"
                autocomplete="off"
                :disabled="disabled"
            />
        </label>
        <div class="al-multi-options">
            <label
                v-for="option in filteredOptions"
                :key="option.value"
                class="al-multi-option"
                :class="{ selected: selected.has(option.value), disabled: optionDisabled(option) }"
            >
                <input type="checkbox" :checked="selected.has(option.value)" :disabled="optionDisabled(option)" @change="toggle(option)" />
                <span>
                    <strong>{{ option.label }}</strong>
                    <small v-if="option.description">{{ option.description }}</small>
                </span>
                <span
                    v-if="showOrder && selected.has(option.value)"
                    class="al-multi-order"
                    :aria-label="`Tingkat ${modelValue.indexOf(option.value) + 1}`"
                    >{{ modelValue.indexOf(option.value) + 1 }}</span
                >
            </label>
            <p v-if="!filteredOptions.length" class="al-multi-empty">{{ query ? 'Pilihan tidak ditemukan.' : 'Belum ada pilihan yang tersedia.' }}</p>
        </div>
        <small v-if="maxSelected" :id="limitId" class="al-multi-limit"
            >Maksimal {{ maxSelected }} pilihan{{ atLimit ? ' sudah terpilih. Hapus pilihan untuk menggantinya.' : '.' }}</small
        >
    </fieldset>
</template>

<style scoped>
.al-multi-select {
    min-width: 0;
    margin: 0;
    padding: 12px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    background: white;
}
.al-multi-select.invalid {
    border-color: #dc5364;
}
.al-multi-legend {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
}
.al-multi-toolbar,
.al-multi-toolbar > div {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.al-multi-toolbar {
    margin-bottom: 10px;
}
.al-multi-toolbar strong {
    color: var(--rs-text-soft);
    font-size: 12px;
}
.al-multi-toolbar button {
    padding: 3px 0;
    border: 0;
    background: transparent;
    color: var(--rs-primary);
    font-size: 11px;
    font-weight: 600;
}
.al-multi-toolbar button:disabled {
    color: var(--rs-muted);
    cursor: not-allowed;
}
.al-multi-search {
    position: relative;
    display: flex;
    align-items: center;
}
.al-multi-search svg {
    position: absolute;
    left: 10px;
    width: 14px;
    color: var(--rs-muted);
    pointer-events: none;
}
.al-multi-search input {
    width: 100%;
    height: 36px;
    padding: 0 10px 0 32px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    outline: none;
    color: var(--rs-primary-deep);
    background: var(--rs-canvas);
    font-size: 12px;
}
.al-multi-search input:focus {
    border-color: var(--rs-primary-pale);
    background: white;
}
.al-multi-options {
    display: grid;
    gap: 3px;
    max-height: 190px;
    overflow-y: auto;
    margin-top: 8px;
}
.al-multi-option {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 9px;
    border-radius: 7px;
    cursor: pointer;
}
.al-multi-option:hover {
    background: var(--rs-canvas);
}
.al-multi-option.selected {
    background: var(--rs-primary-soft);
}
.al-multi-option.disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.al-multi-option input {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    margin: 0;
    padding: 0;
    border-radius: 4px;
    color: var(--rs-primary);
    accent-color: var(--rs-primary);
}
.al-multi-option > span:not(.al-multi-order) {
    display: grid;
    min-width: 0;
    flex: 1;
    overflow-wrap: anywhere;
}
.al-multi-option strong {
    color: var(--rs-text-soft);
    font-size: 12px;
    font-weight: 600;
}
.al-multi-option small {
    color: var(--rs-muted);
    font-size: 10px;
}
.al-multi-order {
    display: grid;
    place-items: center;
    width: 23px;
    height: 23px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--rs-primary);
    color: white;
    font-size: 11px;
}
.al-multi-limit,
.al-multi-empty {
    display: block;
    margin: 8px 0 0;
    color: var(--rs-muted);
    font-size: 11px;
    line-height: 1.6;
}
.al-multi-empty {
    padding: 9px;
}
</style>
