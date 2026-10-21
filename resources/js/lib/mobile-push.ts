type ListenerHandle = { remove(): Promise<void> };
export type NativePushPlugin = {
    checkPermissions(): Promise<{ receive: string }>;
    requestPermissions(): Promise<{ receive: string }>;
    register(): Promise<void>;
    createChannel(options: Record<string, unknown>): Promise<void>;
    addListener(event: string, callback: (data: Record<string, unknown>) => void): Promise<ListenerHandle>;
};
type PushBridge = {
    getPlatform(): string;
    Plugins?: { PushNotifications?: NativePushPlugin };
    registerPlugin?(name: string): NativePushPlugin;
    isPluginAvailable?(name: string): boolean;
};
const bridge = () => (typeof window === 'undefined' ? undefined : (window as Window & { Capacitor?: PushBridge }).Capacitor);
export const nativePushPlatform = () => {
    const platform = bridge()?.getPlatform();
    return platform === 'android' || platform === 'ios' ? platform : null;
};
let plugin: NativePushPlugin | undefined;
export function nativePushPlugin(): NativePushPlugin | undefined {
    const capacitor = bridge();
    if (!nativePushPlatform() || capacitor?.isPluginAvailable?.('PushNotifications') === false) return undefined;
    // The Laravel frontend uses the wrapper's injected bridge. Its plugin
    // proxies are already available under Plugins, without importing Capacitor.
    return (plugin ??= capacitor?.Plugins?.PushNotifications ?? capacitor?.registerPlugin?.('PushNotifications'));
}

export function mobileNotificationUrl(value: unknown, origin: string): string | null {
    if (typeof value !== 'string') return null;
    try {
        const url = new URL(value, origin);
        return url.origin === origin &&
            /^\/absensi\/mobile\/notifikasi\/open\/[a-zA-Z0-9-]+$/.test(url.pathname) &&
            !url.search &&
            !url.hash &&
            !url.username &&
            !url.password
            ? url.pathname
            : null;
    } catch {
        return null;
    }
}

export async function webPushSubscription(publicKey: string): Promise<PushSubscription> {
    // Use this registration directly: /absensi/mobile has no trailing slash and
    // navigator.serviceWorker.ready could wait for another worker/scope indefinitely.
    const registration = await navigator.serviceWorker.register('/mobile-notifications-sw.js', { scope: '/absensi/mobile' });
    if (!registration.active) {
        await new Promise<void>((resolve, reject) => {
            const worker = registration.installing || registration.waiting;
            if (!worker) {
                reject(new Error('Service worker belum tersedia.'));
                return;
            }
            const finish = () => {
                if (worker.state === 'activated') {
                    clearTimeout(timeout);
                    worker.removeEventListener('statechange', finish);
                    resolve();
                } else if (worker.state === 'redundant') {
                    clearTimeout(timeout);
                    worker.removeEventListener('statechange', finish);
                    reject(new Error('Service worker gagal.'));
                }
            };
            const timeout = setTimeout(() => {
                worker.removeEventListener('statechange', finish);
                reject(new Error('Service worker belum aktif.'));
            }, 15000);
            worker.addEventListener('statechange', finish);
            finish();
        });
    }
    const existing = await registration.pushManager.getSubscription();
    if (existing) return existing;
    const raw = atob(publicKey.replaceAll('-', '+').replaceAll('_', '/'));
    return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from(raw, (char) => char.charCodeAt(0)) });
}
