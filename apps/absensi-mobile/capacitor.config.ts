import type { CapacitorConfig } from '@capacitor/cli';
import production from './production.json';

// Laravel/Inertia renders the existing Vue frontend. Android development loads
// that frontend rather than building the separate prototype in src/.
// adb reverse maps device localhost:8600 to the Laravel server on the computer.
const release = process.env.CAPACITOR_BUILD_MODE === 'release';
const pwaUrl = new URL(release ? production.origin : process.env.CAPACITOR_PWA_URL || 'http://localhost:8600');
const isLoopback = ['localhost', '127.0.0.1', '[::1]'].includes(pwaUrl.hostname);
if (pwaUrl.username || pwaUrl.password || pwaUrl.search || pwaUrl.hash || pwaUrl.pathname !== '/') {
    throw new Error('CAPACITOR_PWA_URL harus berupa origin, contoh http://localhost:8600, tanpa path/kredensial.');
}
if (pwaUrl.protocol !== 'https:' && !(pwaUrl.protocol === 'http:' && isLoopback)) {
    throw new Error('Frontend harus memakai HTTPS. HTTP hanya diizinkan untuk localhost melalui adb reverse.');
}
if (release && (pwaUrl.protocol !== 'https:' || isLoopback || pwaUrl.port)) {
    throw new Error('Release wajib memakai domain HTTPS production tanpa port khusus.');
}

const config: CapacitorConfig = {
    appId: 'id.rsarsy.presensi',
    appName: 'RSARSY Presence',
    webDir: 'wrapper',
    includePlugins: ['@capacitor/push-notifications'],
    plugins: {
        PushNotifications: {
            presentationOptions: ['alert', 'sound'],
        },
    },
    android: {
        allowMixedContent: false,
        webContentsDebuggingEnabled: !release,
        useLegacyBridge: false,
        // Service-worker fetches must reach Laravel, not bundled wrapper files.
        resolveServiceWorkerRequests: !release,
    },
    server: {
        // Release uses the native HTTPS wrapper in MainActivity, with the bridge
        // restricted to this origin. It does not use Capacitor's live-reload URL.
        ...(release ? { hostname: pwaUrl.hostname } : { url: pwaUrl.origin }),
        androidScheme: 'https',
        cleartext: !release && pwaUrl.protocol === 'http:',
        errorPath: release ? 'native/offline.html' : 'offline.html',
    },
};

export default config;
