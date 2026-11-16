<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Download, LoaderCircle, Timer, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { lemburDuration } from '../../absensi-mobile/lembur/types';
import '../approval-workflow.css';
import { date, labels } from '../cuti/formatters';
import DetailModal from '../setting/app-release/ReleaseModal.vue';
import type { LemburDetailPayload } from './types';

const props = defineProps<{
    requestId: number;
    initialDetail?: LemburDetailPayload;
}>();
const emit = defineEmits<{ close: []; open: [id: number] }>();
const base = '/absensi/admin-web/lembur';
const detail = ref<LemburDetailPayload | null>(props.initialDetail ?? null);
const loading = ref(false);
const error = ref('');
const lembur = computed(() => detail.value?.lembur ?? null);
const balance = computed(() => detail.value?.balance ?? null);
const can = computed(() => detail.value?.can ?? { cancel: false, decide: false });
const allowApprovedCancellation = computed(() => detail.value?.allowApprovedCancellation ?? false);
const cancelOpen = ref(false);
const cancelForm = useForm({ note: '' });
const decisionForm = useForm({ decision: 'approved', note: '' });
const busy = computed(() => cancelForm.processing || decisionForm.processing);
let pending: AbortController | null = null;
const load = async () => {
    pending?.abort();
    const current = new AbortController();
    pending = current;
    loading.value = true;
    error.value = '';
    try {
        const response = await fetch(`${base}/${props.requestId}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: current.signal,
        });
        if (!response.ok || response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) {
            throw new Error(
                response.status === 401 || response.redirected
                    ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali.'
                    : response.status === 403
                      ? 'Anda tidak memiliki izin melihat detail lembur.'
                      : response.status === 404
                        ? 'Data lembur tidak ditemukan.'
                        : 'Detail lembur belum dapat dimuat. Silakan coba kembali.',
            );
        }
        const data: LemburDetailPayload = await response.json();
        if (pending === current) detail.value = data;
    } catch (cause) {
        if (!current.signal.aborted) error.value = cause instanceof Error ? cause.message : 'Detail lembur belum dapat dimuat.';
    } finally {
        if (pending === current) loading.value = false;
    }
};
const close = () => {
    if (busy.value) return;
    if (cancelOpen.value) cancelOpen.value = false;
    else emit('close');
};
const openCancel = () => {
    if (!can.value.cancel || busy.value || loading.value) return;
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelOpen.value = true;
};
const cancel = () => {
    if (!can.value.cancel || busy.value || loading.value) return;
    cancelForm.post(`${base}/${props.requestId}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelOpen.value = false;
            cancelForm.reset();
            void load();
        },
    });
};
const decide = (decision: 'approved' | 'rejected') => {
    if (!can.value.decide || busy.value || loading.value) return;
    decisionForm.decision = decision;
    decisionForm.post(`${base}/${props.requestId}/decision`, {
        preserveScroll: true,
        onSuccess: () => {
            decisionForm.reset();
            void load();
        },
    });
};
const openSource = (id: number) => {
    if (!busy.value && !loading.value) emit('open', id);
};
onMounted(() => {
    if (!detail.value) void load();
});
onBeforeUnmount(() => pending?.abort());
</script>

