<script setup lang="ts">
import PresensiApprovalContent, { type PresensiApprovalItem } from '@/components/PresensiApprovalContent.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MobileBackLink from './components/MobileBackLink.vue';
defineProps<{ presensiRequest: PresensiApprovalItem }>();
const page = usePage();
const success = computed(() => (page.props.flash as { success?: string } | undefined)?.success);
</script>
<template>
    <Head title="Persetujuan presensi" />
    <main class="min-h-screen bg-emerald-50/50 p-4 pb-12">
        <div class="mx-auto mb-5 max-w-5xl">
            <MobileBackLink href="/absensi/mobile/notifikasi/presensi">Kembali ke Persetujuan presensi</MobileBackLink>
            <p v-if="success" role="status" class="mt-4 rounded-xl bg-emerald-100 p-3 text-emerald-900">
                {{ success }}
            </p>
        </div>
        <PresensiApprovalContent :item="presensiRequest" base="/absensi/mobile/presensi/persetujuan" />
    </main>
</template>
