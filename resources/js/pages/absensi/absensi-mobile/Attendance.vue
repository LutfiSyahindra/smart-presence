<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    Building2,
    CalendarDays,
    Check,
    ChevronDown,
    CircleUserRound,
    Clock3,
    FileText,
    Fingerprint,
    Home,
    LogIn,
    LogOut,
    MapPin,
    Navigation,
    ShieldCheck,
    Sparkles,
    TimerReset,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type AttendanceEvent = {
    time: string;
    date_time_label: string;
    location_name: string | null;
    address: string | null;
    outside_radius: boolean;
    face_verified: boolean;
    face_similarity: number | null;
    schedule_status: string;
    schedule_status_tone: 'success' | 'warning';
};

const props = defineProps<{
    canAccessAccount: boolean;
    today: {
        date: string;
        date_label: string;
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
        location_name: string | null;
        location_address: string | null;
    };
    attendance: {
        is_day_off: boolean;
        status: 'unavailable' | 'day_off' | 'ready_to_clock_in' | 'ready_to_clock_out' | 'complete';
        duration: string | null;
        notes: string | null;
        clock_in: AttendanceEvent | null;
        clock_out: AttendanceEvent | null;
    };
}>();

const now = ref(new Date());
const clockInOpen = ref(Boolean(props.attendance.clock_in));
const clockOutOpen = ref(Boolean(props.attendance.clock_out));
let timer: ReturnType<typeof setInterval> | undefined;

const liveTime = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    })
        .format(now.value)
        .replaceAll('.', ':'),
);

const statusMeta = computed(() => {
    const statuses = {
        unavailable: {
            eyebrow: 'Konfigurasi diperlukan',
            title: 'Layanan belum siap',
            description: 'Profil pegawai, shift, atau lokasi kerja Anda belum lengkap.',
            badge: 'Perlu bantuan admin',
            tone: 'warning',
        },
        day_off: {
            eyebrow: 'Hari libur',
            title: 'Waktunya beristirahat',
            description: 'Hari ini tercatat sebagai hari libur. Anda tidak perlu melakukan presensi.',
            badge: 'Tidak perlu presensi',
            tone: 'rest',
        },
        ready_to_clock_in: {
            eyebrow: 'Siap memulai hari',
            title: 'Clock-in belum tercatat',
            description: 'Pastikan Anda berada di lokasi kerja dan wajah terlihat jelas saat verifikasi.',
            badge: 'Menunggu clock-in',
            tone: 'ready',
        },
        ready_to_clock_out: {
            eyebrow: 'Sedang bertugas',
            title: 'Clock-in berhasil tercatat',
            description: 'Semoga tugas Anda berjalan lancar. Clock-out dapat dilakukan saat pekerjaan selesai.',
            badge: 'Shift sedang berjalan',
            tone: 'active',
        },
        complete: {
            eyebrow: 'Presensi lengkap',
            title: 'Terima kasih hari ini',
            description: 'Clock-in dan clock-out Anda telah tersimpan dengan aman.',
            badge: 'Tugas selesai',
            tone: 'complete',
        },
    } as const;

    return statuses[props.attendance.status];
});

const primaryAction = computed(() => {
    if (props.attendance.status === 'ready_to_clock_in') {
        return {
            label: 'Clock In Sekarang',
            caption: 'Verifikasi lokasi & wajah',
            href: route('absensi.mobile.clock-in.create'),
            icon: LogIn,
        };
    }

    if (props.attendance.status === 'ready_to_clock_out') {
        return {
            label: 'Clock Out Sekarang',
            caption: 'Akhiri shift dengan aman',
            href: route('absensi.mobile.clock-out.create'),
            icon: LogOut,
        };
    }

    return null;
});

const progressState = computed(() => {
    if (props.attendance.clock_out) return 'complete';
    if (props.attendance.clock_in) return 'half';
    return 'empty';
});

onMounted(() => {
    timer = window.setInterval(() => (now.value = new Date()), 1_000);
});

onUnmounted(() => {
    if (timer) window.clearInterval(timer);
});
</script>

