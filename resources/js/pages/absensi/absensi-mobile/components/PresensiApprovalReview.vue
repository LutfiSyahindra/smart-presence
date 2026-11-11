<script setup lang="ts">
import type { PresensiApprovalItem } from '@/components/PresensiApprovalContent.vue';
import { useForm } from '@inertiajs/vue3';
import { Camera, Check, Clock3, ExternalLink, FileText, MapPin, ShieldCheck, X } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { date } from '../../admin-web/cuti/formatters';
import { actionLabel, initials, presensiLabels } from '../presensi-approval';

const props = defineProps<{ item: PresensiApprovalItem }>();
const emit = defineEmits<{ processing: [value: boolean]; decided: [] }>();
const base = '/absensi/mobile/presensi/persetujuan';
const decision = useForm({ decision: 'approved' as 'approved' | 'rejected', note: '' });
const noteInput = ref<HTMLTextAreaElement | null>(null);
const photoFailed = ref(false);

function toggleDecision() {
    if (decision.processing) return;
    decision.decision = decision.decision === 'approved' ? 'rejected' : 'approved';
    decision.clearErrors();
    if (decision.decision === 'rejected') nextTick(() => noteInput.value?.focus());
}
function decide() {
    if (!props.item.can_decide || decision.processing) return;
    decision.clearErrors();
    if (decision.decision === 'rejected' && !decision.note.trim()) {
        decision.setError('note', 'Alasan penolakan wajib diisi.');
        noteInput.value?.focus();
        return;
    }
    decision
        .transform((data) => ({ ...data, note: data.note.trim() }))
        .post(`${base}/${props.item.id}/decision`, {
            preserveScroll: true,
            onSuccess: () => {
                decision.reset();
                emit('decided');
            },
        });
}
watch(
    () => decision.processing,
    (value) => emit('processing', value),
    { flush: 'sync' },
);
onBeforeUnmount(() => emit('processing', false));
</script>

<template>
    <article class="presensi-review">
        <div class="detail-person">
            <span class="person-avatar">{{ initials(item.name) }}</span>
            <div>
                <h3>{{ item.name }}</h3>
                <p>{{ [item.position, item.unit].filter(Boolean).join(' · ') }}</p>
            </div>
        </div>
        <div class="detail-leave">
            <span><MapPin />{{ actionLabel(item.action) }} di luar radius</span>
            <span class="status-badge" :class="item.status">{{ presensiLabels[item.status] }}</span>
            <p><Clock3 />{{ item.recorded_at }} WIB</p>
            <p>Tanggal shift: {{ date(item.date) }}</p>
        </div>
        <section class="reason-block">
            <h3><FileText />Alasan di luar radius</h3>
            <p>{{ item.reason }}</p>
        </section>
        <section class="location-block">
            <h3><MapPin />Bukti lokasi</h3>
            <div class="location-card">
                <strong>{{ item.location.name || 'Lokasi presensi' }}</strong>
                <p>{{ item.location.address || 'Alamat tidak tersedia.' }}</p>
                <dl>
                    <div>
                        <dt>Radius kantor</dt>
                        <dd>{{ item.location.radius_meters != null ? item.location.radius_meters + ' m' : 'Tidak tersedia' }}</dd>
                    </div>
                    <div>
                        <dt>Akurasi GPS</dt>
                        <dd>{{ item.location.accuracy != null ? item.location.accuracy + ' m' : 'Tidak tersedia' }}</dd>
                    </div>
                </dl>
                <a
                    :href="`https://www.google.com/maps?q=${item.location.latitude},${item.location.longitude}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Lihat koordinat lokasi<ExternalLink aria-hidden="true"
                /></a>
            </div>
        </section>
        <section class="photo-block">
            <h3><Camera />Foto verifikasi wajah</h3>
            <div v-if="photoFailed" class="photo-fallback" role="status">
                <Camera />
                <p>Foto belum dapat dimuat.</p>
                <button type="button" @click="photoFailed = false">Coba lagi</button>
            </div>
            <img v-else :src="`${base}/${item.id}/photo`" alt="Foto verifikasi wajah saat presensi" loading="lazy" @error="photoFailed = true" />
        </section>
        <section class="approval-flow">
            <h3><ShieldCheck />Alur persetujuan</h3>
            <ol>
                <li v-for="step in item.approvals" :key="step.level" :class="step.status">
                    <span class="step-number"
                        ><Check v-if="step.status === 'approved'" /><X v-else-if="step.status === 'rejected'" /><span v-else>{{
                            step.level
                        }}</span></span
                    >
                    <div>
                        <strong>{{ step.name }}</strong
                        ><small
                            >Tingkat {{ step.level }} · {{ presensiLabels[step.status]
                            }}<template v-if="step.decided_at"> · {{ step.decided_at }} WIB</template></small
                        >
                        <p v-if="step.note">{{ step.note }}</p>
                    </div>
                </li>
            </ol>
        </section>
        <p class="review-hint">Waktu dan bukti presensi tetap tersimpan. Persetujuan berlaku setelah seluruh pemberi persetujuan menyetujui.</p>
        <form v-if="item.can_decide" class="decision-form" @submit.prevent="decide">
            <label :for="'presensi-note-' + item.id"
                >{{ decision.decision === 'rejected' ? 'Alasan penolakan' : 'Catatan (opsional)'
                }}<span>{{
                    decision.decision === 'rejected'
                        ? 'Wajib diisi agar pemohon memahami keputusan Anda.'
                        : 'Keputusan disimpan pada tingkat persetujuan ' + item.current_level + '.'
                }}</span></label
            >
            <textarea
                :id="'presensi-note-' + item.id"
                ref="noteInput"
                v-model="decision.note"
                :required="decision.decision === 'rejected'"
                maxlength="1000"
                rows="3"
                :disabled="decision.processing"
                :aria-invalid="Boolean(decision.errors.note)"
                :aria-describedby="decision.errors.note ? 'presensi-note-error-' + item.id : undefined"
                :placeholder="decision.decision === 'rejected' ? 'Tuliskan alasan penolakan...' : 'Tambahkan catatan bila diperlukan...'"
            />
            <p
                v-for="(error, key) in decision.errors"
                :id="key === 'note' ? 'presensi-note-error-' + item.id : undefined"
                :key="key"
                class="form-error"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="sheet-actions review-actions">
                <button
                    type="button"
                    class="secondary-button"
                    :class="{ 'reject-button': decision.decision === 'approved' }"
                    :disabled="decision.processing"
                    @click="toggleDecision"
                >
                    {{ decision.decision === 'approved' ? 'Tolak' : 'Kembali' }}
                </button>
                <button
                    type="submit"
                    class="primary-button"
                    :class="{ 'reject-submit': decision.decision === 'rejected' }"
                    :disabled="decision.processing"
                >
                    <Check v-if="decision.decision === 'approved'" /><X v-else />{{
                        decision.processing ? 'Menyimpan...' : decision.decision === 'rejected' ? 'Tolak pengajuan' : 'Setujui pengajuan'
                    }}
                </button>
            </div>
        </form>
    </article>
