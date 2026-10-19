<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, X } from 'lucide-vue-next';
import { watch } from 'vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import { date, days, labels } from '../formatters';
import type { CutiRow } from '../types';

const props = defineProps<{ request: CutiRow | null }>();
const emit = defineEmits<{ close: [] }>();
const decisionForm = useForm({ decision: 'approved', note: '' });
watch(
    () => props.request,
    () => {
        decisionForm.reset();
        decisionForm.clearErrors();
    },
);
const close = () => {
    if (!decisionForm.processing) emit('close');
};
const decide = (decision: 'approved' | 'rejected') => {
    if (!props.request?.can_decide || decisionForm.processing) return;
    decisionForm.decision = decision;
    decisionForm.post(`/absensi/admin-web/cuti/${props.request.id}/decision`, { preserveScroll: true, onSuccess: () => emit('close') });
};
</script>

<template>
    <CrudModal
        :open="!!request"
        :title="`Detail pengajuan #CUTI-${request?.id ?? ''}`"
        description="Riwayat persetujuan cuti secara berurutan."
        @close="close"
    >
        <div v-if="request" class="al-form">
            <div class="al-form-body">
                <div class="al-detail-heading">
                    <div>
                        <h3>{{ request.nama_pegawai }}</h3>
                        <p>{{ request.unit }} · {{ request.jabatan }}</p>
                    </div>
                    <span class="al-status" :class="request.status">{{ labels[request.status] }}</span>
                </div>
                <div class="al-info">
                    <strong>{{ request.nama_cuti || 'Pengajuan sebelum master cuti' }}</strong
                    ><br />
                    <strong>{{ date(request.tanggal_mulai) }} — {{ date(request.tanggal_selesai) }}</strong
                    ><br />
                    {{ request.jumlah_hari ?? days(request.tanggal_mulai, request.tanggal_selesai) }} hari kalender · Diajukan
                    {{ request.created_at }}
                </div>
                <div>
                    <div class="al-section-label"><strong>Alasan cuti</strong></div>
                    <p class="al-reason">{{ request.alasan }}</p>
                </div>
                <div>
                    <div class="al-section-label"><strong>Progres persetujuan</strong></div>
                    <ol class="al-timeline">
                        <li v-for="step in request.approvals" :key="step.level" :class="step.status">
                            <span class="al-timeline-number">
                                <Check v-if="step.status === 'approved'" />
                                <X v-else-if="step.status === 'rejected'" />
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
                <div v-if="request.can_decide" class="al-review">
                    <h3>Persetujuan tingkat {{ request.current_level }}</h3>
                    <p>Periksa tanggal dan alasan sebelum memberikan keputusan.</p>
                    <label class="al-field">
                        <span>Catatan <small>(wajib untuk penolakan)</small></span>
                        <textarea
                            v-model="decisionForm.note"
                            rows="3"
                            maxlength="1000"
                            placeholder="Tambahkan catatan atau alasan penolakan..."
                            :disabled="decisionForm.processing"
                        />
                        <small v-if="decisionForm.errors.note" class="al-error">{{ decisionForm.errors.note }}</small>
                    </label>
                </div>
                <p v-if="decisionForm.errors.decision" class="al-error" role="alert">{{ decisionForm.errors.decision }}</p>
            </div>
            <footer class="al-form-footer">
                <button type="button" class="btn btn-outline-secondary" :disabled="decisionForm.processing" @click="close">Tutup</button>
                <template v-if="request.can_decide">
                    <button type="button" class="btn btn-outline-danger" :disabled="decisionForm.processing" @click="decide('rejected')">
                        Tolak pengajuan
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="decisionForm.processing" @click="decide('approved')">
                        {{ decisionForm.processing ? 'Memproses...' : `Setujui tingkat ${request.current_level}` }}
                    </button>
                </template>
            </footer>
        </div>
    </CrudModal>
</template>
