<script setup lang="ts">
import { mobileCsrfHeaders, useMobileNotifications } from '@/composables/useMobileNotifications';
import { usePermission } from '@/composables/usePermission';
import type { AdminNotification } from '@/types/notifications';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    ArrowRightLeft,
    Bell,
    BellRing,
    CalendarDays,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleX,
    ClipboardList,
    FileInput,
    FolderInput,
    HeartPulse,
    Inbox,
    ListChecks,
    MapPin,
    ReceiptText,
    ShieldCheck,
    Target,
    Timer,
    UserRound,
    UserRoundPlus,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import MobileBackLink from './components/MobileBackLink.vue';

type Paginated<T> = { data: T[]; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
const props = defineProps<{
    notifications: Paginated<AdminNotification>;
    activeTab: 'notifications' | 'approvals';
    unreadCount: number;
    pendingApprovalCount: number;
    cutiPendingApprovalCount?: number;
    lemburPendingApprovalCount?: number;
    today: string;
}>();
const { feedback, pushActive, busy, available, supported, enablePush, refresh } = useMobileNotifications();
const saving = ref(false);
const base = '/absensi/mobile/notifikasi';
const compactCount = (count: number) => (count > 99 ? '99+' : count);
const { can } = usePermission();
const categories = computed(() => [
    { name: 'Reimbursement', icon: ReceiptText, tone: 'teal' },
    {
        name: 'Cuti',
        icon: CalendarDays,
        tone: 'green',
        active: can('mobile.cuti.view'),
        href: `${base}/cuti`,
        count: props.cutiPendingApprovalCount ?? props.pendingApprovalCount,
    },
    { name: 'Presensi', icon: MapPin, tone: 'amber' },
    { name: 'Lembur', icon: Timer, tone: 'orange', active: true, href: `${base}/lembur`, count: props.lemburPendingApprovalCount ?? 0 },
    { name: 'Perubahan shift', icon: ArrowRightLeft, tone: 'cyan' },
    { name: 'Perubahan data', icon: UserRound, tone: 'violet' },
    { name: 'Formulir', icon: FileInput, tone: 'violet' },
    { name: 'Goal', icon: Target, tone: 'rose' },
    { name: 'Timesheet', icon: ClipboardList, tone: 'blue' },
    { name: 'Task', icon: ListChecks, tone: 'green' },
    { name: 'Penambahan karyawan', icon: UserRoundPlus, tone: 'amber' },
    { name: 'Pemindahan karyawan', icon: FolderInput, tone: 'orange' },
]);
const events: Record<string, { label: string; icon: typeof Bell; tone: string }> = {
    approved: { label: 'Disetujui', icon: CircleCheck, tone: 'green' },
    rejected: { label: 'Ditolak', icon: CircleX, tone: 'rose' },
    cancelled: { label: 'Dibatalkan', icon: CircleX, tone: 'neutral' },
    submitted: { label: 'Pengajuan baru', icon: CalendarDays, tone: 'blue' },
    approval_required: { label: 'Butuh persetujuan', icon: ShieldCheck, tone: 'amber' },
};
const noticeStatus = (event: string) => events[event] ?? { label: 'Pembaruan', icon: Bell, tone: 'teal' };
const timestamp = (value: string) =>
    new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value));
const notificationGroups = computed(() => {
    const groups = new Map<string, { label: string; items: AdminNotification[] }>();
    const yesterday = new Date(`${props.today}T12:00:00+07:00`);
    yesterday.setUTCDate(yesterday.getUTCDate() - 1);
    const dayFormat = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit' });
    for (const item of props.notifications.data) {
        const created = new Date(item.created_at);
        const day = dayFormat.format(created);
        const label =
            day === props.today
                ? 'Hari ini'
                : day === dayFormat.format(yesterday)
                  ? 'Kemarin'
                  : new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'Asia/Jakarta' }).format(created);
        if (!groups.has(day)) groups.set(day, { label, items: [] });
        groups.get(day)!.items.push(item);
    }
    return [...groups.values()];
});

