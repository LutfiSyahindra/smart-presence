<script setup lang="ts">
import { useAdminNotificationCounts } from '@/composables/useAdminNotificationCounts';
import type { AdminNotification, NotificationFeed } from '@/types/notifications';
import { Link } from '@inertiajs/vue3';
import { Bell, BellRing, CheckCheck, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const baseUrl = '/absensi/admin-web/notifikasi';
const { counts, updateCounts } = useAdminNotificationCounts();
const menu = ref<HTMLElement | null>(null);
const open = ref(false);
const feed = ref<NotificationFeed>({ items: [], unread_count: 0, pending_approval_count: 0, push_public_key: null });
const toast = ref<AdminNotification | null>(null);
const feedback = ref('');
const pushSupported = ref(false);
const pushActive = ref(false);
const pushBusy = ref(false);
const loading = ref(false);
const markingRead = ref(false);
let initialized = false;
let stopped = false;
let controller: AbortController | undefined;
let timer: ReturnType<typeof setInterval> | undefined;
let toastTimer: ReturnType<typeof setTimeout> | undefined;
let knownIds = new Set<string>();
const pushLabel = computed(() => (pushActive.value ? 'Push aktif' : 'Aktifkan push'));
const date = (value: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const csrfHeaders = (): HeadersInit => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-XSRF-TOKEN': decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice(11) ?? '',
    ),
});

const refresh = async () => {
    if (loading.value || stopped) return;
    loading.value = true;
    controller = new AbortController();
    try {
        const response = await fetch(`${baseUrl}/feed`, { headers: { Accept: 'application/json' }, signal: controller.signal, cache: 'no-store' });
        if (response.status === 401 || response.status === 403) {
            stopped = true;
            feed.value = { items: [], unread_count: 0, pending_approval_count: 0, push_public_key: null };
            updateCounts(null);
            return;
        }
        if (!response.ok) return;
        const next: NotificationFeed = await response.json();
        const newest = next.items.find((item) => !knownIds.has(item.id) && !item.read_at);
        if (initialized && newest) {
            toast.value = newest;
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => (toast.value = null), 8000);
        }
        knownIds = new Set([...knownIds, ...next.items.map((item) => item.id)]);
        initialized = true;
        feed.value = next;
        updateCounts(next);
    } catch {
        // Keep the saved inbox available while offline; the next poll retries.
    } finally {
        loading.value = false;
    }
};

const syncSubscription = async () => {
    if (!pushSupported.value || !feed.value.push_public_key || Notification.permission !== 'granted') return;
    await navigator.serviceWorker.register('/admin-notifications-sw.js', { scope: '/absensi/admin-web/' });
    const registration = await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        const raw = atob(feed.value.push_public_key.replaceAll('-', '+').replaceAll('_', '/'));
        const key = Uint8Array.from(raw, (character) => character.charCodeAt(0));
        subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    }
    const response = await fetch(`${baseUrl}/push-subscriptions`, {
        method: 'POST',
        headers: csrfHeaders(),
        body: JSON.stringify({ ...subscription.toJSON(), contentEncoding: 'aes128gcm' }),
    });
    if (!response.ok) throw new Error('Subscription gagal disimpan.');
    pushActive.value = true;
};

const enablePush = async () => {
    if (pushBusy.value || pushActive.value) return;
    pushBusy.value = true;
    feedback.value = '';
    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            feedback.value =
                permission === 'denied'
                    ? 'Izinkan notifikasi melalui pengaturan situs di browser untuk menerima push.'
                    : 'Push belum diaktifkan. Anda tetap dapat membaca notifikasi di sini.';
            return;
        }
        await syncSubscription();
    } catch {
        feedback.value = 'Push belum berhasil diaktifkan. Periksa koneksi lalu coba kembali.';
    } finally {
        pushBusy.value = false;
    }
};

const markAllRead = async () => {
    if (markingRead.value) return;
    markingRead.value = true;
    try {
        const response = await fetch(`${baseUrl}/read-all`, { method: 'POST', headers: csrfHeaders() });
        if (!response.ok) throw new Error('Gagal menandai notifikasi.');
        await refresh();
    } catch {
        feedback.value = 'Notifikasi belum berhasil ditandai. Coba kembali.';
    } finally {
        markingRead.value = false;
    }
};
const outside = (event: MouseEvent) => {
    if (menu.value && !menu.value.contains(event.target as Node)) open.value = false;
};
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') open.value = false;
};
const onVisible = () => {
    if (!document.hidden) void refresh();
};
const onPush = (event: MessageEvent) => {
    if (event.data?.type === 'admin-notification') void refresh();
};

onMounted(async () => {
    pushSupported.value = window.isSecureContext && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
    document.addEventListener('click', outside);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('visibilitychange', onVisible);
    navigator.serviceWorker?.addEventListener('message', onPush);
    timer = setInterval(() => {
        if (!document.hidden) void refresh();
    }, 20000);
    await refresh();
    if (!stopped) {
        try {
            await syncSubscription();
        } catch {
            /* The enable button allows retrying. */
        }
    }
});
onBeforeUnmount(() => {
    stopped = true;
    controller?.abort();
    if (timer) clearInterval(timer);
    if (toastTimer) clearTimeout(toastTimer);
    document.removeEventListener('click', outside);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('visibilitychange', onVisible);
    navigator.serviceWorker?.removeEventListener('message', onPush);
});
</script>

