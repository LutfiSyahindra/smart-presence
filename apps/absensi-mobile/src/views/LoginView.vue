<script setup lang="ts">
import { Eye, EyeOff, LockKeyhole, Mail } from '@lucide/vue';
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/composables/useAuth';

const router = useRouter();
const auth = useAuth();
const email = ref('');
const password = ref('');
const showPassword = ref(false);
const submitting = ref(false);
const error = ref('');

async function submit() {
    error.value = '';
    submitting.value = true;
    try {
        await auth.login(email.value.trim(), password.value);
        await router.replace({ name: 'home' });
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Login belum berhasil. Silakan coba kembali.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <main class="login-screen">
        <section class="login-hero">
            <img src="/images/arsy-presensi-logo.png" alt="ARSY Presensi" width="500" height="500" class="login-logo" />
            <div>
                <p class="app-eyebrow light">RS ABDURRAHMAN SYAMSURI</p>
                <h1>Presensi yang aman, langsung dari genggaman.</h1>
                <p>Verifikasi lokasi dan wajah dalam satu alur yang sederhana.</p>
            </div>
        </section>

        <section class="login-panel">
            <div class="login-heading">
                <span>Selamat datang</span>
                <h2>Masuk ke akun pegawai</h2>
                <p>Gunakan akun yang sama dengan portal presensi.</p>
            </div>

            <form class="form-stack" @submit.prevent="submit">
                <label class="field">
                    <span>Email</span>
                    <span class="input-wrap">
                        <Mail :size="19" />
                        <input v-model="email" type="email" autocomplete="email" placeholder="nama@rsarsy.id" required />
                    </span>
                </label>
                <label class="field">
                    <span>Kata sandi</span>
                    <span class="input-wrap">
                        <LockKeyhole :size="19" />
                        <input
                            v-model="password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            placeholder="Masukkan kata sandi"
                            required
                        />
                        <button class="icon-button inline" type="button" :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'" @click="showPassword = !showPassword">
                            <EyeOff v-if="showPassword" :size="19" />
                            <Eye v-else :size="19" />
                        </button>
                    </span>
                </label>

                <p v-if="error" class="form-error" role="alert">{{ error }}</p>
                <button class="button primary full" type="submit" :disabled="submitting">
                    <span v-if="submitting" class="spinner small-spinner"></span>
                    {{ submitting ? 'Memeriksa akun…' : 'Masuk' }}
                </button>
            </form>
            <p class="security-note">Token login disimpan terenkripsi pada perangkat ini.</p>
        </section>
    </main>
</template>