<template>
    <Head title="Layanan Presensi" />

    <main class="attendance-workspace">
        <div class="attendance-shell">
            <header class="attendance-header">
                <Link :href="route('absensi.mobile.home')" class="header-back" aria-label="Kembali ke beranda">
                    <ArrowLeft />
                </Link>
                <div class="header-title">
                    <span class="brand-mark"><TimerReset /></span>
                    <span><small>RSARSY Presence</small><strong>Layanan Presensi</strong></span>
                </div>
                <span class="secure-indicator" aria-label="Koneksi aman"><ShieldCheck /></span>
            </header>

            <section class="attendance-content">
                <article class="status-hero" :class="`tone-${statusMeta.tone}`">
                    <span class="hero-orb hero-orb-one" aria-hidden="true"></span>
                    <span class="hero-orb hero-orb-two" aria-hidden="true"></span>

                    <div class="hero-topline">
                        <span class="hero-eyebrow"><Sparkles /> {{ statusMeta.eyebrow }}</span>
                        <span class="live-clock"><i></i>{{ liveTime }} WIB</span>
                    </div>

                    <h1>{{ statusMeta.title }}</h1>
                    <p>{{ statusMeta.description }}</p>

                    <div class="hero-badge"><BadgeCheck /> {{ statusMeta.badge }}</div>

                    <div class="shift-overview">
                        <div>
                            <span class="overview-icon"><Clock3 /></span>
                            <span>
                                <small>{{ today.shift_name || 'Jadwal shift' }}</small>
                                <strong>{{ today.start_time || '--:--' }} — {{ today.end_time || '--:--' }}</strong>
                            </span>
                        </div>
                        <i aria-hidden="true"></i>
                        <div>
                            <span class="overview-icon"><Building2 /></span>
                            <span>
                                <small>Lokasi kerja</small>
                                <strong>{{ today.location_name || 'Belum ditentukan' }}</strong>
                            </span>
                        </div>
                    </div>
                </article>

                <section class="day-summary" aria-labelledby="today-attendance-title">
                    <div class="section-heading">
                        <div>
                            <span>Hari ini</span>
                            <h2 id="today-attendance-title">Perjalanan presensi</h2>
                        </div>
                        <time :datetime="today.date"><CalendarDays /> {{ today.date_label }}</time>
                    </div>

                    <div class="attendance-timeline" :class="`progress-${progressState}`">
                        <div class="timeline-item" :class="{ done: attendance.clock_in }">
                            <span class="timeline-dot"><Check v-if="attendance.clock_in" /><LogIn v-else /></span>
                            <span
                                ><small>Clock In</small><strong>{{ attendance.clock_in?.time || '--:--' }}</strong></span
                            >
                        </div>
                        <div class="timeline-track"><span></span></div>
                        <div class="timeline-item end" :class="{ done: attendance.clock_out }">
                            <span class="timeline-dot"><Check v-if="attendance.clock_out" /><LogOut v-else /></span>
                            <span
                                ><small>Clock Out</small><strong>{{ attendance.clock_out?.time || '--:--' }}</strong></span
                            >
                        </div>
                    </div>

                    <div v-if="attendance.duration" class="duration-strip">
                        <Clock3 />
                        <span
                            ><small>Durasi tercatat</small><strong>{{ attendance.duration }}</strong></span
                        >
                        <span v-if="attendance.status === 'ready_to_clock_out'" class="live-label">Berjalan</span>
                    </div>
                </section>

                <section class="record-section" aria-labelledby="record-title">
                    <div class="section-heading compact">
                        <div>
                            <span>Detail aktivitas</span>
                            <h2 id="record-title">Rekaman hari ini</h2>
                        </div>
                        <span class="record-count"
                            >{{ Number(Boolean(attendance.clock_in)) + Number(Boolean(attendance.clock_out)) }}/2 tercatat</span
                        >
                    </div>

                    <article class="record-card clock-in-record" :class="{ recorded: attendance.clock_in, expanded: clockInOpen }">
                        <button type="button" class="record-trigger" :aria-expanded="clockInOpen" @click="clockInOpen = !clockInOpen">
                            <span class="record-icon"><LogIn /></span>
                            <span class="record-main">
                                <small>Clock In</small>
                                <strong>{{ attendance.clock_in?.time || 'Belum tercatat' }}<i v-if="attendance.clock_in"> WIB</i></strong>
                            </span>
                            <span v-if="attendance.clock_in" class="event-badge" :class="attendance.clock_in.schedule_status_tone">
                                {{ attendance.clock_in.schedule_status }}
                            </span>
                            <span v-else class="event-badge pending">Menunggu</span>
                            <ChevronDown class="record-chevron" />
                        </button>

                        <Transition name="detail">
                            <div v-if="clockInOpen" class="record-details">
                                <template v-if="attendance.clock_in">
                                    <div class="detail-row">
                                        <span><Clock3 /></span>
                                        <p>
                                            <small>Waktu tercatat</small><strong>{{ attendance.clock_in.date_time_label }}</strong>
                                        </p>
                                    </div>
                                    <div class="detail-row">
                                        <span><MapPin /></span>
                                        <p>
                                            <small>Lokasi clock-in</small>
                                            <strong>{{ attendance.clock_in.location_name || today.location_name || 'Lokasi kerja' }}</strong>
                                            <em>{{ attendance.clock_in.address || 'Alamat lokasi tidak tersedia' }}</em>
                                        </p>
                                        <span class="radius-badge" :class="{ outside: attendance.clock_in.outside_radius }">
                                            <Navigation /> {{ attendance.clock_in.outside_radius ? 'Di luar radius' : 'Dalam radius' }}
                                        </span>
                                    </div>
                                    <div class="detail-row">
                                        <span><Fingerprint /></span>
                                        <p>
                                            <small>Verifikasi identitas</small>
                                            <strong>{{ attendance.clock_in.face_verified ? 'Wajah terverifikasi' : 'Belum terverifikasi' }}</strong>
                                            <em v-if="attendance.clock_in.face_similarity !== null">
                                                Kemiripan {{ attendance.clock_in.face_similarity.toFixed(2) }}%
                                            </em>
                                        </p>
                                        <span class="verified-mark" :class="{ muted: !attendance.clock_in.face_verified }"><Check /></span>
                                    </div>
                                </template>
                                <div v-else class="empty-detail">
                                    <span><LogIn /></span>
                                    <p>
                                        <strong>Belum ada rekaman clock-in</strong
                                        ><small>Lakukan clock-in untuk menampilkan waktu, lokasi, dan hasil verifikasi.</small>
                                    </p>
                                </div>
                            </div>
                        </Transition>
                    </article>

                    <article class="record-card clock-out-record" :class="{ recorded: attendance.clock_out, expanded: clockOutOpen }">
                        <button type="button" class="record-trigger" :aria-expanded="clockOutOpen" @click="clockOutOpen = !clockOutOpen">
                            <span class="record-icon"><LogOut /></span>
                            <span class="record-main">
                                <small>Clock Out</small>
                                <strong>{{ attendance.clock_out?.time || 'Belum tercatat' }}<i v-if="attendance.clock_out"> WIB</i></strong>
                            </span>
                            <span v-if="attendance.clock_out" class="event-badge" :class="attendance.clock_out.schedule_status_tone">
                                {{ attendance.clock_out.schedule_status }}
                            </span>
                            <span v-else class="event-badge pending">Menunggu</span>
                            <ChevronDown class="record-chevron" />
                        </button>

                        <Transition name="detail">
                            <div v-if="clockOutOpen" class="record-details">
                                <template v-if="attendance.clock_out">
                                    <div class="detail-row">
                                        <span><Clock3 /></span>
                                        <p>
                                            <small>Waktu tercatat</small><strong>{{ attendance.clock_out.date_time_label }}</strong>
                                        </p>
                                    </div>
                                    <div class="detail-row">
                                        <span><MapPin /></span>
                                        <p>
                                            <small>Lokasi clock-out</small>
                                            <strong>{{ attendance.clock_out.location_name || today.location_name || 'Lokasi kerja' }}</strong>
                                            <em>{{ attendance.clock_out.address || 'Alamat lokasi tidak tersedia' }}</em>
                                        </p>
                                        <span class="radius-badge" :class="{ outside: attendance.clock_out.outside_radius }">
                                            <Navigation /> {{ attendance.clock_out.outside_radius ? 'Di luar radius' : 'Dalam radius' }}
                                        </span>
                                    </div>
                                    <div class="detail-row">
                                        <span><Fingerprint /></span>
                                        <p>
                                            <small>Verifikasi identitas</small>
                                            <strong>{{ attendance.clock_out.face_verified ? 'Wajah terverifikasi' : 'Belum terverifikasi' }}</strong>
                                            <em v-if="attendance.clock_out.face_similarity !== null">
                                                Kemiripan {{ attendance.clock_out.face_similarity.toFixed(2) }}%
                                            </em>
                                        </p>
                                        <span class="verified-mark" :class="{ muted: !attendance.clock_out.face_verified }"><Check /></span>
                                    </div>
                                </template>
                                <div v-else class="empty-detail">
                                    <span><LogOut /></span>
                                    <p>
                                        <strong>Belum ada rekaman clock-out</strong
                                        ><small>Detail akan tersedia setelah Anda menyelesaikan clock-out.</small>
                                    </p>
                                </div>
                            </div>
                        </Transition>
                    </article>
                </section>

                <aside v-if="attendance.notes" class="attendance-note">
                    <span><FileText /></span>
                    <div>
                        <small>Catatan presensi</small>
                        <p>{{ attendance.notes }}</p>
                    </div>
                </aside>

                <section class="action-panel" :class="{ completed: !primaryAction }">
                    <template v-if="primaryAction">
                        <div class="action-copy">
                            <span><Fingerprint /></span>
                            <p>
                                <small>Aksi selanjutnya</small><strong>{{ primaryAction.label }}</strong>
                            </p>
                        </div>
                        <Link :href="primaryAction.href" class="primary-action">
                            <span><component :is="primaryAction.icon" /></span>
                            <span
                                ><strong>{{ primaryAction.label }}</strong
                                ><small>{{ primaryAction.caption }}</small></span
                            >
                            <ArrowLeft />
                        </Link>
                        <p class="security-note"><ShieldCheck /> Lokasi dan wajah akan diverifikasi untuk menjaga keamanan data.</p>
                    </template>
                    <template v-else-if="attendance.status === 'complete'">
                        <span class="complete-icon"><Check /></span>
                        <div>
                            <small>Presensi hari ini</small><strong>Sudah lengkap</strong>
                            <p>Semua rekaman telah tersimpan.</p>
                        </div>
                    </template>
                    <template v-else-if="attendance.status === 'day_off'">
                        <span class="complete-icon rest"><CalendarDays /></span>
                        <div>
                            <small>Status hari ini</small><strong>Hari libur</strong>
                            <p>Nikmati waktu istirahat Anda.</p>
                        </div>
                    </template>
                    <template v-else>
                        <span class="complete-icon warning"><TriangleAlert /></span>
                        <div>
                            <small>Layanan belum tersedia</small><strong>Hubungi administrator</strong>
                            <p>Lengkapi profil pegawai, shift, dan lokasi kerja.</p>
                        </div>
                    </template>
                </section>
            </section>

            <nav class="bottom-navigation" :class="{ 'without-account': !canAccessAccount }" aria-label="Navigasi utama">
                <Link :href="route('absensi.mobile.home')" class="bottom-item">
                    <span><Home /></span><small>Beranda</small>
                </Link>
                <button type="button" class="bottom-item" disabled>
                    <span><CalendarDays /></span><small>Jadwal</small>
                </button>
                <button type="button" class="bottom-item attendance-main active" aria-current="page">
                    <span><TimerReset /></span><small>Presensi</small>
                </button>
                <button type="button" class="bottom-item" disabled>
                    <span><FileText /></span><small>Pengajuan</small>
                </button>
                <Link v-if="canAccessAccount" :href="route('absensi.mobile.account.show')" class="bottom-item">
                    <span><CircleUserRound /></span><small>Akun</small>
                </Link>
            </nav>
        </div>
    </main>
