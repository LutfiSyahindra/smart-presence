import { SecureStorage } from '@aparajita/capacitor-secure-storage';

const tokenKey = 'rsarsy_mobile_token';

export async function getToken(): Promise<string | null> {
    const value = await SecureStorage.get(tokenKey);

    return typeof value === 'string' && value.length > 0 ? value : null;
}

export async function setToken(token: string): Promise<void> {
    await SecureStorage.set(tokenKey, token);
}

export async function removeToken(): Promise<void> {
    await SecureStorage.remove(tokenKey);
}
