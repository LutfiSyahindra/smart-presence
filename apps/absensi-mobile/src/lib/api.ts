import { getToken, removeToken } from './token-storage';

export const apiBaseUrl = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api/mobile/v1').replace(/\/$/, '');

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export async function apiRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
    const token = await getToken();
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (token) headers.set('Authorization', `Bearer ${token}`);
    if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');

    let response: Response;
    try {
        response = await fetch(`${apiBaseUrl}${path}`, { ...init, headers });
    } catch {
        throw new ApiError('Server tidak dapat dijangkau. Periksa koneksi internet lalu coba kembali.', 0);
    }

    if (response.status === 401) await removeToken();

    const contentType = response.headers.get('content-type') || '';
    const payload = contentType.includes('application/json') ? await response.json() : null;
    if (!response.ok) {
        const errors = (payload?.errors || {}) as Record<string, string[]>;
        const firstError = Object.values(errors).flat()[0];
        throw new ApiError(firstError || payload?.message || 'Permintaan belum berhasil diproses.', response.status, errors);
    }

    return payload as T;
}

export async function authenticatedBlob(path: string): Promise<Blob> {
    const token = await getToken();
    const response = await fetch(`${apiBaseUrl}${path}`, {
        headers: {
            Accept: 'image/*',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });
    if (!response.ok) throw new ApiError('Foto tidak dapat dimuat.', response.status);

    return response.blob();
}
