<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { getInitials } from '@/composables/useInitials';
import { Link, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Check, Eye, EyeOff, KeyRound, LoaderCircle, LockKeyhole, Mail, Save, ShieldCheck, Sparkles, UserRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { PermissionGroup, SelectOption } from '../types';
import PermissionChecklist from './PermissionChecklist.vue';

interface UserFormData {
    [key: string]: string | number | number[];
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    role_ids: number[];
    permission_ids: number[];
}

const props = defineProps<{
    roles: SelectOption[];
    permissionGroups: PermissionGroup[];
    submitUrl: string;
    initial?: Partial<UserFormData>;
    editing?: boolean;
    embedded?: boolean;
}>();

const emit = defineEmits<{
    cancel: [];
    saved: [];
}>();

const form = useForm<UserFormData>({
    name: props.initial?.name ?? '',
    email: props.initial?.email ?? '',
    password: '',
    password_confirmation: '',
    role_ids: props.initial?.role_ids ?? [],
    permission_ids: props.initial?.permission_ids ?? [],
});

const showPassword = ref(false);
const showConfirmation = ref(false);
const selectedRoles = computed(() => props.roles.filter((role) => form.role_ids.includes(role.id)));
const passwordMatches = computed(() => Boolean(form.password_confirmation) && form.password === form.password_confirmation);
const passwordScore = computed(() => {
    if (!form.password) return 0;

    return [form.password.length >= 8, /[A-Z]/.test(form.password), /\d/.test(form.password), /[^A-Za-z0-9]/.test(form.password)].filter(Boolean)
        .length;
});
const passwordStrength = computed(() => {
    if (passwordScore.value <= 1) return { label: 'Dasar', tone: 'weak' };
    if (passwordScore.value <= 3) return { label: 'Baik', tone: 'medium' };
    return { label: 'Kuat', tone: 'strong' };
});

const formatAccessLabel = (value: string) => value.replaceAll('-', ' ').replaceAll('.', ' / ');

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => emit('saved') };
    if (props.editing) form.put(props.submitUrl, options);
    else form.post(props.submitUrl, options);
};
</script>

