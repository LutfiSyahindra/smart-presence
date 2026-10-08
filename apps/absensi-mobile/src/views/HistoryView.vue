<script setup lang="ts">
import { CalendarCheck2, Clock3, LogIn, LogOut } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';
import MobileShell from '@/components/MobileShell.vue';
import { ApiError, apiRequest } from '@/lib/api';
import type { ApiEnvelope, HistoryItem } from '@/types';

const items = ref<HistoryItem[]>([]);
const loading = ref(true);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        items.value = (await apiRequest<ApiEnvelope<HistoryItem[]>>('/attendance/history')).data;
    } catch (caught) {
        error.value = caught instanceof ApiError ? caught.message : 'Riwayat belum dapat dimuat.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>

<template>
    <MobileShell title="Riwayat presensi" subtitle="60 catatan presensi terbaru">
        <LoadingState v-if="loading" />
        <ErrorState v-else-if="error" :message="error" @retry="load" />
        <section v-else-if="items.length" class="history-list">
            <article v-for="item in items" :key="item.id" class="history-card">
                <div class="history-date">
                    <span><CalendarCheck2 :size="20" /></span>
                    <div><strong>{{ item.date_label }}</strong><small>{{ item.shift_name || 'Tanpa shift' }}</small></div>
                    <span v-if="item.is_day_off" class="chip">Libur</span>
                </div>
                <div v-if="!item.is_day_off" class="history-times">
                    <div><span><LogIn :size="16" /> Masuk</span><strong>{{ item.clock_in || '--:--' }}</strong></div>
                    <div><span><LogOut :size="16" /> Pulang</span><strong>{{ item.clock_out || '--:--' }}</strong></div>
                    <div><span><Clock3 :size="16" /> Status</span><strong :class="{ warningText: item.late_minutes || item.early_departure_minutes }">{{ item.late_minutes ? `Terlambat ${item.late_minutes}m` : item.early_departure_minutes ? `Pulang awal ${item.early_departure_minutes}m` : 'Sesuai jadwal' }}</strong></div>
                </div>
            </article>
        </section>
        <section v-else class="state-card">
            <CalendarCheck2 :size="34" />
            <h2>Belum ada riwayat</h2>
            <p>Catatan presensi Anda akan muncul di sini.</p>
        </section>
    </MobileShell>
</template>
