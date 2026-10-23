<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    Check,
    CheckCheck,
    ChevronRight,
    Clock3,
    Coffee,
    Eye,
    FileText,
    Info,
    ShieldCheck,
    Sunrise,
    Sunset,
    Timer,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { lemburDuration, lemburStatuses, type LemburAttachment, type LemburRow } from '../lembur/types';
import CutiSheet from './CutiSheet.vue';
import LemburAttachmentPreview from './LemburAttachmentPreview.vue';

const props = defineProps<{ request: LemburRow | null }>();
const emit = defineEmits<{ close: []; busy: [value: boolean] }>();
const decision = useForm({ decision: 'approved', note: '' });
const cancellation = useForm({});
const confirmingCancel = ref(false);
const previewFile = ref<LemburAttachment | null>(null);
const busy = computed(() => decision.processing || cancellation.processing);
const decisionErrors = computed(() => decision.errors as Record<string, string | undefined>);
const cancelErrors = computed(() => cancellation.errors as Record<string, string | undefined>);
const stepLabels: Record<string, string> = {
    pending: 'Menunggu keputusan',
    waiting: 'Menunggu giliran',
    approved: 'Disetujui',
    rejected: 'Ditolak',
    skipped: 'Dilewati',
};
defineExpose({
    handleBack() {
        if (previewFile.value) {
            previewFile.value = null;
            return true;
        }
        if (busy.value) return true;
        if (confirmingCancel.value) {
            confirmingCancel.value = false;
            return true;
        }
        return false;
    },
});
async function confirmCancellation() {
    confirmingCancel.value = true;
    await nextTick();
    document.querySelector<HTMLElement>('.cancel-confirmation')?.scrollIntoView({ block: 'nearest' });
}
watch(busy, (value) => emit('busy', value));
watch(
    () => props.request?.id,
    () => {
        decision.reset();
        decision.clearErrors();
        cancellation.clearErrors();
        confirmingCancel.value = false;
        previewFile.value = null;
    },
);
function decide() {
    if (!props.request?.can_decide || busy.value) return;
    if (decision.decision === 'rejected' && !decision.note.trim()) {
        decision.setError('note', 'Alasan penolakan wajib diisi.');
        return;
    }
    decision
        .transform((data) => ({ ...data, note: data.note.trim() }))
        .post(route('absensi.mobile.lembur.decide', { lemburRequest: props.request.id }), {
            preserveScroll: true,
            onSuccess: () => {
                decision.reset();
                emit('close');
            },
        });
}
function cancel() {
    if (!props.request?.can_cancel || !confirmingCancel.value || busy.value) return;
    cancellation.post(route('absensi.mobile.lembur.cancel', { lemburRequest: props.request.id }), {
        preserveScroll: true,
        onSuccess: () => {
            confirmingCancel.value = false;
            emit('close');
        },
    });
}
</script>

