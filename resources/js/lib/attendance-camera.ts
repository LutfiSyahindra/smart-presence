const MAXIMUM_SIDE = 1280;
const SAMPLE_COUNT = 4;
const SAMPLE_INTERVAL_MS = 150;

// Rank image quality only. This is not face detection or a liveness decision.
export function cameraFrameScore(pixels: Uint8ClampedArray, width: number, height: number): number {
    const gray = new Float32Array(width * height);
    let brightness = 0;
    let clipped = 0;
    for (let index = 0; index < gray.length; index += 1) {
        const offset = index * 4;
        const value = 0.299 * pixels[offset] + 0.587 * pixels[offset + 1] + 0.114 * pixels[offset + 2];
        gray[index] = value;
        brightness += value;
        if (value < 10 || value > 245) clipped += 1;
    }

    let sum = 0;
    let squared = 0;
    let count = 0;
    for (let y = 1; y < height - 1; y += 1) {
        for (let x = 1; x < width - 1; x += 1) {
            const index = y * width + x;
            const laplacian = gray[index - width] + gray[index + width] + gray[index - 1] + gray[index + 1] - 4 * gray[index];
            sum += laplacian;
            squared += laplacian * laplacian;
            count += 1;
        }
    }

    if (!count || !gray.length) return 0;
    const variance = Math.max(0, squared / count - (sum / count) ** 2);
    const exposure = Math.max(0, 1 - Math.abs(brightness / gray.length - 127.5) / 127.5);
    return Math.log1p(variance) * exposure * (1 - clipped / gray.length);
}

export async function captureAttendancePhoto(video: HTMLVideoElement, filename: string, isCurrent: () => boolean): Promise<File> {
    const sample = document.createElement('canvas');
    const best = document.createElement('canvas');
    const quality = document.createElement('canvas');

    try {
        const context = sample.getContext('2d');
        const bestContext = best.getContext('2d');
        const qualityContext = quality.getContext('2d', { willReadFrequently: true });
        if (!context || !bestContext || !qualityContext) throw new Error('Foto kamera tidak dapat diproses. Aktifkan ulang kamera.');

        quality.width = 128;
        quality.height = 128;
        let bestScore = -1;
        for (let index = 0; index < SAMPLE_COUNT; index += 1) {
            // Allow new camera frames and automatic exposure/focus to settle.
            await new Promise<void>((resolve) => window.setTimeout(resolve, SAMPLE_INTERVAL_MS));
            if (!isCurrent()) throw new DOMException('Pengambilan foto dibatalkan.', 'AbortError');
            if (video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || video.paused || video.ended) {
                throw new Error('Gambar kamera belum siap. Aktifkan ulang kamera lalu coba kembali.');
            }
            if (Math.min(video.videoWidth, video.videoHeight) < 160) {
                throw new Error('Resolusi kamera terlalu kecil. Aktifkan ulang kamera lalu coba kembali.');
            }

            const scale = Math.min(1, MAXIMUM_SIDE / Math.max(video.videoWidth, video.videoHeight));
            sample.width = Math.round(video.videoWidth * scale);
            sample.height = Math.round(video.videoHeight * scale);
            context.drawImage(video, 0, 0, sample.width, sample.height);

            // Assess the centre where the on-screen guide asks the user to place
            // their face. Upload the full, unfiltered image, preserving PAD context.
            qualityContext.drawImage(sample, sample.width * 0.2, sample.height * 0.15, sample.width * 0.6, sample.height * 0.7, 0, 0, 128, 128);
            const score = cameraFrameScore(qualityContext.getImageData(0, 0, 128, 128).data, 128, 128);
            if (score >= bestScore) {
                bestScore = score;
                best.width = sample.width;
                best.height = sample.height;
                bestContext.drawImage(sample, 0, 0);
            }
        }

        const blob = await new Promise<Blob | null>((resolve) => best.toBlob(resolve, 'image/jpeg', 0.95));
        if (!isCurrent()) throw new DOMException('Pengambilan foto dibatalkan.', 'AbortError');
        if (!blob?.size || blob.type !== 'image/jpeg') throw new Error('Foto kamera gagal diambil. Silakan coba lagi.');
        return new File([blob], filename, { type: 'image/jpeg' });
    } finally {
        for (const canvas of [sample, best, quality]) {
            canvas.width = 0;
            canvas.height = 0;
        }
    }
}
