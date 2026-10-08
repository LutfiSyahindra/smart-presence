type AndroidLocation = {
    latitude: number;
    longitude: number;
    accuracy: number;
    captured_at: number;
    is_mock: boolean;
};

interface NativeBridge {
    getPlatform(): string;
    nativePromise?(plugin: string, method: string, options: Record<string, unknown>): Promise<AndroidLocation>;
}

const bridge = () => (typeof window === 'undefined' ? undefined : (window as Window & { Capacitor?: NativeBridge }).Capacitor);

export const isAndroidAttendance = () => bridge()?.getPlatform() === 'android';

export async function getAndroidAttendanceLocation(): Promise<AndroidLocation> {
    const capacitor = bridge();
    if (!capacitor?.nativePromise) {
        throw new Error('Pemeriksaan keamanan GPS tidak tersedia. Perbarui aplikasi Android lalu coba kembali.');
    }

    // Use the bridge injected by the wrapper, so the shared web frontend does
    // not need to bundle a second Capacitor runtime. Never fall back to WebView GPS.
    const location = await capacitor.nativePromise('AttendanceLocation', 'getCurrentPosition', {});
    if (location?.is_mock === true) {
        throw new Error('Fake GPS terdeteksi. Nonaktifkan aplikasi lokasi palsu lalu ambil lokasi kembali.');
    }
    if (
        !location ||
        location.is_mock !== false ||
        !Number.isFinite(location.latitude) ||
        Math.abs(location.latitude) > 90 ||
        !Number.isFinite(location.longitude) ||
        Math.abs(location.longitude) > 180 ||
        !Number.isFinite(location.accuracy) ||
        location.accuracy < 0 ||
        !Number.isFinite(location.captured_at) ||
        Date.now() - location.captured_at > 15_000 ||
        location.captured_at > Date.now() + 5_000
    ) {
        throw new Error('Keaslian lokasi GPS belum dapat diverifikasi. Aktifkan GPS dan ambil lokasi kembali.');
    }

    return location;
}
