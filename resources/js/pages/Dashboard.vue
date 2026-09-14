<script setup lang="ts">
import { getInitials } from '@/composables/useInitials';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    ArrowUpRight,
    BadgeCheck,
    CalendarDays,
    Check,
    Fingerprint,
    HeartPulse,
    KeyRound,
    Settings2,
    ShieldCheck,
    Sparkles,
    UserRound,
    UsersRound,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface DashboardOverview {
    users: number | null;
    roles: number | null;
    permissions: number | null;
    access_count: number;
    access_total: number;
}

interface RecentUser {
    id: number;
    name: string;
    email: string;
    verified: boolean;
    created_at: string | null;
}

const props = defineProps<{
    overview: DashboardOverview;
    recentUsers: RecentUser[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/absensi/admin-web/dashboard' }];
const page = usePage<SharedData>();
const now = ref(new Date());
let clockTimer: ReturnType<typeof setInterval> | undefined;

const user = computed(() => page.props.auth.user);
const firstName = computed(() => user.value.name.trim().split(/\s+/)[0]);
const isSuperAdmin = computed(() => page.props.auth.roles.includes('super-admin'));
const roleLabel = computed(() => page.props.auth.roles[0]?.replaceAll('-', ' ') ?? 'Pengguna');
const moduleCount = computed(() => new Set(page.props.auth.permissions.map((permission) => permission.split('.')[0])).size);
const accessCoverage = computed(() => {
    if (!props.overview.access_total) return 0;
    return Math.min(100, Math.round((props.overview.access_count / props.overview.access_total) * 100));
});

const greeting = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('id-ID', {
            hour: '2-digit',
            hour12: false,
            timeZone: 'Asia/Jakarta',
        }).format(now.value),
    );

    if (hour < 11) return 'Selamat pagi';
    if (hour < 15) return 'Selamat siang';
    if (hour < 19) return 'Selamat sore';
    return 'Selamat malam';
});

const dateLabel = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Jakarta',
    }).format(now.value),
);

const timeLabel = computed(() =>
    new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: 'Asia/Jakarta',
    }).format(now.value),
);

const can = (permission: string) => isSuperAdmin.value || page.props.auth.permissions.includes(permission);

const summaryCards = computed(() => [
    props.overview.users !== null
        ? {
              label: 'Pengguna terdaftar',
              value: props.overview.users.toLocaleString('id-ID'),
              note: 'Akun dalam sistem',
              icon: UsersRound,
              tone: 'indigo',
          }
        : {
              label: 'Status akun',
              value: user.value.email_verified_at ? 'Terverifikasi' : 'Perlu verifikasi',
              note: user.value.email_verified_at ? 'Identitas akun valid' : 'Selesaikan verifikasi email',
              icon: BadgeCheck,
              tone: 'indigo',
          },
    props.overview.roles !== null
        ? {
              label: 'Role tersedia',
              value: props.overview.roles.toLocaleString('id-ID'),
              note: 'Kelompok kewenangan',
              icon: ShieldCheck,
              tone: 'cyan',
          }
        : {
              label: 'Role aktif',
              value: roleLabel.value,
              note: `${page.props.auth.roles.length || 1} role ditetapkan`,
              icon: ShieldCheck,
              tone: 'cyan',
          },
    props.overview.permissions !== null
        ? {
              label: 'Permission sistem',
              value: props.overview.permissions.toLocaleString('id-ID'),
              note: 'Kontrol akses granular',
              icon: KeyRound,
              tone: 'violet',
          }
        : {
              label: 'Modul dapat diakses',
              value: moduleCount.value.toLocaleString('id-ID'),
              note: 'Sesuai kewenangan Anda',
              icon: Fingerprint,
              tone: 'violet',
          },
    {
        label: 'Hak akses Anda',
        value: props.overview.access_count.toLocaleString('id-ID'),
        note: `Dari ${props.overview.access_total.toLocaleString('id-ID')} permission`,
        icon: Fingerprint,
        tone: 'emerald',
    },
]);

