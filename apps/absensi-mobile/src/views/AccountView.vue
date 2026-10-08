<script setup lang="ts">
import { Camera, LogOut, Mail, Phone, ShieldCheck, UserRound, UsersRound } from '@lucide/vue';
import { Camera as NativeCamera, CameraDirection, CameraResultType, CameraSource } from '@capacitor/camera';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';
import MobileShell from '@/components/MobileShell.vue';
import { useAuth } from '@/composables/useAuth';
import { ApiError, apiRequest, authenticatedBlob } from '@/lib/api';
import type { AccountData, ApiEnvelope } from '@/types';

const router = useRouter();
const auth = useAuth();
const profile = ref<AccountData | null>(null);
const photoUrl = ref<string | null>(null);
const loading = ref(true);
const uploading = ref(false);
const loggingOut = ref(false);
const error = ref('');
const message = ref('');

async function loadPhoto() {
    if (photoUrl.value) URL.revokeObjectURL(photoUrl.value);
    photoUrl.value = null;
    if (!profile.value?.has_photo) return;
    try {
        photoUrl.value = URL.createObjectURL(await authenticatedBlob('/account/photo'));
    } catch {
        // The placeholder remains visible when a private photo cannot be loaded.
    }
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        profile.value = (await apiRequest<ApiEnvelope<AccountData>>('/account')).data;
        await loadPhoto();
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Profil belum dapat dimuat.';
    } finally {
        loading.value = false;
    }
}

async function updatePhoto() {
    uploading.value = true;
    error.value = '';
    message.value = '';
    try {
        const photo = await NativeCamera.getPhoto({
            source: CameraSource.Camera,
            direction: CameraDirection.Front,
            resultType: CameraResultType.DataUrl,
            quality: 88,
            width: 1280,
            height: 1280,
            correctOrientation: true,
        });
        if (!photo.dataUrl) throw new Error('Camera did not return photo data.');
        const blob = await (await fetch(photo.dataUrl)).blob();
        const form = new FormData();
        form.append('photo', blob, `profile-${Date.now()}.${photo.format || 'jpeg'}`);
        const response = await apiRequest<{ message: string }>('/account/photo', { method: 'POST', body: form });
        message.value = response.message;
        if (profile.value) profile.value.has_photo = true;
        await loadPhoto();
    } catch (caught) {
        if ((caught as Error)?.message?.toLowerCase().includes('cancel')) return;
        error.value = caught instanceof ApiError ? caught.message : 'Foto belum berhasil diperbarui.';
    } finally {
        uploading.value = false;
    }
}

async function logout() {
    loggingOut.value = true;
    await auth.logout();
    await router.replace({ name: 'login' });
}

onMounted(load);
onBeforeUnmount(() => { if (photoUrl.value) URL.revokeObjectURL(photoUrl.value); });
</script>

<template>
    <MobileShell title="Akun pegawai" subtitle="Identitas dan foto verifikasi">
        <LoadingState v-if="loading" />
        <ErrorState v-else-if="error && !profile" :message="error" @retry="load" />
        <template v-else-if="profile">
            <section class="profile-card">
                <div class="profile-photo-wrap">
                    <img v-if="photoUrl" :src="photoUrl" alt="Foto profil" class="profile-photo" />
                    <span v-else class="profile-placeholder"><UserRound :size="44" /></span>
                    <span v-if="profile.has_photo" class="verified-badge"><ShieldCheck :size="15" /></span>
                </div>
                <h2>{{ profile.name }}</h2>
                <p>{{ profile.position || profile.profession || 'Pegawai RSARSY' }}</p>
                <span class="chip">{{ profile.unit || 'Unit belum diatur' }}</span>
                <button class="button secondary full" type="button" :disabled="uploading" @click="updatePhoto">
                    <Camera :size="18" /> {{ uploading ? 'Mengunggah foto…' : profile.has_photo ? 'Perbarui foto wajah' : 'Tambahkan foto wajah' }}
                </button>
            </section>

            <p v-if="message" class="form-success">{{ message }}</p>
            <p v-if="error" class="form-error">{{ error }}</p>

            <section class="detail-list">
                <div><span><UsersRound :size="19" /></span><p><small>NIK</small><strong>{{ profile.nik || 'Belum tersedia' }}</strong></p></div>
                <div><span><Mail :size="19" /></span><p><small>Email</small><strong>{{ profile.email }}</strong></p></div>
                <div><span><Phone :size="19" /></span><p><small>Nomor telepon</small><strong>{{ profile.phone || 'Belum tersedia' }}</strong></p></div>
            </section>

            <section class="notice-card secure">
                <ShieldCheck :size="24" />
                <div><strong>Privasi terlindungi</strong><p>Foto hanya dipakai untuk verifikasi wajah dan tidak tersedia secara publik.</p></div>
            </section>

            <button class="button danger full" type="button" :disabled="loggingOut" @click="logout">
                <LogOut :size="18" /> {{ loggingOut ? 'Keluar…' : 'Keluar dari aplikasi' }}
            </button>
        </template>
    </MobileShell>
</template>