</template>

<style scoped src="../approval-workspace.css"></style>
<style scoped>
.presensi-review {
    color: #243d32;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}
.detail-leave {
    gap: 10px;
}
.detail-leave > .status-badge {
    flex: 0 1 auto;
    padding: 6px 9px;
    border-radius: 8px;
    background: #f8efd9;
    color: #9b8040;
    font-size: 9px;
    line-height: 1.6;
}
.detail-leave > .status-badge.approved {
    background: #e5efdc;
    color: #65804c;
}
.detail-leave > .status-badge.rejected {
    background: #f7e8e1;
    color: #ae735b;
}
.location-block,
.photo-block {
    margin-top: 24px;
}
.location-block h3,
.photo-block h3 {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 12px;
    color: #49643c;
    font-size: 12px;
    font-weight: 700;
}
h3 > svg {
    width: 15px;
    height: 15px;
    color: #8aa16e;
}
.location-card {
    padding: 15px;
    border: 1px solid #e5ecd9;
    border-radius: 15px;
    background: #f3f7ec;
}
.location-card > strong {
    font-size: 12px;
    color: #49643c;
}
.location-card > p {
    margin: 8px 0 14px;
    color: #6e8260;
    font-size: 12px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.location-card dl {
    display: flex;
    flex-wrap: wrap;
    gap: 14px 28px;
    margin: 0;
}
.location-card dt {
    color: #88977a;
    font-size: 10px;
}
.location-card dd {
    margin: 5px 0 0;
    color: #526d43;
    font-size: 12px;
    font-weight: 700;
}
.location-card a {
    display: flex;
    align-items: center;
    gap: 7px;
    min-height: 44px;
    margin-top: 10px;
    color: #527342;
    font-size: 11px;
    font-weight: 700;
}
.location-card a svg {
    width: 13px;
    height: 13px;
}
.photo-block img {
    display: block;
    width: 100%;
    max-height: 300px;
    object-fit: contain;
    border: 1px solid #e5ecd9;
    border-radius: 16px;
    background: #f3f7ec;
}
.photo-fallback {
    padding: 24px;
    border: 1px dashed #dfe6d6;
    border-radius: 16px;
    color: #88977a;
    font-size: 12px;
    text-align: center;
}
.photo-fallback > svg {
    width: 24px;
    height: 24px;
    margin: auto;
}
.photo-fallback p {
    margin: 10px 0;
}
.photo-fallback button {
    min-height: 44px;
    padding: 0 14px;
    border-radius: 10px;
    background: #edf3e5;
    color: #526d43;
    font-weight: 700;
}
.approval-flow li > div {
    min-width: 0;
    overflow-wrap: anywhere;
}
.rejected .step-number {
    border-color: #ecdcd5;
    background: #fcf6f3;
    color: #ae735b;
}
.review-hint {
    margin: 24px 0 0;
    color: #88977a;
    font-size: 11px;
    line-height: 1.8;
}
.review-actions {
    margin-top: 16px;
}
</style>
