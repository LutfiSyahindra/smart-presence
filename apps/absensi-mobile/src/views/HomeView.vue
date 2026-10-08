<script setup lang="ts">
import { ArrowRight, Building2, CheckCircle2, Clock3, LogIn, LogOut, MapPin, RefreshCw, ShieldCheck, UserRound } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import MobileShell from '@/components/MobileShell.vue';
import LoadingState from '@/components/LoadingState.vue';
import ErrorState from '@/components/ErrorState.vue';
import { ApiError, apiRequest } from '@/lib/api';
import type { ApiEnvelope, HomeData } from '@/types';

const data = ref<HomeData | null>(null);
const loading = ref(true);
const error = ref('');

const firstName = computed(() => data.value?.user.name.split(' ')[0] || 'Sahabat');
const action = computed(() => {
    if (data.value?.attendance.status === 'ready_to_clock_in') return { slug: 'clock-in', label: 'Clock-in sekarang', icon: LogIn };
    if (data.value?.attendance.status === 'ready_to_clock_out') return { slug: 'clock-out', label: 'Clock-out sekarang', icon: LogOut };
    return null;
});
const statusCopy = computed(() => {
    switch (data.value?.attendance.status) {
        case 'ready_to_clock_in': return ['Siap untuk clock-in', 'Pastikan lokasi dan kamera perangkat aktif.'];
        case 'ready_to_clock_out': return ['Sedang bertugas', `Durasi kerja ${data.value.attendance.duration || 'sedang dihitung'}.`];
        case 'complete': return ['Presensi hari ini lengkap', 'Terima kasih atas kerja keras Anda hari ini.'];
        case 'day_off': return ['Hari libur', 'Tidak ada kewajiban presensi hari ini.'];
        default: return ['Presensi belum tersedia', 'Hubungi administrator untuk memeriksa konfigurasi akun.'];
    }
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await apiRequest<ApiEnvelope<HomeData>>('/home');
        data.value = response.data;
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Data beranda belum dapat dimuat.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <MobileShell :title="`Halo, ${firstName}`" :subtitle="data?.date.label || 'Memuat tanggal…'">
        <template #header-action>
            <button class="icon-button header-button" type="button" aria-label="Perbarui data" @click="load">
                <RefreshCw :size="20" />
            </button>
        </template>

        <LoadingState v-if="loading" />
        <ErrorState v-else-if="error" :message="error" @retry="load" />

        <template v-else-if="data">
            <section class="status-hero" :class="`status-${data.attendance.status}`">
                <div class="status-icon">
                    <CheckCircle2 v-if="data.attendance.status === 'complete'" :size="29" />
                    <Clock3 v-else :size="29" />
                </div>
                <div class="status-copy">
                    <span>Status hari ini</span>
                    <h2>{{ statusCopy[0] }}</h2>
                    <p>{{ statusCopy[1] }}</p>
                </div>
            </section>

            <RouterLink
                v-if="action"
                :to="{ name: 'attendance-action', params: { action: action.slug } }"
                class="primary-action-card"
            >
                <span class="action-icon"><component :is="action.icon" :size="28" /></span>
                <span>
                    <small>Presensi cepat</small>
                    <strong>{{ action.label }}</strong>
                </span>
                <ArrowRight :size="24" />
            </RouterLink>

            <section class="section-block">
                <div class="section-title">
                    <div>
                        <span>Jadwal</span>
                        <h2>Shift hari ini</h2>
                    </div>
                    <RouterLink :to="{ name: 'attendance' }">Detail</RouterLink>
                </div>
                <div class="schedule-card">
                    <div class="schedule-row">
                        <span class="soft-icon"><Clock3 :size="20" /></span>
                        <div><small>Jam kerja</small><strong>{{ data.schedule.start_time || '--:--' }} – {{ data.schedule.end_time || '--:--' }}</strong></div>
                        <span class="chip">{{ data.schedule.shift_name || 'Belum diatur' }}</span>
                    </div>
                    <div class="schedule-row">
                        <span class="soft-icon"><Building2 :size="20" /></span>
                        <div><small>Lokasi kerja</small><strong>{{ data.schedule.location_name || 'Belum diatur' }}</strong></div>
                    </div>
                </div>
            </section>

            <section class="section-block">
                <div class="section-title"><div><span>Ringkasan</span><h2>Aktivitas presensi</h2></div></div>
                <div class="time-grid">
                    <article class="time-card">
                        <span><LogIn :size="18" /> Masuk</span>
                        <strong>{{ data.attendance.clock_in?.time || '--:--' }}</strong>
                        <small>{{ data.attendance.clock_in ? 'Terverifikasi' : 'Belum tercatat' }}</small>
                    </article>
                    <article class="time-card">
                        <span><LogOut :size="18" /> Pulang</span>
                        <strong>{{ data.attendance.clock_out?.time || '--:--' }}</strong>
                        <small>{{ data.attendance.clock_out ? 'Terverifikasi' : 'Belum tercatat' }}</small>
                    </article>
                </div>
            </section>

            <section v-if="!data.user.has_profile_photo" class="notice-card warning">
                <UserRound :size="24" />
                <div><strong>Foto profil diperlukan</strong><p>Tambahkan foto wajah sebelum melakukan presensi.</p></div>
                <RouterLink v-if="data.user.can_access_account" :to="{ name: 'account' }">Atur</RouterLink>
            </section>
            <section v-else class="notice-card secure">
                <ShieldCheck :size="24" />
                <div><strong>Perangkat siap</strong><p>Lokasi dan wajah akan diverifikasi saat presensi.</p></div>
                <MapPin :size="20" />
            </section>
        </template>
    </MobileShell>
</template>