<template>
    <DetailModal
        :open="true"
        :title="cancelOpen ? 'Batalkan pengajuan lembur' : `Detail #LEMBUR-${requestId}`"
        :description="
            cancelOpen ? 'Alasan dan pelaku pembatalan akan disimpan dalam riwayat pengajuan.' : 'Informasi lembur dan riwayat persetujuan pegawai.'
        "
        :busy="busy"
        @close="close"
    >
        <div v-if="loading" class="ml-modal-message" role="status"><LoaderCircle class="ml-spinner" /> Memuat detail lembur...</div>
        <div v-else-if="error" class="ml-modal-message" role="alert">
            <p class="al-error">{{ error }}</p>
            <button type="button" class="btn btn-outline-primary" @click="load">Coba lagi</button>
        </div>
        <div v-else-if="lembur && !cancelOpen" class="ml-modal-body">
            <section class="al-detail-heading">
                <div>
                    <span class="al-eyebrow"><Timer /> #LEMBUR-{{ lembur.id }}</span>
                    <h3>{{ lembur.request_kind === 'usage' ? 'Penggunaan saldo lembur' : 'Detail lembur pegawai' }}</h3>
                    <p>{{ lembur.name }} / {{ lembur.unit }} / {{ lembur.position }}</p>
                </div>
                <span class="al-status" :class="lembur.status">{{ labels[lembur.status] }}</span>
            </section>
            <section class="al-panel al-form">
                <div class="al-form-body">
                    <div class="ml-detail-grid">
                        <div class="al-info">
                            <strong>{{ date(lembur.date) }}</strong>
                            <p>Shift {{ lembur.shift_name }}</p>
                            <p>{{ lembur.shift_start }} sampai {{ lembur.shift_end }}</p>
                        </div>
                        <div class="al-info">
                            <strong
                                >{{ lemburDuration(lembur.duration_minutes) }}
                                {{ lembur.request_kind === 'usage' ? 'penggunaan' : 'lembur bersih' }}</strong
                            >
                            <p>{{ lembur.compensation === 'pay' ? 'Upah lembur' : 'Pengganti waktu libur' }}</p>
                            <small
                                >Dicatat {{ lembur.created_at }}<template v-if="lembur.recorded_by"> oleh {{ lembur.recorded_by }}</template></small
                            >
                        </div>
                    </div>
                    <div v-if="lembur.request_kind === 'overtime'" class="ml-detail-grid">
                        <div v-for="part in ['before', 'after'] as const" :key="part" class="al-info">
                            <strong>{{ part === 'before' ? 'Sebelum shift' : 'Setelah shift' }}</strong>
                            <template v-if="lembur[`${part}_start`]"
                                ><p>{{ lembur[`${part}_start`] }} sampai {{ lembur[`${part}_end`] }}</p>
                                <p>Istirahat {{ lemburDuration(lembur[`${part}_break_minutes`]) }}</p></template
                            >
                            <p v-else>Tidak ada lembur</p>
                        </div>
                    </div>
                    <template v-else>
                        <div class="al-info">
                            <strong>{{ lembur.usage_type === 'late_arrival' ? 'Telat masuk' : 'Pulang cepat' }}</strong>
                            <p>{{ lembur.usage_start }} sampai {{ lembur.usage_end }}</p>
                        </div>
                        <div>
                            <div class="al-section-label"><strong>Sumber saldo waktu</strong></div>
                            <p v-if="lembur.manual_balance_minutes">
                                Saldo pengaturan admin / Excel: {{ lemburDuration(lembur.manual_balance_minutes) }}
                            </p>
                            <ul class="ml-sources">
                                <li v-for="source in lembur.sources" :key="source.id">
                                    <button type="button" class="ml-source-link" :disabled="busy" @click="openSource(source.id)">
                                        #LEMBUR-{{ source.id }} / {{ date(source.date) }}
                                    </button>
                                    /
                                    {{ lemburDuration(source.minutes) }}
                                </li>
                            </ul>
                        </div>
                    </template>
                    <div>
                        <div class="al-section-label">
                            <strong>Alasan {{ lembur.request_kind === 'usage' ? 'penggunaan' : 'lembur' }}</strong>
                        </div>
                        <p class="al-reason">{{ lembur.reason }}</p>
                    </div>
                    <div v-if="lembur.attachments.length">
                        <div class="al-section-label"><strong>Lampiran</strong></div>
                        <ul class="ml-attachments">
                            <li v-for="file in lembur.attachments" :key="file.url">
                                <a :href="file.url"><Download /> {{ file.name }} ({{ Math.ceil(file.size / 1024) }} KB)</a>
                            </li>
                        </ul>
                    </div>
                    <div v-if="lembur.status === 'cancelled'" class="al-info ml-cancellation">
                        <strong
                            >Pengajuan dibatalkan<template v-if="lembur.cancelled_by"> oleh {{ lembur.cancelled_by }}</template></strong
                        >
                        <p v-if="lembur.decided_at">{{ lembur.decided_at }}</p>
                        <p class="al-reason">{{ lembur.cancellation_note || 'Dibatalkan oleh pemohon.' }}</p>
                    </div>
                    <div>
                        <div class="al-section-label"><strong>Progres persetujuan</strong></div>
                        <ol v-if="lembur.approvals.length" class="al-timeline">
                            <li v-for="step in lembur.approvals" :key="step.level" :class="step.status">
                                <span class="al-timeline-number"
                                    ><Check v-if="step.status === 'approved'" /><X v-else-if="step.status === 'rejected'" /><template v-else>{{
                                        step.level
                                    }}</template></span
                                >
                                <div>
                                    <strong>{{ step.name }}</strong
                                    ><small
                                        >Tingkat {{ step.level }} / {{ labels[step.status]
                                        }}<template v-if="step.decided_at"> / {{ step.decided_at }}</template></small
                                    >
                                    <p v-if="step.note" class="al-reason">{{ step.note }}</p>
                                </div>
                            </li>
                        </ol>
                        <p v-else class="al-help">Belum ada riwayat persetujuan.</p>
                    </div>
                    <div v-if="can.decide" class="al-review">
                        <h3>Persetujuan tingkat {{ lembur.current_level }}</h3>
                        <p>Anda terdaftar sebagai approver pada tingkat ini.</p>
                        <label class="al-field"
                            ><span>Catatan (wajib untuk penolakan)</span
                            ><textarea v-model="decisionForm.note" rows="3" maxlength="1000" :disabled="decisionForm.processing" />
                        </label>
                    </div>
                    <p v-for="(error, key) in decisionForm.errors" :key="key" class="al-error" role="alert">{{ error }}</p>
                    <p v-if="lembur.status === 'approved' && !allowApprovedCancellation" class="al-help">
                        Pembatalan lembur yang disetujui dinonaktifkan pada menu Konfigurasi.
                    </p>
                    <p v-if="can.cancel" class="al-help">
                        Untuk koreksi data, batalkan pengajuan lalu catat ulang. Saldo yang sudah digunakan harus dibatalkan penggunaannya terlebih
                        dahulu.
                    </p>
                </div>
                <footer class="al-form-footer ml-footer">
                    <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="close">Tutup</button>
                    <button v-if="can.cancel" class="btn btn-outline-danger" type="button" :disabled="busy" @click="openCancel">
                        Batalkan pengajuan
                    </button>
                    <template v-if="can.decide"
                        ><button
                            class="btn btn-outline-danger"
                            type="button"
                            :disabled="decisionForm.processing || cancelForm.processing"
                            @click="decide('rejected')"
                        >
                            Tolak</button
                        ><button
                            class="btn btn-primary"
                            type="button"
                            :disabled="decisionForm.processing || cancelForm.processing"
                            @click="decide('approved')"
                        >
                            {{ decisionForm.processing ? 'Memproses...' : 'Setujui' }}
                        </button></template
                    >
                </footer>
            </section>
            <section v-if="balance" class="al-panel">
                <header class="al-panel-header">
                    <div>
                        <h2>Saldo waktu {{ lembur.name }}</h2>
                        <p>Semua periode hingga saat ini. Penggunaan yang menunggu approval dicadangkan dari saldo tersedia.</p>
                    </div>
                </header>
                <div class="ml-balance-grid">
                    <div>
                        <small>Diperoleh</small><strong>{{ lemburDuration(balance.earned_minutes) }}</strong>
                    </div>
                    <div>
                        <small>Terpakai</small><strong>{{ lemburDuration(balance.used_minutes) }}</strong>
                    </div>
                    <div>
                        <small>Dicadangkan</small><strong>{{ lemburDuration(balance.reserved_minutes) }}</strong>
                    </div>
                    <div>
                        <small>Tersedia</small><strong>{{ lemburDuration(balance.available_minutes) }}</strong>
                    </div>
                </div>
            </section>
        </div>
        <form v-if="cancelOpen && lembur && !loading && !error" class="al-form" @submit.prevent="cancel">
            <div class="al-form-body">
                <p class="al-info">#LEMBUR-{{ lembur.id }} / {{ lembur.name }} / {{ date(lembur.date) }}</p>
                <label class="al-field"
                    ><span>Alasan pembatalan <b>*</b></span
                    ><textarea v-model="cancelForm.note" rows="4" maxlength="1000" required :disabled="cancelForm.processing" />
                </label>
                <p v-for="(error, key) in cancelForm.errors" :key="key" class="al-error" role="alert">{{ error }}</p>
            </div>
            <footer class="al-form-footer">
                <button class="btn btn-outline-secondary" type="button" :disabled="cancelForm.processing" @click="cancelOpen = false">Kembali</button
                ><button class="btn btn-danger" type="submit" :disabled="cancelForm.processing">
                    {{ cancelForm.processing ? 'Memproses...' : 'Batalkan pengajuan' }}
                </button>
            </footer>
        </form>
    </DetailModal>
