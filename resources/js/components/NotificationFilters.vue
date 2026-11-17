<script setup lang="ts">
import NotificationFilterSelect from '@/components/NotificationFilterSelect.vue';
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import type { NotificationFilterOptions, NotificationFilterValues } from '@/types/notification-filters';
import { Dialog, DialogDescription, DialogPanel, DialogTitle } from '@headlessui/vue';
import { ChevronRight, SlidersHorizontal, X } from 'lucide-vue-next';
import { computed, onUnmounted, reactive, ref, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        filters: NotificationFilterValues;
        filterOptions: NotificationFilterOptions;
        disabled?: boolean;
        dateLabel?: string;
        mobile?: boolean;
    }>(),
    { disabled: false, dateLabel: 'Tanggal pengajuan', mobile: false },
);
const emit = defineEmits<{ apply: [filters: NotificationFilterValues] }>();
const empty = (): NotificationFilterValues => ({ date_from: '', date_to: '', unit: '', jabatan: '', employee_name: '' });
const values = (filters: NotificationFilterValues) =>
    Object.fromEntries(Object.keys(empty()).map((key) => [key, filters[key as keyof NotificationFilterValues] ?? ''])) as NotificationFilterValues;
const draft = reactive(values(props.filters));
const id = useId();
const open = ref(false);
const closeButton = ref<HTMLButtonElement | null>(null);
const invalidRange = computed(() => Boolean(draft.date_from && draft.date_to && draft.date_to < draft.date_from));
const shortDate = (date: string) => date.split('-').reverse().join('/');
const activeFilters = computed(() => {
    const filters = values(props.filters);
    const dates =
        filters.date_from && filters.date_to
            ? `${shortDate(filters.date_from)} – ${shortDate(filters.date_to)}`
            : filters.date_from
              ? `Sejak ${shortDate(filters.date_from)}`
              : filters.date_to
                ? `Sampai ${shortDate(filters.date_to)}`
                : '';
    return [
        dates,
        filters.unit && `Unit: ${filters.unit}`,
        filters.jabatan && `Jabatan: ${filters.jabatan}`,
        filters.employee_name && `Nama: ${filters.employee_name}`,
    ].filter(Boolean);
});
watch(
    () => props.filters,
    (filters) => Object.assign(draft, values(filters)),
    { deep: true },
);
const apply = () => {
    if (props.disabled || invalidRange.value) return;
    open.value = false;
    emit('apply', { ...draft, employee_name: draft.employee_name.trim() });
};
const reset = () => {
    if (props.disabled) return;
    Object.assign(draft, empty());
    apply();
};
const openFilters = () => {
    if (props.disabled) return;
    Object.assign(draft, values(props.filters));
    open.value = true;
};
const closeFilters = () => {
    open.value = false;
    Object.assign(draft, values(props.filters));
};
if (props.mobile) {
    const removeBackHandler = registerMobileBackHandler(() => {
        if (!open.value) return false;
        closeFilters();
        return true;
    });
    onUnmounted(removeBackHandler);
}
</script>

