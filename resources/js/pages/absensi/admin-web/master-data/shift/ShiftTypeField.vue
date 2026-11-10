<script setup lang="ts">
defineProps<{ modelValue: boolean; error?: string }>();
defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <label class="shift-type-field">
        <span>Jenis shift</span>
        <select :value="modelValue ? 'off' : 'work'" @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value === 'off')">
            <option value="work">Kerja</option>
            <option value="off">OFF / Libur</option>
        </select>
        <small v-if="error" class="shift-type-error">{{ error }}</small>
        <small v-else-if="modelValue">Hari libur tanpa jam kerja. Pegawai tidak perlu melakukan presensi.</small>
    </label>
</template>

<style scoped>
.shift-type-field {
    display: grid;
    gap: 8px;
    margin-bottom: 18px;
    color: #334155;
    font-size: 13px;
    font-weight: 600;
}
.shift-type-field select {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 10px 12px;
    background: white;
    color: #0f172a;
    font: inherit;
}
.shift-type-field small {
    font-weight: 400;
}
.shift-type-error {
    color: #dc2626;
}
</style>