</template>

<style scoped>
.ml-modal-body {
    display: grid;
    grid-auto-rows: max-content;
    align-content: start;
    gap: 20px;
    padding: 24px;
    overflow-y: auto;
    min-height: 0;
}
.ml-modal-body .al-form-body {
    overflow-y: visible;
}
.ml-modal-message {
    display: grid;
    justify-items: center;
    gap: 16px;
    padding: 40px 24px;
}
.ml-source-link {
    border: 0;
    padding: 0;
    background: transparent;
    color: var(--rs-primary);
    text-align: left;
    text-decoration: underline;
}
.ml-spinner {
    animation: ml-spin 1s linear infinite;
}
@keyframes ml-spin {
    to {
        transform: rotate(360deg);
    }
}
@media (prefers-reduced-motion: reduce) {
    .ml-spinner {
        animation: none;
    }
}
.ml-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.ml-detail-grid p,
.ml-cancellation p {
    margin: 8px 0 0;
}
.ml-attachments,
.ml-sources {
    padding-left: 20px;
    overflow-wrap: anywhere;
}
.ml-attachments a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.ml-attachments svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.ml-footer {
    flex-wrap: wrap;
}
.ml-balance-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
    padding: 0 24px 24px;
}
.ml-balance-grid small {
    display: block;
    color: var(--rs-muted);
    margin-bottom: 6px;
}
.ml-balance-grid strong {
    font-size: 22px;
    color: var(--rs-primary);
}
@media (max-width: 640px) {
    .ml-detail-grid {
        grid-template-columns: 1fr;
    }
    .ml-balance-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