</template>

<style scoped>
.attendance-workspace {
    --ink: #183438;
    --muted: #78898c;
    --line: #e3ebe8;
    min-height: 100svh;
    color: var(--ink);
    background:
        radial-gradient(circle at 6% 4%, rgba(37, 133, 122, 0.13), transparent 27rem),
        radial-gradient(circle at 95% 94%, rgba(201, 156, 87, 0.14), transparent 28rem), #e9f0ed;
    font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
}

.attendance-shell {
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

.attendance-header {
    min-height: 72px;
    padding: max(14px, env(safe-area-inset-top)) 17px 12px;
    display: grid;
    grid-template-columns: 42px 1fr 42px;
    align-items: center;
    gap: 10px;
}

.header-back,
.secure-indicator {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border: 1px solid #e0e9e5;
    border-radius: 14px;
    color: #426466;
    background: rgba(255, 255, 255, 0.88);
    box-shadow: 0 7px 18px rgba(31, 67, 64, 0.055);
}

.header-back {
    transition: 160ms ease;
}

.header-back:hover {
    color: #087f78;
    border-color: #c8ddd7;
    transform: translateX(-2px);
}

.header-back svg,
.secure-indicator svg {
    width: 17px;
    height: 17px;
}

.secure-indicator {
    color: #a7793e;
    background: #fffaf0;
}

.header-title {
    min-width: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
}

.brand-mark {
    width: 34px;
    height: 34px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 12px;
    color: #f6fffc;
    background: linear-gradient(145deg, #0e4a4c, #21766e);
    box-shadow: 0 7px 15px rgba(16, 83, 80, 0.18);
}

.brand-mark svg {
    width: 16px;
}

.header-title > span:last-child {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.header-title small {
    color: #9b7b4a;
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}

.header-title strong {
    margin-top: 1px;
    overflow: hidden;
    color: #203d40;
    font-size: 13px;
    font-weight: 830;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.attendance-content {
    padding: 3px 16px 28px;
    flex: 1;
}

.status-hero {
    min-height: 302px;
    padding: 21px;
    overflow: hidden;
    position: relative;
    isolation: isolate;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 30px;
    color: #f4fffc;
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.055), transparent 40%), linear-gradient(135deg, #0b3236 0%, #155150 62%, #236c66 100%);
    box-shadow:
        0 24px 48px rgba(12, 61, 59, 0.2),
        inset 0 1px rgba(255, 255, 255, 0.06);
}

.status-hero::after {
    content: '';
    width: 130%;
    height: 118px;
    position: absolute;
    z-index: -1;
    right: -20%;
    bottom: -73px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.045);
    transform: rotate(-4deg);
}

.status-hero.tone-complete {
    background: linear-gradient(135deg, #103a3c, #176057 62%, #267a6c);
}

.status-hero.tone-warning {
    background: linear-gradient(135deg, #3d3430, #665442 62%, #8c704e);
}

.status-hero.tone-rest {
    background: linear-gradient(135deg, #243946, #36586a 60%, #54778b);
}

.hero-orb {
    position: absolute;
    z-index: -1;
    border-radius: 999px;
    pointer-events: none;
}

.hero-orb-one {
    width: 155px;
    height: 155px;
    top: -77px;
    right: -43px;
    background: rgba(224, 185, 117, 0.22);
}

.hero-orb-two {
    width: 92px;
    height: 92px;
    bottom: 41px;
    left: -61px;
    background: rgba(91, 213, 186, 0.14);
    filter: blur(2px);
}

.hero-topline,
.shift-overview,
.timeline-item,
.duration-strip,
.record-trigger,
.detail-row,
.action-copy,
.primary-action,
.security-note {
    display: flex;
    align-items: center;
}

.hero-topline {
    justify-content: space-between;
    gap: 10px;
}

.hero-eyebrow,
.live-clock {
    min-height: 27px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 9px;
    font-weight: 820;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.hero-eyebrow {
    color: #b5ddd3;
}

.hero-eyebrow svg {
    width: 12px;
}

.live-clock {
    padding: 0 9px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 999px;
    color: #e5f5f1;
    background: rgba(255, 255, 255, 0.075);
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.03em;
}

.live-clock i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #69e0bd;
    box-shadow: 0 0 0 4px rgba(105, 224, 189, 0.1);
    animation: live-pulse 2s ease-in-out infinite;
}

.status-hero h1 {
    max-width: 330px;
    margin: 19px 0 0;
    color: #fff;
    font-size: 26px;
    font-weight: 850;
    line-height: 1.08;
    letter-spacing: -0.045em;
}

.status-hero > p {
    max-width: 370px;
    min-height: 38px;
    margin: 9px 0 0;
    color: #b9d2ce;
    font-size: 11px;
    line-height: 1.65;
}

.hero-badge {
    width: fit-content;
    min-height: 28px;
    margin-top: 14px;
    padding: 0 10px;
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid rgba(214, 181, 123, 0.22);
    border-radius: 999px;
    color: #f1d9ad;
    background: rgba(215, 174, 104, 0.1);
    font-size: 9px;
    font-weight: 780;
}

.hero-badge svg {
    width: 12px;
}

.shift-overview {
    min-height: 72px;
    margin-top: 18px;
    padding: 11px 13px;
    gap: 11px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 18px;
    background: rgba(5, 36, 38, 0.22);
    backdrop-filter: blur(12px);
}

.shift-overview > div {
    min-width: 0;
    display: flex;
    flex: 1;
    align-items: center;
    gap: 9px;
}

.shift-overview > i {
    width: 1px;
    height: 31px;
    flex: 0 0 auto;
    background: rgba(255, 255, 255, 0.12);
}

.overview-icon {
    width: 30px;
    height: 30px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 10px;
    color: #d7b875;
    background: rgba(255, 255, 255, 0.08);
}

.overview-icon svg {
    width: 14px;
}

.shift-overview > div > span:last-child {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.shift-overview small {
    color: #93b7b2;
    font-size: 8px;
    font-weight: 700;
}

.shift-overview strong {
    margin-top: 3px;
    overflow: hidden;
    color: #f5fbf9;
    font-size: 10px;
    font-weight: 790;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.day-summary,
.record-card,
.action-panel {
    border: 1px solid var(--line);
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 13px 31px rgba(27, 64, 61, 0.055);
}

.day-summary {
    margin-top: 16px;
    padding: 19px;
    border-radius: 23px;
}

.section-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 13px;
}

.section-heading > div > span {
    color: #a07842;
    font-size: 8px;
    font-weight: 850;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}

.section-heading h2 {
    margin: 3px 0 0;
    color: #213d40;
    font-size: 16px;
    font-weight: 830;
    letter-spacing: -0.025em;
}

.section-heading time {
    max-width: 150px;
    display: flex;
    align-items: center;
    gap: 5px;
    color: #798a8d;
    font-size: 9px;
    font-weight: 680;
    line-height: 1.35;
    text-align: right;
}

.section-heading time svg {
    width: 12px;
    flex: 0 0 auto;
    color: #25887f;
}

.attendance-timeline {
    margin-top: 20px;
    display: grid;
    grid-template-columns: auto minmax(50px, 1fr) auto;
    align-items: center;
    gap: 9px;
}

.timeline-item {
    min-width: 94px;
    gap: 9px;
}

.timeline-item.end {
    justify-content: flex-end;
    text-align: right;
}

.timeline-item.end > span:last-child {
    order: -1;
}

.timeline-dot {
    width: 35px;
    height: 35px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid #dfe8e5;
    border-radius: 12px;
    color: #a4aeac;
    background: #f4f7f6;
}

.timeline-dot svg {
    width: 15px;
}

.timeline-item.done .timeline-dot {
    border-color: #bee6db;
    color: #fff;
    background: linear-gradient(145deg, #199a88, #0c746d);
    box-shadow: 0 7px 15px rgba(15, 128, 117, 0.18);
}

.timeline-item > span:last-child {
    display: flex;
    flex-direction: column;
}

.timeline-item small {
    color: #8d9999;
    font-size: 8px;
    font-weight: 730;
}

.timeline-item strong {
    margin-top: 2px;
    color: #294548;
    font-size: 15px;
    font-weight: 840;
    font-variant-numeric: tabular-nums;
}

.timeline-track {
    height: 3px;
    overflow: hidden;
    border-radius: 999px;
    background: #e7edeb;
}

.timeline-track span {
    width: 0;
    height: 100%;
    display: block;
    border-radius: inherit;
    background: linear-gradient(90deg, #168d80, #d1aa68);
    transition: width 500ms ease;
}

.progress-half .timeline-track span {
    width: 50%;
}

.progress-complete .timeline-track span {
    width: 100%;
}

.duration-strip {
    min-height: 47px;
    margin-top: 16px;
    padding: 8px 10px;
    gap: 9px;
    border-radius: 14px;
    color: #52706f;
    background: #f0f7f4;
}

.duration-strip > svg {
    width: 15px;
    color: #16877c;
}

.duration-strip > span:nth-child(2) {
    display: flex;
    flex: 1;
    flex-direction: column;
}

.duration-strip small {
    color: #879593;
    font-size: 8px;
}

.duration-strip strong {
    margin-top: 1px;
    color: #31504f;
    font-size: 11px;
    font-weight: 800;
}

.live-label,
.record-count {
    min-height: 24px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    border: 1px solid #cce8df;
    border-radius: 999px;
    color: #138176;
    background: #ecfaf6;
    font-size: 8px;
    font-weight: 800;
}

.record-section {
    margin-top: 22px;
}

.section-heading.compact {
    padding: 0 3px;
    align-items: flex-end;
}

.record-count {
    border-color: #e2e8e5;
    color: #718381;
    background: #f4f7f6;
}

.record-card {
    margin-top: 11px;
    overflow: hidden;
    border-radius: 20px;
    transition:
        border-color 180ms ease,
        box-shadow 180ms ease;
}

.record-card.recorded {
    border-color: #d5e8e2;
}

.record-card.expanded {
    box-shadow: 0 17px 36px rgba(27, 64, 61, 0.08);
}

.record-trigger {
    width: 100%;
    min-height: 76px;
    padding: 13px 14px;
    gap: 10px;
    border: 0;
    color: inherit;
    background: transparent;
    text-align: left;
}

.record-icon {
    width: 42px;
    height: 42px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 14px;
    color: #13887d;
    background: #e9f7f3;
}

.clock-out-record .record-icon {
    color: #a87838;
    background: #fff5e5;
}

.record-icon svg {
    width: 18px;
}

.record-main {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.record-main small {
    color: #899795;
    font-size: 9px;
    font-weight: 720;
}

.record-main strong {
    margin-top: 2px;
    overflow: hidden;
    color: #294548;
    font-size: 14px;
    font-weight: 830;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.record-main strong i {
    color: #899694;
    font-size: 8px;
    font-style: normal;
    font-weight: 700;
}

.event-badge {
    min-height: 24px;
    padding: 0 8px;
    display: inline-flex;
    align-items: center;
    flex: 0 0 auto;
    border: 1px solid #c9eadd;
    border-radius: 999px;
    color: #147e72;
    background: #eefaf6;
    font-size: 8px;
    font-weight: 780;
}

.event-badge.warning {
    border-color: #efdcb5;
    color: #a16a22;
    background: #fff8e9;
}

.event-badge.pending {
    border-color: #e2e7e5;
    color: #929c9a;
    background: #f5f7f6;
}

.record-chevron {
    width: 15px;
    flex: 0 0 auto;
    color: #98a4a2;
    transition: transform 180ms ease;
}

.record-card.expanded .record-chevron {
    transform: rotate(180deg);
}

.record-details {
    padding: 4px 14px 14px 66px;
    border-top: 1px solid #edf1ef;
}

.detail-row {
    min-height: 57px;
    gap: 9px;
    border-bottom: 1px dashed #e4ebe8;
}

.detail-row:last-child {
    border-bottom: 0;
}

.detail-row > span:first-child {
    width: 27px;
    height: 27px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 9px;
    color: #59817b;
    background: #eef5f3;
}

.detail-row > span:first-child svg {
    width: 12px;
}

.detail-row p {
    min-width: 0;
    margin: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.detail-row small {
    color: #929d9b;
    font-size: 8px;
}

.detail-row strong {
    margin-top: 2px;
    color: #395552;
    font-size: 10px;
    font-weight: 790;
}

.detail-row em {
    margin-top: 2px;
    overflow: hidden;
    color: #8a9896;
    font-size: 8px;
    font-style: normal;
    line-height: 1.35;
    text-overflow: ellipsis;
}

.radius-badge {
    min-height: 23px;
    padding: 0 7px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex: 0 0 auto;
    border-radius: 999px;
    color: #198072;
    background: #edf9f5;
    font-size: 7px;
    font-weight: 780;
}

.radius-badge.outside {
    color: #a16c25;
    background: #fff5e5;
}

.radius-badge svg {
    width: 9px;
}

.verified-mark {
    width: 23px;
    height: 23px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 50%;
    color: #fff;
    background: #20a18f;
}

.verified-mark.muted {
    background: #b1bbb9;
}

.verified-mark svg {
    width: 11px;
}

.empty-detail {
    min-height: 70px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.empty-detail > span {
    width: 31px;
    height: 31px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 10px;
    color: #9ca7a5;
    background: #f1f4f3;
}

.empty-detail svg {
    width: 13px;
}

.empty-detail p {
    margin: 0;
    display: flex;
    flex-direction: column;
}

.empty-detail strong {
    color: #647573;
    font-size: 10px;
    font-weight: 780;
}

.empty-detail small {
    margin-top: 3px;
    color: #98a3a1;
    font-size: 8px;
    line-height: 1.45;
}

.attendance-note {
    margin-top: 12px;
    padding: 13px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border: 1px solid #eee2c9;
    border-radius: 17px;
    color: #6e5c42;
    background: #fffaf0;
}

.attendance-note > span {
    width: 29px;
    height: 29px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 10px;
    color: #a57b40;
    background: #f8ebd1;
}

.attendance-note svg {
    width: 13px;
}

.attendance-note div {
    min-width: 0;
}

.attendance-note small {
    color: #9a825e;
    font-size: 8px;
    font-weight: 800;
    text-transform: uppercase;
}

.attendance-note p {
    margin: 3px 0 0;
    font-size: 9px;
    line-height: 1.55;
    white-space: pre-line;
}

.action-panel {
    margin-top: 18px;
    padding: 16px;
    border-radius: 23px;
}

.action-copy {
    margin-bottom: 11px;
    gap: 9px;
}

.action-copy > span {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    color: #a67839;
    background: #fff3df;
}

.action-copy svg {
    width: 14px;
}

.action-copy p {
    margin: 0;
    display: flex;
    flex-direction: column;
}

.action-copy small {
    color: #909b99;
    font-size: 8px;
}

.action-copy strong {
    color: #34504f;
    font-size: 11px;
    font-weight: 800;
}

.primary-action {
    min-height: 66px;
    padding: 9px 13px;
    gap: 11px;
    border-radius: 17px;
    color: #f8fffd;
    background: linear-gradient(135deg, #0c4d4e, #197069 67%, #267e74);
    box-shadow: 0 13px 25px rgba(13, 91, 86, 0.2);
    transition:
        transform 180ms ease,
        box-shadow 180ms ease;
}

.primary-action:hover {
    color: #fff;
    box-shadow: 0 16px 30px rgba(13, 91, 86, 0.27);
    transform: translateY(-2px);
}

.primary-action > span:first-child {
    width: 40px;
    height: 40px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.13);
    border-radius: 13px;
    color: #f1d6a5;
    background: rgba(255, 255, 255, 0.08);
}

.primary-action > span:first-child svg {
    width: 18px;
}

.primary-action > span:nth-child(2) {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.primary-action strong {
    font-size: 12px;
    font-weight: 820;
}

.primary-action small {
    margin-top: 3px;
    color: #abd1ca;
    font-size: 8px;
}

.primary-action > svg {
    width: 17px;
    transform: rotate(180deg);
}

.security-note {
    margin: 10px 2px 0;
    justify-content: center;
    gap: 5px;
    color: #8a9896;
    font-size: 8px;
    line-height: 1.4;
    text-align: center;
}

.security-note svg {
    width: 11px;
    flex: 0 0 auto;
    color: #38887f;
}

.action-panel.completed {
    min-height: 82px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-color: #d5e9e2;
    background: linear-gradient(135deg, #f4fbf8, #fff);
}

.complete-icon {
    width: 45px;
    height: 45px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 15px;
    color: #fff;
    background: linear-gradient(145deg, #1a9b88, #0e756d);
    box-shadow: 0 8px 17px rgba(16, 126, 115, 0.18);
}

.complete-icon.rest {
    background: linear-gradient(145deg, #50788b, #365866);
}

.complete-icon.warning {
    background: linear-gradient(145deg, #c69a55, #9a7137);
}

.complete-icon svg {
    width: 19px;
}

.action-panel.completed > div {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.action-panel.completed small {
    color: #8b9997;
    font-size: 8px;
}

.action-panel.completed strong {
    margin-top: 2px;
    color: #31504e;
    font-size: 13px;
    font-weight: 820;
}

.action-panel.completed p {
    margin: 3px 0 0;
    color: #8b9997;
    font-size: 9px;
}

.bottom-navigation {
    min-height: 74px;
    padding: 8px 13px max(8px, env(safe-area-inset-bottom));
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    align-items: end;
    position: sticky;
    z-index: 20;
    bottom: 0;
    border-top: 1px solid rgba(216, 228, 223, 0.9);
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 -14px 35px rgba(28, 61, 59, 0.06);
    backdrop-filter: blur(18px);
}

.bottom-navigation.without-account {
    grid-template-columns: repeat(4, 1fr);
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

.bottom-item:disabled {
    opacity: 1;
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
    font-size: 8px;
    font-weight: 720;
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
    background: linear-gradient(145deg, #a8783b, #d5ad69);
    box-shadow: 0 9px 22px rgba(151, 104, 44, 0.3);
}

.bottom-item.attendance-main svg {
    width: 21px;
    height: 21px;
}

.bottom-item.attendance-main small {
    color: #997340;
    font-weight: 850;
}

.detail-enter-active,
.detail-leave-active {
    overflow: hidden;
    transition: 180ms ease;
}

.detail-enter-from,
.detail-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

@keyframes live-pulse {
    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.55;
        transform: scale(0.82);
    }
}

@media (max-width: 385px) {
    .attendance-content {
        padding-inline: 12px;
    }

    .status-hero {
        padding: 18px;
    }

    .status-hero h1 {
        font-size: 23px;
    }

    .shift-overview {
        padding-inline: 9px;
        gap: 7px;
    }

    .overview-icon {
        display: none;
    }

    .event-badge {
        display: none;
    }

    .record-details {
        padding-left: 14px;
    }

    .radius-badge {
        max-width: 76px;
        line-height: 1.2;
        text-align: center;
    }
}

@media (min-width: 700px) {
    .attendance-shell {
        min-height: calc(100svh - 40px);
        margin-block: 20px;
        border: 1px solid rgba(210, 223, 218, 0.8);
        border-radius: 34px;
    }

    .bottom-navigation {
        border-radius: 0 0 34px 34px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .live-clock i {
        animation: none;
    }

    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