async function readAll() {
    if (saving.value) return;
    saving.value = true;
    try {
        const response = await fetch(`${base}/read-all`, { method: 'POST', headers: mobileCsrfHeaders() });
        if (!response.ok) throw new Error();
        await refresh();
        router.reload({ only: ['notifications', 'unreadCount'], onFinish: () => (saving.value = false) });
    } catch {
        feedback.value = 'Notifikasi belum berhasil ditandai. Coba kembali.';
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Inbox" />
    <main class="inbox-workspace">
        <div class="inbox-shell">
            <header class="inbox-header">
                <MobileBackLink href="/absensi/mobile" class="back-button" aria-label="Kembali"><ArrowLeft /></MobileBackLink>
                <div>
                    <h1>Inbox</h1>
                    <p>Notifikasi &amp; persetujuan</p>
                </div>
                <span class="header-mark" aria-hidden="true"><Inbox /></span>
            </header>

            <section class="inbox-hero" aria-labelledby="inbox-title">
                <div class="hero-orbit" aria-hidden="true"><Inbox /></div>
                <span class="eyebrow">RUANG AKTIVITAS ANDA</span>
                <h2 id="inbox-title">Kabar Anda<span>.</span></h2>
                <p>Setiap kabar, dalam satu tempat.</p>
                <div class="hero-summary">
                    <span
                        ><i :class="{ 'has-unread': unreadCount }"></i>{{ unreadCount ? `${unreadCount} belum dibaca` : 'Semua sudah dibaca' }}</span
                    >
                    <span><ShieldCheck />{{ pendingApprovalCount }} perlu ditinjau</span>
                </div>
            </section>

            <nav class="inbox-tabs" aria-label="Kategori Inbox">
                <Link
                    :href="base"
                    :class="{ active: activeTab === 'notifications' }"
                    :aria-current="activeTab === 'notifications' ? 'page' : undefined"
                    preserve-scroll
                >
                    <Bell /><span>Notifikasi</span><span v-if="unreadCount" class="tab-count">{{ compactCount(unreadCount) }}</span>
                </Link>
                <Link
                    :href="`${base}?tab=approvals`"
                    :class="{ active: activeTab === 'approvals' }"
                    :aria-current="activeTab === 'approvals' ? 'page' : undefined"
                    preserve-scroll
                >
                    <ShieldCheck /><span>Butuh persetujuan</span
                    ><span v-if="pendingApprovalCount" class="tab-count">{{ compactCount(pendingApprovalCount) }}</span>
                </Link>
            </nav>

            <div class="inbox-content">
                <p v-if="feedback" class="feedback" role="status">{{ feedback }}</p>

                <template v-if="activeTab === 'notifications'">
                    <div class="section-heading">
                        <div>
                            <span class="section-eyebrow">UNTUK ANDA</span>
                            <h2>Kabar terbaru</h2>
                        </div>
                        <button v-if="unreadCount" type="button" class="read-all" :disabled="saving" @click="readAll">
                            <CheckCheck /><span>{{ saving ? 'Menandai...' : 'Baca semua' }}</span>
                        </button>
                    </div>

                    <section v-for="group in notificationGroups" :key="group.label" class="notification-group" :aria-label="group.label">
                        <h3 class="date-heading">{{ group.label }}<span></span></h3>
                        <div class="notice-list">
                            <Link v-for="item in group.items" :key="item.id" :href="item.url" class="notice-card" :class="{ unread: !item.read_at }">
                                <span class="item-icon" :class="noticeStatus(item.event).tone"
                                    ><component :is="noticeStatus(item.event).icon"
                                /></span>
                                <div class="notice-copy">
                                    <div class="notice-topline">
                                        <span class="notice-category">PENGAJUAN CUTI</span
                                        ><span v-if="!item.read_at" class="unread-dot" aria-label="Belum dibaca"></span>
                                    </div>
                                    <h3>{{ item.title }}</h3>
                                    <p>{{ item.message }}</p>
                                    <div class="notice-meta">
                                        <span class="status-pill" :class="noticeStatus(item.event).tone">{{ noticeStatus(item.event).label }}</span
                                        ><time :datetime="item.created_at">{{ timestamp(item.created_at) }}</time>
                                    </div>
                                </div>
                                <ChevronRight class="item-chevron" />
                            </Link>
                        </div>
                    </section>

                    <section v-if="!notifications.data.length" class="empty-state">
                        <span class="empty-icon"><Inbox /></span><span class="section-eyebrow">RUANG UNTUK KABAR BAIK</span>
                        <h2>Belum ada notifikasi</h2>
                        <p>Pembaruan pengajuan Anda akan hadir di sini.<br />Kami akan mengabari saat ada kabar terbaru.</p>
                    </section>

                    <section class="push-card" :class="{ 'push-enabled': pushActive }">
                        <span class="push-icon"><BellRing /></span>
                        <div>
                            <h2>{{ pushActive ? 'Anda selalu terhubung' : 'Jangan lewatkan kabar' }}</h2>
                            <p>
                                {{
                                    pushActive
                                        ? 'Notifikasi aktif. Pembaruan pengajuan akan langsung sampai kepada Anda.'
                                        : 'Dapatkan pembaruan pengajuan langsung di perangkat Anda.'
                                }}
                            </p>
                            <button v-if="available && !pushActive" type="button" :disabled="busy" @click="enablePush">
                                {{ busy ? 'Mengaktifkan...' : 'Aktifkan notifikasi' }}<ArrowRight />
                            </button>
                            <p v-else-if="!pushActive" class="push-help">
                                {{
                                    supported
                                        ? 'Notifikasi perangkat belum tersedia. Semua kabar tetap tersimpan di Inbox.'
                                        : 'Di iPhone, tambahkan aplikasi ke Layar Utama, lalu buka untuk mengaktifkan notifikasi.'
                                }}
                            </p>
                        </div>
                        <CircleCheck v-if="pushActive" class="push-check" />
                    </section>
                </template>

                <template v-else>
                    <div class="section-heading">
                        <div>
                            <span class="section-eyebrow">PERLU PERHATIAN ANDA</span>
                            <h2>Persetujuan</h2>
                        </div>
                        <span class="section-tag">{{ categories.length }} kategori</span>
                    </div>
                    <p class="section-description">Tinjau pengajuan dan berikan keputusan Anda.</p>
                    <div class="category-list">
                        <component
                            :is="category.active ? Link : 'button'"
                            v-for="category in categories"
                            :key="category.name"
                            :href="category.active ? category.href : undefined"
                            :type="category.active ? undefined : 'button'"
                            :disabled="!category.active"
                            class="category-row"
                            :class="{ 'category-active': category.active }"
                        >
                            <span class="item-icon" :class="category.tone"><component :is="category.icon" /></span>
                            <span class="category-copy"
                                ><strong>{{ category.name }}</strong
                                ><small v-if="category.active">{{
                                    category.count
                                        ? `${category.count} pengajuan menunggu Anda`
                                        : `Tinjau pengajuan ${category.name.toLowerCase()} karyawan`
                                }}</small></span
                            >
                            <template v-if="category.active"
                                ><span v-if="category.count" class="approval-count">{{ compactCount(category.count) }}</span
                                ><span v-else class="active-label">Aktif</span><ChevronRight class="item-chevron"
                            /></template>
                            <span v-else class="soon-label">Segera hadir</span>
                        </component>
                    </div>
                    <p class="category-note"><ShieldCheck />Persetujuan tersedia sesuai penugasan Anda.</p>
                </template>

                <nav v-if="activeTab === 'notifications' && notifications.last_page > 1" class="pagination" aria-label="Halaman Inbox">
                    <Link v-if="notifications.prev_page_url" :href="notifications.prev_page_url" preserve-scroll><ChevronLeft />Sebelumnya</Link
                    ><span v-else></span>
                    <span>{{ notifications.current_page }} / {{ notifications.last_page }}</span>
                    <Link v-if="notifications.next_page_url" :href="notifications.next_page_url" preserve-scroll>Berikutnya<ChevronRight /></Link
                    ><span v-else></span>
                </nav>

                <footer class="inbox-footer"><HeartPulse /><span>Selalu terhubung. Bekerja lebih tenang.</span></footer>
            </div>
        </div>
    </main>
</template>

<style scoped>
.inbox-workspace {
    --ink: #233e35;
    --green: #18775e;
    min-height: 100dvh;
    background: #eaf0eb;
    color: var(--ink);
    font-family: 'Plus Jakarta Sans', sans-serif;
    -webkit-tap-highlight-color: transparent;
}
.inbox-shell {
    max-width: 500px;
    min-height: 100dvh;
    margin: auto;
    background: #f6f8f4;
}
.inbox-header {
    position: sticky;
    top: 0;
    z-index: 30;
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 44px;
    align-items: center;
    gap: 12px;
    padding: calc(16px + env(safe-area-inset-top)) 20px 16px;
    border-bottom: 1px solid #e1e8df;
    background: #f6f8f4f5;
    backdrop-filter: blur(16px);
}
.inbox-header > div {
    text-align: center;
}
.inbox-header h1 {
    margin: 0;
    color: var(--ink);
    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.7px;
}
.inbox-header p {
    margin: 4px 0 0;
    color: #77876d;
    font-size: 10px;
}
.header-mark {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border: 1px solid #dfe7d7;
    border-radius: 14px;
    background: #edf2e7;
    color: #6d8659;
}
.header-mark svg {
    width: 19px;
    height: 19px;
}
.back-button {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border: 1px solid #e0e7df;
    border-radius: 14px;
    background: #fff;
    color: var(--ink);
}
.back-button svg {
    width: 18px;
    height: 18px;
}
.inbox-hero {
    position: relative;
    overflow: hidden;
    margin: 18px 18px 0;
    padding: 27px 25px 23px;
    border: 1px solid #235a47;
    border-radius: 26px;
    background: linear-gradient(120deg, #163c30, #235c46);
    box-shadow: 0 12px 26px #234d3617;
    color: #fff;
}
.inbox-hero::after {
    position: absolute;
    right: -52px;
    top: -91px;
    width: 250px;
    height: 250px;
    border: 1px solid #d2e5bf16;
    border-radius: 50%;
    content: '';
    pointer-events: none;
}
.eyebrow {
    position: relative;
    z-index: 1;
    color: #bdcfae;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 2px;
}
.inbox-hero h2 {
    position: relative;
    z-index: 1;
    margin: 10px 0 8px;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -1.2px;
    line-height: 1.15;
}
.inbox-hero h2 > span {
    color: #c4ce94;
}
.inbox-hero > p {
    position: relative;
    z-index: 1;
    margin: 0;
    color: #d0dfcf;
    font-size: 12px;
    line-height: 1.7;
}
.hero-orbit {
    position: absolute;
    right: 27px;
    top: 32px;
    display: grid;
    place-items: center;
    width: 78px;
    height: 78px;
    transform: rotate(-12deg);
    border: 1px solid #d1dfc52b;
    border-radius: 25px;
    background: linear-gradient(135deg, #dbe9cf16, #dbe9cf03);
    box-shadow: inset 0 1px 0 #ffffff0d;
}
.hero-orbit svg {
    width: 32px;
    height: 32px;
    color: #c8d8b5;
    stroke-width: 1.3;
}
.hero-summary {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 11px 16px;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid #e2ebd521;
    font-size: 11px;
}
.hero-summary > span {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #d2e0cb;
}
.hero-summary > span + span {
    color: #d5d9b8;
}
.hero-summary i {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #95ad94;
}
.hero-summary i.has-unread {
    background: #cbd99d;
    box-shadow: 0 0 0 3px #d5e5a510;
}
.hero-summary svg {
    width: 13px;
    height: 13px;
}
.inbox-tabs {
    display: flex;
    gap: 5px;
    margin: 22px 18px 0;
    padding: 5px;
    border: 1px solid #e1e7dd;
    border-radius: 17px;
    background: #edf1e9;
}
.inbox-tabs > a {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    flex: 1;
    min-height: 44px;
    padding: 9px 5px;
    border-radius: 12px;
    color: #74826f;
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    transition:
        background 0.2s,
        color 0.2s;
}
.inbox-tabs > a:last-child {
    flex: 1.4;
}
.inbox-tabs > a.active {
    background: #fff;
    color: #235c43;
    box-shadow: 0 2px 6px #3047240b;
    font-weight: 800;
}
.inbox-tabs svg {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
}
.tab-count {
    display: grid;
    place-items: center;
    min-width: 19px;
    height: 19px;
    padding: 0 4px;
    border-radius: 6px;
    background: #e6eddf;
    color: #517147;
    font-size: 9px;
    font-weight: 800;
}
.active .tab-count {
    background: #285a43;
    color: #fff;
}
.inbox-content {
    padding: 26px 18px calc(24px + env(safe-area-inset-bottom));
}
.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 0 3px 14px;
}
.section-eyebrow {
    display: block;
    margin-bottom: 6px;
    color: #929a80;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1.8px;
}
.section-heading h2 {
    margin: 0;
    color: var(--ink);
    font-size: 19px;
    font-weight: 800;
    letter-spacing: -0.6px;
}
.read-all {
    display: flex;
    align-items: center;
    gap: 5px;
    min-height: 44px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--green);
    font-size: 11px;
    font-weight: 700;
}
.read-all svg {
    width: 15px;
    height: 15px;
}
button:disabled {
    cursor: default;
}
.read-all:disabled {
    opacity: 0.6;
}
.date-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 22px 4px 11px;
    color: #88947d;
    font-size: 10px;
    font-weight: 600;
}
.date-heading > span {
    flex: 1;
    height: 1px;
    background: #e6eadf;
}
.notice-list {
    overflow: hidden;
    border: 1px solid #e3e9df;
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 3px 12px #29432603;
}
.notice-card {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 19px 15px;
    color: inherit;
    text-decoration: none;
}
.notice-card + .notice-card {
    border-top: 1px solid #edf0e9;
}
.notice-card.unread {
    background: #fafff8;
}
.notice-card.unread::before {
    position: absolute;
    top: 22px;
    bottom: 22px;
    left: 0;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: #8fa568;
    content: '';
}
.item-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    border: 1px solid #00000003;
    border-radius: 14px;
}
.item-icon svg {
    width: 20px;
    height: 20px;
    stroke-width: 1.7;
}
.green {
    background: #edf4e8;
    color: #5b8052;
}
.teal {
    background: #eaf5ee;
    color: #529e8b;
}
.amber {
    background: #fcf3df;
    color: #c89c3e;
}
.orange {
    background: #fcf0e4;
    color: #cc945e;
}
.cyan {
    background: #e6f4f4;
    color: #62a5b1;
}
.violet {
    background: #f0ecf8;
    color: #9b84bb;
}
.rose {
    background: #fbeeee;
    color: #bc7779;
}
.blue {
    background: #edf1fb;
    color: #7e91bf;
}
.neutral {
    background: #eff1ed;
    color: #8c9388;
}
.notice-copy {
    flex: 1;
    min-width: 0;
}
.notice-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 12px;
    margin-bottom: 5px;
}
.notice-category {
    color: #8f9c83;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 1.2px;
}
.unread-dot {
    flex-shrink: 0;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #86a562;
}
.notice-copy h3 {
    margin: 0;
    color: var(--ink);
    font-size: 14px;
    font-weight: 800;
    line-height: 1.7;
    overflow-wrap: anywhere;
}
.notice-copy > p {
    margin: 5px 0 11px;
    color: #7a8777;
    font-size: 12px;
    line-height: 1.85;
    overflow-wrap: anywhere;
}
.notice-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px 9px;
}
.status-pill {
    padding: 4px 7px;
    border-radius: 5px;
    font-size: 9px;
    font-weight: 700;
}
.notice-meta time {
    color: #96a08e;
    font-size: 9px;
}
.item-chevron {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
    color: #a3ae9c;
}
.notice-card > .item-chevron {
    align-self: center;
    margin-left: -3px;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 42px 14px;
    text-align: center;
}
.empty-icon {
    display: grid;
    place-items: center;
    width: 76px;
    height: 76px;
    margin-bottom: 24px;
    border: 1px solid #e0e8d8;
    border-radius: 25px;
    background: linear-gradient(140deg, #eff3e8, #e5ecdb);
    box-shadow: 0 8px 24px #4d633c08;
    color: #789363;
    transform: rotate(-5deg);
}
.empty-icon svg {
    width: 30px;
    height: 30px;
    stroke-width: 1.4;
}
.empty-state h2 {
    margin: 5px 0 10px;
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.4px;
}
.empty-state > p {
    max-width: 290px;
    margin: 0;
    color: #86917d;
    font-size: 11px;
    line-height: 1.9;
}
.push-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-top: 26px;
    padding: 18px 16px;
    border: 1px solid #e2e7d7;
    border-radius: 18px;
    background: #eef2e6;
}
.push-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border: 1px solid #dee5ce;
    border-radius: 11px;
    background: #f7f9ef;
    color: #83945c;
}
.push-icon > svg {
    width: 16px;
    height: 16px;
}
.push-card > div {
    min-width: 0;
}
.push-card h2 {
    margin: 1px 0 6px;
    color: #596e46;
    font-size: 12px;
    font-weight: 800;
}
.push-card p {
    margin: 0;
    color: #8c967d;
    font-size: 11px;
    line-height: 1.9;
}
.push-card button {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 44px;
    margin-top: 3px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #567443;
    font-size: 11px;
    font-weight: 800;
}
.push-card button > svg {
    width: 13px;
    height: 13px;
}
.push-card .push-help {
    margin-top: 8px;
    font-size: 10px;
}
.push-check {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    color: #7e9664;
}
.section-tag {
    flex-shrink: 0;
    padding: 6px 9px;
    border: 1px solid #e4e8db;
    border-radius: 7px;
    background: #eff2e8;
    color: #8b957a;
    font-size: 9px;
    font-weight: 600;
}
.section-description {
    margin: -3px 3px 20px;
    color: #87917e;
    font-size: 12px;
    line-height: 1.9;
}
.category-list {
    overflow: hidden;
    border: 1px solid #e2e7dc;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 4px 18px #29432603;
}
.category-row {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 74px;
    padding: 14px 16px;
    border: 0;
    background: #fff;
    color: var(--ink);
    text-align: left;
    text-decoration: none;
}
.category-row + .category-row {
    border-top: 1px solid #f0f2ec;
}
.category-row.category-active {
    position: relative;
    min-height: 88px;
    background: linear-gradient(100deg, #f0f6e9, #fafff7);
}
.category-active::before {
    position: absolute;
    top: 23px;
    bottom: 23px;
    left: 0;
    width: 3px;
    border-radius: 0 4px 4px 0;
    background: #8da365;
    content: '';
}
.category-active .item-icon {
    border-color: #e0e8d4;
    background: #e3edd5;
    color: #618044;
}
.category-copy {
    flex: 1;
    min-width: 0;
}
.category-copy > strong {
    display: block;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.6;
}
.category-copy > small {
    display: block;
    margin-top: 4px;
    color: #89997b;
    font-size: 10px;
    line-height: 1.6;
}
.category-active .category-copy > strong {
    color: #375a31;
    font-weight: 800;
}
.category-row:disabled .category-copy {
    color: #8e9888;
}
.category-row:disabled .item-icon {
    opacity: 0.8;
}
.soon-label {
    flex-shrink: 0;
    padding: 4px 6px;
    border: 1px solid #eef0e9;
    border-radius: 5px;
    color: #a4ad9c;
    font-size: 9px;
    font-weight: 500;
}
.approval-count {
    display: grid;
    place-items: center;
    min-width: 24px;
    height: 24px;
    padding: 0 5px;
    border-radius: 8px;
    background: #e0ebd3;
    color: #66834d;
    font-size: 10px;
    font-weight: 800;
}
.active-label {
    padding: 4px 7px;
    border-radius: 5px;
    background: #e5eddc;
    color: #6b8557;
    font-size: 8px;
    font-weight: 700;
}
.category-note {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin: 18px 0 0;
    color: #9aa38f;
    font-size: 10px;
}
.category-note svg {
    width: 12px;
    height: 12px;
}
.pagination {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 15px;
    margin-top: 24px;
    color: #8b967d;
    font-size: 10px;
}
.pagination a {
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 44px;
    color: var(--green);
    text-decoration: none;
}
.pagination > :last-child {
    justify-self: end;
}
.pagination svg {
    width: 15px;
    height: 15px;
}
.inbox-footer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 30px;
    color: #a4ad99;
    font-size: 8px;
}
.inbox-footer > svg {
    width: 13px;
    height: 13px;
    color: #97a687;
}
.feedback {
    margin: 0 0 20px;
    padding: 13px 16px;
    border: 1px solid #dce6d2;
    border-radius: 12px;
    background: #edf4e7;
    color: #617b50;
    font-size: 11px;
    line-height: 1.8;
}
a:focus-visible,
button:focus-visible {
    outline: 3px solid #9eb888;
    outline-offset: 3px;
}
@media (hover: hover) {
    .notice-card:hover {
        background: #f8fcf5;
    }
    .category-active:hover {
        filter: brightness(0.98);
    }
}
@media (max-width: 359px) {
    .inbox-header {
        padding-right: 16px;
        padding-left: 16px;
    }
    .inbox-hero,
    .inbox-tabs {
        margin-right: 12px;
        margin-left: 12px;
    }
    .inbox-hero {
        padding-right: 20px;
        padding-left: 20px;
    }
    .hero-orbit {
        right: 18px;
        width: 65px;
        height: 65px;
    }
    .inbox-content {
        padding-right: 12px;
        padding-left: 12px;
    }
    .inbox-tabs > a {
        gap: 4px;
        font-size: 10px;
    }
    .inbox-tabs > a > svg {
        display: none;
    }
    .category-row {
        gap: 10px;
        padding-right: 12px;
        padding-left: 12px;
    }
    .category-copy > strong {
        font-size: 13px;
    }
    .notice-card {
        gap: 9px;
        padding-right: 12px;
        padding-left: 12px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .inbox-tabs > a {
        transition: none;
    }
}
</style>
