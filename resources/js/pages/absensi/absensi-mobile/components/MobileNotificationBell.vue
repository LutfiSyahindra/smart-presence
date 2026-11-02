<script setup lang="ts">
import { useMobileNotifications } from '@/composables/useMobileNotifications';
import { usePermission } from '@/composables/usePermission';
import { Link } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';

defineProps<{ navigation?: boolean }>();

const { feed } = useMobileNotifications();
const { can } = usePermission();
</script>

<template>
    <Link
        v-if="can('mobile.notifications')"
        class="mobile-bell"
        :class="{ 'mobile-bell-navigation': navigation }"
        href="/absensi/mobile/notifikasi"
        :aria-label="`Buka Inbox, ${feed.unread_count} notifikasi belum dibaca`"
    >
        <span class="mobile-bell-icon">
            <Bell /><span v-if="feed.unread_count" class="mobile-bell-count">{{ feed.unread_count > 99 ? '99+' : feed.unread_count }}</span>
        </span>
        <small v-if="navigation">Notifikasi</small>
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
.mobile-bell-icon {
    display: grid;
    place-items: center;
}
.mobile-bell-count {
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
.mobile-bell-navigation {
    min-width: 0;
    width: auto;
    min-height: 48px;
    height: auto;
    padding: 4px 2px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-direction: column;
    gap: 3px;
    border: 0;
    border-radius: 12px;
    color: #93a09f;
    background: transparent;
}
.mobile-bell-navigation .mobile-bell-icon {
    width: 30px;
    height: 26px;
    position: relative;
}
.mobile-bell-navigation svg {
    width: 18px;
    height: 18px;
    stroke-width: 1.9;
}
.mobile-bell-navigation .mobile-bell-count {
    top: -4px;
    right: -10px;
    line-height: 1.2;
}
.mobile-bell-navigation small {
    font-size: 11px;
    font-weight: 720;
}
</style>
