<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import PresensiLoginLayout from '@/layouts/auth/PresensiLoginLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff, LoaderCircle, LockKeyhole, Mail, ShieldCheck } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    loginUrl: string;
    portal: 'presensi' | 'admin';
}>();

const form = useForm({
    email: '',
    password: '',
});

const showPassword = ref(false);

const submit = () => {
    if (form.processing) return;

    form.post(props.loginUrl, {
        onFinish: () => {
            form.reset('password');
            showPassword.value = false;
        },
    });
};
</script>

<template>
    <component
        :is="portal === 'admin' ? AuthBase : PresensiLoginLayout"
        :compact="portal === 'admin' ? true : undefined"
        :title="portal === 'admin' ? 'Masuk ke portal admin' : 'Selamat datang kembali'"
        :description="
            portal === 'admin' ? 'Gunakan akun dengan akses administrator untuk melanjutkan.' : 'Masuk dengan akun pegawai untuk memulai presensi.'
        "
    >
        <Head :title="portal === 'admin' ? 'Login Admin' : 'Login Presensi'" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600" role="status">
            {{ status }}
        </div>

        <form
            @submit.prevent="submit"
            class="login-form flex flex-col gap-4"
            :class="{ 'presensi-form': portal !== 'admin' }"
            :aria-busy="form.processing"
        >
            <div class="login-fields grid gap-4">
                <div class="login-field grid gap-2">
                    <Label for="email">Alamat email</Label>
                    <div class="login-input-wrap">
                        <span class="login-icon-slot" aria-hidden="true"><Mail class="login-field-icon" /></span>
                        <input
                            id="email"
                            class="login-input"
                            name="email"
                            type="email"
                            required
                            :autofocus="portal === 'admin'"
                            autocomplete="email"
                            inputmode="email"
                            autocapitalize="none"
                            enterkeyhint="next"
                            :spellcheck="false"
                            v-model="form.email"
                            placeholder="nama@rsarsy.id"
                            :aria-invalid="!!form.errors.email"
                            :aria-describedby="form.errors.email ? 'email-error' : undefined"
                        />
                    </div>
                    <InputError id="email-error" :message="form.errors.email" />
                </div>

                <div class="login-field grid gap-2">
                    <Label for="password">Kata sandi</Label>
                    <div class="login-input-wrap">
                        <span class="login-icon-slot" aria-hidden="true"><LockKeyhole class="login-field-icon" /></span>
                        <input
                            id="password"
                            class="login-input"
                            name="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            v-model="form.password"
                            placeholder="Masukkan kata sandi"
                            :aria-invalid="!!form.errors.password"
                            :aria-describedby="form.errors.password ? 'password-error' : undefined"
                            :spellcheck="false"
                            autocapitalize="none"
                            enterkeyhint="go"
                        />
                        <button
                            type="button"
                            class="login-password-toggle"
                            :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                            :aria-pressed="showPassword"
                            aria-controls="password"
                            @click="showPassword = !showPassword"
                        >
                            <EyeOff v-if="showPassword" aria-hidden="true" />
                            <Eye v-else aria-hidden="true" />
                        </button>
                    </div>
                    <InputError id="password-error" :message="form.errors.password" />
                </div>

                <div class="login-options flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                    <span class="login-persistence"><ShieldCheck aria-hidden="true" /> Tetap masuk di perangkat ini</span>
                    <TextLink v-if="canResetPassword" :href="route('password.request')" class="login-forgot text-sm">Lupa kata sandi?</TextLink>
                </div>

                <Button type="submit" class="login-submit mt-1 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ form.processing ? 'Memeriksa akun…' : portal === 'admin' ? 'Masuk ke portal admin' : 'Masuk ke presensi' }}
                    <ArrowRight v-if="portal !== 'admin' && !form.processing" class="login-submit-arrow" aria-hidden="true" />
                </Button>
            </div>

            <div v-if="portal === 'admin'" class="text-center text-sm text-muted-foreground">
                Ingin melakukan presensi?
                <TextLink :href="route('login')">Buka aplikasi presensi</TextLink>
            </div>
        </form>
    </component>
</template>

<style scoped>
.login-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    min-height: 58px;
    padding: 5px 6px 5px 10px;
    border: 1px solid #d8e4df;
    border-radius: 16px;
    background: linear-gradient(180deg, #fff, #fcfefd);
    box-shadow:
        0 2px 4px rgb(23 50 56 / 3%),
        inset 0 1px 0 #fff;
    transition:
        border-color 180ms ease,
        box-shadow 180ms ease;
}

