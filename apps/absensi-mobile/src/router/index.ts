import { createRouter, createWebHashHistory } from 'vue-router';
import { useAuth } from '@/composables/useAuth';

const router = createRouter({
    history: createWebHashHistory(),
    routes: [
        { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
        { path: '/', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: '/presensi', name: 'attendance', component: () => import('@/views/AttendanceView.vue') },
        { path: '/presensi/:action(clock-in|clock-out)', name: 'attendance-action', component: () => import('@/views/AttendanceActionView.vue') },
        { path: '/riwayat', name: 'history', component: () => import('@/views/HistoryView.vue') },
        { path: '/akun', name: 'account', component: () => import('@/views/AccountView.vue') },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

router.beforeEach(async (to) => {
    const auth = useAuth();
    await auth.restore();
    if (!auth.isAuthenticated.value && !to.meta.guest) return { name: 'login' };
    if (auth.isAuthenticated.value && to.meta.guest) return { name: 'home' };
});

export default router;
