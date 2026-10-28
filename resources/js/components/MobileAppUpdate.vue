<script setup lang="ts">
import {
    appUpdateProgress,
    canPresentAppUpdate,
    checkAppUpdate,
    downloadAppUpdate,
    installAppUpdate,
    installedApp,
    isAndroidApp,
    openAppDownload,
    type AppUpdateProgress,
    type AppUpdateStatus,
} from '@/lib/mobile-app-update';
import { router } from '@inertiajs/vue3';
import { ArrowDownToLine, RefreshCw, Smartphone } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const status = ref<AppUpdateStatus | null>(null);
const modal = ref<HTMLDialogElement | null>(null);
const path = ref(typeof window === 'undefined' ? '/' : window.location.pathname);
const dismissed = ref<number | null>(null);
const error = ref('');
const downloading = ref(false);
const inAppUpdates = ref(false);
const progress = ref<AppUpdateProgress | null>(null);
const connected = ref(typeof navigator === 'undefined' || navigator.onLine !== false);
const currentProgress = computed(() => (progress.value?.release_id === status.value?.release?.id ? progress.value : null));
const busy = computed(
    () => downloading.value || ['checking', 'downloading', 'verifying', 'permission', 'installing'].includes(currentProgress.value?.stage ?? ''),
);
const packageReady = computed(() => currentProgress.value?.stage === 'ready');
const required = computed(() => status.value?.update_required && connected.value && ready.value);
const percent = computed(() => {
    const value = currentProgress.value;
    return value?.total_bytes ? Math.min(100, Math.floor((value.downloaded_bytes / value.total_bytes) * 100)) : 0;
});
const progressLabel = computed(() => {
    switch (currentProgress.value?.stage) {
        case 'checking':
            return 'Memeriksa pembaruan…';
        case 'downloading':
            return `Mengunduh pembaruan ${percent.value}%`;
        case 'verifying':
            return 'Memeriksa keamanan berkas…';
        case 'permission':
            return 'Aktifkan izin pemasangan, lalu kembali ke aplikasi.';
        case 'installing':
            return 'Pilih Perbarui atau Pasang pada konfirmasi Android.';
        case 'ready':
            return 'Unduhan selesai. Pembaruan siap dipasang.';
        default:
            return '';
    }
});
const manualUrl = ref('');
const copied = ref(false);
const ready = ref(false);
const presenting = computed(
    () =>
        (ready.value || busy.value || packageReady.value) &&
        canPresentAppUpdate(path.value) &&
        status.value?.update_available &&
        status.value.compatible &&
        status.value.release &&
        (required.value || dismissed.value !== status.value.release.id),
);
let checking = false;
let lastCheck = 0;
let timer: ReturnType<typeof setInterval> | undefined;
let progressTimer: ReturnType<typeof setInterval> | undefined;
let polling = false;
let autoInstall = false;
let stopNavigation: (() => void) | undefined;
let stopSuccess: (() => void) | undefined;

async function check(force = false) {
    if (!isAndroidApp() || document.hidden || navigator.onLine === false || checking || busy.value || (!force && Date.now() - lastCheck < 60000))
        return;
    checking = true;
    try {
        const info = await installedApp();
        inAppUpdates.value = info.in_app_updates === true;
        const result = await checkAppUpdate(info);
        if (busy.value) return;
        status.value = result;
        lastCheck = Date.now();
        ready.value = true;
    } catch {
        // A network failure never locks employees out of attendance.
        ready.value = false;
    } finally {
        checking = false;
    }
}

