<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { usePermission } from '@/composables/usePermission';
import type { SharedData } from '@/types';
import type { AttendanceSubmissionStatus } from '@/types/attendance-submission';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    BadgeCheck,
    CalendarDays,
    CalendarRange,
    ChevronRight,
    CircleUserRound,
    Clock3,
    History,
    Home,
    LogIn,
    LogOut,
    MapPin,
    Megaphone,
    ShieldCheck,
    Sparkles,
    Timer,
    TimerReset,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import MobileNotificationBell from './components/MobileNotificationBell.vue';

const props = defineProps<{
    attendanceSubmissions?: AttendanceSubmissionStatus[];
    canAccessAdmin: boolean;
    canAccessAccount: boolean;
    profilePhotoUrl: string | null;
    todaySchedule: {
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
        schedule_note?: string | null;
        late_arrival_minutes?: number;
        early_departure_minutes?: number;
        location_name: string | null;
    };
    todayAttendance: {
        is_on_leave: boolean;
        clocked_in_at: string | null;
        clocked_out_at: string | null;
        is_day_off: boolean;
    };
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const now = ref(new Date());
const feedback = ref('');
let clockTimer: ReturnType<typeof setInterval> | undefined;
let feedbackTimer: ReturnType<typeof setTimeout> | undefined;

const greeting = computed(() => {
    const hour = now.value.getHours();

    if (hour < 11) return 'Selamat pagi';
    if (hour < 15) return 'Selamat siang';
    if (hour < 18) return 'Selamat sore';

    return 'Selamat malam';
});

const initials = computed(() =>
    user.value.name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase(),
);

const formattedDate = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(now.value),
);

const liveTime = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    })
        .format(now.value)
        .replace('.', ':'),
);

const scheduleAdjustments = computed(() =>
    [
        { label: 'Telat masuk', minutes: props.todaySchedule.late_arrival_minutes ?? 0, icon: LogIn },
        { label: 'Pulang cepat', minutes: props.todaySchedule.early_departure_minutes ?? 0, icon: LogOut },
    ].filter((adjustment) => adjustment.minutes > 0),
);

const quickActions = computed(() =>
    [
        {
            label: 'Riwayat',
            caption: 'Kehadiran',
            icon: History,
            color: '#087f78',
            background: '#e8f6f2',
            history: true,
            permission: 'mobile.history',
        },
        { label: 'Perubahan Shift', caption: 'Pengajuan shift', icon: ArrowRightLeft, color: '#5b54a4', background: '#efedfb', perubahanShift: true },
        { label: 'Lembur', caption: 'Jam tambahan', icon: Timer, color: '#b86a37', background: '#fff1e8', lembur: true },
        ...(props.canAccessAdmin
            ? [{ label: 'Portal admin', caption: 'Kelola data', icon: ShieldCheck, color: '#31577c', background: '#eaf1f7', admin: true }]
            : []),
        {
            label: 'Cuti',
            caption: 'Saldo cuti',
            icon: CalendarRange,
            color: '#8e7544',
            background: '#f4eddb',
            cuti: true,
            permission: 'mobile.cuti.view',
        },
    ].filter((action) => !('permission' in action) || can(action.permission as string)),
);

const showFeedback = (message: string) => {
    feedback.value = message;

    if (feedbackTimer) window.clearTimeout(feedbackTimer);
    feedbackTimer = window.setTimeout(() => (feedback.value = ''), 2800);
};

onMounted(() => {
    clockTimer = window.setInterval(() => (now.value = new Date()), 30_000);
    const flashMessage = page.props.flash.success || page.props.flash.error;
    if (flashMessage) showFeedback(flashMessage);
});

onUnmounted(() => {
    if (clockTimer) window.clearInterval(clockTimer);
    if (feedbackTimer) window.clearTimeout(feedbackTimer);
});
const { can } = usePermission();
</script>