<template>
    <li ref="menu" class="nav-item notification-menu">
        <button
            type="button"
            class="nav-link noble-icon-button notification-trigger"
            :aria-expanded="open"
            aria-controls="admin-notification-menu"
            :aria-label="`Notifikasi, ${counts.unread_count} belum dibaca`"
            @click.stop="
                open = !open;
                refresh();
            "
        >
            <Bell /><span v-if="counts.unread_count" class="notification-count">{{ counts.unread_count > 99 ? '99+' : counts.unread_count }}</span>
        </button>
        <div v-if="open" id="admin-notification-menu" class="notification-panel">
            <header>
                <strong>Notifikasi</strong
                ><button v-if="feed.unread_count" type="button" :disabled="markingRead" @click="markAllRead"><CheckCheck /> Tandai dibaca</button>
            </header>
            <div class="notification-push">
                <button v-if="pushSupported && feed.push_public_key" type="button" :disabled="pushBusy || pushActive" @click="enablePush">
                    <BellRing /> {{ pushBusy ? 'Mengaktifkan...' : pushLabel }}
                </button>
                <span v-else>{{ pushSupported ? 'Push belum tersedia.' : 'Push tidak didukung pada browser atau koneksi ini.' }}</span>
                <p v-if="feedback" role="status">{{ feedback }}</p>
            </div>
            <div class="notification-list">
                <Link
                    v-for="item in feed.items"
                    :key="item.id"
                    :href="item.url"
                    class="notification-item"
                    :class="{ unread: !item.read_at }"
                    @click="open = false"
                >
                    <span class="notification-dot" :class="{ unread: !item.read_at }"></span>
                    <span
                        ><strong>{{ item.title }}</strong
                        ><span>{{ item.message }}</span
                        ><small>{{ date(item.created_at) }}</small></span
                    >
                </Link>
                <p v-if="!feed.items.length" class="notification-empty">{{ loading ? 'Memuat notifikasi...' : 'Belum ada notifikasi.' }}</p>
            </div>
            <Link :href="`${baseUrl}/semua`" class="notification-footer" @click="open = false">Lihat semua notifikasi</Link>
        </div>
        <Teleport to="body">
            <div v-if="toast" class="notification-toast" role="status" aria-live="polite">
                <BellRing /><Link :href="toast.url" @click="toast = null"
                    ><strong>{{ toast.title }}</strong
                    ><span>{{ toast.message }}</span></Link
                >
                <button type="button" aria-label="Tutup notifikasi" @click="toast = null"><X /></button>
            </div>
        </Teleport>
    </li>
</template>

<style scoped>
.notification-menu {
    position: relative;
}
.notification-trigger {
    position: relative;
}
.notification-count {
    position: absolute;
    top: -2px;
    right: -5px;
    min-width: 18px;
    padding: 2px 4px;
    border-radius: 20px;
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
}
.notification-panel {
    position: absolute;
    top: calc(100% + 14px);
    right: 0;
    z-index: 1100;
    width: min(390px, calc(100vw - 28px));
    border: 1px solid var(--rs-border);
    border-radius: 14px;
    background: #fff;
    color: var(--rs-ink);
    box-shadow: 0 15px 50px rgba(var(--rs-primary-deep-rgb), 0.141);
    overflow: hidden;
}
.notification-panel header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 17px;
}
.notification-panel button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 0;
    background: transparent;
    color: var(--rs-primary);
    font-size: 11px;
}
.notification-panel button:disabled {
    opacity: 0.65;
    cursor: default;
}
.notification-panel button svg {
    width: 15px;
    height: 15px;
}
.notification-push {
    padding: 12px 17px;
    background: var(--rs-primary-soft);
    font-size: 11px;
}
.notification-push p {
    margin: 8px 0 0;
}
.notification-list {
    max-height: 370px;
    overflow-y: auto;
}
.notification-item {
    display: flex;
    gap: 10px;
    padding: 14px 17px;
    border-bottom: 1px solid var(--rs-surface-muted);
    color: inherit;
    text-decoration: none;
}
.notification-item:hover,
.notification-item.unread {
    background: var(--rs-canvas);
}
.notification-item > span:last-child {
    display: grid;
    gap: 5px;
    min-width: 0;
}
.notification-item strong {
    font-size: 12px;
    line-height: 1.5;
}
.notification-item span span {
    font-size: 11px;
    line-height: 1.6;
    color: var(--rs-text-soft);
}
.notification-item small {
    font-size: 10px;
    color: var(--rs-muted);
}
.notification-dot {
    width: 7px;
    height: 7px;
    margin-top: 5px;
    flex-shrink: 0;
    border-radius: 50%;
}
.notification-dot.unread {
    background: var(--rs-primary);
}
.notification-empty {
    padding: 24px;
    margin: 0;
    text-align: center;
    font-size: 12px;
    color: var(--rs-muted);
}
.notification-footer {
    display: block;
    padding: 14px;
    text-align: center;
    font-size: 12px;
    color: var(--rs-primary);
    border-top: 1px solid var(--rs-border);
}
.notification-toast {
    position: fixed;
    top: 85px;
    right: 20px;
    z-index: 1200;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    width: min(390px, calc(100vw - 40px));
    padding: 18px;
    border: 1px solid var(--rs-primary-border);
    border-radius: 14px;
    background: #fff;
    color: var(--rs-primary);
    box-shadow: 0 12px 45px rgba(var(--rs-primary-deep-rgb), 0.161);
}
.notification-toast > svg {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
}
.notification-toast a {
    display: grid;
    gap: 7px;
    color: var(--rs-ink);
    text-decoration: none;
    font-size: 12px;
    line-height: 1.6;
}
.notification-toast span {
    color: var(--rs-text-soft);
    font-size: 11px;
}
.notification-toast button {
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--rs-muted);
}
.notification-toast button svg {
    width: 16px;
    height: 16px;
}
@media (max-width: 575px) {
    .notification-panel {
        position: fixed;
        top: 72px;
        right: 14px;
    }
}
</style>