.login-input-wrap:hover {
    border-color: #adc8bf;
}

.login-input-wrap:focus-within {
    border-color: #087f78;
    box-shadow:
        0 0 0 4px rgb(8 127 120 / 9%),
        0 4px 12px rgb(23 50 56 / 4%);
}

.login-input-wrap:has(input[aria-invalid='true']) {
    border-color: #d15b60;
}

.login-input-wrap:has(input[aria-invalid='true']):focus-within {
    box-shadow: 0 0 0 4px rgb(209 91 96 / 10%);
}

.login-icon-slot {
    width: 36px;
    height: 36px;
    display: grid;
    flex: 0 0 36px;
    place-items: center;
    border: 1px solid #e2eee8;
    border-radius: 11px;
    color: #62857a;
    background: #f1f7f4;
    pointer-events: none;
    transition:
        color 180ms ease,
        background 180ms ease;
}

.login-input-wrap:focus-within .login-icon-slot {
    color: #087f78;
    background: #e8f5f1;
}

.login-field-icon {
    width: 18px;
    height: 18px;
    stroke-width: 1.7;
}

.login-input {
    width: 0;
    min-width: 0;
    flex: 1 1 0;
    height: 46px;
    padding: 0 6px 0 0;
    border: 0;
    border-radius: 0;
    outline: none;
    color: #173238;
    background: transparent;
    font-family: inherit;
    font-size: 16px;
    font-weight: 500;
    box-shadow: none;
    scroll-margin-block: 24px;
}

.login-input:focus-visible {
    outline: none;
    box-shadow: none;
}

.login-input::placeholder {
    color: #83958e;
    font-size: 14px;
    font-weight: 400;
}

.login-input:-webkit-autofill {
    -webkit-text-fill-color: #173238;
    box-shadow: 0 0 0 1000px #fcfefd inset;
}

.login-password-toggle {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 12px;
    color: #667c7e;
    background: transparent;
    cursor: pointer;
}

.login-password-toggle:hover {
    color: #087f78;
    background: #e8f5f1;
}

.login-password-toggle:focus-visible {
    outline: 2px solid #087f78;
    outline-offset: -3px;
}

.login-password-toggle svg {
    width: 19px;
    height: 19px;
    stroke-width: 1.8;
}

.presensi-form .login-fields {
    min-width: 0;
    gap: 20px;
}

.presensi-form .login-field > label {
    color: #334f52;
    font-size: 13px;
    font-weight: 700;
}

.presensi-form .login-options {
    margin-top: -3px;
    column-gap: 12px;
}

.login-persistence {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #627b76;
    font-size: 12px;
    font-weight: 500;
}

.login-persistence svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
    color: #087f78;
}

.presensi-form .login-forgot {
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    color: #087f78;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.presensi-form .login-forgot:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}

.presensi-form .login-forgot:focus-visible {
    outline: 2px solid #087f78;
    outline-offset: 3px;
    border-radius: 4px;
}

.presensi-form .login-submit {
    min-height: 54px;
    margin-top: 0;
    gap: 10px;
    border: 1px solid #08736d;
    border-radius: 13px;
    color: #fff;
    background: linear-gradient(115deg, #165d5b, #087f78);
    box-shadow: 0 8px 18px rgb(8 127 120 / 17%);
    font-size: 14px;
    font-weight: 700;
    --ring: 176 88% 26%;
    transition:
        box-shadow 160ms ease,
        filter 160ms ease;
}

.presensi-form .login-submit:hover {
    filter: brightness(1.08);
    box-shadow: 0 10px 24px rgb(8 127 120 / 23%);
}

.presensi-form .login-submit:disabled {
    cursor: wait;
    opacity: 0.7;
}

.presensi-form .login-submit-arrow {
    width: 17px;
    height: 17px;
}

@media (max-width: 960px) {
    .presensi-form .login-fields {
        gap: 18px;
    }

    .presensi-form .login-options {
        margin-top: -4px;
    }
}

@media (max-width: 380px) {
    .presensi-form .login-options {
        column-gap: 8px;
    }

    .login-persistence,
    .presensi-form .login-forgot {
        font-size: 12px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .login-input-wrap,
    .login-icon-slot,
    .presensi-form .login-submit {
        transition: none;
    }
}
</style>