const dismiss = () => {
    if (required.value || busy.value) return;
    dismissed.value = status.value?.release?.id ?? null;
};
const download = async () => {
    if (!status.value?.release || busy.value) return;
    downloading.value = true;
    error.value = '';
    try {
        if (inAppUpdates.value) {
            await downloadAppUpdate(status.value.release);
            autoInstall = true;
            await readProgress();
        } else if (!(await openAppDownload(status.value.release))) {
            manualUrl.value = `${window.location.origin}/mobile-app/android/releases/${status.value.release.id}/download`;
        }
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Unduhan belum berhasil. Coba kembali.';
    } finally {
        downloading.value = false;
    }
};
const install = async () => {
    if (!status.value?.release || busy.value || !packageReady.value) return;
    downloading.value = true;
    autoInstall = false;
    error.value = '';
    try {
        await installAppUpdate(status.value.release);
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Pemasangan belum berhasil. Coba kembali.';
    } finally {
        downloading.value = false;
        await readProgress();
    }
};
async function readProgress() {
    if (!inAppUpdates.value || polling || document.hidden) return;
    polling = true;
    try {
        progress.value = await appUpdateProgress();
    } catch {
        // The WebView may be resuming. A failed status read never starts a second download.
    } finally {
        polling = false;
    }
    if (autoInstall && packageReady.value && !downloading.value && presenting.value) void install();
}
const copyUrl = async () => {
    try {
        await navigator.clipboard.writeText(manualUrl.value);
        copied.value = true;
    } catch {
        error.value = 'Tekan dan tahan tautan untuk menyalinnya, lalu buka melalui browser.';
    }
};
const visibility = () => {
    if (!document.hidden) {
        void readProgress();
        void check();
    }
};
const online = () => {
    connected.value = true;
    void check(true);
};
const offline = () => {
    connected.value = false;
    ready.value = false;
};
const nativeBack = (event: Event) => {
    if (!modal.value?.open) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    dismiss();
};
const navigated = () => {
    path.value = window.location.pathname;
    void check();
};

watch(presenting, async (show) => {
    await nextTick();
    if (show && !modal.value?.open) modal.value?.showModal();
    else if (!show && modal.value?.open) modal.value.close();
});

watch(
    () => status.value?.release?.id,
    () => {
        autoInstall = false;
        manualUrl.value = '';
        copied.value = false;
        error.value = '';
    },
);

onMounted(() => {
    if (!isAndroidApp()) return;
    stopNavigation = router.on('navigate', navigated);
    stopSuccess = router.on('success', navigated);
    window.addEventListener('rsarsy:back', nativeBack, true);
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('online', online);
    window.addEventListener('offline', offline);
    window.addEventListener('focus', visibility);
    timer = setInterval(() => {
        void check();
    }, 60000);
    progressTimer = setInterval(() => {
        if (inAppUpdates.value && (presenting.value || busy.value)) void readProgress();
    }, 500);
    void check(true);
});
onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
    if (progressTimer) clearInterval(progressTimer);
    stopNavigation?.();
    stopSuccess?.();
    window.removeEventListener('rsarsy:back', nativeBack, true);
    document.removeEventListener('visibilitychange', visibility);
    window.removeEventListener('online', online);
    window.removeEventListener('offline', offline);
    window.removeEventListener('focus', visibility);
    modal.value?.close();
});
</script>

