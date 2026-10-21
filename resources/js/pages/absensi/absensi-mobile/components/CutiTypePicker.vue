<script setup lang="ts">
import { Dialog, DialogDescription, DialogPanel, DialogTitle } from '@headlessui/vue';
import { CalendarDays, Check, ChevronDown, Leaf, Search, X } from 'lucide-vue-next';
import { computed, ref, useId } from 'vue';

type LeaveOption = { id: number; nama: string; is_tahunan: boolean; tahun: number; jumlah_hari: number; sisa: number };

const props = withDefaults(defineProps<{ modelValue: string; options: LeaveOption[]; year: number; error?: string; disabled?: boolean }>(), {
    error: '',
    disabled: false,
});
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const pickerId = useId();
const open = ref(false);
const search = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const selectedOption = computed(() => props.options.find((option) => String(option.id) === props.modelValue));
const filteredOptions = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('id-ID');
    return props.options.filter((option) => option.nama.toLocaleLowerCase('id-ID').includes(query));
});

function openPicker() {
    search.value = '';
    open.value = true;
}

function selectOption(option: LeaveOption) {
    emit('update:modelValue', String(option.id));
    open.value = false;
}

function clearSearch() {
    search.value = '';
    searchInput.value?.focus();
}
</script>

<template>
    <div class="type-picker">
        <label :for="`${pickerId}-trigger`" class="picker-label">
            Jenis cuti <span aria-hidden="true">*</span><span class="sr-only"> (wajib)</span>
        </label>
        <button
            :id="`${pickerId}-trigger`"
            type="button"
            class="picker-trigger"
            :class="{ 'is-open': open, 'has-selection': selectedOption, 'has-error': error }"
            :disabled="disabled || !options.length"
            aria-haspopup="dialog"
            :aria-expanded="open"
            :aria-invalid="!!error"
            :aria-describedby="error || !options.length ? `${pickerId}-feedback` : undefined"
            @click="openPicker"
        >
            <span class="type-icon" :class="{ 'is-annual': !selectedOption || selectedOption.is_tahunan }" aria-hidden="true">
                <component :is="!selectedOption || selectedOption.is_tahunan ? Leaf : CalendarDays" />
            </span>
            <span class="trigger-copy">
                <span class="trigger-title">{{ selectedOption?.nama || 'Pilih jenis cuti' }}</span>
                <span class="trigger-description">{{
                    selectedOption
                        ? selectedOption.is_tahunan
                            ? `Kuota tahunan ${selectedOption.tahun}`
                            : 'Batas per pengajuan'
                        : 'Sesuaikan dengan kebutuhan Anda'
                }}</span>
            </span>
            <span v-if="selectedOption" class="trigger-quota" :class="{ depleted: selectedOption.is_tahunan && selectedOption.sisa <= 0 }">
                {{ selectedOption.is_tahunan ? selectedOption.sisa : selectedOption.jumlah_hari }} <small>hari</small>
            </span>
            <ChevronDown class="trigger-chevron" :class="{ rotated: open }" aria-hidden="true" />
        </button>

        <Dialog :open="open" :initial-focus="searchInput" class="type-picker-dialog" @close="open = false">
            <div class="picker-overlay" aria-hidden="true"></div>
            <div class="picker-position">
                <DialogPanel class="picker-panel">
                    <header class="panel-heading">
                        <div>
                            <DialogTitle as="h2">Pilih jenis cuti</DialogTitle>
                            <DialogDescription>Cari dan pilih jenis cuti sesuai kebutuhan Anda.</DialogDescription>
                        </div>
                        <button type="button" class="picker-close" aria-label="Tutup pilihan jenis cuti" @click="open = false"><X /></button>
                    </header>
                    <div class="picker-search">
                        <Search aria-hidden="true" />
                        <input
                            ref="searchInput"
                            v-model="search"
                            type="search"
                            placeholder="Cari jenis cuti..."
                            aria-label="Cari jenis cuti"
                            :aria-controls="`${pickerId}-options`"
                            autocomplete="off"
                            @keydown.enter.prevent
                        />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="clearSearch"><X /></button>
                    </div>
                    <p class="search-count" role="status">{{ filteredOptions.length }} jenis cuti{{ search.trim() ? ' ditemukan' : ' tersedia' }}</p>
                    <ul :id="`${pickerId}-options`" class="picker-options" aria-label="Jenis cuti tersedia">
                        <li v-for="option in filteredOptions" :key="`${option.id}-${option.tahun}`">
                            <button
                                type="button"
                                class="type-option"
                                :class="{ 'is-selected': String(option.id) === modelValue }"
                                :aria-pressed="String(option.id) === modelValue"
                                :aria-label="`${option.nama}, ${option.is_tahunan ? `tahun ${option.tahun}, sisa ${option.sisa} hari` : `maksimal ${option.jumlah_hari} hari per pengajuan`}`"
                                :disabled="disabled"
                                @click="selectOption(option)"
                            >
                                <span class="type-icon" :class="{ 'is-annual': option.is_tahunan }" aria-hidden="true">
                                    <component :is="option.is_tahunan ? Leaf : CalendarDays" />
                                </span>
                                <span class="option-copy">
                                    <span class="option-category">{{ option.is_tahunan ? `TAHUNAN · ${option.tahun}` : 'PER PENGAJUAN' }}</span>
                                    <span class="option-name">{{ option.nama }}</span>
                                    <span v-if="option.is_tahunan && option.sisa <= 0" class="quota-empty">Saldo sudah terpakai</span>
                                </span>
                                <span class="option-quota" :class="{ depleted: option.is_tahunan && option.sisa <= 0 }">
                                    <strong>{{ option.is_tahunan ? option.sisa : option.jumlah_hari }} <small>hari</small></strong>
                                    <span>{{ option.is_tahunan ? 'tersisa' : 'maksimal' }}</span>
                                </span>
                                <span class="option-check" aria-hidden="true"><Check v-if="String(option.id) === modelValue" /></span>
                            </button>
                        </li>
                    </ul>
                    <div v-if="!filteredOptions.length" class="search-empty">
                        <Search aria-hidden="true" />
                        <strong>Jenis cuti tidak ditemukan</strong>
                        <p>Coba kata kunci lain atau hapus pencarian.</p>
                        <button type="button" @click="clearSearch">Hapus pencarian</button>
                    </div>
                    <p class="panel-note"><span aria-hidden="true"></span>Kuota tahunan mengikuti tanggal mulai cuti.</p>
                </DialogPanel>
            </div>
        </Dialog>
        <p v-if="error || !options.length" :id="`${pickerId}-feedback`" class="picker-error" role="alert">
            {{ error || `Belum ada jenis cuti untuk tahun ${year}.` }}
        </p>
        <p v-else-if="selectedOption && !open" class="selection-note">
            <Check aria-hidden="true" />
            {{
                selectedOption.is_tahunan
                    ? `Sisa saldo ${selectedOption.sisa} hari tersedia untuk tahun ${selectedOption.tahun}.`
                    : `Maksimal ${selectedOption.jumlah_hari} hari untuk setiap pengajuan.`
            }}
        </p>
    </div>