<template>
    <div class="notification-filters" :class="{ 'is-mobile': mobile }">
        <template v-if="mobile">
            <button
                type="button"
                class="filter-trigger"
                :class="{ 'has-active-filters': activeFilters.length > 0 }"
                :disabled="disabled"
                aria-haspopup="dialog"
                :aria-expanded="open"
                @click="openFilters"
            >
                <span class="filter-trigger-icon" aria-hidden="true"><SlidersHorizontal /></span>
                <span class="filter-trigger-copy">
                    <strong>Filter notifikasi</strong>
                    <span v-if="activeFilters.length" class="filter-active-badge">
                        <span class="filter-badge-dot" aria-hidden="true"></span>Filter aktif ({{ activeFilters.length }})
                    </span>
                    <small v-else>Tanggal, unit, jabatan, atau nama</small>
                </span>
                <ChevronRight class="filter-chevron" aria-hidden="true" />
            </button>
            <div v-if="activeFilters.length" class="active-filters" role="status">
                <span v-for="filter in activeFilters" :key="filter" class="active-filter">{{ filter }}</span>
                <button type="button" class="clear-filters" :disabled="disabled" @click="reset">Reset filter</button>
            </div>
        </template>

        <component
            :is="mobile ? Dialog : 'div'"
            v-bind="mobile ? { open, initialFocus: closeButton } : {}"
            :class="mobile ? 'notification-filter-dialog' : undefined"
            @close="closeFilters"
        >
            <div v-if="mobile" class="filter-backdrop" aria-hidden="true"></div>
            <div :class="{ 'filter-position': mobile }">
                <component :is="mobile ? DialogPanel : 'div'" :class="{ 'filter-sheet': mobile }">
                    <header v-if="mobile" class="filter-sheet-header">
                        <span class="filter-handle" aria-hidden="true"></span>
                        <div>
                            <DialogTitle as="h2">Filter notifikasi</DialogTitle>
                            <DialogDescription>Pilih filter untuk menemukan kabar Anda.</DialogDescription>
                        </div>
                        <button ref="closeButton" type="button" class="close-filter" aria-label="Tutup filter notifikasi" @click="closeFilters">
                            <X />
                        </button>
                    </header>
                    <form class="filter-form" aria-label="Filter notifikasi" @submit.prevent="apply">
                        <fieldset :disabled="disabled">
                            <legend v-if="!mobile">Filter notifikasi</legend>
                            <div class="filter-body">
                                <p class="date-hint">Rentang tanggal berdasarkan {{ dateLabel.toLowerCase() }}.</p>
                                <div class="filter-fields">
                                    <label :for="`${id}-from`"
                                        >Dari tanggal<input
                                            :id="`${id}-from`"
                                            v-model="draft.date_from"
                                            type="date"
                                            :max="draft.date_to || undefined"
                                            :aria-invalid="invalidRange || undefined"
                                            :aria-describedby="invalidRange ? `${id}-error` : undefined"
                                    /></label>
                                    <label :for="`${id}-to`"
                                        >Sampai tanggal<input
                                            :id="`${id}-to`"
                                            v-model="draft.date_to"
                                            type="date"
                                            :min="draft.date_from || undefined"
                                            :aria-invalid="invalidRange || undefined"
                                            :aria-describedby="invalidRange ? `${id}-error` : undefined"
                                    /></label>
                                    <NotificationFilterSelect
                                        v-model="draft.unit"
                                        label="Unit"
                                        all-label="Semua unit"
                                        :options="filterOptions.units"
                                        :disabled="disabled"
                                        :mobile="mobile"
                                    />
                                    <NotificationFilterSelect
                                        v-model="draft.jabatan"
                                        label="Jabatan"
                                        all-label="Semua jabatan"
                                        :options="filterOptions.positions"
                                        :disabled="disabled"
                                        :mobile="mobile"
                                    />
                                    <label :for="`${id}-name`"
                                        >Nama pegawai<input
                                            :id="`${id}-name`"
                                            v-model="draft.employee_name"
                                            type="search"
                                            maxlength="150"
                                            placeholder="Cari nama pegawai..."
                                    /></label>
                                </div>
                                <p v-if="invalidRange" :id="`${id}-error`" class="filter-error" role="alert">
                                    Tanggal akhir harus sama atau setelah tanggal awal.
                                </p>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="apply-filter" :disabled="invalidRange">Terapkan filter</button>
                                <button type="button" @click="reset">Reset filter</button>
                            </div>
                        </fieldset>
                    </form>
                </component>
            </div>
        </component>
    </div>
</template>