<template>
    <Head title="Beranda Presensi" />

    <main class="mobile-workspace">
        <div class="mobile-shell">
            <header class="mobile-header">
                <div class="profile-greeting">
                    <div class="profile-avatar" aria-hidden="true">
                        <img v-if="profilePhotoUrl" :src="profilePhotoUrl" alt="" />
                        <span v-else>{{ initials }}</span>
                        <i></i>
                    </div>
                    <div class="profile-copy">
                        <p>{{ greeting }},</p>
                        <h1>{{ user.name }}</h1>
                        <span><AppLogoIcon class="size-4" /> RSARSY Presence</span>
                    </div>
                </div>

                <div class="header-actions">
                    <MobileNotificationBell />
                    <Link :href="route('logout')" method="post" as="button" aria-label="Keluar dari akun">
                        <LogOut />
                    </Link>
                </div>
            </header>

            <section class="mobile-content">
                <aside v-if="attendanceSubmissions?.length" class="submission-notices" aria-label="Status verifikasi absensi">
                    <Link
                        v-for="submission in attendanceSubmissions"
                        :key="submission.id"
                        :href="submission.status_url"
                        class="submission-notice"
                        :class="{ failed: submission.status === 'failed' }"
                    >
                        <Clock3 aria-hidden="true" />
                        <span
                            ><strong>{{ submission.action === 'clock-in' ? 'Absen masuk' : 'Absen pulang' }} · {{ submission.time }}</strong
                            >{{
                                submission.status === 'failed'
                                    ? 'Verifikasi gagal. Lihat hasil dan coba kembali.'
                                    : 'Permintaan tersimpan. Lihat status verifikasi.'
                            }}</span
                        >
                        <ChevronRight aria-hidden="true" />
                    </Link>
                </aside>
                <article class="schedule-card" aria-labelledby="today-schedule-title">
                    <span class="schedule-glow schedule-glow-one" aria-hidden="true"></span>
                    <span class="schedule-glow schedule-glow-two" aria-hidden="true"></span>

                    <div class="schedule-topline">
                        <div>
                            <span class="eyebrow"><Sparkles /> Jadwal hari ini</span>
                            <h2 id="today-schedule-title">{{ todaySchedule.shift_name || 'Shift belum ditentukan' }}</h2>
                        </div>
                        <time :datetime="now.toISOString()">{{ formattedDate }}</time>
                    </div>

                    <div class="schedule-time-row">
                        <div class="schedule-location">
                            <span><MapPin /></span>
                            <p>
                                <small>Lokasi kerja</small><strong>{{ todaySchedule.location_name || 'Belum ditentukan' }}</strong>
                            </p>
                        </div>
                        <div class="schedule-hours">
                            <small>Jam kerja</small>
                            <strong>{{ todaySchedule.start_time || '--:--' }} <i>—</i> {{ todaySchedule.end_time || '--:--' }}</strong>
                        </div>
                    </div>

                    <section v-if="scheduleAdjustments.length" class="schedule-adjustment" aria-labelledby="schedule-adjustment-title">
                        <div class="adjustment-heading">
                            <h3 id="schedule-adjustment-title"><Timer aria-hidden="true" /> Penggunaan lembur</h3>
                            <span class="adjustment-badge"><BadgeCheck aria-hidden="true" /> Disetujui</span>
                        </div>
                        <dl class="adjustment-details">
                            <div v-for="adjustment in scheduleAdjustments" :key="adjustment.label" class="adjustment-item">
                                <dt><component :is="adjustment.icon" aria-hidden="true" /> {{ adjustment.label }}</dt>
                                <dd>{{ adjustment.minutes }} <span>menit</span></dd>
                            </div>
                        </dl>
                        <p class="adjustment-caption">Jam kerja sudah disesuaikan.</p>
                    </section>
                    <p v-else-if="todaySchedule.schedule_note" class="schedule-note">{{ todaySchedule.schedule_note }}</p>

                    <div v-if="can('mobile.attendance') && (can('mobile.clock-in') || can('mobile.clock-out'))" class="attendance-panel">
                        <Link
                            v-if="
                                can('mobile.attendance') &&
                                can('mobile.clock-in') &&
                                !todayAttendance.clocked_in_at &&
                                !todayAttendance.is_day_off &&
                                !todayAttendance.is_on_leave
                            "
                            :href="route('absensi.mobile.clock-in.create')"
                            as="button"
                            class="attendance-action check-in"
                        >
                            <span><LogIn /></span>
                            <span><small>Mulai kerja</small><strong>Clock In</strong></span>
                        </Link>
                        <button v-else-if="can('mobile.clock-in')" type="button" class="attendance-action check-in completed" disabled>
                            <span><LogIn /></span>
                            <span>
                                <small>{{
                                    todayAttendance.is_on_leave || todayAttendance.is_day_off
                                        ? 'Tidak perlu presensi'
                                        : `Tercatat ${todayAttendance.clocked_in_at}`
                                }}</small>
                                <strong>{{
                                    todayAttendance.is_on_leave ? 'CUTI' : todayAttendance.is_day_off ? 'Hari Libur' : 'Sudah Clock In'
                                }}</strong>
                            </span>
                        </button>
                        <span v-if="can('mobile.clock-in') && can('mobile.clock-out')" class="attendance-divider" aria-hidden="true"></span>
                        <Link
                            v-if="
                                can('mobile.attendance') &&
                                can('mobile.clock-out') &&
                                todayAttendance.clocked_in_at &&
                                !todayAttendance.clocked_out_at &&
                                !todayAttendance.is_day_off &&
                                !todayAttendance.is_on_leave
                            "
                            :href="route('absensi.mobile.clock-out.create')"
                            as="button"
                            class="attendance-action check-out"
                        >
                            <span><LogOut /></span>
                            <span><small>Selesai kerja</small><strong>Clock Out</strong></span>
                        </Link>
                        <button v-else-if="can('mobile.clock-out')" type="button" class="attendance-action check-out completed" disabled>
                            <span><LogOut /></span>
                            <span>
                                <small>{{
                                    todayAttendance.is_on_leave || todayAttendance.is_day_off
                                        ? 'Tidak perlu presensi'
                                        : todayAttendance.clocked_out_at
                                          ? `Tercatat ${todayAttendance.clocked_out_at}`
                                          : 'Clock in terlebih dahulu'
                                }}</small>
                                <strong>{{
                                    todayAttendance.is_on_leave
                                        ? 'CUTI'
                                        : todayAttendance.is_day_off
                                          ? 'Hari Libur'
                                          : todayAttendance.clocked_out_at
                                            ? 'Sudah Clock Out'
                                            : 'Clock Out'
                                }}</strong>
                            </span>
                        </button>
                    </div>

                    <div class="attendance-status">
                        <span>
                            <i></i>
                            {{
                                todayAttendance.is_on_leave
                                    ? 'Cuti disetujui. Clock-in dan clock-out dinonaktifkan.'
                                    : todayAttendance.is_day_off
                                      ? 'Hari ini tercatat sebagai hari libur'
                                      : todayAttendance.clocked_out_at
                                        ? `Clock-out tercatat ${todayAttendance.clocked_out_at} WIB`
                                        : todayAttendance.clocked_in_at
                                          ? `Clock-in tercatat ${todayAttendance.clocked_in_at} WIB`
                                          : 'Siap untuk presensi'
                            }}
                        </span>
                        <span><Clock3 /> {{ liveTime }} WIB</span>
                    </div>
                </article>

                <section class="quick-section" aria-labelledby="quick-menu-title">
                    <div class="section-heading">
                        <div>
                            <span>Akses cepat</span>
                            <h2 id="quick-menu-title">Apa yang Anda butuhkan?</h2>
                        </div>
                        <button type="button" aria-label="Lihat semua menu" @click="showFeedback('Menu lengkap sedang disiapkan.')">
                            <span>Semua</span><ChevronRight />
                        </button>
                    </div>

                    <div class="quick-grid">
                        <template v-for="action in quickActions" :key="action.label">
                            <Link
                                v-if="can('mobile.history') && action.history"
                                :href="route('absensi.mobile.attendance.log')"
                                class="quick-action"
                                :style="{ '--action-color': action.color, '--action-background': action.background }"
                            >
                                <span class="quick-icon"><component :is="action.icon" /></span>
                                <strong>{{ action.label }}</strong>
                                <small>{{ action.caption }}</small>
                            </Link>
                            <Link
                                v-else-if="action.perubahanShift"
                                :href="route('absensi.mobile.perubahan-shift.index')"
                                class="quick-action"
                                :style="{ '--action-color': action.color, '--action-background': action.background }"
                            >
                                <span class="quick-icon"><component :is="action.icon" /></span>
                                <strong>{{ action.label }}</strong>
                                <small>{{ action.caption }}</small>
                            </Link>
                            <Link
                                v-else-if="action.lembur"
                                :href="route('absensi.mobile.lembur.index')"
                                class="quick-action"
                                :style="{ '--action-color': action.color, '--action-background': action.background }"
                            >
                                <span class="quick-icon"><component :is="action.icon" /></span>
                                <strong>{{ action.label }}</strong>
                                <small>{{ action.caption }}</small>
                            </Link>
                            <Link
                                v-else-if="can('mobile.cuti.view') && action.cuti"
                                :href="route('absensi.mobile.cuti.index')"
                                class="quick-action"
                                :style="{ '--action-color': action.color, '--action-background': action.background }"
                            >
                                <span class="quick-icon"><component :is="action.icon" /></span>
                                <strong>{{ action.label }}</strong>
                                <small>{{ action.caption }}</small>
                            </Link>
                            <Link
                                v-else-if="action.admin"
                                :href="route('portal.switch', { portal: 'admin' })"
                                method="post"
                                as="button"
                                class="quick-action"
                                :style="{ '--action-color': action.color, '--action-background': action.background }"
                            >
                                <span class="quick-icon"><component :is="action.icon" /></span>
                                <strong>{{ action.label }}</strong>
                                <small>{{ action.caption }}</small>
                            </Link>
                            <button
                                v-else
                                type="button"
                                class="quick-action"
                                :style="{ '--action-color': action.color, '--action-background': action.background }"
                                @click="showFeedback(`Fitur ${action.label} sedang disiapkan.`)"
                            >
                                <span class="quick-icon"><component :is="action.icon" /></span>
                                <strong>{{ action.label }}</strong>
                                <small>{{ action.caption }}</small>
                            </button>
                        </template>
                    </div>
                </section>

                <section class="announcement-card" aria-labelledby="announcement-title">
                    <div class="announcement-icon"><Megaphone /></div>
                    <div class="announcement-copy">
                        <span>Informasi pegawai</span>
                        <h2 id="announcement-title">Belum ada pengumuman baru</h2>
                        <p>Informasi terbaru dari rumah sakit akan tampil di bagian ini.</p>
                    </div>
                    <button type="button" aria-label="Lihat semua pengumuman" @click="showFeedback('Belum ada pengumuman untuk ditampilkan.')">
                        <ChevronRight />
                    </button>
                </section>
            </section>

            <nav
                class="bottom-navigation"
                :style="{ gridTemplateColumns: `repeat(${3 + Number(canAccessAccount) + Number(can('mobile.attendance'))}, 1fr)` }"
                aria-label="Navigasi utama"
            >
                <button type="button" class="bottom-item active" aria-current="page">
                    <span><Home /></span>
                    <small>Beranda</small>
                </button>
                <button type="button" class="bottom-item" @click="showFeedback('Halaman jadwal sedang disiapkan.')">
                    <span><CalendarDays /></span>
                    <small>Jadwal</small>
                </button>
                <Link v-if="can('mobile.attendance')" :href="route('absensi.mobile.attendance')" class="bottom-item attendance-main">
                    <span><TimerReset /></span>
                    <small>Presensi</small>
                </Link>
                <button type="button" class="bottom-item" @click="showFeedback('Halaman pengajuan sedang disiapkan.')">
                    <span><FileText /></span>
                    <small>Pengajuan</small>
                </button>
                <Link v-if="canAccessAccount" :href="route('absensi.mobile.account.show')" class="bottom-item">
                    <span><CircleUserRound /></span>
                    <small>Akun</small>
                </Link>
            </nav>

            <Transition name="toast">
                <div v-if="feedback" class="mobile-toast" role="status">
                    <span><Clock3 /></span>
                    {{ feedback }}
                </div>
            </Transition>
        </div>
    </main>
