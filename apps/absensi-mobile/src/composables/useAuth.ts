import { Device } from '@capacitor/device';
import { computed, reactive } from 'vue';
import { apiRequest } from '@/lib/api';
import { getToken, removeToken, setToken } from '@/lib/token-storage';

interface AuthUser {
    id: number;
    name: string;
    email: string;
    can_access_account: boolean;
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
        restore,
        login,
        logout,
    };
}
