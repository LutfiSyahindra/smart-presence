import type { SharedData } from '@/types';
import type { AdminNotificationCounts } from '@/types/notifications';
import { usePage } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';
import { inject, provide, readonly, ref, watch } from 'vue';

const createCounts = () => {
    const counts = ref<AdminNotificationCounts>({ unread_count: 0, pending_approval_count: 0 });
    const updateCounts = (value: AdminNotificationCounts | null) => {
        counts.value = {
            unread_count: value?.unread_count ?? 0,
            pending_approval_count: value?.pending_approval_count ?? 0,
        };
    };

    return { counts: readonly(counts), updateCounts };
};

const countsKey: InjectionKey<ReturnType<typeof createCounts>> = Symbol('admin-notification-counts');

export const provideAdminNotificationCounts = () => {
    const page = usePage<SharedData>();
    const state = createCounts();
    watch(
        () => [page.props.auth.user?.id, page.props.adminNotificationCounts] as const,
        ([, counts]) => state.updateCounts(counts),
        { immediate: true },
    );
    provide(countsKey, state);
};

export const useAdminNotificationCounts = () => {
    const state = inject(countsKey);
    if (!state) throw new Error('Admin notification counts must be provided by the app layout.');
    return state;
};
