<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { AdminNotification } from '@/types/notifications';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, Inbox } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import '../approval-workflow.css';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';

defineProps<{ notifications: Paginated<AdminNotification>; unreadCount: number }>();
const baseUrl = '/absensi/admin-web/notifikasi';
const markingRead = ref(false);
const date = (value: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value));
let polling: ReturnType<typeof router.poll> | undefined;
onMounted(() => {
    polling = router.poll(20000, { only: ['notifications', 'unreadCount'] });
});
onBeforeUnmount(() => polling?.stop());
const markAllRead = () => {
    if (markingRead.value) return;
    markingRead.value = true;
    router.post(`${baseUrl}/read-all`, {}, { preserveScroll: true, onFinish: () => (markingRead.value = false) });
};
</script>

<template>
    <Head title="Semua Notifikasi" />
    <AppLayout :breadcrumbs="[{ title: 'Notifikasi', href: `${baseUrl}/semua` }]">
        <div class="al-page">
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><Bell /> Notifikasi pengajuan</span>
                    <h1>Semua Notifikasi</h1>
                    <p>{{ unreadCount }} notifikasi belum dibaca. Pantau pengajuan baru dan setiap pembaruan persetujuan.</p>
                </div>
                <button v-if="unreadCount" type="button" class="btn btn-outline-primary al-button" :disabled="markingRead" @click="markAllRead">
                    <CheckCheck /> Tandai semua dibaca
                </button>
            </section>
            <section class="al-panel">
                <nav class="al-tabs" aria-label="Kategori notifikasi">
                    <Link :href="`${baseUrl}/semua`" class="inbox-tab active" aria-current="page"><Bell /> Semua notifikasi</Link>
                    <Link :href="`${baseUrl}/butuh-approval`" class="inbox-tab"><Inbox /> Butuh approval</Link>
                    <Link :href="`${baseUrl}/disetujui`" class="inbox-tab">Pengajuan disetujui</Link>
                </nav>
                <div v-if="notifications.data.length" class="inbox-list">
                    <Link v-for="item in notifications.data" :key="item.id" :href="item.url" class="inbox-item" :class="{ unread: !item.read_at }">
                        <span class="inbox-icon"><Bell /></span>
                        <div>
                            <strong>{{ item.title }}</strong>
                            <p>{{ item.message }}</p>
                            <small>{{ date(item.created_at) }}</small>
                        </div>
                        <span v-if="!item.read_at" class="inbox-unread">Baru</span>
                    </Link>
                </div>
                <div v-else class="al-empty">
                    <Bell />
                    <h3>Belum ada notifikasi</h3>
                    <p>Pengajuan baru dan pembaruan persetujuan akan tampil di sini.</p>
                </div>
                <Pagination :links="notifications.links" :from="notifications.from" :to="notifications.to" :total="notifications.total" />
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.inbox-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-radius: 9px;
    color: #81899c;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
}
.inbox-tab.active {
    background: #f1effc;
    color: #6f60c6;
}
.inbox-tab svg {
    width: 16px;
    height: 16px;
}
.inbox-item {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    padding: 22px;
    border-bottom: 1px solid #edf0f5;
    color: inherit;
    text-decoration: none;
}
.inbox-item:hover,
.inbox-item.unread {
    background: #faf9ff;
}
.inbox-icon {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    background: #f1effc;
    color: #6f60c6;
    border-radius: 12px;
}
.inbox-icon svg {
    width: 18px;
    height: 18px;
}
.inbox-item div {
    flex: 1;
}
.inbox-item strong {
    font-size: 13px;
}
.inbox-item p {
    margin: 6px 0;
    color: #70798c;
    font-size: 12px;
    line-height: 1.7;
}
.inbox-item small {
    color: #81899c;
    font-size: 11px;
}
.inbox-unread {
    padding: 3px 8px;
    border-radius: 8px;
    background: #ede9fb;
    color: #6f60c6;
    font-size: 10px;
}
</style>
