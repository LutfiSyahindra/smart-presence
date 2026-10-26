<script setup lang="ts">
import { Camera as CameraIcon, Check, CheckCircle2, Clock3, Crosshair, MapPin, RefreshCw, Send, ShieldCheck } from '@lucide/vue';
import { Camera, CameraDirection, CameraResultType, CameraSource } from '@capacitor/camera';
import { Geolocation } from '@capacitor/geolocation';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';
import MobileShell from '@/components/MobileShell.vue';
import { ApiError, apiRequest } from '@/lib/api';
import type { ApiEnvelope, AttendanceActionData, AttendanceSubmissionStatus, LocationSample } from '@/types';

const route = useRoute();
const action = computed(() => route.params.action as 'clock-in' | 'clock-out');
const title = computed(() => action.value === 'clock-in' ? 'Clock-in' : 'Clock-out');
const context = ref<AttendanceActionData | null>(null);
const samples = ref<LocationSample[]>([]);
const photoBlob = ref<Blob | null>(null);
const photoPreview = ref<string | null>(null);
const outsideRadius = ref(false);
const outsideNote = ref('');
const loading = ref(true);
const verifying = ref(false);
const submitting = ref(false);
const error = ref('');
const progress = ref('');
const success = ref<{ message: string; time: string; face_similarity: number | null } | null>(null);
const submission = ref<AttendanceSubmissionStatus | null>(null);
const pendingSubmission = computed(() => submission.value && ['pending', 'processing'].includes(submission.value.status));
let statusTimer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;
let polling = false;

function applyStatus(value: AttendanceSubmissionStatus) {
    submission.value = value;
    if (value.status === 'succeeded') success.value = { message: value.message, time: value.time, face_similarity: value.face_similarity };
}

async function pollStatus() {
    if (disposed || polling || !pendingSubmission.value || !submission.value) return;
    clearTimeout(statusTimer);
    polling = true;
    try {
        const result = await apiRequest<ApiEnvelope<AttendanceSubmissionStatus>>(`/attendance-submissions/${submission.value.id}`);
        if (!disposed) { applyStatus(result.data); error.value = ''; }
    } catch {
        if (!disposed) error.value = 'Status belum dapat diperbarui. Permintaan tersimpan; periksa kembali saat koneksi tersedia.';
    } finally {
        polling = false;
        if (!disposed && pendingSubmission.value && !document.hidden) statusTimer = setTimeout(pollStatus, error.value ? 10000 : 3000);
    }
}

function resumeStatus() {
    clearTimeout(statusTimer);
    if (!document.hidden) void pollStatus();
}

async function prepareAgain() {
    submission.value = null;
    error.value = '';
    resetEvidence();
    try { context.value = (await apiRequest<ApiEnvelope<AttendanceActionData>>(`/attendance/${action.value}`)).data; }
    catch (caught) { error.value = caught instanceof ApiError ? caught.message : 'Persiapan presensi belum berhasil.'; }
}

const accurateSamples = computed(() => samples.value.filter((sample) => sample.accuracy <= (context.value?.location_challenge.max_accuracy_meters || 100)));
const bestAccuracy = computed(() => accurateSamples.value.length ? Math.min(...accurateSamples.value.map((sample) => sample.accuracy)) : null);
const ready = computed(() => Boolean(photoBlob.value && accurateSamples.value.length >= (context.value?.location_challenge.min_samples || 3)));

async function load() {
    loading.value = true;
    error.value = '';
    resetEvidence();
    try {
        const latest = (await apiRequest<ApiEnvelope<AttendanceSubmissionStatus | null>>(`/attendance-submissions/latest?action=${action.value}`)).data;
        if (latest) {
            applyStatus(latest);
            if (pendingSubmission.value) void pollStatus();
            return;
        }
        context.value = (await apiRequest<ApiEnvelope<AttendanceActionData>>(`/attendance/${action.value}`)).data;
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Persiapan presensi belum berhasil.';
    } finally {
        loading.value = false;
    }
}

