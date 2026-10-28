export interface InstalledApp {
    version_code: number;
    version_name: string | null;
    sdk_int?: number;
    in_app_updates?: boolean;
}

export interface AppRelease {
    id: number;
    version_code: number;
    version_name: string;
    size_bytes: number;
    release_notes: string;
    required_at: string | null;
    download_url: string;
}

export interface AppUpdateStatus {
    release: AppRelease | null;
    update_available: boolean;
    update_required: boolean;
    compatible: boolean;
}

export interface AppUpdateProgress {
    stage: 'idle' | 'checking' | 'downloading' | 'verifying' | 'ready' | 'permission' | 'installing' | 'error';
    release_id: number;
    downloaded_bytes: number;
    total_bytes: number;
    error: string;
}

interface UpdateBridge {
    getPlatform(): string;
    nativePromise?(plugin: string, method: string, options: Record<string, unknown>): Promise<unknown>;
}

const bridge = () => (window as Window & { Capacitor?: UpdateBridge }).Capacitor;
export const isAndroidApp = () => typeof window !== 'undefined' && bridge()?.getPlatform() === 'android';

export async function installedApp(): Promise<InstalledApp> {
    let deadline: ReturnType<typeof setTimeout> | undefined;
    try {
        const info = (await Promise.race([
            bridge()?.nativePromise?.('AppUpdates', 'getInfo', {}),
            new Promise<never>((_, reject) => {
                deadline = setTimeout(() => reject(new Error('Version check timed out')), 3000);
            }),
        ])) as InstalledApp | undefined;
        if (info && Number.isSafeInteger(info.version_code) && info.version_code > 0) return info;
    } catch {
        // Older APKs lack this plugin. Still offer a download, without guessing their version.
    } finally {
        if (deadline) clearTimeout(deadline);
    }
    return { version_code: 0, version_name: null };
}

export async function checkAppUpdate(info: InstalledApp): Promise<AppUpdateStatus> {
    const query = new URLSearchParams({ version_code: String(info.version_code) });
    if (info.sdk_int) query.set('sdk_int', String(info.sdk_int));
    const response = await fetch(`/mobile-app/android/release?${query}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: AbortSignal.timeout(10000),
    });
    if (!response.ok) throw new Error('Pemeriksaan versi belum tersedia.');
    return response.json();
}

export async function openAppDownload(release: AppRelease): Promise<boolean> {
    if (!Number.isSafeInteger(release.id) || release.id < 1) throw new Error('Rilis APK tidak valid.');
    try {
        const native = bridge();
        if (!native?.nativePromise) throw new Error('Native bridge unavailable');
        await native.nativePromise('AppUpdates', 'openDownload', { releaseId: release.id });
        return true;
    } catch {
        // Legacy WebViews cannot reliably hand same-origin APK downloads to a browser.
        // The UI provides a copyable URL for this one-time manual update.
        return false;
    }
}

async function updateAction(method: string, release: AppRelease): Promise<void> {
    if (!Number.isSafeInteger(release.id) || release.id < 1) throw new Error('Rilis APK tidak valid.');
    const native = bridge();
    if (!isAndroidApp() || !native?.nativePromise) throw new Error('Pembaruan dalam aplikasi belum tersedia pada APK ini.');
    // Native code fetches trusted metadata itself; URLs, paths and hashes from JS
    // cannot choose which file is downloaded or handed to the Android installer.
    await native.nativePromise('AppUpdates', method, { releaseId: release.id });
}

export const downloadAppUpdate = (release: AppRelease) => updateAction('downloadUpdate', release);
export const installAppUpdate = (release: AppRelease) => updateAction('installUpdate', release);

export async function appUpdateProgress(): Promise<AppUpdateProgress> {
    const native = bridge();
    if (!isAndroidApp() || !native?.nativePromise) throw new Error('Status pembaruan belum tersedia.');
    return (await native.nativePromise('AppUpdates', 'getUpdateState', {})) as AppUpdateProgress;
}

export function canPresentAppUpdate(path: string): boolean {
    // Let GPS/camera, attendance submission, and forms finish before prompting.
    return ['/', '/login', '/absensi/mobile', '/absensi/mobile/akun'].includes(path.replace(/\/$/, '') || '/');
}
