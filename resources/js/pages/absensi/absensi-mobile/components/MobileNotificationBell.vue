<script setup lang="ts">
import { useMobileNotifications } from '@/composables/useMobileNotifications';
import { Link } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';

const { feed } = useMobileNotifications();
</script>

<template>
    <Link class="mobile-bell" href="/absensi/mobile/notifikasi" :aria-label="`Buka Inbox, ${feed.unread_count} notifikasi belum dibaca`">
        <Bell /><span v-if="feed.unread_count">{{ feed.unread_count > 99 ? '99+' : feed.unread_count }}</span>
    </Link>
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
    text-decoration: none;
}
.mobile-bell:focus-visible {
    outline: 3px solid #8fc4b3;
    outline-offset: 3px;
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
</style>