<template>
    <Teleport to="body">
        <dialog ref="modal" class="app-update-dialog" aria-labelledby="app-update-title" @cancel.prevent="dismiss">
            <div v-if="status?.release" class="app-update-content">
                <span class="update-icon"><Smartphone :size="28" /></span>
                <p class="update-eyebrow">ARSY PRESENSI</p>
                <h2 id="app-update-title">{{ required ? 'Perbarui aplikasi untuk melanjutkan' : 'Versi baru sudah tersedia' }}</h2>
                <p class="update-version">
                    Versi {{ status.release.version_name }} ·
                    {{ (status.release.size_bytes / 1048576).toLocaleString('id-ID', { maximumFractionDigits: 1 }) }} MB
                </p>
                <p class="update-notes">{{ status.release.release_notes }}</p>
                <p v-if="inAppUpdates" class="update-hint">
                    Ketuk Perbarui sekarang. Aplikasi akan mengunduh pembaruan, lalu membuka konfirmasi pemasangan Android. Jika diminta, aktifkan
                    Izinkan dari sumber ini. Setelah selesai, buka kembali aplikasi.
                </p>
                <p v-else class="update-hint">
                    Unduh APK, buka file dari folder Download, lalu pilih Perbarui / Pasang. Setelah selesai, buka kembali aplikasi.
                </p>
                <p v-if="!required && status.release.required_at" class="update-hint">
                    Batas pembaruan: {{ new Date(status.release.required_at).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' }) }} WIB.
                </p>
                <div v-if="inAppUpdates && progressLabel" class="update-progress" role="status" aria-live="polite">
                    <p>{{ progressLabel }}</p>
                    <progress v-if="currentProgress?.stage === 'downloading'" :value="percent" max="100" aria-label="Progres unduhan pembaruan" />
                </div>
                <p v-if="error || currentProgress?.error" class="update-error" role="alert">{{ error || currentProgress?.error }}</p>
                <div v-if="manualUrl" class="update-manual" role="status">
                    <p>Untuk memperbarui APK lama ini, salin tautan lalu buka melalui Chrome atau browser di HP Anda.</p>
                    <input :value="manualUrl" readonly aria-label="Tautan unduhan APK" @focus="($event.target as HTMLInputElement).select()" />
                    <button class="update-secondary" @click="copyUrl">{{ copied ? 'Tautan tersalin' : 'Salin tautan unduhan' }}</button>
                </div>
                <button class="update-primary" :disabled="busy" @click="packageReady ? install() : download()">
                    <ArrowDownToLine :size="18" />
                    {{ busy ? 'Memproses pembaruan…' : packageReady ? 'Pasang pembaruan' : inAppUpdates ? 'Perbarui sekarang' : 'Unduh pembaruan' }}
                </button>
                <button v-if="!required" class="update-secondary" :disabled="busy" @click="dismiss">Nanti</button>
                <button v-else class="update-secondary" :disabled="busy" @click="check(true)"><RefreshCw :size="15" /> Periksa kembali</button>
            </div>
        </dialog>
    </Teleport>
</template>

<style scoped>
.app-update-dialog {
    position: fixed;
    inset: 0;
    margin: auto;
    width: min(440px, calc(100% - 32px));
    max-height: calc(100dvh - 48px);
    border: 0;
    border-radius: 24px;
    padding: 0;
    background: #fff;
    color: #173238;
    box-shadow: 0 24px 80px #123b3440;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.app-update-dialog::backdrop {
    background: #102c35b3;
}
.app-update-content {
    padding: 28px;
}
.update-icon {
    display: inline-flex;
    padding: 14px;
    border-radius: 18px;
    background: #e2f3ed;
    color: #087f78;
    margin-bottom: 20px;
}
.update-eyebrow {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.16em;
    color: #087f78;
    margin: 0 0 8px;
}
h2 {
    font-size: 23px;
    line-height: 1.35;
    margin: 0 0 12px;
    font-weight: 800;
}
.update-version,
.update-hint {
    font-size: 12px;
    line-height: 1.7;
    color: #617773;
}
.update-notes {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 14px;
    line-height: 1.7;
    max-height: 180px;
    overflow-y: auto;
    padding: 14px;
    border-radius: 12px;
    background: #f4f8f6;
}
.update-primary,
.update-secondary {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    width: 100%;
    border: 0;
    padding: 13px;
    border-radius: 12px;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
}
.update-primary {
    background: #087f78;
    color: #fff;
    margin-top: 20px;
}
.update-secondary {
    background: transparent;
    color: #526d66;
    margin-top: 5px;
}
.update-error {
    color: #b42318;
    font-size: 12px;
}
.update-progress {
    margin-top: 16px;
    color: #087f78;
    font-size: 12px;
    line-height: 1.7;
}
.update-progress progress {
    width: 100%;
    height: 8px;
    accent-color: #087f78;
}
.update-manual {
    font-size: 12px;
    line-height: 1.7;
    padding: 12px;
    background: #f4f8f6;
    border-radius: 12px;
}
.update-manual input {
    width: 100%;
    padding: 8px;
    border: 1px solid #cdded8;
    border-radius: 8px;
    font-size: 11px;
    user-select: all;
}
button:disabled {
    opacity: 0.65;
}
</style>
