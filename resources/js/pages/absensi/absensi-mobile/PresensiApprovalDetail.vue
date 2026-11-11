<script setup lang="ts">
import type { PresensiApprovalItem } from '@/components/PresensiApprovalContent.vue';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CircleCheck } from 'lucide-vue-next';
import MobileBackLink from './components/MobileBackLink.vue';
import PresensiApprovalReview from './components/PresensiApprovalReview.vue';
defineProps<{ presensiRequest: PresensiApprovalItem }>();
const page = usePage<SharedData>();
</script>
<template>
    <Head title="Persetujuan Presensi" />
    <main class="approval-workspace">
        <div class="approval-shell">
            <header class="approval-header">
                <div class="header-row">
                    <MobileBackLink href="/absensi/mobile/notifikasi/presensi" class="icon-button" aria-label="Kembali ke Persetujuan Presensi"
                        ><ArrowLeft
                    /></MobileBackLink>
                    <div class="header-title">
                        <h1>Presensi</h1>
                        <span>RINCIAN PERSETUJUAN</span>
                    </div>
                </div>
            </header>
            <div class="approval-content">
                <div v-if="page.props.flash.success" class="notice success" role="status">
                    <CircleCheck /><span>{{ page.props.flash.success }}</span>
                </div>
                <div v-if="page.props.flash.error" class="notice" role="alert">{{ page.props.flash.error }}</div>
                <PresensiApprovalReview :key="presensiRequest.id" :item="presensiRequest" />
            </div>
        </div>
    </main>
</template>

<style scoped src="./approval-workspace.css"></style>
<style scoped>
@media (max-width: 359px) {
    .header-row {
        grid-template-columns: 44px minmax(0, 1fr) 44px;
    }
}
</style>