function resetEvidence() {
    samples.value = [];
    photoBlob.value = null;
    outsideRadius.value = false;
    outsideNote.value = '';
    success.value = null;
    if (photoPreview.value) URL.revokeObjectURL(photoPreview.value);
    photoPreview.value = null;
}

function delay(milliseconds: number) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

async function collectLocation() {
    if (!context.value) return;
    progress.value = 'Meminta izin lokasi…';
    const permission = await Geolocation.requestPermissions();
    if (permission.location === 'denied' && permission.coarseLocation === 'denied') {
        throw new Error('Izin lokasi ditolak. Aktifkan lokasi untuk aplikasi ini melalui pengaturan perangkat.');
    }

    const challenge = context.value.location_challenge;
    const interval = Math.ceil((challenge.collection_seconds * 1000) / Math.max(1, challenge.min_samples - 1));
    const collected: LocationSample[] = [];
    let accurate: LocationSample[] = [];

    for (let attempt = 0; attempt < 20; attempt += 1) {
        if (attempt > 0) await delay(interval);
        progress.value = `Memverifikasi GPS ${Math.min(accurate.length + 1, challenge.min_samples)}/${challenge.min_samples}…`;
        const position = await Geolocation.getCurrentPosition({
            enableHighAccuracy: true,
            maximumAge: 0,
            timeout: 15_000,
        });
        const sample: LocationSample = {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            captured_at: Date.now(),
        };
        collected.push(sample);
        accurate = collected.filter((item) => item.accuracy <= challenge.max_accuracy_meters);
        const span = accurate.length > 1 ? accurate.at(-1)!.captured_at - accurate[0].captured_at : 0;
        if (accurate.length >= challenge.min_samples && span >= challenge.collection_seconds * 1000) break;
    }

    if (accurate.length < challenge.min_samples) {
        throw new Error(`Akurasi GPS belum cukup baik. Diperlukan ${challenge.min_samples} sampel di bawah ${challenge.max_accuracy_meters} meter.`);
    }
    const span = accurate.at(-1)!.captured_at - accurate[0].captured_at;
    if (span < challenge.collection_seconds * 1000) {
        throw new Error('GPS belum stabil. Berpindahlah ke area yang lebih terbuka lalu coba kembali.');
    }

    samples.value = collected;
    const latitude = median(accurate.map((sample) => sample.latitude));
    const longitude = median(accurate.map((sample) => sample.longitude));
    outsideRadius.value = distanceInMeters(latitude, longitude, context.value.office.latitude, context.value.office.longitude) > context.value.office.radius_meters;
}

async function takePhoto() {
    progress.value = 'Membuka kamera depan…';
    const photo = await Camera.getPhoto({
        source: CameraSource.Camera,
        direction: CameraDirection.Front,
        resultType: CameraResultType.DataUrl,
        quality: 88,
        width: 1280,
        height: 1280,
        correctOrientation: true,
        allowEditing: false,
    });
    if (!photo.dataUrl) throw new Error('Kamera tidak menghasilkan foto. Silakan ambil foto kembali.');
    const blob = await (await fetch(photo.dataUrl)).blob();
    photoBlob.value = blob;
    if (photoPreview.value) URL.revokeObjectURL(photoPreview.value);
    photoPreview.value = URL.createObjectURL(blob);
}

async function verify() {
    verifying.value = true;
    error.value = '';
    resetEvidence();
    try {
        await loadFreshChallenge();
        await collectLocation();
        await takePhoto();
        progress.value = 'Lokasi dan foto siap dikirim.';
    } catch (caught) {
        if ((caught as Error)?.message?.toLowerCase().includes('cancel')) {
            error.value = 'Pengambilan foto dibatalkan.';
        } else {
            error.value = caught instanceof ApiError ? caught.message : (caught as Error)?.message || 'Verifikasi perangkat belum berhasil.';
        }
    } finally {
        verifying.value = false;
    }
}

