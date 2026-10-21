<script setup lang="ts">
import { useMobileNotifications } from '@/composables/useMobileNotifications';
import { Link } from '@inertiajs/vue3';
import { Bell, BellRing, X } from 'lucide-vue-next';
import { ref } from 'vue';

const { feed, feedback, pushActive, busy, available, supported, enablePush, refresh } = useMobileNotifications();
const open = ref(false);
</script>

<template>
    <div class="mobile-notifications">
        <button
            class="mobile-bell"
            type="button"
            :aria-label="`Notifikasi, ${feed.unread_count} belum dibaca`"
            :aria-expanded="open"
            @click="
                open = !open;
                refresh();
            "
        >
            <Bell /><span v-if="feed.unread_count">{{ feed.unread_count > 99 ? '99+' : feed.unread_count }}</span>
        </button>
        <Teleport to="body">
            <div v-if="open" class="mobile-notification-backdrop" @click.self="open = false" @keydown.esc="open = false">
                <section class="mobile-notification-panel" role="dialog" aria-modal="true" aria-label="Notifikasi">
                    <header>
                        <h2>Notifikasi</h2>
                        <button type="button" aria-label="Tutup notifikasi" autofocus @click="open = false"><X /></button>
                    </header>
                    <div class="push-settings">
                        <button v-if="available" type="button" :disabled="busy || pushActive" @click="enablePush">
                            <BellRing />{{ pushActive ? 'Notifikasi aktif' : busy ? 'Mengaktifkan...' : 'Aktifkan notifikasi' }}
                        </button>
                        <p v-else>
                            {{
                                supported
                                    ? 'Push belum tersedia. Anda tetap dapat membaca notifikasi di sini.'
                                    : 'Untuk push di iPhone, tambahkan aplikasi ke Layar Utama dan buka dari sana.'
                            }}
                        </p>
                        <p v-if="feedback" role="status">{{ feedback }}</p>
                    </div>
                    <div class="mobile-notice-list">
                        <Link v-for="item in feed.items" :key="item.id" :href="item.url" :class="{ unread: !item.read_at }" @click="open = false">
                            <strong>{{ item.title }}</strong
                            ><span>{{ item.message }}</span>
                        </Link>
                        <p v-if="!feed.items.length" class="empty">Belum ada notifikasi pengajuan.</p>
                    </div>
                    <Link class="all-notices" href="/absensi/mobile/notifikasi" @click="open = false">Lihat semua notifikasi</Link>
                </section>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.mobile-bell {
    position: relative;
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border: 1px solid #dcebe6;
    border-radius: 14px;
    background: #fff;
    color: #177b6b;
}
.mobile-bell svg {
    width: 20px;
    height: 20px;
}
.mobile-bell span {
    position: absolute;
    top: -5px;
    right: -5px;
    padding: 2px 5px;
    border-radius: 12px;
    background: #cf5149;
    color: white;
    font-size: 10px;
    font-weight: 700;
}
.mobile-notification-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1300;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 72px 16px 24px;
    background: #123a304d;
}
.mobile-notification-panel {
    width: min(100%, 440px);
    max-height: calc(100dvh - 100px);
    overflow-y: auto;
    border-radius: 22px;
    background: white;
    color: #243e35;
    box-shadow: 0 20px 60px #11382a33;
    font-family: 'Plus Jakarta Sans', sans-serif;
}
.mobile-notification-panel header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
}
h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
}
header button {
    border: 0;
    background: transparent;
    color: #647a71;
}
header button svg {
    width: 20px;
    height: 20px;
}
.push-settings {
    padding: 14px 20px;
    background: #eef7f2;
    font-size: 12px;
    line-height: 1.7;
}
.push-settings button {
    display: flex;
    align-items: center;
    gap: 9px;
    border: 0;
    background: transparent;
    color: #177b6b;
    font-weight: 700;
}
.push-settings button:disabled {
    opacity: 0.65;
}
.push-settings svg {
    width: 18px;
    height: 18px;
}
.push-settings p {
    margin: 0;
}
.mobile-notice-list a {
    display: grid;
    gap: 6px;
    padding: 17px 20px;
    border-bottom: 1px solid #edf2ef;
    color: inherit;
    text-decoration: none;
}
.mobile-notice-list a.unread {
    background: #f4faf7;
    border-left: 3px solid #21947b;
}
.mobile-notice-list strong {
    font-size: 12px;
}
.mobile-notice-list span {
    font-size: 11px;
    line-height: 1.7;
    color: #677e73;
}
.empty {
    padding: 30px 20px;
    text-align: center;
    color: #74857c;
    font-size: 12px;
}
.all-notices {
    display: block;
    padding: 18px;
    text-align: center;
    color: #167f6a;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
</style>
