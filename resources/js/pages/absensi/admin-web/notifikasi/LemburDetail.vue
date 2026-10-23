<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Bell, Check, Download, X } from 'lucide-vue-next';
import type { LemburRow } from '../../absensi-mobile/lembur/types';
import '../approval-workflow.css';
import { date, labels } from '../cuti/formatters';
import FlashMessage from '../setting/components/FlashMessage.vue';
import { duration } from './types';

type WebLembur = Omit<LemburRow, 'start_time' | 'end_time' | 'can_cancel' | 'attachments'> & {
    attachments: { name: string; size: number; url: string }[];
};
const props = defineProps<{ lembur: WebLembur }>();
const decisionForm = useForm({ decision: 'approved', note: '' });
const decide = (decision: 'approved' | 'rejected') => {
    if (!props.lembur.can_decide || decisionForm.processing) return;
    decisionForm.decision = decision;
    decisionForm.post(`/absensi/admin-web/notifikasi/pengajuan/lembur/${props.lembur.id}/decision`, {
        preserveScroll: true,
        onSuccess: () => decisionForm.reset(),
    });
};
</script>

<template>
    <Head :title="`Pengajuan #LEMBUR-${lembur.id}`" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Notifikasi', href: '/absensi/admin-web/notifikasi/butuh-approval' },
            { title: `#LEMBUR-${lembur.id}`, href: '' },
        ]"
    >
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><Bell /> Pengajuan #LEMBUR-{{ lembur.id }}</span>
                    <h1>{{ lembur.request_kind === 'usage' ? 'Penggunaan waktu lembur' : 'Pengajuan lembur' }}</h1>
                    <p>{{ lembur.name }} · {{ lembur.unit }} · {{ lembur.position }}</p>
                </div>
                <span class="al-status" :class="lembur.status">{{ labels[lembur.status] }}</span>
            </section>
            <section class="al-panel al-form">
                <div class="al-form-body">
                    <div class="al-info">
                        <strong
                            >{{ date(lembur.date) }} · {{ duration(lembur.duration_minutes) }}
                            {{ lembur.request_kind === 'usage' ? 'penggunaan' : 'lembur bersih' }}</strong
                        ><br />
                        Shift {{ lembur.shift_name }}: {{ lembur.shift_start }} s.d. {{ lembur.shift_end }}<br />
                        <template v-if="lembur.request_kind === 'usage'"
                            >Keperluan: {{ lembur.usage_type === 'late_arrival' ? 'Telat masuk' : 'Pulang cepat' }}<br />Waktu penggunaan:
                            {{ lembur.usage_start }} s.d. {{ lembur.usage_end }}<br
                        /></template>
                        <template v-else>Kompensasi: {{ lembur.compensation === 'pay' ? 'Upah lembur' : 'Pengganti waktu libur' }}<br /></template>
                        Diajukan {{ lembur.created_at }}
                    </div>
                    <div v-if="lembur.request_kind === 'usage'" class="al-info">
                        <strong>Sumber saldo lembur</strong>
                        <p v-for="source in lembur.sources" :key="source.id">
                            #LEMBUR-{{ source.id }} · {{ date(source.date) }} · {{ duration(source.minutes) }}
                        </p>
                    </div>
                    <div v-else class="lembur-segments">
                        <div v-for="part in ['before', 'after'] as const" :key="part" class="al-info">
                            <strong>{{ part === 'before' ? 'Sebelum shift' : 'Setelah shift' }}</strong>
                            <template v-if="lembur[`${part}_minutes`]">
                                <p>{{ lembur[`${part}_start`] }} s.d. {{ lembur[`${part}_end`] }}</p>
                                <p>{{ duration(lembur[`${part}_minutes`]) }} · Istirahat {{ duration(lembur[`${part}_break_minutes`]) }}</p>
                            </template>
                            <p v-else>Tidak diajukan</p>
                        </div>
                    </div>
                    <div>
                        <div class="al-section-label">
                            <strong>{{ lembur.request_kind === 'usage' ? 'Alasan penggunaan' : 'Alasan lembur' }}</strong>
                        </div>
                        <p class="al-reason">{{ lembur.reason }}</p>
                    </div>
                    <div v-if="lembur.attachments.length">
                        <div class="al-section-label"><strong>Lampiran</strong></div>
                        <ul class="lembur-attachments">
                            <li v-for="file in lembur.attachments" :key="file.url">
                                <a :href="file.url"><Download /> {{ file.name }} ({{ Math.ceil(file.size / 1024) }} KB)</a>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <div class="al-section-label"><strong>Progres persetujuan</strong></div>
                        <ol class="al-timeline">
                            <li v-for="step in lembur.approvals" :key="step.level" :class="step.status">
                                <span class="al-timeline-number">
                                    <Check v-if="step.status === 'approved'" /><X v-else-if="step.status === 'rejected'" />
                                    <template v-else>{{ step.level }}</template>
                                </span>
                                <div>
                                    <strong>{{ step.name }}</strong>
                                    <small
                                        >Tingkat {{ step.level }} · {{ labels[step.status]
                                        }}<template v-if="step.decided_at"> · {{ step.decided_at }}</template></small
                                    >
                                    <p v-if="step.note" class="al-reason">{{ step.note }}</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                    <div v-if="lembur.can_decide" class="al-review">
                        <h3>Persetujuan tingkat {{ lembur.current_level }}</h3>
                        <p>Periksa waktu, durasi, dan lampiran sebelum memberikan keputusan.</p>
                        <label class="al-field">
                            <span>Catatan <small>(wajib untuk penolakan)</small></span>
                            <textarea v-model="decisionForm.note" rows="3" maxlength="1000" :disabled="decisionForm.processing" />
                            <small v-if="decisionForm.errors.note" class="al-error" role="alert">{{ decisionForm.errors.note }}</small>
                        </label>
                    </div>
                    <p v-if="decisionForm.errors.decision" class="al-error" role="alert">{{ decisionForm.errors.decision }}</p>
                </div>
                <footer class="al-form-footer">
                    <Link href="/absensi/admin-web/notifikasi/butuh-approval" class="btn btn-outline-secondary">Kembali ke notifikasi</Link>
                    <template v-if="lembur.can_decide">
                        <button type="button" class="btn btn-outline-danger" :disabled="decisionForm.processing" @click="decide('rejected')">
                            Tolak pengajuan
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="decisionForm.processing" @click="decide('approved')">
                            {{ decisionForm.processing ? 'Memproses...' : `Setujui tingkat ${lembur.current_level}` }}
                        </button>
                    </template>
                </footer>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.lembur-segments {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.lembur-segments p {
    margin: 8px 0 0;
}
.lembur-attachments {
    padding-left: 20px;
    overflow-wrap: anywhere;
}
.lembur-attachments a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.lembur-attachments svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
@media (max-width: 640px) {
    .lembur-segments {
        grid-template-columns: 1fr;
    }
}
</style>