</template>

<style scoped>
.submission-notices {
    position: relative;
    z-index: 1;
    width: 100%;
    margin: 0;
}
.submission-notice {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
    padding: 14px 16px;
    border: 1px solid #cce7de;
    border-radius: 16px;
    background: #eaf7f1;
    color: #176451;
}
.submission-notice.failed {
    border-color: #f0d8c6;
    background: #fff5ec;
    color: #954a20;
}
.submission-notice > svg {
    flex: none;
    width: 20px;
}
.submission-notice span {
    flex: 1;
    font-size: 12px;
    line-height: 1.6;
}
.submission-notice strong {
    display: block;
    font-size: 13px;
}
.schedule-adjustment {
    margin: 0 0 16px;
    padding: 13px;
    border: 1px solid rgba(228, 189, 123, 0.3);
    border-radius: 18px;
    background: linear-gradient(120deg, rgba(228, 189, 123, 0.1), rgba(255, 255, 255, 0.025));
    box-shadow: inset 0 1px 0 rgba(255, 239, 211, 0.06);
}

.adjustment-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}

.adjustment-heading h3 {
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #f4dfb8;
    font-size: 12px;
    font-weight: 750;
    letter-spacing: -0.01em;
}

.adjustment-heading h3 svg {
    width: 14px;
    height: 14px;
    color: #e4bd7b;
}

