<script setup lang="ts">
import PresensiApprovalContent, { type PresensiApprovalItem } from '@/components/PresensiApprovalContent.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import FlashMessage from '../setting/components/FlashMessage.vue';
defineProps<{ presensiRequest: PresensiApprovalItem }>();
</script>
<template>
    <Head title="Persetujuan presensi di luar radius" />
    <AppLayout :breadcrumbs="[{ title: 'Persetujuan presensi', href: '/absensi/admin-web/notifikasi/butuh-approval?request_type=presensi' }]">
        <div class="p-4 md:p-6">
            <FlashMessage /><Link href="/absensi/admin-web/notifikasi/butuh-approval?request_type=presensi" class="mb-4 inline-block"
                >&larr; Kembali ke antrean presensi</Link
            >
            <PresensiApprovalContent :item="presensiRequest" base="/absensi/admin-web/notifikasi/pengajuan/presensi" />
        </div>
    </AppLayout>
</template>