<template>
    <CutiSheet
        :open="!!request"
        :title="request?.request_kind === 'usage' ? 'Penggunaan waktu lembur' : 'Detail lembur'"
        :description="request ? `Pengajuan #${request.id} · ${request.created_at}` : ''"
        :busy="busy"
        @close="emit('close')"
    >
        <template v-if="request">
            <section class="detail-hero">
                <div>
                    <span class="eyebrow">{{ request.request_kind === 'usage' ? 'WAKTU YANG DIGUNAKAN' : 'TOTAL LEMBUR BERSIH' }}</span
                    ><strong>{{ lemburDuration(request.duration_minutes) }}</strong>
                </div>
                <span class="status-label" :class="request.status">{{
                    lemburStatuses.find((status) => status.value === request!.status)?.label
                }}</span>
            </section>
            <div class="employee-detail">
                <strong>{{ request.name }}</strong>
                <p>{{ request.position }} · {{ request.unit }}</p>
            </div>
            <dl class="detail-meta">
                <div>
                    <dt><CalendarDays />{{ request.request_kind === 'usage' ? 'Tanggal penggunaan' : 'Tanggal lembur' }}</dt>
                    <dd>{{ new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' }).format(new Date(`${request.date}T12:00:00`)) }}</dd>
                </div>
                <div>
                    <dt><Clock3 />{{ request.shift_name }}</dt>
                    <dd>{{ request.shift_start }}<ChevronRight />{{ request.shift_end }}</dd>
                </div>
                <div>
                    <dt><Wallet />{{ request.request_kind === 'usage' ? 'Keperluan' : 'Kompensasi' }}</dt>
                    <dd>
                        {{
                            request.request_kind === 'usage'
                                ? request.usage_type === 'late_arrival'
                                    ? 'Telat masuk'
                                    : 'Pulang cepat'
                                : request.compensation === 'pay'
                                  ? 'Uang lembur'
                                  : 'Cuti pengganti'
                        }}
                    </dd>
                </div>
            </dl>
            <section v-if="request.request_kind === 'usage'" class="reason-detail">
                <h3><Clock3 />Waktu penggunaan</h3>
                <p>{{ request.usage_start }} s.d. {{ request.usage_end }}</p>
                <p>
                    {{
                        request.status === 'approved'
                            ? 'Sudah berlaku pada perhitungan presensi sesuai shift ini.'
                            : request.status === 'pending'
                              ? 'Saldo dicadangkan. Izin berlaku setelah seluruh persetujuan selesai.'
                              : 'Saldo penggunaan telah dikembalikan.'
                    }}
                </p>
                <h3>Sumber saldo</h3>
                <p v-for="source in request.sources" :key="source.id">
                    Lembur #{{ source.id }} · {{ source.date }} · {{ lemburDuration(source.minutes) }}
                </p>
            </section>
            <div v-else class="segment-grid">
                <section v-for="part in ['before', 'after'] as const" :key="part" class="segment">
                    <h3><component :is="part === 'before' ? Sunrise : Sunset" />{{ part === 'before' ? 'Sebelum shift' : 'Setelah shift' }}</h3>
                    <template v-if="request[`${part}_minutes`]"
                        ><strong>{{ lemburDuration(request[`${part}_minutes`] - request[`${part}_break_minutes`]) }} <small>bersih</small></strong>
                        <p>{{ request[`${part}_start`] }}<br />s.d. {{ request[`${part}_end`] }}</p>
                        <small class="break-info"><Coffee />Istirahat {{ lemburDuration(request[`${part}_break_minutes`]) }}</small></template
                    >
                    <p v-else class="no-segment">Tidak diajukan</p>
                </section>
            </div>
            <section class="reason-detail">
                <h3><FileText />{{ request.request_kind === 'usage' ? 'Alasan penggunaan' : 'Alasan lembur' }}</h3>
                <p>{{ request.reason }}</p>
            </section>
            <section v-if="request.attachments.length" class="attachments">
                <h3>
                    Lampiran <small>{{ request.attachments.length }} file</small>
                </h3>
                <button
                    v-for="(file, index) in request.attachments"
                    :key="index"
                    type="button"
                    :aria-label="`Pratinjau lampiran ${file.name}`"
                    @click="previewFile = file"
                >
                    <FileText /><span
                        ><strong>{{ file.name }}</strong
                        ><small>{{ (file.size / 1024 / 1024).toFixed(2) }} MB</small></span
                    ><Eye />
                </button>
            </section>
            <section class="approval-detail">
                <h3><ShieldCheck />Alur persetujuan</h3>
                <ol>
                    <li v-for="step in request.approvals" :key="step.level" :class="step.status">
                        <span class="step-number"
                            ><Check v-if="step.status === 'approved'" /><X v-else-if="step.status === 'rejected'" /><template v-else>{{
                                step.level
                            }}</template></span
                        >
                        <div>
                            <strong>{{ step.name }}</strong
                            ><small
                                >{{ stepLabels[step.status] || step.status
                                }}<template v-if="step.decided_at"> · {{ step.decided_at }}</template></small
                            >
                            <p v-if="step.note">{{ step.note }}</p>
                        </div>
                    </li>
                </ol>
            </section>
            <form v-if="request.can_decide" id="lembur-decision" class="decision-form" @submit.prevent="decide">
                <h3>Keputusan Anda</h3>
                <div class="decision-options" role="group" aria-label="Keputusan pengajuan">
                    <button
                        type="button"
                        :disabled="busy"
                        :class="{ selected: decision.decision === 'approved' }"
                        :aria-pressed="decision.decision === 'approved'"
                        @click="
                            decision.decision = 'approved';
                            decision.clearErrors();
                        "
                    >
                        <CheckCheck />Setujui</button
                    ><button
                        type="button"
                        :disabled="busy"
                        :class="{ selected: decision.decision === 'rejected', rejecting: decision.decision === 'rejected' }"
                        :aria-pressed="decision.decision === 'rejected'"
                        @click="
                            decision.decision = 'rejected';
                            decision.clearErrors();
                        "
                    >
                        <X />Tolak
                    </button>
                </div>
                <label for="lembur-decision-note">{{ decision.decision === 'rejected' ? 'Alasan penolakan *' : 'Catatan (opsional)' }}</label
                ><textarea
                    id="lembur-decision-note"
                    v-model="decision.note"
                    rows="3"
                    maxlength="1000"
                    :required="decision.decision === 'rejected'"
                    :disabled="busy"
                    :aria-invalid="!!decisionErrors.note"
                    :aria-describedby="decisionErrors.note ? 'decision-note-error' : undefined"
                    placeholder="Tuliskan catatan untuk pemohon…"
                ></textarea>
                <p v-if="decisionErrors.note" id="decision-note-error" class="error-note" role="alert">{{ decisionErrors.note }}</p>
                <p v-if="decisionErrors.decision" class="error-note" role="alert">{{ decisionErrors.decision }}</p>
                <p class="approval-hint">
                    <Info />{{
                        decision.decision === 'rejected'
                            ? 'Pengajuan dihentikan dan pemohon menerima catatan Anda.'
                            : 'Persetujuan diteruskan ke tahap berikutnya jika masih ada.'
                    }}
                </p>
            </form>
            <div v-if="confirmingCancel" class="cancel-confirmation" role="alert">
                <strong>Batalkan pengajuan ini?</strong>
                <p>Proses persetujuan dihentikan. Anda dapat membuat pengajuan baru jika diperlukan.</p>
                <div>
                    <button type="button" :disabled="busy" @click="confirmingCancel = false">Kembali</button
                    ><button type="button" class="danger-button" :disabled="busy" @click="cancel">
                        {{ cancellation.processing ? 'Membatalkan…' : 'Ya, batalkan' }}
                    </button>
                </div>
            </div>
            <p v-if="cancelErrors.cancel" class="error-note" role="alert">{{ cancelErrors.cancel }}</p>
        </template>
        <LemburAttachmentPreview v-if="previewFile" :file="previewFile" @close="previewFile = null" />
        <template v-if="request?.can_decide || request?.can_cancel" #footer
            ><button
                v-if="request?.can_decide"
                type="submit"
                form="lembur-decision"
                class="primary-button"
                :class="{ rejecting: decision.decision === 'rejected' }"
                :disabled="busy"
            >
                <ShieldCheck />{{
                    decision.processing ? 'Menyimpan keputusan…' : decision.decision === 'approved' ? 'Simpan persetujuan' : 'Tolak pengajuan'
                }}</button
            ><button
                v-else-if="request?.can_cancel && !confirmingCancel"
                type="button"
                class="cancel-button"
                :disabled="busy"
                @click="confirmCancellation"
            >
                Batalkan pengajuan
            </button>
            <p v-else class="footer-note"><Timer />Konfirmasi pembatalan pada bagian di atas.</p></template
        >
    </CutiSheet>
</template>

<style scoped>
button,
textarea {
    font: inherit;
}
.detail-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    border: 1px solid #294e3c;
    border-radius: 18px;
    padding: 18px;
    background: radial-gradient(ellipse at 100% 0, #6c815a70, transparent 75%), #254c3e;
    color: #fff;
}
.eyebrow {
    display: block;
    color: #c7c7a3;
    font-size: 8px;
    font-weight: 750;
    letter-spacing: 0.14em;
}
.detail-hero strong {
    display: block;
    margin-top: 8px;
    color: #ead39e;
    font-size: 32px;
    font-weight: 700;
    letter-spacing: -0.05em;
    line-height: 1.2;
}
.status-label {
    align-self: flex-start;
    border-radius: 8px;
    padding: 6px 8px;
    color: #e0d2a3;
    background: #ebd59918;
    border: 1px solid #ebd59924;
    font-size: 9px;
    font-weight: 650;
}
.status-label.approved {
    background: #cfe1b524;
    color: #d6e8be;
}
.status-label.rejected {
    background: #e9aa9029;
    color: #efc0a7;
}
.status-label.cancelled {
    background: #d5dbce18;
    color: #c2cbbb;
}
.employee-detail {
    margin: 22px 0 16px;
}
.employee-detail strong {
    font-size: 16px;
    font-weight: 750;
    letter-spacing: -0.035em;
    overflow-wrap: anywhere;
}
.employee-detail p {
    margin: 6px 0 0;
    color: #8b9b7d;
    font-size: 11px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.detail-meta {
    margin: 0;
    padding: 15px;
    border: 1px solid #e2e8d8;
    border-radius: 15px;
    background: #f7f9f1;
}
.detail-meta > div + div {
    border-top: 1px solid #e6ebdd;
    margin-top: 12px;
    padding-top: 12px;
}
.detail-meta dt {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #94a182;
    font-size: 10px;
}
.detail-meta dt svg {
    width: 13px;
    height: 13px;
}
.detail-meta dd {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px;
    margin: 7px 0 0;
    color: #4f6940;
    font-size: 11px;
    line-height: 1.8;
    font-weight: 650;
}
.detail-meta dd svg {
    width: 12px;
    height: 12px;
    color: #9baa85;
}
.segment-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
    margin-top: 16px;
}
.segment {
    min-width: 0;
    border: 1px solid #e3e8d7;
    border-radius: 14px;
    padding: 13px 11px;
    background: #fcfdf8;
}
.segment h3 {
    display: flex;
    align-items: center;
    gap: 5px;
    margin: 0 0 13px;
    color: #8b956e;
    font-size: 10px;
    font-weight: 700;
}
.segment h3 svg {
    width: 14px;
    height: 14px;
    color: #aa935f;
}
.segment > strong {
    font-size: 18px;
    font-weight: 700;
    color: #536d41;
    letter-spacing: -0.035em;
}
.segment > strong small {
    font-size: 8px;
    font-weight: 500;
    color: #99a58a;
}
.segment p {
    margin: 9px 0;
    font-size: 9px;
    line-height: 1.9;
    color: #8c9a7a;
}
.break-info {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #9aa68b;
    font-size: 8px;
}
.break-info svg {
    width: 11px;
    height: 11px;
}
.reason-detail {
    margin-top: 22px;
}
.reason-detail h3,
.approval-detail h3,
.attachments h3,
.decision-form h3 {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 12px;
    color: #536a43;
    font-size: 12px;
    font-weight: 750;
}
.reason-detail h3 svg,
.approval-detail h3 svg {
    width: 15px;
    height: 15px;
    color: #9bab7e;
}
.reason-detail p {
    margin: 0;
    padding: 14px;
    border: 1px solid #e5eadc;
    border-radius: 12px;
    background: #fafcf5;
    color: #7e916a;
    font-size: 12px;
    line-height: 1.9;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.attachments {
    margin-top: 22px;
}
.attachments h3 small {
    margin-left: auto;
    color: #a0ac90;
    font-size: 9px;
    font-weight: 500;
}
.attachments button {
    display: flex;
    align-items: center;
    gap: 9px;
    border: 1px solid #e0e8d3;
    border-radius: 11px;
    padding: 11px;
    color: #729456;
    background: #f7faf0;
    width: 100%;
    text-align: left;
    cursor: pointer;
}
.attachments button + button {
    margin-top: 7px;
}
.attachments button > svg {
    flex-shrink: 0;
    width: 17px;
    height: 17px;
}
.attachments button > span {
    min-width: 0;
    flex: 1;
}
.attachments strong {
    display: block;
    font-size: 10px;
    font-weight: 650;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.attachments small {
    font-size: 8px;
    color: #99a789;
}
.approval-detail {
    margin-top: 24px;
}
.approval-detail ol {
    list-style: none;
    margin: 0;
    padding: 0;
}
.approval-detail li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 0;
    position: relative;
}
.approval-detail li:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 14px;
    top: 43px;
    bottom: -8px;
    border-left: 1px solid #dce6ce;
}
.step-number {
    display: grid;
    place-items: center;
    width: 29px;
    height: 29px;
    flex-shrink: 0;
    border: 1px solid #e0e7d2;
    border-radius: 10px;
    background: #f0f4e6;
    color: #9baa83;
    font-size: 10px;
    font-weight: 700;
}
.step-number svg {
    width: 14px;
    height: 14px;
}
.pending .step-number {
    background: #f6eddb;
    color: #a68951;
    border-color: #e7d9b4;
}
.approved .step-number {
    background: #607f49;
    border-color: #607f49;
    color: #fff;
}
.rejected .step-number {
    background: #f8eae0;
    color: #b27b5d;
    border-color: #edd5c1;
}
.approval-detail li > div {
    min-width: 0;
}
.approval-detail strong {
    display: block;
    margin-top: 3px;
    font-size: 12px;
    font-weight: 650;
    overflow-wrap: anywhere;
}
.approval-detail small {
    display: block;
    margin-top: 5px;
    font-size: 9px;
    color: #9aa88a;
    line-height: 1.8;
}
.approval-detail p {
    margin: 8px 0 0;
    color: #89997a;
    background: #f4f7ee;
    border-radius: 9px;
    padding: 9px;
    font-size: 10px;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.decision-form {
    border-top: 1px solid #e2e9d7;
    margin-top: 20px;
    padding-top: 20px;
}
.decision-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px;
    margin-bottom: 17px;
}
.decision-options button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 44px;
    border: 1px solid #e0e7d3;
    border-radius: 11px;
    background: #fff;
    color: #90a079;
    font-size: 11px;
    font-weight: 650;
}
.decision-options svg {
    width: 16px;
    height: 16px;
}
.decision-options .selected {
    color: #5a7d42;
    background: #eef5e1;
    border-color: #a0b781;
}
.decision-options .rejecting {
    color: #ad7150;
    background: #faf0e7;
    border-color: #d5aa88;
}
.decision-form label {
    display: block;
    margin-bottom: 9px;
    color: #80916e;
    font-size: 10px;
}
.decision-form textarea {
    display: block;
    width: 100%;
    border: 1px solid #dde5cf;
    border-radius: 12px;
    padding: 12px;
    resize: vertical;
    color: #617d4b;
    background: #fff;
    font-size: 12px;
    line-height: 1.8;
}
.decision-form textarea[aria-invalid='true'] {
    border-color: #c08b68;
}
.approval-hint {
    display: flex;
    align-items: flex-start;
    gap: 5px;
    margin: 10px 0 0;
    font-size: 9px;
    line-height: 1.8;
    color: #9aa48b;
}
.approval-hint svg {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
    margin-top: 2px;
}
.primary-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    min-height: 47px;
    border: 1px solid #3c5b37;
    border-radius: 13px;
    background: #355b41;
    color: #fff;
    font-size: 12px;
    font-weight: 650;
}
.primary-button svg {
    width: 16px;
    height: 16px;
}
.primary-button.rejecting {
    background: #a77852;
    border-color: #a77852;
}
.cancel-button {
    width: 100%;
    min-height: 44px;
    border: 1px solid #dec8af;
    border-radius: 12px;
    color: #ae835a;
    background: #fffcf6;
    font-size: 11px;
    font-weight: 650;
}
.cancel-confirmation {
    border: 1px solid #e4cfac;
    border-radius: 15px;
    background: #fcf5e6;
    margin-top: 22px;
    padding: 15px;
}
.cancel-confirmation > strong {
    font-size: 13px;
    color: #9a7c48;
}
.cancel-confirmation p {
    margin: 8px 0 14px;
    color: #a69168;
    font-size: 11px;
    line-height: 1.8;
}
.cancel-confirmation > div {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.cancel-confirmation button {
    min-height: 42px;
    border: 1px solid #decfaf;
    border-radius: 10px;
    background: #fff;
    color: #9c8a63;
    font-size: 10px;
    font-weight: 650;
}
.cancel-confirmation .danger-button {
    background: #ad8356;
    color: #fff;
    border-color: #ad8356;
}
.footer-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    color: #9d9b7c;
    font-size: 10px;
    line-height: 1.7;
    margin: 0;
}
.footer-note svg {
    width: 13px;
    height: 13px;
}
.error-note {
    color: #ac6d4d;
    margin: 9px 0 0;
    font-size: 11px;
    line-height: 1.7;
}
button,
a {
    cursor: pointer;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}
button:disabled {
    opacity: 0.5;
    cursor: default;
}
button:focus-visible,
a:focus-visible,
textarea:focus-visible {
    outline: 3px solid #bca166;
    outline-offset: 3px;
}
@media (max-width: 360px) {
    .segment-grid {
        gap: 7px;
    }
    .segment {
        padding-inline: 9px;
    }
    .segment h3 {
        font-size: 9px;
    }
    .segment p {
        font-size: 8px;
    }
    .detail-hero {
        padding-inline: 14px;
    }
}
</style>