<template>
    <form class="user-premium-form" :class="{ card: !embedded, 'is-embedded': embedded }" @submit.prevent="submit">
        <div :class="embedded ? 'admin-modal-body' : 'card-body'">
            <div class="user-form-preview">
                <span class="user-form-avatar">{{ getInitials(form.name) || '?' }}</span>
                <div>
                    <span class="user-form-preview-kicker"><Sparkles /> Pratinjau akun</span>
                    <strong>{{ form.name || 'Nama pengguna' }}</strong>
                    <small>{{ form.email || 'email@rsarsy.co.id' }}</small>
                </div>
                <div class="user-form-preview-stats">
                    <span><ShieldCheck /> {{ form.role_ids.length }} role</span>
                    <span><KeyRound /> {{ form.permission_ids.length }} akses langsung</span>
                </div>
            </div>

            <section class="user-form-section" aria-labelledby="user-identity-form-title">
                <header class="user-form-section-heading">
                    <span><UserRound /></span>
                    <div>
                        <small>Langkah 01</small>
                        <h3 id="user-identity-form-title">Identitas pengguna</h3>
                        <p>Informasi utama untuk mengenali dan menghubungi pemilik akun.</p>
                    </div>
                </header>

                <div class="user-form-grid">
                    <div class="user-form-field">
                        <label for="name">Nama lengkap <span>*</span></label>
                        <div class="user-form-input">
                            <UserRound />
                            <input
                                id="name"
                                v-model="form.name"
                                type="text"
                                :class="{ 'is-invalid': form.errors.name }"
                                autocomplete="name"
                                placeholder="Contoh: Ahmad Fauzi"
                                autofocus
                            />
                        </div>
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>
                    <div class="user-form-field">
                        <label for="email">Alamat email <span>*</span></label>
                        <div class="user-form-input">
                            <Mail />
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                :class="{ 'is-invalid': form.errors.email }"
                                autocomplete="email"
                                placeholder="nama@rsarsy.co.id"
                            />
                        </div>
                        <small class="user-field-hint">Digunakan sebagai identitas untuk masuk ke sistem.</small>
                        <InputError class="mt-1" :message="form.errors.email" />
                    </div>
                </div>
            </section>

            <section class="user-form-section" aria-labelledby="user-security-form-title">
                <header class="user-form-section-heading">
                    <span class="tone-violet"><LockKeyhole /></span>
                    <div>
                        <small>Langkah 02</small>
                        <h3 id="user-security-form-title">Keamanan akun</h3>
                        <p>{{ editing ? 'Biarkan kosong jika kata sandi tidak perlu diubah.' : 'Gunakan minimal 8 karakter yang sulit ditebak.' }}</p>
                    </div>
                </header>

                <div class="user-form-grid">
                    <div class="user-form-field">
                        <label for="password">Kata sandi <span v-if="!editing">*</span><em v-else>Opsional</em></label>
                        <div class="user-form-input has-action">
                            <LockKeyhole />
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                :class="{ 'is-invalid': form.errors.password }"
                                autocomplete="new-password"
                                placeholder="Minimal 8 karakter"
                            />
                            <button
                                type="button"
                                :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                                :aria-pressed="showPassword"
                                @click="showPassword = !showPassword"
                            >
                                <EyeOff v-if="showPassword" />
                                <Eye v-else />
                            </button>
                        </div>
                        <div v-if="form.password" class="user-password-strength" :class="passwordStrength.tone">
                            <span><i v-for="level in 4" :key="level" :class="{ active: level <= passwordScore }"></i></span>
                            <small>Kekuatan: {{ passwordStrength.label }}</small>
                        </div>
                        <InputError class="mt-1" :message="form.errors.password" />
                    </div>
                    <div class="user-form-field">
                        <label for="password_confirmation">Konfirmasi kata sandi <span v-if="!editing">*</span></label>
                        <div class="user-form-input has-action">
                            <BadgeCheck />
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                :type="showConfirmation ? 'text' : 'password'"
                                autocomplete="new-password"
                                placeholder="Ulangi kata sandi"
                            />
                            <button
                                type="button"
                                :aria-label="showConfirmation ? 'Sembunyikan konfirmasi kata sandi' : 'Tampilkan konfirmasi kata sandi'"
                                :aria-pressed="showConfirmation"
                                @click="showConfirmation = !showConfirmation"
                            >
                                <EyeOff v-if="showConfirmation" />
                                <Eye v-else />
                            </button>
                        </div>
                        <small v-if="passwordMatches" class="user-field-success"><Check /> Kata sandi cocok</small>
                    </div>
                </div>
            </section>

            <section class="user-form-section" aria-labelledby="user-role-form-title">
                <header class="user-form-section-heading">
                    <span class="tone-emerald"><ShieldCheck /></span>
                    <div>
                        <small>Langkah 03</small>
                        <h3 id="user-role-form-title">Role pengguna</h3>
                        <p>Pilih satu atau beberapa kelompok kewenangan yang sesuai dengan tanggung jawab pengguna.</p>
                    </div>
                    <span class="user-form-selection-count">{{ selectedRoles.length }} dipilih</span>
                </header>

                <div v-if="roles.length" class="user-role-option-grid">
                    <label v-for="role in roles" :key="role.id" :class="{ selected: form.role_ids.includes(role.id) }">
                        <input v-model="form.role_ids" type="checkbox" :value="role.id" />
                        <span><ShieldCheck /></span>
                        <span>
                            <strong>{{ formatAccessLabel(role.name) }}</strong>
                            <small>Role akses sistem</small>
                        </span>
                        <i><Check /></i>
                    </label>
                </div>
                <div v-else class="user-form-inline-empty"><ShieldCheck /> Belum ada role yang dapat dipilih.</div>
                <InputError class="mt-2" :message="form.errors.role_ids" />
            </section>

            <section class="user-form-section" aria-labelledby="user-permission-form-title">
                <header class="user-form-section-heading">
                    <span class="tone-cyan"><KeyRound /></span>
                    <div>
                        <small>Langkah 04 · Opsional</small>
                        <h3 id="user-permission-form-title">Permission langsung</h3>
                        <p>Tambahkan pengecualian akses di luar permission yang sudah diperoleh dari role.</p>
                    </div>
                    <span class="user-form-selection-count">{{ form.permission_ids.length }} dipilih</span>
                </header>

                <div class="user-form-access-note">
                    <KeyRound />
                    <span><strong>Gunakan seperlunya.</strong> Akses langsung cocok untuk kebutuhan khusus dan sebaiknya ditinjau berkala.</span>
                </div>
                <PermissionChecklist v-model="form.permission_ids" :groups="permissionGroups" />
                <InputError class="mt-2" :message="form.errors.permission_ids" />
            </section>
        </div>

        <div :class="embedded ? 'admin-modal-footer user-form-footer' : 'card-footer user-form-footer'">
            <div class="user-form-footer-summary">
                <BadgeCheck />
                <span
                    ><strong>{{ editing ? 'Perbarui dengan aman' : 'Siap membuat akun' }}</strong
                    ><small>{{ form.role_ids.length }} role · {{ form.permission_ids.length }} permission langsung</small></span
                >
            </div>
            <div>
                <button v-if="embedded" type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="emit('cancel')">
                    Batal
                </button>
                <Link v-else href="/absensi/admin-web/setting/users" class="btn btn-outline-secondary">Batal</Link>
                <button type="submit" class="btn btn-primary user-form-submit" :disabled="form.processing || (editing && !form.isDirty)">
                    <LoaderCircle v-if="form.processing" class="user-submit-spinner" />
                    <Save v-else />
                    {{ form.processing ? 'Menyimpan...' : editing ? 'Simpan perubahan' : 'Buat pengguna' }}
                </button>
            </div>
        </div>
    </form>
</template>
