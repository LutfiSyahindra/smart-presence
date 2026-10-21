<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import '../approval-workflow.css';
import CutiRequestDetail from '../cuti/components/CutiRequestDetail.vue';
import { date, labels } from '../cuti/formatters';
import type { CutiRow } from '../cuti/types';
import FlashMessage from '../setting/components/FlashMessage.vue';

const props = defineProps<{ cuti: CutiRow }>();
const detailOpen = ref(true);
watch(
    () => props.cuti,
    () => (detailOpen.value = false),
);
</script>

<template>
    <Head :title="`Pengajuan #CUTI-${cuti.id}`" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Notifikasi', href: '/absensi/admin-web/notifikasi/semua' },
            { title: `#CUTI-${cuti.id}`, href: '' },
        ]"
    >
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><Bell /> Pengajuan #CUTI-{{ cuti.id }}</span>
                    <h1>{{ cuti.nama_cuti || 'Pengajuan cuti' }}</h1>
                    <p>{{ cuti.nama_pegawai }} · {{ cuti.unit }} · {{ cuti.jabatan }}</p>
                </div>
                <span class="al-status" :class="cuti.status">{{ labels[cuti.status] }}</span>
            </section>
            <section class="al-panel detail-summary">
                <p>{{ date(cuti.tanggal_mulai) }} s.d. {{ date(cuti.tanggal_selesai) }} · {{ cuti.jumlah_hari }} hari kalender</p>
                <button type="button" class="btn btn-primary" @click="detailOpen = true">
                    {{ cuti.can_decide ? 'Tinjau pengajuan' : 'Lihat detail pengajuan' }}
                </button>
                <Link href="/absensi/admin-web/notifikasi/semua" class="btn btn-outline-secondary">Kembali ke notifikasi</Link>
            </section>
        </div>
        <CutiRequestDetail :request="detailOpen ? cuti : null" @close="detailOpen = false" />
    </AppLayout>
</template>

<style scoped>
.detail-summary {
    padding: 24px;
}
.detail-summary p {
    margin-bottom: 18px;
    color: #70798c;
}
.detail-summary .btn {
    margin: 0 10px 10px 0;
}
</style>
