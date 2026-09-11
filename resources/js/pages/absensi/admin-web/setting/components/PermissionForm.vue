<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { BadgeCheck, KeyRound, Layers3, LoaderCircle, LockKeyhole, Save, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    submitUrl: string;
    initial?: { name: string; is_system?: boolean };
    editing?: boolean;
    embedded?: boolean;
}>();

const emit = defineEmits<{
    cancel: [];
    saved: [];
}>();

const form = useForm({ name: props.initial?.name ?? '' });
const formattedPermissionName = computed(() => form.name.trim().replaceAll('-', ' ').replaceAll('.', ' / ') || 'Nama permission');
const permissionGroup = computed(() => form.name.trim().split('.')[0] || 'resource');
const hasValidPattern = computed(() => /^[a-z0-9]+(?:[.-][a-z0-9]+)*$/.test(form.name.trim()));

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('saved') };
    if (props.editing) form.put(props.submitUrl, options);
    else form.post(props.submitUrl, options);
};
</script>

<template>
    <form class="user-premium-form permission-premium-form" :class="{ card: !embedded, 'is-embedded': embedded }" @submit.prevent="submit">
        <div :class="embedded ? 'admin-modal-body' : 'card-body'">
            <div class="user-form-preview permission-form-preview">
                <span class="user-form-avatar permission-form-avatar">
                    <LockKeyhole v-if="initial?.is_system" />
                    <KeyRound v-else />
                </span>
                <div>
                    <span class="user-form-preview-kicker"><Sparkles /> Pratinjau permission</span>
                    <strong class="text-capitalize">{{ formattedPermissionName }}</strong>
                    <small>{{ form.name || 'resource.aksi' }}</small>
                </div>
                <div class="user-form-preview-stats">
                    <span class="text-capitalize"><Layers3 /> {{ permissionGroup }}</span>
                    <span v-if="initial?.is_system"><LockKeyhole /> Permission sistem</span>
                    <span v-else><ShieldCheck /> Permission khusus</span>
                </div>
            </div>

            <section class="user-form-section" aria-labelledby="permission-identity-form-title">
                <header class="user-form-section-heading">
                    <span><KeyRound /></span>
                    <div>
                        <small>Langkah 01</small>
                        <h3 id="permission-identity-form-title">Identitas permission</h3>
                        <p>Tetapkan nama teknis yang singkat dan konsisten agar kewenangan mudah dikelompokkan serta dikenali.</p>
                    </div>
                </header>

                <div class="user-form-grid role-form-grid">
                    <div class="user-form-field">
                        <label for="name">Nama permission <span>*</span></label>
                        <div class="user-form-input" :class="{ 'is-disabled': initial?.is_system }">
                            <KeyRound />
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                :class="{ 'is-invalid': form.errors.name }"
                                :disabled="initial?.is_system"
                                aria-describedby="permission-name-hint"
                                autocomplete="off"
                                placeholder="Contoh: attendance.approve"
                                autofocus
                            />
                        </div>
                        <small id="permission-name-hint" class="user-field-hint">
                            {{
                                initial?.is_system
                                    ? 'Nama permission sistem dikunci untuk menjaga kewenangan inti aplikasi.'
                                    : 'Gunakan huruf kecil dan pola resource.aksi; angka serta tanda hubung juga diperbolehkan.'
                            }}
                        </small>
                        <span v-if="form.name && hasValidPattern && !initial?.is_system" class="user-field-success">
                            <BadgeCheck /> Pola nama permission sudah sesuai
                        </span>
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                </div>

                <div class="user-form-access-note permission-form-note" :class="{ 'role-system-note': initial?.is_system }">
                    <LockKeyhole v-if="initial?.is_system" />
                    <KeyRound v-else />
                    <span v-if="initial?.is_system">
                        <strong>Permission dilindungi.</strong> Identitas permission ini hanya dapat ditinjau dan tidak dapat diubah.
                    </span>
                    <span v-else>
                        <strong>Jaga satu tanggung jawab.</strong> Satu permission sebaiknya mewakili satu aksi agar mudah diterapkan pada role.
                    </span>
                </div>
            </section>
        </div>

        <div :class="embedded ? 'admin-modal-footer user-form-footer' : 'card-footer user-form-footer'">
            <div class="user-form-footer-summary">
                <BadgeCheck />
                <span>
                    <strong>{{
                        initial?.is_system ? 'Permission inti terlindungi' : editing ? 'Perbarui dengan aman' : 'Siap membuat permission'
                    }}</strong>
                    <small>{{ initial?.is_system ? 'Tidak ada perubahan yang dapat disimpan' : `Grup: ${permissionGroup}` }}</small>
                </span>
            </div>
            <div>
                <button v-if="embedded" type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="emit('cancel')">
                    {{ initial?.is_system ? 'Tutup' : 'Batal' }}
                </button>
                <Link v-else href="/absensi/admin-web/setting/permissions" class="btn btn-outline-secondary">
                    {{ initial?.is_system ? 'Kembali' : 'Batal' }}
                </Link>
                <button
                    v-if="!initial?.is_system"
                    type="submit"
                    class="btn btn-primary user-form-submit"
                    :disabled="form.processing || !hasValidPattern || (editing && !form.isDirty)"
                >
                    <LoaderCircle v-if="form.processing" class="user-submit-spinner" />
                    <Save v-else />
                    {{ form.processing ? 'Menyimpan...' : editing ? 'Simpan perubahan' : 'Buat permission' }}
                </button>
            </div>
        </div>
    </form>
</template>
