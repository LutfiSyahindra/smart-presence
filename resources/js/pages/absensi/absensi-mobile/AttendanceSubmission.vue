<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { AttendanceSubmissionStatus } from '@/types/attendance-submission';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Clock3, Home, LoaderCircle, RefreshCw, ShieldCheck } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{ submission: AttendanceSubmissionStatus }>();
const submission = ref(props.submission);
const connectionMessage = ref('');
const refreshing = ref(false);
const pending = computed(() => ['pending', 'processing'].includes(submission.value.status));
const title = computed(
    () =>
        ({ pending: 'Menunggu verifikasi', processing: 'Sedang diverifikasi', succeeded: 'Absensi berhasil', failed: 'Verifikasi gagal' })[
            submission.value.status
        ],
);
const dateLabel = computed(() =>
    new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'Asia/Jakarta' }).format(new Date(submission.value.received_at)),
);
let timer: ReturnType<typeof setTimeout> | undefined;
let request: AbortController | undefined;
let disposed = false;

watch(
    () => props.submission,
    (value) => {
        submission.value = value;
    },
);

async function refresh() {
    if (disposed || refreshing.value) return;
    clearTimeout(timer);
    refreshing.value = true;
    request = new AbortController();
    const timeout = setTimeout(() => request?.abort(), 15000);
    try {
        const response = await fetch(submission.value.status_url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: request.signal,
        });
        if (!response.ok) throw new Error(String(response.status));
        submission.value = (await response.json()).data;
        connectionMessage.value = '';
        openSummary();
    } catch (error) {
        if (disposed) return;
        connectionMessage.value =
            error instanceof Error && ['401', '403'].includes(error.message)
                ? 'Sesi Anda berakhir. Buka beranda untuk masuk kembali dan memeriksa hasil.'
                : 'Status belum dapat diperbarui. Permintaan yang tersimpan tetap diproses; periksa kembali saat koneksi tersedia.';
    } finally {
        clearTimeout(timeout);
        refreshing.value = false;
        if (!disposed && pending.value && !document.hidden) timer = setTimeout(refresh, connectionMessage.value ? 10000 : 3000);
    }
}

function openSummary() {
    if (!disposed && submission.value.status === 'succeeded' && submission.value.success_url) {
        router.visit(submission.value.success_url, { replace: true });
    }
}

function resume() {
    clearTimeout(timer);
    if (!document.hidden && pending.value) void refresh();
}

onMounted(() => {
    if (pending.value) void refresh();
    else openSummary();
    document.addEventListener('visibilitychange', resume);
    window.addEventListener('online', resume);
});
onUnmounted(() => {
    disposed = true;
    clearTimeout(timer);
    request?.abort();
    document.removeEventListener('visibilitychange', resume);
    window.removeEventListener('online', resume);
});
</script>

<template>
    <Head :title="title" />
    <main class="submission-workspace">
        <section class="submission-card" aria-labelledby="submission-title">
            <AppLogoIcon variant="full" class="brand" />
            <div class="status-icon" :class="submission.status" aria-hidden="true">
                <LoaderCircle v-if="pending" class="spin" />
                <CheckCircle2 v-else-if="submission.status === 'succeeded'" />
                <CircleAlert v-else />
            </div>
            <div aria-live="polite" aria-atomic="true">
                <p class="eyebrow">{{ submission.action === 'clock-in' ? 'ABSEN MASUK' : 'ABSEN PULANG' }}</p>
                <h1 id="submission-title">{{ title }}</h1>
                <p class="message">{{ submission.message }}</p>
            </div>
            <div class="time-card">
                <Clock3 aria-hidden="true" />
                <div>
                    <span>Waktu pengiriman diterima</span><strong>{{ submission.time }} WIB</strong><small>{{ dateLabel }}</small>
                </div>
            </div>
            <p v-if="pending" class="assurance">
                <ShieldCheck aria-hidden="true" />Waktu pengiriman sudah tersimpan. Anda boleh menutup halaman dan memeriksa hasil melalui beranda.
            </p>
            <p v-if="submission.status === 'succeeded' && submission.outside_radius" class="assurance">
                Catatan lokasi di luar radius turut tersimpan.
            </p>
            <p v-if="connectionMessage" class="connection-message" role="status">{{ connectionMessage }}</p>
            <Link v-if="submission.status === 'succeeded' && submission.success_url" :href="submission.success_url" class="primary-action">
                Lihat ringkasan presensi
            </Link>
            <Link v-if="submission.status === 'failed'" :href="submission.retry_url" class="primary-action">Ambil foto dan coba kembali</Link>
            <button v-if="pending" type="button" class="secondary-action" :disabled="refreshing" @click="refresh">
                <RefreshCw :class="{ spin: refreshing }" />Periksa status
            </button>
            <Link :href="route('absensi.mobile.home')" class="home-action"><Home />Kembali ke beranda</Link>
        </section>
    </main>
</template>

<style scoped>
.submission-workspace {
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: max(24px, env(safe-area-inset-top)) 20px max(24px, env(safe-area-inset-bottom));
    background: radial-gradient(ellipse at top, #d8f3ec, #f5f9f8 65%);
    color: #173e3a;
}
.submission-card {
    width: 100%;
    max-width: 460px;
    padding: 32px 24px;
    border: 1px solid #e1ece8;
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 16px 60px #16493d0d;
    text-align: center;
}
.brand {
    width: 72px;
    height: 72px;
    margin: 0 auto 24px;
}
.status-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 24px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #e8f6f2;
    color: #087f78;
}
.status-icon svg {
    width: 42px;
    height: 42px;
}
.status-icon.failed {
    background: #fff0e8;
    color: #b54820;
}
.eyebrow {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.16em;
    color: #087f78;
}
h1 {
    margin-top: 10px;
    font-size: 26px;
    font-weight: 750;
    line-height: 1.2;
}
.message {
    margin-top: 14px;
    font-size: 14px;
    line-height: 1.7;
    color: #526c67;
}
.time-card {
    display: flex;
    align-items: center;
    gap: 18px;
    margin: 24px 0 18px;
    padding: 20px;
    background: #f3f8f6;
    border-radius: 18px;
    text-align: left;
}
.time-card > svg {
    flex: none;
    width: 28px;
    height: 28px;
    color: #087f78;
}
.time-card span,
.time-card small {
    display: block;
    color: #526c67;
    font-size: 12px;
}
.time-card strong {
    display: block;
    margin: 4px 0;
    font-size: 28px;
}
.assurance {
    display: flex;
    gap: 8px;
    text-align: left;
    color: #526c67;
    font-size: 12px;
    line-height: 1.7;
}
.assurance svg {
    flex: none;
    width: 18px;
    margin-top: 2px;
}
.connection-message {
    margin-top: 18px;
    font-size: 12px;
    line-height: 1.7;
    color: #8b491c;
}
.primary-action,
.secondary-action,
.home-action {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    min-height: 46px;
    width: 100%;
    margin-top: 18px;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 650;
}
.primary-action {
    background: #087f78;
    color: #fff;
}
.secondary-action {
    border: 1px solid #d8e8e2;
    color: #087f78;
}
.secondary-action:disabled {
    opacity: 0.6;
}
.home-action {
    color: #526c67;
}
.home-action svg,
.secondary-action svg {
    width: 18px;
    height: 18px;
}
.spin {
    animation: spin 1.4s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (prefers-reduced-motion: reduce) {
    .spin {
        animation: none;
    }
}
</style>
