<script setup lang="ts">
import { mobileCsrfHeaders, useMobileNotifications } from '@/composables/useMobileNotifications';
import type { AdminNotification } from '@/types/notifications';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Bell, BellRing, CheckCheck, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    notifications: { data: AdminNotification[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
}>();
const { feed, feedback, pushActive, busy, available, supported, enablePush, refresh } = useMobileNotifications();
const saving = ref(false);
const date = (value: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
async function readAll() {
    if (saving.value) return;
    saving.value = true;
    try {
        const response = await fetch('/absensi/mobile/notifikasi/read-all', { method: 'POST', headers: mobileCsrfHeaders() });
        if (!response.ok) throw new Error();
        await refresh();
        router.reload({ only: ['notifications'] });
    } catch {
        feedback.value = 'Notifikasi belum berhasil ditandai. Coba kembali.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Notifikasi" />
    <main class="notices-workspace">
        <div class="notices-shell">
            <header>
                <Link href="/absensi/mobile" aria-label="Kembali ke beranda"><ArrowLeft /></Link>
                <div>
                    <h1>Notifikasi</h1>
                    <p>Pembaruan pengajuan Anda</p>
                </div>
                <Bell />
            </header>
            <section class="push-card">
                <BellRing />
                <div>
                    <strong>Ikuti status pengajuan</strong>
                    <p>Terima pemberitahuan saat pengajuan disetujui atau diperbarui.</p>
                    <button v-if="available" type="button" :disabled="busy || pushActive" @click="enablePush">
                        {{ pushActive ? 'Notifikasi aktif' : busy ? 'Mengaktifkan...' : 'Aktifkan notifikasi' }}
                    </button>
                    <p v-else>
                        {{
                            supported
                                ? 'Push belum tersedia. Riwayat tetap dapat dibaca di sini.'
                                : 'Di iPhone, tambahkan aplikasi ke Layar Utama, lalu buka dan aktifkan notifikasi.'
                        }}
                    </p>
                    <p v-if="feedback" role="status">{{ feedback }}</p>
                </div>
            </section>
            <div class="list-heading">
                <h2>Riwayat notifikasi</h2>
                <button v-if="feed.unread_count" type="button" :disabled="saving" @click="readAll"><CheckCheck />Tandai dibaca</button>
            </div>
            <section class="notice-list">
                <Link v-for="item in notifications.data" :key="item.id" :href="item.url" :class="{ unread: !item.read_at }"
                    ><span class="notice-icon"><Bell /></span>
                    <div>
                        <strong>{{ item.title }}</strong>
                        <p>{{ item.message }}</p>
                        <time :datetime="item.created_at">{{ date(item.created_at) }}</time>
                    </div>
                    <span v-if="!item.read_at" class="unread-dot" aria-label="Belum dibaca"></span
                ></Link>
                <div v-if="!notifications.data.length" class="empty">
                    <Bell />
                    <h2>Belum ada notifikasi</h2>
                    <p>Pembaruan pengajuan Anda akan muncul di sini.</p>
                </div>
            </section>
            <nav v-if="notifications.last_page > 1" class="pagination" aria-label="Halaman notifikasi">
                <Link v-if="notifications.prev_page_url" :href="notifications.prev_page_url"><ChevronLeft />Sebelumnya</Link
                ><span>{{ notifications.current_page }} / {{ notifications.last_page }}</span
                ><Link v-if="notifications.next_page_url" :href="notifications.next_page_url">Berikutnya<ChevronRight /></Link>
            </nav>
        </div>
    </main>
</template>

<style scoped>
.notices-workspace {
    min-height: 100dvh;
    padding: 24px 16px 48px;
    background: #f2f6f3;
    color: #233e33;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.notices-shell {
    max-width: 560px;
    margin: auto;
}
header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 26px;
}
header > a {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    background: white;
    color: #267e68;
}
header > svg {
    margin-left: auto;
    color: #2a8b73;
}
h1 {
    margin: 0 0 4px;
    font-size: 22px;
    font-weight: 800;
}
header p {
    margin: 0;
    color: #74897e;
    font-size: 12px;
}
.push-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 22px;
    border: 1px solid #d7e9df;
    border-radius: 22px;
    background: #e9f4ee;
}
.push-card > svg {
    flex-shrink: 0;
    color: #27876e;
}
.push-card strong {
    font-size: 14px;
}
.push-card p {
    margin: 8px 0;
    color: #617f70;
    font-size: 12px;
    line-height: 1.8;
}
.push-card button {
    margin-top: 5px;
    padding: 10px 15px;
    border: 0;
    border-radius: 10px;
    background: #187f68;
    color: white;
    font-size: 12px;
    font-weight: 700;
}
button:disabled {
    opacity: 0.65;
}
.list-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin: 30px 0 14px;
}
h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
}
.list-heading button {
    display: flex;
    align-items: center;
    gap: 5px;
    border: 0;
    background: transparent;
    color: #24826a;
    font-size: 11px;
}
.list-heading svg {
    width: 16px;
}
.notice-list {
    overflow: hidden;
    border: 1px solid #e2ebe5;
    border-radius: 22px;
    background: white;
}
.notice-list > a {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    padding: 20px;
    border-bottom: 1px solid #edf2ee;
    color: inherit;
    text-decoration: none;
}
.notice-list > a:last-child {
    border-bottom: 0;
}
.notice-list a.unread {
    background: #f7fcf9;
}
.notice-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 12px;
    background: #eaf5ee;
    color: #308d73;
}
.notice-icon svg {
    width: 17px;
    height: 17px;
}
.notice-list strong {
    font-size: 12px;
    line-height: 1.7;
}
.notice-list p {
    margin: 7px 0;
    font-size: 11px;
    line-height: 1.8;
    color: #6b8175;
}
time {
    color: #8a9a90;
    font-size: 10px;
}
.unread-dot {
    flex-shrink: 0;
    width: 7px;
    height: 7px;
    margin: 8px 0 0 auto;
    border-radius: 50%;
    background: #2a9a77;
}
.empty {
    padding: 50px 24px;
    text-align: center;
}
.empty > svg {
    margin: 0 auto 18px;
    color: #6d9c84;
}
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 18px;
    margin-top: 22px;
    color: #7a8e80;
    font-size: 11px;
}
.pagination a {
    display: flex;
    align-items: center;
    color: #208267;
    text-decoration: none;
}
.pagination svg {
    width: 16px;
}
</style>
