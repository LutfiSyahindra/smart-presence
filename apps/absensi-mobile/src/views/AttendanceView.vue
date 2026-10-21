<script setup lang="ts">
import { ArrowRight, CheckCircle2, Clock3, LogIn, LogOut, MapPin, ShieldCheck } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';
import MobileShell from '@/components/MobileShell.vue';
import { ApiError, apiRequest } from '@/lib/api';
import type { ApiEnvelope, Attendance } from '@/types';

const attendance = ref<Attendance | null>(null);
const loading = ref(true);
const error = ref('');
const action = computed(() => attendance.value?.status === 'ready_to_clock_in' ? 'clock-in' : attendance.value?.status === 'ready_to_clock_out' ? 'clock-out' : null);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        attendance.value = (await apiRequest<ApiEnvelope<Attendance>>('/attendance')).data;
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Rincian presensi belum dapat dimuat.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>

<template>
    <MobileShell title="Presensi hari ini" subtitle="Catatan waktu, lokasi, dan verifikasi">
        <LoadingState v-if="loading" />
        <ErrorState v-else-if="error" :message="error" @retry="load" />
        <template v-else-if="attendance">
            <section class="date-banner">
                <span><Clock3 :size="21" /></span>
                <div><small>Tanggal presensi</small><strong>{{ attendance.date_label }}</strong></div>
                <span class="chip">{{ attendance.shift.name || 'Tanpa shift' }}</span>
            </section>

            <p v-if="attendance.is_on_leave" class="notes-card">Cuti Anda telah disetujui. Clock-in dan clock-out dinonaktifkan selama cuti.</p>

            <section class="timeline-card">
                <article class="timeline-item" :class="{ complete: attendance.clock_in }">
                    <span class="timeline-marker"><LogIn :size="19" /></span>
                    <div class="timeline-body">
                        <div class="timeline-heading"><div><small>Clock-in</small><strong>{{ attendance.clock_in?.time || 'Belum tercatat' }}</strong></div><CheckCircle2 v-if="attendance.clock_in" :size="21" /></div>
                        <template v-if="attendance.clock_in">
                            <p><MapPin :size="15" /> {{ attendance.clock_in.address || attendance.office.name }}</p>
                            <p><ShieldCheck :size="15" /> Wajah terverifikasi<span v-if="attendance.clock_in.face_similarity"> · {{ attendance.clock_in.face_similarity.toFixed(1) }}%</span></p>
                            <span v-if="attendance.clock_in.late_minutes" class="chip warning-chip">Terlambat {{ attendance.clock_in.late_minutes }} menit</span>
                        </template>
                    </div>
                </article>
                <article class="timeline-item" :class="{ complete: attendance.clock_out }">
                    <span class="timeline-marker"><LogOut :size="19" /></span>
                    <div class="timeline-body">
                        <div class="timeline-heading"><div><small>Clock-out</small><strong>{{ attendance.clock_out?.time || 'Belum tercatat' }}</strong></div><CheckCircle2 v-if="attendance.clock_out" :size="21" /></div>
                        <template v-if="attendance.clock_out">
                            <p><MapPin :size="15" /> {{ attendance.clock_out.address || attendance.office.name }}</p>
                            <p><ShieldCheck :size="15" /> Wajah terverifikasi<span v-if="attendance.clock_out.face_similarity"> · {{ attendance.clock_out.face_similarity.toFixed(1) }}%</span></p>
                            <span v-if="attendance.clock_out.early_departure_minutes" class="chip warning-chip">Lebih awal {{ attendance.clock_out.early_departure_minutes }} menit</span>
                        </template>
                    </div>
                </article>
            </section>

            <div class="info-grid">
                <article><small>Jadwal</small><strong>{{ attendance.shift.start_time || '--:--' }} – {{ attendance.shift.end_time || '--:--' }}</strong></article>
                <article><small>Durasi</small><strong>{{ attendance.duration || 'Belum tersedia' }}</strong></article>
            </div>
            <p v-if="attendance.notes" class="notes-card"><strong>Catatan</strong>{{ attendance.notes }}</p>

            <RouterLink v-if="action" :to="{ name: 'attendance-action', params: { action } }" class="button primary full sticky-action">
                {{ action === 'clock-in' ? 'Mulai clock-in' : 'Mulai clock-out' }} <ArrowRight :size="19" />
            </RouterLink>
        </template>
    </MobileShell>
</template>
