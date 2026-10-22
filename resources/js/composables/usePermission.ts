import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const page = usePage<SharedData>();
    const can = (permission: string) => page.props.auth.roles.includes('super-admin') || page.props.auth.permissions.includes(permission);

    return { can };
}