async function loadFreshChallenge() {
    context.value = (await apiRequest<ApiEnvelope<AttendanceActionData>>(`/attendance/${action.value}`)).data;
}

async function submit() {
    if (!context.value || !photoBlob.value || !ready.value) return;
    if (outsideRadius.value && outsideNote.value.trim().length < 5) {
        error.value = 'Tuliskan alasan lokasi di luar radius minimal 5 karakter.';
        return;
    }

    submitting.value = true;
    error.value = '';
    progress.value = 'Memverifikasi wajah dan menyimpan presensi…';
    try {
        const accurate = accurateSamples.value;
        const latitude = median(accurate.map((sample) => sample.latitude));
        const longitude = median(accurate.map((sample) => sample.longitude));
        const form = new FormData();
        form.append('latitude', String(latitude));
        form.append('longitude', String(longitude));
        form.append('accuracy', String(Math.max(...accurate.map((sample) => sample.accuracy))));
        form.append('location_token', context.value.location_challenge.token);
        form.append('outside_radius_confirmed', outsideRadius.value ? '1' : '0');
        form.append('outside_radius_note', outsideNote.value.trim());
        form.append('photo', photoBlob.value, `${action.value}-${Date.now()}.jpeg`);
        samples.value.forEach((sample, index) => {
            form.append(`location_samples[${index}][latitude]`, String(sample.latitude));
            form.append(`location_samples[${index}][longitude]`, String(sample.longitude));
            form.append(`location_samples[${index}][accuracy]`, String(sample.accuracy));
            form.append(`location_samples[${index}][captured_at]`, String(sample.captured_at));
        });

        const response = await apiRequest<{ message: string; data: AttendanceSubmissionStatus | { time: string; face_similarity: number | null } }>(`/attendance/${action.value}`, {
            method: 'POST',
            body: form,
        });
        if ('status' in response.data) {
            applyStatus(response.data);
            if (pendingSubmission.value) void pollStatus();
        } else success.value = { message: response.message, ...response.data };
        progress.value = '';
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Presensi belum berhasil disimpan.';
    } finally {
        submitting.value = false;
    }
}

