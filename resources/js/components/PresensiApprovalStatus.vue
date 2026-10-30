<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
defineProps<{ status?: string | null; href?: string }>();
const labels: Record<string, string> = { pending: 'Presensi menunggu persetujuan', approved: 'Presensi disetujui', rejected: 'Presensi ditolak' };
</script>
<template>
    <div v-if="status" class="presensi-approval-status" :class="status" role="status">
        <strong>{{ labels[status] }}</strong
        ><Link v-if="href" :href="href">Lihat persetujuan</Link>
    </div>
</template>
<style scoped>
.presensi-approval-status {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 12px;
    justify-content: space-between;
    padding: 10px 12px;
    margin: 10px 0;
    border-radius: 10px;
    background: #fff2cf;
    color: #775611;
    font-size: 12px;
}
.approved {
    background: #e1f5e8;
    color: #17643b;
}
.rejected {
    background: #fde7eb;
    color: #ac233e;
}
a {
    color: inherit;
    text-decoration: underline;
}
</style>