<style scoped>
.notification-filters {
    margin: 16px 0;
    padding: 18px;
    border: 1px solid var(--rs-border, #dfe7dc);
    border-radius: 14px;
    background: var(--rs-canvas, #fff);
    color: var(--rs-text, #253e30);
}
fieldset {
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
legend {
    margin: 0 0 6px;
    padding: 0;
    font-size: 13px;
    font-weight: 700;
}
.date-hint {
    margin: 0 0 14px;
    color: var(--rs-muted, #647460);
    font-size: 11px;
}
.filter-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: 12px;
}
label {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
}
input,
select {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    box-sizing: border-box;
    padding: 9px 10px;
    border: 1px solid var(--rs-border, #dfe7dc);
    border-radius: 8px;
    background: #fff;
    color: #253e30;
    font: inherit;
    font-weight: 400;
}
input:focus-visible,
select:focus-visible,
button:focus-visible {
    outline: 2px solid var(--rs-primary, #275e43);
    outline-offset: 2px;
}
.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
}
button {
    min-height: 44px;
    padding: 10px 16px;
    border: 1px solid var(--rs-border, #dfe7dc);
    border-radius: 8px;
    background: #fff;
    color: #275e43;
    font-size: 12px;
    font-weight: 600;
}
button.apply-filter {
    border-color: var(--rs-primary, #275e43);
    background: var(--rs-primary, #275e43);
    color: #fff;
}
button:disabled,
fieldset:disabled {
    opacity: 0.6;
}
.filter-error {
    margin-top: 12px;
    color: #b42318;
    font-size: 12px;
}
.notification-filters.is-mobile {
    padding: 0;
    border: 0;
    background: transparent;
}
.filter-trigger {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 72px;
    padding: 12px 14px;
    border-color: #dfe7dc;
    border-radius: 16px;
    color: #253e30;
    text-align: left;
}
.filter-trigger-icon {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #edf4e8;
    color: #275e43;
}
.filter-trigger.has-active-filters {
    border-color: #a9c6a0;
    background: #fafff8;
}
.filter-trigger-icon svg,
.close-filter svg {
    width: 19px;
    height: 19px;
}
.filter-trigger-copy {
    flex: 1;
    min-width: 0;
}
.filter-trigger-copy strong {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    font-size: 13px;
}
.filter-trigger-copy small {
    display: block;
    margin-top: 4px;
    color: #647460;
    font-size: 11px;
    font-weight: 400;
    line-height: 1.5;
}
.filter-active-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
    padding: 3px 7px;
    border: 1px solid #c5ddbc;
    border-radius: 999px;
    background: #eaf3e5;
    color: #275e43;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.4;
}
.filter-badge-dot {
    flex-shrink: 0;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.filter-chevron {
    flex-shrink: 0;
    width: 17px;
    height: 17px;
    color: #71836a;
}
.active-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}
.active-filter {
    max-width: 100%;
    padding: 6px 9px;
    border: 1px solid #dfe7d9;
    border-radius: 8px;
    background: #edf4e8;
    color: #375a31;
    font-size: 11px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}
.clear-filters {
    padding: 6px 10px;
    border-color: transparent;
    background: transparent;
    font-size: 11px;
}
:global(.notification-filter-dialog) {
    position: fixed;
    inset: 0;
    z-index: 100;
    color: #253e30;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}
.filter-backdrop {
    position: fixed;
    inset: 0;
    background: #102d3680;
    backdrop-filter: blur(4px);
}
.filter-position {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    height: 100dvh;
    padding-top: calc(12px + env(safe-area-inset-top, 0px));
}
.filter-sheet {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 500px;
    max-height: 100%;
    min-height: 0;
    overflow: hidden;
    border-radius: 24px 24px 0 0;
    background: #fcfdfb;
    box-shadow: 0 -12px 60px #102d3626;
}
.filter-sheet-header {
    position: relative;
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 28px 20px 16px;
    border-bottom: 1px solid #e3e9df;
}
.filter-handle {
    position: absolute;
    top: 10px;
    left: calc(50% - 18px);
    width: 36px;
    height: 4px;
    border-radius: 8px;
    background: #d5dfd2;
}
.filter-sheet-header h2 {
    margin: 0;
    font-size: 19px;
    font-weight: 750;
    letter-spacing: -0.5px;
}
.filter-sheet-header p {
    margin: 5px 0 0;
    color: #647460;
    font-size: 12px;
    line-height: 1.6;
}
.close-filter {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 44px;
    padding: 0;
    border-radius: 12px;
}
.filter-sheet .filter-form,
.filter-sheet fieldset {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
}
.filter-sheet .filter-body {
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 18px 20px;
    scroll-padding-block: 18px;
}
.filter-sheet .date-hint {
    font-size: 12px;
    line-height: 1.6;
}
.filter-sheet .filter-fields {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px 12px;
}
.filter-sheet .filter-fields > :nth-child(n + 3) {
    grid-column: 1 / -1;
}
.filter-sheet label {
    gap: 8px;
    font-size: 13px;
}
.filter-sheet input,
.filter-sheet select {
    min-height: 48px;
    padding: 12px 10px;
    border-radius: 12px;
    font-size: 16px;
}
.filter-sheet input[type='date'] {
    display: block;
    max-width: 100%;
    padding-inline: 8px;
    font-size: 16px;
}
.filter-sheet .filter-actions {
    flex-shrink: 0;
    flex-wrap: nowrap;
    margin: 0;
    padding: 12px 20px calc(12px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #e3e9df;
    background: #fcfdfb;
}
.filter-sheet .filter-actions > button {
    flex: 1;
    min-height: 48px;
    padding-inline: 10px;
    border-radius: 12px;
    font-size: 13px;
}
.filter-sheet .filter-actions > .apply-filter {
    order: 1;
    flex: 1.6;
}
.filter-trigger,
.clear-filters,
.filter-sheet button {
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}
@media (max-width: 359px) {
    .filter-sheet-header,
    .filter-sheet .filter-body,
    .filter-sheet .filter-actions {
        padding-inline: 16px;
    }
    .filter-sheet .filter-fields {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (min-width: 640px) {
    .filter-position {
        align-items: center;
        padding: 24px;
    }
    .filter-sheet {
        border-radius: 24px;
        max-height: 90dvh;
    }
}
@media (max-width: 480px) {
    .filter-fields {
        grid-template-columns: minmax(0, 1fr);
    }
    .filter-actions > button {
        flex: 1;
    }
}
</style>
