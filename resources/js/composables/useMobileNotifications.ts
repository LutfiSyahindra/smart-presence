import { mobileNotificationUrl, nativePushPlatform, nativePushPlugin, webPushSubscription } from '@/lib/mobile-push';
import type { AdminNotification } from '@/types/notifications';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type MobileFeed = {
    items: AdminNotification[];
    unread_count: number;
    push_public_key: string | null;
    native_push: { android: boolean; ios: boolean };
};
const emptyFeed = (): MobileFeed => ({ items: [], unread_count: 0, push_public_key: null, native_push: { android: false, ios: false } });
const feed = ref(emptyFeed());
const feedback = ref('');
const pushActive = ref(false);
const busy = ref(false);
const supported = ref(false);
const base = '/absensi/mobile/notifikasi';
let userId: number | null = null;
let authenticatedUserId: number | null = null;
let abort: AbortController | undefined;
let polling: ReturnType<typeof setInterval> | undefined;
let started = false;
let nativeListeners: Promise<void> | undefined;
let pendingUrl: string | null = null;
let registration: { resolve(): void; reject(error: Error): void } | undefined;
let syncing: { owner: number; promise: Promise<void> } | undefined;

export const mobileCsrfHeaders = (): HeadersInit => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-XSRF-TOKEN': decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    ),
});
async function save(url: string, body: unknown) {
    const response = await fetch(url, { method: 'POST', headers: mobileCsrfHeaders(), body: JSON.stringify(body) });
    if (!response.ok) throw new Error('Notifikasi belum berhasil diaktifkan. Periksa koneksi dan coba kembali.');
}
async function refresh() {
    if (!userId || document.hidden) return;
    abort?.abort();
    const owner = userId;
    const controller = new AbortController();
    abort = controller;
    try {
        const response = await fetch(`${base}/feed`, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
        if (owner !== userId || controller.signal.aborted) return;
        if (response.status === 401 || response.status === 403) {
            feed.value = emptyFeed();
            pushActive.value = false;
            return;
        }
        if (response.ok) {
            const next: MobileFeed = await response.json();
            if (owner === userId && !controller.signal.aborted) feed.value = next;
        }
    } catch {
        /* Reconnect or the next visible poll retries. */
    }
}
function openPending() {
    if (!authenticatedUserId || !pendingUrl) return;
    const url = pendingUrl;
    pendingUrl = null;
    router.visit(url);
}
async function listenNative() {
    const plugin = nativePushPlugin();
    if (!plugin) throw new Error('Perbarui aplikasi untuk mengaktifkan notifikasi perangkat.');
    nativeListeners ??= (async () => {
        await plugin.addListener('registration', async (token) => {
            const owner = userId;
            if (!owner) return;
            try {
                await save(`${base}/devices`, { platform: nativePushPlatform(), token: token.value });
                if (owner !== userId) return;
                pushActive.value = true;
                registration?.resolve();
            } catch {
                if (owner === userId) registration?.reject(new Error('Perangkat belum berhasil didaftarkan. Coba kembali.'));
            }
        });
        await plugin.addListener('registrationError', () =>
            registration?.reject(new Error('Pendaftaran push gagal. Periksa konfigurasi perangkat.')),
        );
        await plugin.addListener('pushNotificationReceived', () => {
            void refresh();
        });
        await plugin.addListener('pushNotificationActionPerformed', (action) => {
            const notification = action.notification as { data?: { url?: unknown } } | undefined;
            pendingUrl = mobileNotificationUrl(notification?.data?.url, window.location.origin);
            openPending();
        });
    })();
    await nativeListeners;
    return plugin;
}
async function performSync() {
    const owner = userId;
    if (!owner) return;
    const platform = nativePushPlatform();
    if (platform) {
        if (!feed.value.native_push[platform]) throw new Error('Notifikasi perangkat belum tersedia. Hubungi administrator.');
        const plugin = await listenNative();
        if ((await plugin.checkPermissions()).receive !== 'granted') {
            pushActive.value = false;
            return;
        }
        if (platform === 'android') await plugin.createChannel({ id: 'pengajuan', name: 'Pengajuan', importance: 4, visibility: 0, vibration: true });
        await new Promise<void>((resolve, reject) => {
            const timer = setTimeout(() => finish(new Error('Pendaftaran notifikasi belum selesai. Coba kembali.')), 20000);
            const finish = (error?: Error) => {
                clearTimeout(timer);
                registration = undefined;
                if (error) reject(error);
                else resolve();
            };
            registration = { resolve: () => finish(), reject: finish };
            void plugin.register().catch(() => finish(new Error('Pendaftaran push gagal. Periksa konfigurasi perangkat.')));
        });
    } else if (supported.value && feed.value.push_public_key && Notification.permission === 'granted') {
        const subscription = await webPushSubscription(feed.value.push_public_key);
        if (owner !== userId) return;
        await save(`${base}/push-subscriptions`, { ...subscription.toJSON(), contentEncoding: 'aes128gcm' });
        if (owner === userId) pushActive.value = true;
    } else {
        pushActive.value = false;
    }
}
function syncPush(): Promise<void> {
    const owner = userId;
    if (!owner) return Promise.resolve();
    if (syncing?.owner === owner) return syncing.promise;
    const promise = performSync().finally(() => {
        if (syncing?.promise === promise) syncing = undefined;
    });
    syncing = { owner, promise };
    return promise;
}
async function enablePush() {
    if (busy.value || pushActive.value) return;
    busy.value = true;
    feedback.value = '';
    try {
        // Request permission directly from the button gesture (required by iOS PWA).
        const nativePlatform = nativePushPlatform();
        const nativePlugin = nativePlatform ? nativePushPlugin() : undefined;
        if (nativePlatform && !nativePlugin) throw new Error('Perbarui aplikasi untuk mengaktifkan notifikasi perangkat.');
        const permission = nativePlugin ? (await nativePlugin.requestPermissions()).receive : await Notification.requestPermission();
        if (permission !== 'granted') {
            feedback.value = 'Izinkan notifikasi di pengaturan aplikasi atau browser untuk menerima push.';
            return;
        }
        await syncPush();
    } catch (error) {
        feedback.value = error instanceof Error ? error.message : 'Notifikasi belum berhasil diaktifkan.';
    } finally {
        busy.value = false;
    }
}

export function startMobileNotifications(initialPage: { url: string; props: Record<string, unknown> }) {
    if (started) return;
    started = true;
    supported.value =
        Boolean(nativePushPlatform()) ||
        (window.isSecureContext && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window);
    // Register native tap listeners on every page, including a cold start at login.
    if (nativePushPlatform()) void listenNative().catch(() => {});
    const navigate = async (page: typeof initialPage) => {
        const auth = page.props.auth as { user?: { id: number } } | undefined;
        authenticatedUserId = auth?.user?.id ?? null;
        const path = new URL(page.url, window.location.origin).pathname;
        const next = path === '/absensi/mobile' || path.startsWith('/absensi/mobile/') ? authenticatedUserId : null;
        if (next === userId) {
            openPending();
            return;
        }
        abort?.abort();
        registration?.reject(new Error('Sesi berubah.'));
        if (polling) clearInterval(polling);
        userId = next;
        feed.value = emptyFeed();
        pushActive.value = false;
        feedback.value = '';
        if (!next) return;
        polling = setInterval(() => {
            void refresh();
        }, 20000);
        await refresh();
        if (next !== userId) return;
        openPending();
        try {
            await syncPush();
        } catch {
            /* The activation button provides a retry. */
        }
    };
    router.on('navigate', (event) => {
        void navigate(event.detail.page);
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden)
            void refresh()
                .then(() => syncPush())
                .catch(() => {
                    pushActive.value = false;
                });
    });
    window.addEventListener('online', () => {
        void refresh()
            .then(() => syncPush())
            .catch(() => {
                pushActive.value = false;
            });
    });
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type === 'mobile-notification') void refresh();
    });
    void navigate(initialPage);
}

export function useMobileNotifications() {
    const available = computed(() => {
        const platform = nativePushPlatform();
        return supported.value && (platform ? feed.value.native_push[platform] : Boolean(feed.value.push_public_key));
    });
    return { feed, feedback, pushActive, busy, supported, available, refresh, enablePush };
}