function median(values: number[]) {
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

function distanceInMeters(fromLat: number, fromLng: number, toLat: number, toLng: number) {
    const radius = 6_371_000;
    const latDelta = ((toLat - fromLat) * Math.PI) / 180;
    const lngDelta = ((toLng - fromLng) * Math.PI) / 180;
    const a = Math.sin(latDelta / 2) ** 2
        + Math.cos((fromLat * Math.PI) / 180) * Math.cos((toLat * Math.PI) / 180) * Math.sin(lngDelta / 2) ** 2;
    return radius * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

onMounted(() => {
    void load();
    document.addEventListener('visibilitychange', resumeStatus);
    window.addEventListener('online', resumeStatus);
});
onUnmounted(() => {
    disposed = true;
    clearTimeout(statusTimer);
    document.removeEventListener('visibilitychange', resumeStatus);
    window.removeEventListener('online', resumeStatus);
    if (photoPreview.value) URL.revokeObjectURL(photoPreview.value);
});
</script>

<template>
    <MobileShell :title="title" subtitle="Verifikasi lokasi dan wajah">
        <LoadingState v-if="loading" />
        <ErrorState v-else-if="error && !context && !submission" :message="error" @retry="load" />

        <section v-else-if="submission && submission.status !== 'succeeded'" class="success-screen">
            <span class="success-mark"><Clock3 :size="42" /></span>
            <p class="app-eyebrow">{{ submission.status === 'failed' ? 'Verifikasi gagal' : 'Permintaan tersimpan' }}</p>
            <h2>{{ submission.time }} WIB</h2>
            <p>{{ submission.message }}</p>
            <p v-if="pendingSubmission">Waktu pengiriman sudah tersimpan. Anda dapat kembali ke beranda dan melihat hasilnya nanti.</p>
            <p v-if="error" class="form-error" role="status">{{ error }}</p>
            <button v-if="submission.status === 'failed'" type="button" class="button primary full" @click="prepareAgain">Ambil foto dan coba kembali</button>
            <button v-else type="button" class="button text full" @click="pollStatus">Periksa status</button>
            <RouterLink :to="{ name: 'home' }" class="button primary full">Kembali ke beranda</RouterLink>
        </section>

        <section v-else-if="success" class="success-screen">
            <span class="success-mark"><Check :size="42" /></span>
            <p class="app-eyebrow">Presensi tersimpan</p>
            <h2>{{ success.time }} WIB</h2>
            <p>{{ success.message }}</p>
            <div class="success-verification"><ShieldCheck :size="20" /> Wajah terverifikasi<span v-if="success.face_similarity">{{ success.face_similarity.toFixed(1) }}%</span></div>
            <RouterLink :to="{ name: 'home' }" class="button primary full">Kembali ke beranda</RouterLink>
        </section>

        <template v-else-if="context">
            <section class="action-summary">
                <div><small>{{ context.schedule.date_label }}</small><h2>{{ context.schedule.shift_name || 'Shift belum diatur' }}</h2></div>
                <span class="chip"><Clock3 :size="14" /> {{ context.schedule.start_time || '--:--' }}–{{ context.schedule.end_time || '--:--' }}</span>
            </section>

            <section class="verification-list">
                <article :class="{ done: accurateSamples.length >= context.location_challenge.min_samples }">
                    <span><Crosshair :size="23" /></span>
                    <div><strong>Lokasi perangkat</strong><p>{{ accurateSamples.length ? `${accurateSamples.length} sampel · akurasi terbaik ${bestAccuracy?.toFixed(0)} m` : 'Ambil beberapa sampel GPS akurat' }}</p></div>
                    <CheckCircle2 v-if="accurateSamples.length >= context.location_challenge.min_samples" :size="22" />
                </article>
                <article :class="{ done: photoBlob }">
                    <span><CameraIcon :size="23" /></span>
                    <div><strong>Foto wajah langsung</strong><p>{{ photoBlob ? 'Foto kamera depan siap diverifikasi' : 'Pastikan wajah terlihat jelas' }}</p></div>
                    <CheckCircle2 v-if="photoBlob" :size="22" />
                </article>
            </section>

            <section class="location-card">
                <MapPin :size="22" />
                <div><small>Lokasi yang diizinkan</small><strong>{{ context.office.name }}</strong><p>{{ context.office.address }} · radius {{ context.office.radius_meters }} m</p></div>
            </section>

            <div v-if="photoPreview" class="face-preview"><img :src="photoPreview" alt="Pratinjau wajah" /><span>Foto verifikasi</span></div>

            <label v-if="outsideRadius" class="field outside-note">
                <span>Anda berada di luar radius kantor</span>
                <textarea v-model="outsideNote" rows="3" maxlength="500" placeholder="Tuliskan alasan Anda melakukan presensi dari lokasi ini…"></textarea>
            </label>

            <p v-if="error" class="form-error" role="alert">{{ error }}</p>
            <p v-if="progress" class="progress-note"><span v-if="verifying || submitting" class="spinner small-spinner"></span>{{ progress }}</p>

            <button v-if="!ready" class="button primary full" type="button" :disabled="verifying" @click="verify">
                <RefreshCw v-if="verifying" :size="19" />
                <CameraIcon v-else :size="19" />
                {{ verifying ? 'Sedang memverifikasi…' : 'Mulai verifikasi' }}
            </button>
            <div v-else class="button-stack">
                <button class="button primary full" type="button" :disabled="submitting" @click="submit">
                    <Send :size="18" /> {{ submitting ? 'Mengirim presensi…' : `Kirim ${title}` }}
                </button>
                <button class="button text full" type="button" :disabled="submitting" @click="verify">Ambil ulang lokasi & foto</button>
            </div>
        </template>
    </MobileShell>
</template>
