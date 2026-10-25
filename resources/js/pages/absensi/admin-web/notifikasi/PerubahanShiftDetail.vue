<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRightLeft, Check, X } from 'lucide-vue-next';
import '../approval-workflow.css';
import { date, labels } from '../cuti/formatters';
import FlashMessage from '../setting/components/FlashMessage.vue';
import type { ShiftSnapshot } from './types';

const props = defineProps<{
    shiftRequest: {
        id: number;
        name: string;
        position: string;
        unit: string;
        date: string;
        status: string;
        original_shift: ShiftSnapshot;
        requested_shift: ShiftSnapshot;
        reason: string;
        current_level: number | null;
        created_at: string;
        can_decide: boolean;
        approvals: { level: number; name: string; status: string; note: string | null; decided_at: string | null }[];
    };
}>();
const form = useForm({ decision: 'approved', note: '' });
function decide(decision: 'approved' | 'rejected') {
    if (!props.shiftRequest.can_decide || form.processing) return;
    form.clearErrors();
    if (decision === 'rejected' && !form.note.trim()) {
        form.setError('note', 'Alasan penolakan wajib diisi.');
        return;
    }
    form.decision = decision;
    form.transform((data) => ({ ...data, note: data.note.trim() })).post(
        `/absensi/admin-web/notifikasi/pengajuan/perubahan-shift/${props.shiftRequest.id}/decision`,
        {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        },
    );
}
</script>

<template>
    <Head :title="`Perubahan shift #SHIFT-${shiftRequest.id}`" />
    <AppLayout
        :breadcrumbs="[
            { title: 'Notifikasi', href: '/absensi/admin-web/notifikasi/butuh-approval?request_type=perubahan-shift' },
            { title: `#SHIFT-${shiftRequest.id}`, href: '' },
        ]"
    >
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><ArrowRightLeft />Pengajuan #SHIFT-{{ shiftRequest.id }}</span>
                    <h1>Perubahan shift</h1>
                    <p>{{ shiftRequest.name }} · {{ shiftRequest.unit }} · {{ shiftRequest.position }}</p>
                </div>
                <span class="al-status" :class="shiftRequest.status">{{ labels[shiftRequest.status] }}</span>
            </section>
            <section class="al-panel al-form">
                <div class="al-form-body">
                    <div class="al-info">
                        <strong>{{ date(shiftRequest.date) }}</strong>
                        <p>
                            Diajukan {{ shiftRequest.created_at }} WIB. Perubahan berlaku hanya untuk tanggal ini setelah seluruh approver menyetujui.
                        </p>
                    </div>
                    <div class="shift-grid">
                        <div v-for="(shift, index) in [shiftRequest.original_shift, shiftRequest.requested_shift]" :key="index" class="al-info">
                            <small>{{ index ? 'SHIFT TUJUAN' : 'SHIFT SAAT INI' }}</small>
                            <h3>{{ shift.name }}</h3>
                            <p>{{ shift.start_time }} – {{ shift.end_time }}{{ shift.next_day ? ' (+1 hari)' : '' }}</p>
                        </div>
                    </div>
                    <div>
                        <div class="al-section-label"><strong>Alasan perubahan</strong></div>
                        <p class="al-reason">{{ shiftRequest.reason }}</p>
                    </div>
                    <div>
                        <div class="al-section-label"><strong>Progres persetujuan</strong></div>
                        <ol class="al-timeline">
                            <li v-for="step in shiftRequest.approvals" :key="step.level" :class="step.status">
                                <span class="al-timeline-number"
                                    ><Check v-if="step.status === 'approved'" /><X v-else-if="step.status === 'rejected'" /><template v-else>{{
                                        step.level
                                    }}</template></span
                                >
                                <div>
                                    <strong>{{ step.name }}</strong
                                    ><small
                                        >Tingkat {{ step.level }} · {{ labels[step.status]
                                        }}<template v-if="step.decided_at"> · {{ step.decided_at }} WIB</template></small
                                    >
                                    <p v-if="step.note" class="al-reason">{{ step.note }}</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                    <div v-if="shiftRequest.can_decide" class="al-review">
                        <h3>Persetujuan tingkat {{ shiftRequest.current_level }}</h3>
                        <p>Periksa tanggal dan shift tujuan sebelum memberikan keputusan.</p>
                        <label class="al-field"
                            ><span>Catatan <small>(wajib untuk penolakan)</small></span
                            ><textarea v-model="form.note" rows="3" maxlength="1000" :disabled="form.processing" /><small
                                v-if="form.errors.note"
                                class="al-error"
                                role="alert"
                                >{{ form.errors.note }}</small
                            ></label
                        >
                    </div>
                    <p v-if="form.errors.decision" class="al-error" role="alert">{{ form.errors.decision }}</p>
                </div>
                <footer class="al-form-footer">
                    <Link href="/absensi/admin-web/notifikasi/butuh-approval?request_type=perubahan-shift" class="btn btn-outline-secondary"
                        >Kembali ke notifikasi</Link
                    ><template v-if="shiftRequest.can_decide"
                        ><button type="button" class="btn btn-outline-danger" :disabled="form.processing" @click="decide('rejected')">
                            Tolak pengajuan</button
                        ><button type="button" class="btn btn-primary" :disabled="form.processing" @click="decide('approved')">
                            {{ form.processing ? 'Memproses…' : `Setujui tingkat ${shiftRequest.current_level}` }}
                        </button></template
                    >
                </footer>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.shift-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.shift-grid h3 {
    margin: 12px 0;
    font-size: 19px;
    overflow-wrap: anywhere;
}
.shift-grid p {
    margin: 0;
}
@media (max-width: 640px) {
    .shift-grid {
        grid-template-columns: 1fr;
    }
}
</style>
