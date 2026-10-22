import { ApiError, apiRequest } from '@/lib/api';
import { getToken, removeToken, setToken } from '@/lib/token-storage';
import { Device } from '@capacitor/device';
import { computed, reactive } from 'vue';

interface AuthUser {
    id: number;
    name: string;
    email: string;
    can_access_account: boolean;
    permissions: string[];
}

const state = reactive({
    ready: false,
    token: null as string | null,
    user: null as AuthUser | null,
});

let restorePromise: Promise<void> | null = null;

async function restore(): Promise<void> {
    if (state.ready) return;
    if (restorePromise) return restorePromise;

    restorePromise = (async () => {
        state.token = await getToken();
        if (state.token) {
            try {
                state.user = (await apiRequest<{ user: AuthUser }>('/auth/user')).user;
            } catch (error) {
                if (error instanceof ApiError && [401, 403].includes(error.status)) {
                    await removeToken();
                    state.token = null;
                }
            }
        }
        state.ready = true;
    })();

    return restorePromise;
}

async function login(email: string, password: string): Promise<void> {
    const info = await Device.getInfo();
    const deviceName = `RSARSY ${info.model || info.operatingSystem || 'Mobile'}`.slice(0, 120);
    const response = await apiRequest<{
        token: string;
        user: AuthUser;
    }>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password, device_name: deviceName }),
    });

    await setToken(response.token);
    state.token = response.token;
    state.user = response.user;
}

async function logout(): Promise<void> {
    try {
        await apiRequest('/auth/logout', { method: 'POST' });
    } finally {
        await removeToken();
        state.token = null;
        state.user = null;
    }
}

export function useAuth() {
    return {
        state,
        isAuthenticated: computed(() => Boolean(state.token)),
        can: (permission: string) => state.user?.permissions.includes(permission) ?? false,
        restore,
        login,
        logout,
    };
}