</template>

<style scoped>
.type-picker {
    min-width: 0;
    color: #244735;
    font: inherit;
}
.picker-label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 550;
}
.picker-label > span:first-child {
    color: #946c34;
}
.picker-trigger {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 80px;
    border: 1px solid #d7e1d5;
    border-radius: 16px;
    padding: 14px;
    background: linear-gradient(115deg, #fff 55%, #f5f7ef);
    text-align: left;
    box-shadow:
        0 2px 4px #24473504,
        inset 0 1px 0 #fff;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    transition:
        border-color 0.18s,
        box-shadow 0.18s,
        background 0.18s;
}
.picker-trigger:not(:disabled):hover,
.picker-trigger.is-open {
    border-color: #8ba68a;
    box-shadow:
        0 0 0 3px #526f4810,
        0 5px 16px #24473508;
}
.picker-trigger.has-selection {
    border-color: #aac0a5;
    background: linear-gradient(115deg, #f7faf4, #fff);
}
.picker-trigger:focus-visible {
    outline: 3px solid #b99754;
    outline-offset: 3px;
}
.picker-trigger.has-error {
    border-color: #ac503c;
}
.picker-trigger:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}
.type-icon {
    display: grid;
    flex: 0 0 42px;
    place-items: center;
    height: 44px;
    border: 1px solid #e7dfc9;
    border-radius: 13px;
    background: linear-gradient(140deg, #faf5e9, #f1ead8);
    color: #8a7139;
}
.type-icon.is-annual {
    border-color: #dce7d7;
    background: linear-gradient(140deg, #eff5e9, #e1ecdb);
    color: #507247;
}
.type-icon svg {
    width: 21px;
    height: 21px;
    stroke-width: 1.65;
}
.trigger-copy,
.option-copy {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 4px;
    overflow-wrap: anywhere;
}
.trigger-title {
    font-size: 14px;
    line-height: 1.5;
    font-weight: 650;
}
.trigger-description {
    color: #6b7d64;
    font-size: 11px;
    line-height: 1.6;
    font-weight: 400;
}
.trigger-quota {
    flex-shrink: 0;
    border: 1px solid #e6ddc4;
    border-radius: 8px;
    padding: 4px 7px;
    background: #f8f3e6;
    color: #80652e;
    font-size: 15px;
    font-weight: 700;
    white-space: nowrap;
}
.trigger-quota small {
    font-size: 10px;
    font-weight: 500;
}
.trigger-chevron {
    flex: 0 0 16px;
    width: 16px;
    height: 16px;
    color: #6c8062;
    transition: transform 0.2s;
}
.trigger-chevron.rotated {
    transform: rotate(180deg);
}
/* Dialog is teleported outside the component's scoped root. */
:global(.type-picker-dialog) {
    position: fixed;
    inset: 0;
    z-index: 110;
    color: #244735;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}
.picker-overlay {
    position: fixed;
    inset: 0;
    background: #102d36a6;
    backdrop-filter: blur(7px);
}
.picker-position {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100dvh;
    padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom))
        max(16px, env(safe-area-inset-left));
}
.picker-panel {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    width: 100%;
    max-width: 480px;
    max-height: min(640px, 100%);
    overflow: hidden;
    border: 1px solid #dce5d6;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 24px 80px #102d3633;
}
.panel-heading {
    display: flex;
    flex-shrink: 0;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #edf0e7;
    padding: 18px;
    background: linear-gradient(110deg, #f4f7ef, #fcfbf6);
}
.panel-heading h2 {
    margin: 0;
    font-size: 19px;
    line-height: 1.4;
    font-weight: 750;
}
.panel-heading p {
    margin: 6px 0 0;
    color: #6b7d64;
    font-size: 12px;
    line-height: 1.6;
}
.picker-close,
.picker-search button {
    display: grid;
    flex: 0 0 44px;
    place-items: center;
    width: 44px;
    height: 44px;
    border: 1px solid #e3eae5;
    border-radius: 12px;
    background: #fff;
    color: #526f48;
    cursor: pointer;
}
.picker-close svg,
.picker-search svg {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
}
.picker-search {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    gap: 10px;
    min-height: 48px;
    margin: 16px 16px 0;
    border: 1px solid #d7e1d5;
    border-radius: 12px;
    padding-left: 12px;
    color: #6b7d64;
    background: #fafcf8;
}
.picker-search:focus-within {
    border-color: #8ba68a;
    box-shadow: 0 0 0 3px #526f4810;
}
.picker-search input {
    flex: 1;
    min-width: 0;
    width: 100%;
    border: 0;
    padding: 12px 8px 12px 0;
    outline: none;
    background: transparent;
    color: #244735;
    font: inherit;
    font-size: 14px;
    box-shadow: none;
}
.picker-search input::-webkit-search-cancel-button {
    display: none;
}
.picker-search button {
    border: 0;
    background: transparent;
}
.search-count {
    flex-shrink: 0;
    margin: 10px 18px 6px;
    color: #758266;
    font-size: 11px;
    line-height: 1.6;
}
.picker-options {
    display: grid;
    flex-shrink: 1;
    gap: 6px;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    scroll-padding-block: 8px;
    margin: 0;
    padding: 8px 12px;
    list-style: none;
    outline: none;
}
.type-option {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 82px;
    border: 1px solid transparent;
    border-radius: 12px;
    padding: 11px 10px;
    background: #fff;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition:
        background 0.16s,
        border-color 0.16s;
}
.type-option:not(:disabled):hover,
.type-option:focus-visible {
    border-color: #c5d3bc;
    background: #f5f8f1;
}
.picker-panel button:focus-visible {
    outline: 3px solid #b99754;
    outline-offset: 2px;
}
.picker-panel button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}
.search-empty {
    display: flex;
    min-height: 0;
    flex-direction: column;
    align-items: center;
    overflow-y: auto;
    padding: 24px 16px;
    text-align: center;
}
.search-empty > svg {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    margin-bottom: 12px;
    color: #8ba077;
}
.search-empty strong {
    font-size: 14px;
}
.search-empty p {
    margin: 8px 0 12px;
    color: #6b7d64;
    font-size: 12px;
    line-height: 1.6;
}
.search-empty button {
    min-height: 44px;
    border: 1px solid #c5d3bc;
    border-radius: 10px;
    padding: 8px 14px;
    background: #f5f8f1;
    color: #45683b;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.type-option.is-selected {
    border-color: #aec49e;
    background: linear-gradient(115deg, #eff5e8, #f8faf4);
}
.type-option .type-icon {
    flex-basis: 36px;
    height: 40px;
    border-radius: 11px;
}
.type-option .type-icon svg {
    width: 19px;
    height: 19px;
}
.option-category {
    color: #7b8064;
    font-size: 9px;
    line-height: 1.5;
    font-weight: 600;
    letter-spacing: 0.06em;
}
.option-name {
    font-size: 13px;
    line-height: 1.5;
    font-weight: 650;
}
.option-quota {
    flex-shrink: 0;
    text-align: right;
}
.option-quota strong {
    display: block;
    color: #45683b;
    font-size: 21px;
    line-height: 1.2;
    font-weight: 700;
    letter-spacing: -0.04em;
    font-variant-numeric: tabular-nums;
}
.option-quota strong small {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0;
}
.option-quota > span {
    display: block;
    margin-top: 3px;
    color: #758266;
    font-size: 10px;
    line-height: 1.5;
}
.option-check {
    display: grid;
    flex: 0 0 18px;
    place-items: center;
    height: 18px;
    border: 1px solid #d8e1d1;
    border-radius: 50%;
    background: #fff;
}
.is-selected .option-check {
    border-color: #466c3c;
    background: #466c3c;
    color: #fff;
}
.option-check svg {
    width: 12px;
    height: 12px;
    stroke-width: 2.5;
}
.quota-empty,
.depleted,
.depleted strong {
    color: #a06b35;
}
.quota-empty {
    font-size: 10px;
    line-height: 1.5;
}
.panel-note,
.selection-note {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    margin: 0;
    color: #6d7b60;
    font-size: 11px;
    line-height: 1.7;
    font-weight: 400;
}
.panel-note {
    align-items: center;
    flex-shrink: 0;
    border-top: 1px solid #edf0e7;
    padding: 10px 14px;
    background: #fafbf7;
}
.panel-note > span {
    flex: 0 0 5px;
    height: 5px;
    border-radius: 50%;
    background: #bca369;
}
.selection-note {
    margin-top: 8px;
}
.selection-note svg {
    flex: 0 0 13px;
    width: 13px;
    height: 13px;
    margin-top: 3px;
    color: #5a7d49;
}
.picker-error {
    margin: 8px 0 0;
    color: #a34834;
    font-size: 13px;
    line-height: 1.6;
}
@media (max-width: 380px) {
    .picker-trigger {
        gap: 9px;
        padding: 12px;
    }
    .trigger-description {
        font-size: 10px;
    }
    .type-option {
        gap: 8px;
        padding-inline: 8px;
    }
    .type-option .type-icon {
        flex-basis: 30px;
        height: 36px;
    }
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        transition: none !important;
    }
}
</style>