.adjustment-badge {
    padding: 4px 7px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid rgba(151, 225, 196, 0.2);
    border-radius: 999px;
    color: #c6f2df;
    background: rgba(119, 214, 185, 0.09);
    font-size: 10px;
    font-weight: 750;
    line-height: 1.2;
    white-space: nowrap;
}

.adjustment-badge svg {
    width: 12px;
    height: 12px;
}

.adjustment-details {
    margin: 11px 0 0;
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: 9px;
}

.adjustment-item {
    min-width: 0;
    padding: 10px 11px;
    border: 1px solid rgba(238, 221, 186, 0.1);
    border-radius: 12px;
    background: rgba(4, 29, 32, 0.2);
}

.adjustment-item dt {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
    color: #cee2d9;
    font-size: 11px;
    font-weight: 650;
    line-height: 1.4;
}

.adjustment-item dt svg {
    width: 12px;
    height: 12px;
    flex: 0 0 auto;
    color: #e4bd7b;
}

.adjustment-item dd {
    margin: 5px 0 0;
    color: #fff3d9;
    font-size: 25px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.04em;
    line-height: 1.1;
}

.adjustment-item dd span {
    color: #c9d9ce;
    font-size: 11px;
    font-weight: 550;
    letter-spacing: 0;
}

.adjustment-caption {
    margin: 9px 0 0;
    color: #c9d9ce;
    font-size: 10px;
    line-height: 1.5;
}

