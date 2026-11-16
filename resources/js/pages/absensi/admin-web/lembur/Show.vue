<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import '../approval-workflow.css';
import FlashMessage from '../setting/components/FlashMessage.vue';
import LemburDetailModal from './LemburDetailModal.vue';
import type { LemburDetailPayload } from './types';

const props = defineProps<LemburDetailPayload>();
const base = '/absensi/admin-web/lembur';
const selectedId = ref(props.lembur.id);
watch(
    () => props.lembur.id,
    (id) => {
        selectedId.value = id;
    },
);
</script>

<template>
    <Head :title="`Detail Lembur #${selectedId}`" />
    <AppLayout :breadcrumbs="[{ title: 'Lembur', href: base }]">
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <h1>Lembur</h1>
                    <p>Informasi lembur dan penggunaan saldo pegawai.</p>
                </div>
                <Link :href="base" class="btn btn-outline-primary">Kembali ke daftar lembur</Link>
            </section>
        </div>
        <LemburDetailModal
            :key="selectedId"
            :request-id="selectedId"
            :initial-detail="selectedId === lembur.id ? props : undefined"
            @close="router.visit(base)"
            @open="selectedId = $event"
        />
    </AppLayout>
</template>
