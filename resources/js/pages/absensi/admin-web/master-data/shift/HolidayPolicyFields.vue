<script setup lang="ts">
import { holidayOptions, type HolidayPolicy } from './types';

defineProps<{ policy: HolidayPolicy; error?: string }>();
defineEmits<{ change: [field: keyof HolidayPolicy, value: boolean] }>();
</script>

<template>
    <fieldset class="holiday-policy">
        <legend>Hari libur shift</legend>
        <p>Centang hari yang diliburkan. Pilihan yang tidak dicentang tetap menjadi hari kerja.</p>
        <div class="holiday-policy-options">
            <label v-for="option in holidayOptions" :key="option.key">
                <input
                    type="checkbox"
                    :checked="policy[option.key]"
                    @change="$emit('change', option.key, ($event.target as HTMLInputElement).checked)"
                />
                <span
                    ><strong>{{ option.label }}</strong
                    ><small>{{ option.description }}</small></span
                >
            </label>
        </div>
        <p v-if="error" class="policy-error" role="alert">{{ error }}</p>
    </fieldset>
</template>

<style scoped>
.holiday-policy {
    min-width: 0;
    margin: 20px 0 0;
    padding: 18px;
    border: 1px solid #e6eaf1;
    border-radius: 14px;
}
.holiday-policy legend {
    float: none;
    width: auto;
    margin: 0;
    padding: 0 6px;
    color: #172033;
    font-size: 13px;
    font-weight: 700;
}
.holiday-policy p {
    margin: 0 0 14px;
    color: #697386;
    font-size: 12px;
    line-height: 1.6;
}
.holiday-policy-options {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
.holiday-policy-options label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
}
.holiday-policy-options input {
    flex-shrink: 0;
    margin-top: 3px;
    accent-color: #626fee;
}
.holiday-policy-options strong,
.holiday-policy-options small {
    display: block;
}
.holiday-policy-options strong {
    color: #172033;
    font-size: 13px;
}
.holiday-policy-options small {
    margin-top: 3px;
    color: #697386;
    font-size: 11px;
}
.holiday-policy .policy-error {
    margin-top: 12px;
    margin-bottom: 0;
    color: #dc2626;
}
@media (max-width: 520px) {
    .holiday-policy-options {
        grid-template-columns: 1fr;
    }
}
</style>
