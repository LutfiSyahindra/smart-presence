<script setup lang="ts">
import { Check, ChevronDown, Search } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

interface SearchableSelectOption {
    value: string | number;
    label: string;
    description?: string | null;
    searchText?: string | null;
    disabled?: boolean;
}

const props = withDefaults(
    defineProps<{
        modelValue: string | number;
        options: SearchableSelectOption[];
        label?: string;
        placeholder?: string;
        placeholderValue?: string | number;
        placeholderDisabled?: boolean;
        searchPlaceholder?: string;
        disabled?: boolean;
        searchThreshold?: number;
    }>(),
    {
        label: undefined,
        placeholder: 'Pilih data',
        placeholderValue: '',
        placeholderDisabled: false,
        searchPlaceholder: 'Cari pilihan...',
        disabled: false,
        searchThreshold: 3,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | number];
    change: [value: string | number];
}>();

const root = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const listboxId = useId();

const useSearch = computed(() => props.options.length > props.searchThreshold);
const selectedOption = computed(() => props.options.find((option) => option.value === props.modelValue) ?? null);
const nativeValue = computed({
    get: () => props.modelValue,
    set: (value: string | number) => selectValue(value),
});
const filteredOptions = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('id-ID');
    if (!needle) return props.options;

    return props.options.filter((option) =>
        [option.label, option.description, option.searchText].filter(Boolean).join(' ').toLocaleLowerCase('id-ID').includes(needle),
    );
});

const selectValue = (value: string | number) => {
    emit('update:modelValue', value);
    emit('change', value);
    open.value = false;
    query.value = '';
};

const toggle = async () => {
    if (props.disabled) return;
    open.value = !open.value;
    query.value = '';
    activeIndex.value = 0;
    if (open.value) {
        await nextTick();
        searchInput.value?.focus();
    }
};

const handleSearchKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.stopPropagation();
        open.value = false;
        return;
    }

    if (event.key === 'Enter') event.preventDefault();
    if (!filteredOptions.value.length) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % filteredOptions.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + filteredOptions.value.length) % filteredOptions.value.length;
    } else if (event.key === 'Enter') {
        const option = filteredOptions.value[activeIndex.value];
        if (option && !option.disabled) selectValue(option.value);
    }
};

const handleOutsideClick = (event: PointerEvent) => {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
};

watch(query, () => (activeIndex.value = 0));
watch(
    () => props.disabled,
    (disabled) => {
        if (disabled) open.value = false;
    },
);
watch(useSearch, (searchable) => {
    if (!searchable) open.value = false;
});

onMounted(() => document.addEventListener('pointerdown', handleOutsideClick));
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleOutsideClick));
</script>

<template>
    <div ref="root" class="searchable-select" :class="{ disabled, open }">
        <select v-if="!useSearch" v-model="nativeValue" class="searchable-select-native" :disabled="disabled" :aria-label="label">
            <option :value="placeholderValue" :disabled="placeholderDisabled">{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">
                {{ option.label }}
            </option>
        </select>

        <template v-else>
            <button
                type="button"
                class="searchable-select-trigger"
                :disabled="disabled"
                :aria-label="label"
                :aria-expanded="open"
                :aria-controls="listboxId"
                aria-haspopup="listbox"
                @click="toggle"
            >
                <span :class="{ placeholder: !selectedOption }">{{ selectedOption?.label || placeholder }}</span>
                <ChevronDown aria-hidden="true" />
            </button>

            <div v-if="open" class="searchable-select-panel">
                <label class="searchable-select-search">
                    <Search aria-hidden="true" />
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="search"
                        :placeholder="searchPlaceholder"
                        :aria-label="searchPlaceholder"
                        autocomplete="off"
                        @keydown="handleSearchKeydown"
                    />
                </label>

                <div :id="listboxId" class="searchable-select-options" role="listbox" :aria-label="label">
                    <button
                        v-if="placeholder && !placeholderDisabled && !query"
                        type="button"
                        class="searchable-select-option placeholder-option"
                        :class="{ selected: modelValue === placeholderValue }"
                        role="option"
                        :aria-selected="modelValue === placeholderValue"
                        @click="selectValue(placeholderValue)"
                    >
                        <span>{{ placeholder }}</span>
                        <Check v-if="modelValue === placeholderValue" aria-hidden="true" />
                    </button>
                    <button
                        v-for="(option, index) in filteredOptions"
                        :key="option.value"
                        type="button"
                        class="searchable-select-option"
                        :class="{ selected: option.value === modelValue, active: index === activeIndex }"
                        :disabled="option.disabled"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        @mouseenter="activeIndex = index"
                        @click="selectValue(option.value)"
                    >
                        <span>
                            <strong>{{ option.label }}</strong>
                            <small v-if="option.description">{{ option.description }}</small>
                        </span>
                        <Check v-if="option.value === modelValue" aria-hidden="true" />
                    </button>
                    <p v-if="!filteredOptions.length" class="searchable-select-empty">Pilihan tidak ditemukan.</p>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.searchable-select {
    position: relative;
    width: 100%;
}

.searchable-select-native,
.searchable-select-trigger {
    width: 100%;
    height: 44px;
    border: 1px solid #dce1e9;
    border-radius: 9px;
    outline: none;
    color: #17213d;
    background: white;
    font-size: 13px;
}

.searchable-select-native {
    padding: 0 10px;
}

.searchable-select-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 0 10px;
    text-align: left;
}

