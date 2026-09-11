<script setup lang="ts">
import { Check, KeyRound, Search, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { PermissionGroup } from '../types';

const props = withDefaults(
    defineProps<{
        modelValue: number[];
        groups: PermissionGroup[];
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: number[]];
}>();

const query = ref('');
const selected = computed(() => new Set(props.modelValue));
const permissionTotal = computed(() => props.groups.reduce((total, group) => total + group.permissions.length, 0));
const filteredGroups = computed(() => {
    const term = query.value.trim().toLowerCase();
    if (!term) return props.groups;

    return props.groups
        .map((group) => ({
            ...group,
            permissions: group.permissions.filter(
                (permission) => permission.name.toLowerCase().includes(term) || group.group.toLowerCase().includes(term),
            ),
        }))
        .filter((group) => group.permissions.length);
});

const togglePermission = (id: number) => {
    const next = new Set(props.modelValue);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    emit('update:modelValue', [...next]);
};

const toggleGroup = (ids: number[]) => {
    const next = new Set(props.modelValue);
    const allSelected = ids.every((id) => next.has(id));
    ids.forEach((id) => {
        if (allSelected) next.delete(id);
        else next.add(id);
    });
    emit('update:modelValue', [...next]);
};
</script>

<template>
    <div v-if="groups.length" class="permission-checklist">
        <div class="permission-checklist-toolbar">
            <div>
                <span><KeyRound /></span>
                <p>
                    <strong>{{ modelValue.length }} dipilih</strong><small>dari {{ permissionTotal }} permission tersedia</small>
                </p>
            </div>
            <label v-if="permissionTotal > 6" class="permission-checklist-search">
                <Search />
                <span class="visually-hidden">Cari permission</span>
                <input v-model="query" type="search" placeholder="Cari permission..." :disabled="disabled" />
                <button v-if="query" type="button" aria-label="Hapus pencarian permission" @click="query = ''"><X /></button>
            </label>
        </div>

        <div v-if="filteredGroups.length" class="permission-group-grid">
            <fieldset
                v-for="group in filteredGroups"
                :key="group.group"
                class="admin-permission-group"
                :class="{ 'has-selection': group.permissions.some((permission) => selected.has(permission.id)) }"
                :disabled="disabled"
            >
                <div class="permission-group-heading">
                    <div>
                        <span>{{ group.group.charAt(0).toUpperCase() }}</span>
                        <p>
                            <strong>{{ group.group }}</strong>
                            <small
                                >{{ group.permissions.filter((item) => selected.has(item.id)).length }}/{{ group.permissions.length }} dipilih</small
                            >
                        </p>
                    </div>
                    <button type="button" :disabled="disabled" @click="toggleGroup(group.permissions.map((item) => item.id))">
                        {{ group.permissions.every((item) => selected.has(item.id)) ? 'Kosongkan' : 'Pilih semua' }}
                    </button>
                </div>
                <div class="permission-option-list">
                    <label v-for="permission in group.permissions" :key="permission.id" :class="{ selected: selected.has(permission.id) }">
                        <input
                            type="checkbox"
                            :checked="selected.has(permission.id)"
                            :disabled="disabled"
                            @change="togglePermission(permission.id)"
                        />
                        <span><Check /></span>
                        <span>{{ permission.name }}</span>
                    </label>
                </div>
            </fieldset>
        </div>

        <div v-else class="permission-checklist-empty">
            <Search />
            <strong>Permission tidak ditemukan</strong>
            <span>Coba kata kunci lain.</span>
            <button type="button" @click="query = ''">Hapus pencarian</button>
        </div>
    </div>
    <p v-else class="user-form-inline-empty"><KeyRound /> Belum ada permission. Tambahkan permission terlebih dahulu.</p>
</template>