const quickActions = computed(() => {
    const actions = [
        can('users.create')
            ? {
                  title: 'Tambah pengguna',
                  description: 'Buat akun dan tetapkan akses',
                  href: '/absensi/admin-web/setting/users/create',
                  icon: UsersRound,
                  tone: 'indigo',
              }
            : null,
        can('users.view')
            ? {
                  title: 'Kelola pengguna',
                  description: 'Tinjau akun dan role',
                  href: '/absensi/admin-web/setting/users',
                  icon: UserRound,
                  tone: 'cyan',
              }
            : null,
        can('roles.view')
            ? {
                  title: 'Atur role',
                  description: 'Kelola kewenangan tim',
                  href: '/absensi/admin-web/setting/roles',
                  icon: ShieldCheck,
                  tone: 'violet',
              }
            : null,
        can('permissions.view')
            ? {
                  title: 'Permission',
                  description: 'Kontrol akses terperinci',
                  href: '/absensi/admin-web/setting/permissions',
                  icon: KeyRound,
                  tone: 'emerald',
              }
            : null,
        {
            title: 'Profil saya',
            description: 'Perbarui identitas akun',
            href: '/settings/profile',
            icon: Settings2,
            tone: 'slate',
        },
    ];

    return actions.filter((action) => action !== null).slice(0, 4);
});

onMounted(() => {
    clockTimer = window.setInterval(() => (now.value = new Date()), 1000);
});

