<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Crown, KeyRound, LoaderCircle, Save, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import type { PermissionGroup } from '../types';
import PermissionChecklist from './PermissionChecklist.vue';

const props = defineProps<{
    permissionGroups: PermissionGroup[];
    submitUrl: string;
    initial?: { name: string; permission_ids: number[]; is_system?: boolean };
    editing?: boolean;
    embedded?: boolean;
}>();

const emit = defineEmits<{
    cancel: [];
    saved: [];
}>();

const form = useForm({
    name: props.initial?.name ?? '',
    permission_ids: props.initial?.permission_ids ?? ([] as number[]),
});

const permissionTotal = computed(() => props.permissionGroups.reduce((total, group) => total + group.permissions.length, 0));
const formattedRoleName = computed(() => form.name.trim().replaceAll('-', ' ').replaceAll('_', ' ') || 'Nama role');

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('saved') };
    if (props.editing) form.put(props.submitUrl, options);
    else form.post(props.submitUrl, options);
};
</script>

<template>
    <form class="user-premium-form role-premium-form" :class="{ card: !embedded, 'is-embedded': embedded }" @submit.prevent="submit">
        <div :class="embedded ? 'admin-modal-body' : 'card-body'">
            <div class="user-form-preview role-form-preview">
                <span class="user-form-avatar role-form-avatar">
                    <Crown v-if="initial?.is_system" />
                    <ShieldCheck v-else />
                </span>
                <div>
                    <span class="user-form-preview-kicker"><Sparkles /> Pratinjau role</span>
                    <strong class="text-capitalize">{{ formattedRoleName }}</strong>
                    <small>{{ form.name || 'nama-role' }}</small>
                </div>
                <div class="user-form-preview-stats">
                    <span><KeyRound /> {{ form.permission_ids.length }} permission</span>
                    <span v-if="initial?.is_system"><Crown /> Role sistem</span>
                    <span v-else><ShieldCheck /> Role khusus</span>
                </div>
            </div>

            <section class="user-form-section" aria-labelledby="role-identity-form-title">
                <header class="user-form-section-heading">
                    <span><ShieldCheck /></span>
                    <div>
                        <small>Langkah 01</small>
                        <h3 id="role-identity-form-title">Identitas role</h3>
                        <p>Tetapkan nama singkat dan konsisten agar role mudah dikenali saat diberikan kepada pengguna.</p>
                    </div>
                </header>

                <div class="user-form-grid role-form-grid">
                    <div class="user-form-field">
                        <label for="name">Nama role <span>*</span></label>
                        <div class="user-form-input" :class="{ 'is-disabled': initial?.is_system }">
                            <ShieldCheck />
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                :class="{ 'is-invalid': form.errors.name }"
                                :disabled="initial?.is_system"
                                aria-describedby="role-name-hint"
                                autocomplete="off"
                                placeholder="Contoh: kepala-unit"
                                autofocus
                            />
                        </div>
                        <small id="role-name-hint" class="user-field-hint">
                            {{
                                initial?.is_system
                                    ? 'Nama role sistem dikunci untuk menjaga akses inti aplikasi.'
                                    : 'Gunakan huruf kecil; spasi, tanda hubung, dan garis bawah diperbolehkan.'
                            }}
                        </small>
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                </div>
            </section>

            <section class="user-form-section" aria-labelledby="role-permission-form-title">
                <header class="user-form-section-heading">
                    <span class="tone-cyan"><KeyRound /></span>
                    <div>
                        <small>Langkah 02</small>
                        <h3 id="role-permission-form-title">Permission role</h3>
                        <p>
                            {{
                                initial?.is_system
                                    ? 'Super admin otomatis memperoleh seluruh permission dan tidak dapat diubah.'
                                    : 'Pilih kewenangan yang otomatis diperoleh setiap pengguna dengan role ini.'
                            }}
                        </p>
                    </div>
                    <span class="user-form-selection-count">{{ form.permission_ids.length }} dipilih</span>
                </header>

                <div class="user-form-access-note" :class="{ 'role-system-note': initial?.is_system }">
                    <Crown v-if="initial?.is_system" />
                    <KeyRound v-else />
                    <span v-if="initial?.is_system"><strong>Role dilindungi.</strong> Seluruh akses akan tetap tersinkron untuk super admin.</span>
                    <span v-else
                        ><strong>Terapkan prinsip akses minimum.</strong> Berikan hanya permission yang benar-benar dibutuhkan role ini.</span
                    >
                </div>
                <PermissionChecklist v-model="form.permission_ids" :groups="permissionGroups" :disabled="initial?.is_system" />
                <InputError class="mt-2" :message="form.errors.permission_ids" />
            </section>
        </div>

        <div :class="embedded ? 'admin-modal-footer user-form-footer' : 'card-footer user-form-footer'">
            <div class="user-form-footer-summary">
                <BadgeCheck />
                <span>
                    <strong>{{ editing ? 'Perbarui dengan aman' : 'Siap membuat role' }}</strong>
                    <small>{{ form.permission_ids.length }} dari {{ permissionTotal }} permission dipilih</small>
                </span>
            </div>
            <div>
                <button v-if="embedded" type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="emit('cancel')">
                    Batal
                </button>
                <Link v-else href="/absensi/admin-web/setting/roles" class="btn btn-outline-secondary">Batal</Link>
                <button
                    type="submit"
                    class="btn btn-primary user-form-submit"
                    :disabled="form.processing || Boolean(initial?.is_system) || (editing && !form.isDirty)"
                >
                    <LoaderCircle v-if="form.processing" class="user-submit-spinner" />
                    <Save v-else />
                    {{ form.processing ? 'Menyimpan...' : editing ? 'Simpan perubahan' : 'Buat role' }}
                </button>
            </div>
        </div>
    </form>
</template>
