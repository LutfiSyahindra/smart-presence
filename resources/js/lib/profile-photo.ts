const PHOTO_DIMENSION = 1024;
const MIN_PHOTO_DIMENSION = 480;
const MAX_PHOTO_BYTES = 1024 * 1024;

export async function prepareProfilePhoto(file: File): Promise<File> {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        throw new Error('Gunakan foto JPG, PNG, atau WebP.');
    }

    const sourceUrl = URL.createObjectURL(file);
    const image = new Image();
    const canvas = document.createElement('canvas');

    try {
        await new Promise<void>((resolve, reject) => {
            image.onload = () => resolve();
            image.onerror = () => reject(new Error('Foto tidak dapat dibaca. Pilih foto lain yang masih dapat dibuka.'));
            image.src = sourceUrl;
        });

        if (!image.naturalWidth || !image.naturalHeight) {
            throw new Error('Dimensi foto tidak valid. Silakan pilih foto lain.');
        }

        const scale = Math.min(
            PHOTO_DIMENSION / Math.max(image.naturalWidth, image.naturalHeight),
            Math.max(1, MIN_PHOTO_DIMENSION / Math.min(image.naturalWidth, image.naturalHeight)),
        );
        const width = Math.max(1, Math.round(image.naturalWidth * scale));
        const height = Math.max(1, Math.round(image.naturalHeight * scale));
        canvas.width = Math.max(MIN_PHOTO_DIMENSION, width);
        canvas.height = Math.max(MIN_PHOTO_DIMENSION, height);
        const context = canvas.getContext('2d');

        if (!context) throw new Error('Foto belum dapat disesuaikan. Silakan coba kembali.');

        // Preserve the entire photo. Pad narrow images to meet the upload's minimum dimensions.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(
            image,
            0,
            0,
            image.naturalWidth,
            image.naturalHeight,
            (canvas.width - width) / 2,
            (canvas.height - height) / 2,
            width,
            height,
        );

        for (const quality of [0.92, 0.85, 0.78, 0.7]) {
            const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

            if (!blob || blob.type !== 'image/jpeg' || !blob.size) {
                throw new Error('Foto belum dapat disesuaikan. Silakan coba kembali.');
            }

            if (blob.size <= MAX_PHOTO_BYTES) {
                const name = file.name.replace(/\.[^.]+$/, '') || 'foto-profil';
                return new File([blob], `${name}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
            }
        }

        throw new Error('Ukuran foto belum berhasil disesuaikan. Silakan pilih foto lain.');
    } finally {
        URL.revokeObjectURL(sourceUrl);
        image.onload = null;
        image.onerror = null;
        canvas.width = 0;
        canvas.height = 0;
    }
}