.schedule-note {
    margin: 0 0 16px;
    font-size: 11px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.mobile-workspace {
    --mobile-ink: #173238;
    --mobile-muted: #728288;
    --mobile-line: #e5ece9;
    --mobile-surface: #ffffff;
    --mobile-bottom-navigation-height: calc(74px + env(safe-area-inset-bottom, 0px));
    min-height: 100svh;
    color: var(--mobile-ink);
    background:
        radial-gradient(circle at 8% 8%, rgba(70, 151, 140, 0.12), transparent 25rem),
        radial-gradient(circle at 92% 92%, rgba(218, 173, 105, 0.12), transparent 27rem), #eaf0ee;
    font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
}

.mobile-shell {
    width: 100%;
    max-width: 500px;
    min-height: 100svh;
    margin: 0 auto;
    display: flex;
    position: relative;
    flex-direction: column;
    overflow-x: hidden;
    background: #f7faf8;
    box-shadow: 0 0 80px rgba(16, 48, 51, 0.13);
}

.mobile-header {
    padding: max(21px, env(safe-area-inset-top)) 21px 17px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    position: relative;
    z-index: 2;
}

.profile-greeting {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.profile-avatar {
    width: 48px;
    height: 48px;
    padding: 3px;
    box-sizing: border-box;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    position: relative;
    border: 1px solid rgba(12, 102, 96, 0.17);
    border-radius: 17px;
    color: #fff;
    background: linear-gradient(145deg, #165d5b, #0d3439);
    box-shadow: 0 9px 22px rgba(14, 65, 66, 0.17);
    font-size: 15px;
    font-weight: 850;
    letter-spacing: 0.04em;
}

.profile-avatar::before {
    content: '';
    position: absolute;
    inset: 3px;
    border: 1px solid rgba(255, 255, 255, 0.21);
    border-radius: 13px;
    z-index: 1;
    pointer-events: none;
}

.profile-avatar img {
    width: calc(100% - 6px);
    max-width: none;
    height: calc(100% - 6px);
    display: block;
    position: absolute;
    inset: 3px;
    border-radius: 13px;
    object-fit: cover;
    object-position: center;
    clip-path: inset(0 round 13px);
}

.profile-avatar i {
    width: 11px;
    height: 11px;
    position: absolute;
    z-index: 2;
    right: -2px;
    bottom: -1px;
    border: 2px solid #f7faf8;
    border-radius: 999px;
    background: #37b98e;
}

.profile-copy {
    min-width: 0;
}

.profile-copy p,
.profile-copy h1 {
    margin: 0;
}

.profile-copy p {
    color: #77878b;
    font-size: 13px;
    font-weight: 650;
}

.profile-copy h1 {
    max-width: 235px;
    margin-top: 1px;
    overflow: hidden;
    color: #173238;
    font-size: 17px;
    font-weight: 820;
    letter-spacing: -0.025em;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.profile-copy > span {
    margin-top: 3px;
    display: flex;
    align-items: center;
    gap: 4px;
    color: #9b7b4a;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.profile-copy > span svg {
    width: 10px;
    height: 10px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

.header-actions button {
    width: 39px;
    height: 39px;
    display: grid;
    place-items: center;
    position: relative;
    border: 1px solid #e2eae7;
    border-radius: 13px;
    color: #50686d;
    background: rgba(255, 255, 255, 0.85);
    box-shadow: 0 6px 18px rgba(32, 67, 65, 0.05);
    transition: 160ms ease;
}

.header-actions button:hover {
    border-color: #c8dcd7;
    color: #087f78;
    transform: translateY(-1px);
}

.header-actions button svg {
    width: 17px;
    height: 17px;
}

.header-actions button i {
    width: 7px;
    height: 7px;
    position: absolute;
    top: 8px;
    right: 8px;
    border: 1.5px solid #fff;
    border-radius: 999px;
    background: #d7a657;
}

.mobile-content {
    padding: 0 16px calc(var(--mobile-bottom-navigation-height) + 36px);
    flex: 1;
}

.schedule-card {
    padding: 20px;
    position: relative;
    overflow: hidden;
    isolation: isolate;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 29px;
    color: #f8fffd;
    background: linear-gradient(142deg, rgba(255, 255, 255, 0.045), transparent 42%), linear-gradient(135deg, #0d3539 0%, #164f4f 62%, #246964 100%);
    box-shadow:
        0 24px 46px rgba(10, 55, 55, 0.2),
        0 3px 8px rgba(10, 55, 55, 0.08);
}

.schedule-card::after {
    content: '';
    width: 135%;
    height: 130px;
    position: absolute;
    z-index: -1;
    right: -26%;
    bottom: -75px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.045);
    transform: rotate(-5deg);
}

.schedule-glow {
    position: absolute;
    z-index: -1;
    border-radius: 999px;
    filter: blur(2px);
    pointer-events: none;
}

.schedule-glow-one {
    width: 145px;
    height: 145px;
    top: -70px;
    right: -40px;
    background: rgba(216, 179, 116, 0.2);
}

.schedule-glow-two {
    width: 90px;
    height: 90px;
    bottom: 44px;
    left: -58px;
    background: rgba(103, 224, 194, 0.15);
}

.schedule-topline {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.schedule-topline h2 {
    margin: 6px 0 0;
    color: #fff;
    font-size: 21px;
    font-weight: 820;
    letter-spacing: -0.035em;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #acd8cc;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.eyebrow svg {
    width: 11px;
    height: 11px;
    color: #e5bf7f;
}

.schedule-topline time {
    max-width: 122px;
    padding: 7px 9px;
    border: 1px solid rgba(255, 255, 255, 0.11);
    border-radius: 10px;
    color: rgba(239, 252, 248, 0.78);
    background: rgba(4, 29, 32, 0.17);
    font-size: 11px;
    font-weight: 700;
    line-height: 1.3;
    text-align: right;
}

.schedule-time-row {
    margin: 19px 0 17px;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 15px;
}

.schedule-location {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 9px;
}

.schedule-location > span {
    width: 34px;
    height: 34px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.11);
    border-radius: 11px;
    color: #e7c487;
    background: rgba(5, 31, 33, 0.2);
}

.schedule-location svg {
    width: 15px;
    height: 15px;
}

.schedule-location p,
.schedule-hours {
    margin: 0;
    display: flex;
    flex-direction: column;
}

.schedule-location small,
.schedule-hours small {
    color: rgba(218, 242, 236, 0.62);
    font-size: 11px;
    font-weight: 650;
}

.schedule-location strong {
    margin-top: 2px;
    overflow: hidden;
    color: #f6fffc;
    font-size: 13px;
    font-weight: 760;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.schedule-hours {
    align-items: flex-end;
    flex: 0 0 auto;
}

.schedule-hours strong {
    margin-top: 3px;
    color: #fff;
    font-size: 19px;
    font-weight: 820;
    letter-spacing: -0.04em;
}

.schedule-hours strong i {
    padding: 0 3px;
    color: #e4bd7b;
    font-style: normal;
    font-weight: 500;
}

.attendance-panel {
    min-height: 83px;
    padding: 8px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    position: relative;
    border: 1px solid rgba(255, 255, 255, 0.74);
    border-radius: 19px;
    background: rgba(251, 255, 253, 0.95);
    box-shadow: 0 16px 28px rgba(2, 31, 33, 0.2);
    backdrop-filter: blur(12px);
}

.attendance-action {
    min-width: 0;
    min-height: 64px;
    padding: 8px 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border: 0;
    border-radius: 13px;
    color: #244247;
    background: transparent;
    transition: 170ms ease;
}

.attendance-action:hover {
    background: #edf7f3;
    transform: translateY(-1px);
}

.attendance-action.completed {
    cursor: default;
    opacity: 0.78;
}

.attendance-action.completed:hover {
    background: transparent;
    transform: none;
}

.attendance-action > span:first-child {
    width: 37px;
    height: 37px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 12px;
}

.attendance-action.check-in > span:first-child {
    color: #087f78;
    background: #def2ec;
}

.attendance-action.check-out > span:first-child {
    color: #a66c22;
    background: #fff0d7;
}

.attendance-action svg {
    width: 18px;
    height: 18px;
}

.attendance-action > span:last-child {
    min-width: 0;
    display: flex;
    flex-direction: column;
    text-align: left;
}

.attendance-action small {
    color: #819095;
    font-size: 11px;
    font-weight: 700;
}

.attendance-action strong {
    margin-top: 1px;
    font-size: 15px;
    font-weight: 850;
    line-height: 1.15;
    letter-spacing: -0.02em;
    white-space: normal;
}

.attendance-divider {
    width: 1px;
    height: 42px;
    background: #dfe9e5;
}

.attendance-status {
    padding: 13px 3px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    color: rgba(226, 245, 240, 0.72);
    font-size: 11px;
    font-weight: 680;
}

.attendance-status span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.attendance-status i {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #77d6b9;
    box-shadow: 0 0 0 4px rgba(119, 214, 185, 0.11);
}

.attendance-status svg {
    width: 11px;
    height: 11px;
    color: #e4bd7b;
}

.quick-section {
    margin-top: 17px;
    padding: 19px 17px 16px;
    border: 1px solid var(--mobile-line);
    border-radius: 24px;
    background: var(--mobile-surface);
    box-shadow: 0 12px 31px rgba(35, 71, 67, 0.06);
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.section-heading span,
.announcement-copy > span {
    color: #9c7b48;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}

.section-heading h2 {
    margin: 3px 0 0;
    color: #1f393e;
    font-size: 16px;
    font-weight: 820;
    letter-spacing: -0.025em;
}

.section-heading > button {
    padding: 5px 6px 5px 9px;
    display: inline-flex;
    align-items: center;
    gap: 2px;
    border: 1px solid #e6ece9;
    border-radius: 999px;
    color: #6f7f83;
    background: #fafcfb;
    font-size: 11px;
    font-weight: 750;
}

.section-heading > button span {
    color: inherit;
    font: inherit;
    letter-spacing: 0;
    text-transform: none;
}

.section-heading > button svg {
    width: 12px;
    height: 12px;
}

.quick-grid {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 7px;
}

.quick-action {
    min-width: 0;
    min-height: 91px;
    padding: 7px 3px;
    display: flex;
    align-items: center;
    flex-direction: column;
    border: 0;
    border-radius: 15px;
    color: var(--mobile-ink);
    background: transparent;
    transition: 170ms ease;
}

.quick-action:hover {
    background: #f7faf9;
    transform: translateY(-2px);
}

.quick-icon {
    width: 43px;
    height: 43px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    color: var(--action-color);
    background: var(--action-background);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55);
}

.quick-icon svg {
    width: 20px;
    height: 20px;
    stroke-width: 1.9;
}

.quick-action strong {
    max-width: 100%;
    min-height: 32px;
    margin-top: 8px;
    display: flex;
    align-items: flex-end;
    font-size: 12px;
    font-weight: 800;
    line-height: 1.15;
    text-align: center;
}

.quick-action small {
    margin-top: 1px;
    color: #9aa5a7;
    font-size: 11px;
    white-space: nowrap;
}

.announcement-card {
    margin-top: 14px;
    padding: 17px;
    display: flex;
    align-items: center;
    gap: 13px;
    position: relative;
    overflow: hidden;
    border: 1px solid #eadfca;
    border-radius: 22px;
    background: radial-gradient(circle at 94% 0, rgba(198, 149, 74, 0.15), transparent 95px), linear-gradient(135deg, #fffaf0, #fffdf9);
    box-shadow: 0 10px 27px rgba(91, 70, 35, 0.06);
}

.announcement-icon {
    width: 46px;
    height: 46px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid #ead8b7;
    border-radius: 15px;
    color: #9c6c2a;
    background: rgba(255, 255, 255, 0.7);
}

.announcement-icon svg {
    width: 20px;
    height: 20px;
}

.announcement-copy {
    min-width: 0;
    flex: 1;
}

.announcement-copy h2 {
    margin: 3px 0 0;
    color: #3f3b30;
    font-size: 14px;
    font-weight: 820;
}

.announcement-copy p {
    margin: 4px 0 0;
    color: #8e887b;
    font-size: 11px;
    line-height: 1.45;
}

.announcement-card > button {
    width: 30px;
    height: 30px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid #eadfcf;
    border-radius: 10px;
    color: #987a4e;
    background: rgba(255, 255, 255, 0.75);
}

.announcement-card > button svg {
    width: 14px;
    height: 14px;
}

.bottom-navigation {
    width: 100%;
    max-width: 500px;
    min-height: var(--mobile-bottom-navigation-height);
    margin-inline: auto;
    padding: 8px 13px calc(8px + env(safe-area-inset-bottom, 0px));
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    align-items: end;
    position: fixed;
    z-index: 20;
    right: 0;
    bottom: 0;
    left: 0;
    border-top: 1px solid rgba(216, 228, 223, 0.9);
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 -14px 35px rgba(28, 61, 59, 0.06);
    backdrop-filter: blur(18px);
}

.bottom-item {
    min-width: 0;
    min-height: 48px;
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

.bottom-item > span {
    width: 30px;
    height: 26px;
    display: grid;
    place-items: center;
    border-radius: 10px;
}

.bottom-item svg {
    width: 18px;
    height: 18px;
    stroke-width: 1.9;
}

.bottom-item small {
    font-size: 11px;
    font-weight: 720;
}

.bottom-item.active {
    color: #087f78;
}

.bottom-item.active > span {
    background: #e8f5f1;
}

.bottom-item.attendance-main {
    color: #315858;
}

.bottom-item.attendance-main > span {
    width: 51px;
    height: 51px;
    margin-top: -23px;
    border: 5px solid #fff;
    border-radius: 18px;
    color: #f8fffd;
    background: linear-gradient(145deg, #b68746, #d4ae6f);
    box-shadow: 0 9px 20px rgba(151, 104, 44, 0.26);
}

.bottom-item.attendance-main svg {
    width: 21px;
    height: 21px;
}

.bottom-item.attendance-main small {
    color: #7d8989;
}

.mobile-toast {
    width: max-content;
    max-width: calc(100% - 32px);
    min-height: 45px;
    padding: 9px 14px 9px 9px;
    display: flex;
    align-items: center;
    gap: 9px;
    position: fixed;
    z-index: 50;
    right: 50%;
    bottom: calc(var(--mobile-bottom-navigation-height) + 16px);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    color: #f4fcfa;
    background: rgba(13, 48, 52, 0.95);
    box-shadow: 0 15px 34px rgba(8, 40, 42, 0.25);
    backdrop-filter: blur(12px);
    transform: translateX(50%);
    font-size: 12px;
    font-weight: 680;
}

.mobile-toast > span {
    width: 28px;
    height: 28px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 9px;
    color: #e8c68d;
    background: rgba(255, 255, 255, 0.09);
}

.mobile-toast svg {
    width: 14px;
    height: 14px;
}

.toast-enter-active,
.toast-leave-active {
    transition: 180ms ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translate(50%, 8px) scale(0.97);
}

button,
a {
    -webkit-tap-highlight-color: transparent;
}

button:focus-visible,
a:focus-visible {
    outline: 3px solid rgba(26, 138, 128, 0.25);
    outline-offset: 2px;
}

@media (max-width: 420px) {
    .attendance-action {
        min-height: 98px;
        flex-direction: column;
        gap: 6px;
        text-align: center;
    }

    .attendance-action > span:last-child {
        align-items: center;
        text-align: center;
    }

    .quick-action {
        min-height: 110px;
    }
}

@media (max-width: 385px) {
    .mobile-header {
        padding-inline: 16px;
    }

    .profile-avatar {
        width: 44px;
        height: 44px;
    }

    .profile-copy h1 {
        max-width: 190px;
        font-size: 16px;
    }

    .header-actions > button:first-child {
        display: none;
    }

    .schedule-card {
        padding: 17px;
        border-radius: 25px;
    }

    .schedule-hours strong {
        font-size: 17px;
    }

    .attendance-action {
        gap: 6px;
    }

    .attendance-action > span:first-child {
        width: 33px;
        height: 33px;
    }

    .quick-section {
        padding-inline: 11px;
    }
}

@media (min-width: 700px) {
    .mobile-shell {
        min-height: calc(100svh - 40px);
        margin-block: 20px;
        border: 1px solid rgba(210, 223, 218, 0.8);
        border-radius: 34px;
    }

    .bottom-navigation {
        max-width: 498px;
        bottom: 20px;
        border-radius: 0 0 34px 34px;
    }

    .mobile-toast {
        bottom: calc(var(--mobile-bottom-navigation-height) + 36px);
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