.searchable-select-trigger > span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.searchable-select-trigger .placeholder {
    color: #7b8493;
}

.searchable-select-trigger > svg {
    width: 15px;
    flex: 0 0 auto;
    color: #858fa1;
    transition: transform 0.15s ease;
}

.open .searchable-select-trigger > svg {
    transform: rotate(180deg);
}

.searchable-select-native:focus,
.searchable-select-trigger:focus,
.open .searchable-select-trigger {
    border-color: #929afa;
    box-shadow: 0 0 0 3px rgba(88, 101, 220, 0.09);
}

.invalid .searchable-select-native,
.invalid .searchable-select-trigger {
    border-color: #dc5364;
}

.disabled .searchable-select-native,
.disabled .searchable-select-trigger {
    color: #8d95a4;
    background: #eef0f3;
    cursor: not-allowed;
}

.searchable-select-panel {
    position: absolute;
    z-index: 40;
    top: calc(100% + 5px);
    right: 0;
    left: 0;
    overflow: hidden;
    padding: 7px;
    border: 1px solid #dfe3ea;
    border-radius: 11px;
    background: white;
    box-shadow: 0 16px 38px rgba(23, 33, 61, 0.18);
}

.searchable-select-search {
    position: relative;
    display: flex;
    align-items: center;
}

.searchable-select-search > svg {
    position: absolute;
    left: 10px;
    width: 14px;
    color: #8993a5;
    pointer-events: none;
}

.searchable-select-search input {
    width: 100%;
    height: 36px;
    padding: 0 10px 0 31px;
    border: 1px solid #e1e5eb;
    border-radius: 8px;
    outline: none;
    color: #17213d;
    background: #f8f9fb;
    font-size: 12px;
}

.searchable-select-search input:focus {
    border-color: #929afa;
    background: white;
}

.searchable-select-options {
    display: grid;
    gap: 2px;
    max-height: 210px;
    overflow-y: auto;
    margin-top: 6px;
}

.searchable-select-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    padding: 8px 9px;
    border: 0;
    border-radius: 7px;
    color: #3c465a;
    background: transparent;
    text-align: left;
}

.searchable-select-option:hover,
.searchable-select-option.active {
    background: #f2f3ff;
}

.searchable-select-option.selected {
    color: #4e59c4;
    background: #eceeff;
}

.searchable-select-option > span {
    display: grid;
    min-width: 0;
}

.searchable-select-option strong {
    overflow: hidden;
    font-size: 13px;
    font-weight: 750;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.searchable-select-option small {
    overflow: hidden;
    margin-top: 1px;
    color: #8891a2;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.searchable-select-option > svg {
    width: 14px;
    flex: 0 0 auto;
}

.searchable-select-option:disabled {
    opacity: 0.5;
}

.placeholder-option span {
    color: #778195;
    font-size: 13px;
}

.searchable-select-empty {
    margin: 0;
    padding: 13px 8px;
    color: #8d95a4;
    font-size: 11px;
    text-align: center;
}
</style>