onBeforeUnmount(() => {
    if (clockTimer) window.clearInterval(clockTimer);
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="premium-dashboard">
            <section class="dashboard-hero" aria-labelledby="dashboard-welcome">
                <div class="dashboard-hero-grid" aria-hidden="true"></div>
                <div class="dashboard-orb dashboard-orb-one" aria-hidden="true"></div>
                <div class="dashboard-orb dashboard-orb-two" aria-hidden="true"></div>

                <div class="dashboard-hero-content">
                    <div class="dashboard-status-pill">
                        <span class="dashboard-live-dot"></span>
                        Sistem operasional
                        <span class="dashboard-status-divider"></span>
                        <Sparkles class="dashboard-status-icon" />
                        Workspace personal
                    </div>

                    <p class="dashboard-hero-kicker">Pusat kontrol RSARSY</p>
                    <h1 id="dashboard-welcome">
                        {{ greeting }}, <span>{{ firstName }}</span
                        >.
                    </h1>
                    <p class="dashboard-hero-copy">
                        Pantau akses, kelola tim, dan lanjutkan pekerjaan penting dari satu ruang kerja yang ringkas dan aman.
                    </p>

                    <div class="dashboard-hero-actions">
                        <Link :href="quickActions[0].href" class="dashboard-primary-action">
                            {{ quickActions[0].title }}
                            <ArrowRight />
                        </Link>
                        <Link href="/settings/profile" class="dashboard-secondary-action">
                            Lihat profil
                            <ArrowUpRight />
                        </Link>
                    </div>

                    <div class="dashboard-trust-row">
                        <span><Check /> Akses berbasis role</span>
                        <span><Check /> Data terlindungi</span>
                        <span><Check /> Sesi terautentikasi</span>
                    </div>
                </div>

                <div class="dashboard-clock-card">
                    <div class="dashboard-clock-topline">
                        <span>Waktu lokal</span>
                        <span class="dashboard-clock-zone">WIB</span>
                    </div>
                    <time class="dashboard-clock" :datetime="now.toISOString()">{{ timeLabel }}</time>
                    <div class="dashboard-clock-date">
                        <CalendarDays />
                        <span>{{ dateLabel }}</span>
                    </div>
                    <div class="dashboard-clock-divider"></div>
                    <div class="dashboard-clock-footer">
                        <span class="dashboard-clock-icon"><HeartPulse /></span>
                        <div>
                            <strong>Layanan siap digunakan</strong>
                            <small>Semua fungsi inti terhubung</small>
                        </div>
                        <Activity class="dashboard-pulse-icon" />
                    </div>
                </div>
            </section>

            <section class="dashboard-section" aria-labelledby="overview-title">
                <div class="dashboard-section-heading">
                    <div>
                        <span class="dashboard-eyebrow">Ringkasan</span>
                        <h2 id="overview-title">Ikhtisar workspace</h2>
                    </div>
                    <span class="dashboard-updated"><span></span> Diperbarui langsung</span>
                </div>

                <div class="dashboard-stat-grid">
                    <article v-for="card in summaryCards" :key="card.label" class="dashboard-stat-card" :class="`tone-${card.tone}`">
                        <div class="dashboard-stat-icon"><component :is="card.icon" /></div>
                        <div class="dashboard-stat-copy">
                            <span>{{ card.label }}</span>
                            <strong>{{ card.value }}</strong>
                            <small>{{ card.note }}</small>
                        </div>
                        <span class="dashboard-stat-spark" aria-hidden="true"></span>
                    </article>
                </div>
            </section>

            <div class="dashboard-content-grid">
                <section class="dashboard-panel dashboard-activity-panel" aria-labelledby="activity-title">
                    <div class="dashboard-panel-header">
                        <div>
                            <span class="dashboard-eyebrow">Aktivitas terkini</span>
                            <h2 id="activity-title">{{ recentUsers.length ? 'Pengguna terbaru' : 'Kondisi akun Anda' }}</h2>
                        </div>
                        <Link v-if="recentUsers.length && can('users.view')" href="/absensi/admin-web/setting/users" class="dashboard-text-link">
                            Lihat semua <ArrowUpRight />
                        </Link>
                    </div>

                    <div v-if="recentUsers.length" class="dashboard-user-list">
                        <Link
                            v-for="recentUser in recentUsers"
                            :key="recentUser.id"
                            href="/absensi/admin-web/setting/users"
                            class="dashboard-user-row"
                        >
                            <span class="dashboard-user-avatar">{{ getInitials(recentUser.name) }}</span>
                            <span class="dashboard-user-identity">
                                <strong>{{ recentUser.name }}</strong>
                                <small>{{ recentUser.email }}</small>
                            </span>
                            <span class="dashboard-verification" :class="{ verified: recentUser.verified }">
                                <BadgeCheck /> {{ recentUser.verified ? 'Terverifikasi' : 'Belum verifikasi' }}
                            </span>
                            <time>{{ recentUser.created_at }}</time>
                            <ArrowRight class="dashboard-row-arrow" />
                        </Link>
                    </div>

                    <div v-else class="dashboard-account-state">
                        <div class="dashboard-account-visual">
                            <span class="dashboard-account-avatar">{{ getInitials(user.name) }}</span>
                            <span class="dashboard-account-check"><Check /></span>
                        </div>
                        <div>
                            <h3>Akun siap digunakan</h3>
                            <p>Identitas dan sesi Anda aktif. Fitur ditampilkan otomatis berdasarkan role yang diberikan administrator.</p>
                            <Link href="/settings/profile" class="dashboard-text-link">Tinjau profil <ArrowRight /></Link>
                        </div>
                    </div>
                </section>

                <aside class="dashboard-side-column">
                    <section class="dashboard-panel" aria-labelledby="quick-action-title">
                        <div class="dashboard-panel-header dashboard-panel-header-compact">
                            <div>
                                <span class="dashboard-eyebrow">Akses cepat</span>
                                <h2 id="quick-action-title">Mulai dari sini</h2>
                            </div>
                        </div>
                        <div class="dashboard-action-list">
                            <Link
                                v-for="action in quickActions"
                                :key="action.title"
                                :href="action.href"
                                class="dashboard-action-row"
                                :class="`tone-${action.tone}`"
                            >
                                <span class="dashboard-action-icon"><component :is="action.icon" /></span>
                                <span>
                                    <strong>{{ action.title }}</strong>
                                    <small>{{ action.description }}</small>
                                </span>
                                <ArrowUpRight />
                            </Link>
                        </div>
                    </section>

                    <section class="dashboard-access-card" aria-labelledby="access-title">
                        <div class="dashboard-access-copy">
                            <span class="dashboard-eyebrow">Keamanan</span>
                            <h2 id="access-title">Cakupan akses</h2>
                            <p>{{ isSuperAdmin ? 'Akses penuh administrator' : 'Akses disesuaikan dengan role' }}</p>
                            <span class="dashboard-access-badge"><ShieldCheck /> Terproteksi</span>
                        </div>
                        <div class="dashboard-progress-ring" :style="{ '--progress': `${accessCoverage * 3.6}deg` }">
                            <div>
                                <strong>{{ accessCoverage }}%</strong><small>akses</small>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
