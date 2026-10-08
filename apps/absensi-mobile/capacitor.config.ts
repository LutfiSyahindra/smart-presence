import type { CapacitorConfig } from '@capacitor/cli';

// Laravel/Inertia renders the existing Vue frontend. Android development loads
// that frontend rather than building the separate prototype in src/.
// adb reverse maps device localhost:8600 to the Laravel server on the computer.
const pwaUrl = new URL(process.env.CAPACITOR_PWA_URL || 'http://localhost:8600');
const isLoopback = ['localhost', '127.0.0.1', '[::1]'].includes(pwaUrl.hostname);
if (pwaUrl.username || pwaUrl.password || pwaUrl.search || pwaUrl.hash || pwaUrl.pathname !== '/') {
    throw new Error('CAPACITOR_PWA_URL harus berupa origin, contoh http://localhost:8600, tanpa path/kredensial.');
}
if (pwaUrl.protocol !== 'https:' && !(pwaUrl.protocol === 'http:' && isLoopback)) {
    throw new Error('Frontend harus memakai HTTPS. HTTP hanya diizinkan untuk localhost melalui adb reverse.');
}

const config: CapacitorConfig = {
    appId: 'id.rsarsy.presensi',
    appName: 'RSARSY Presence',
    webDir: 'wrapper',
    includePlugins: [],
    android: {
        allowMixedContent: false,
    },
    server: {
        // Capacitor server.url is a development facility. Release builds are
        // blocked in app/build.gradle until the production PWA wrapper is set up.
        url: pwaUrl.origin,
        androidScheme: 'https',
        cleartext: pwaUrl.protocol === 'http:',
        errorPath: 'offline.html',
    },
};

export default config;
