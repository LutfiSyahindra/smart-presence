<script setup lang="ts">
import { mobileGoBack, registerMobileBackHandler } from '@/lib/mobile-navigation';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Clock3, Info, ShieldCheck, Sunrise, Sunset, Timer } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import CutiSheet from './components/CutiSheet.vue';
import { lemburDuration as duration, type LemburBalance } from './lembur/types';

const props = defineProps<{
    today: string;
    balance: LemburBalance;
    employee: { name: string; position: string | null; unit: string | null } | null;
    shift: { id: number; name: string; start_time: string; end_time: string } | null;
    approvalPreview: { level: number; name: string }[];
    unavailableReason: string | null;
}>();
const form = useForm({
    tanggal_lembur: props.today,
    shift_id: props.shift?.id ?? null,
    usage_type: 'late_arrival',
    duration_minutes: 60,
    alasan: '',
});
const errors = computed(() => form.errors as Record<string, string | undefined>);
const discardOpen = ref(false);
let submitted = false;
let removeBack: (() => void) | undefined;
let removeGuard: (() => void) | undefined;
const options = [
    { value: 'late_arrival', title: 'Telat masuk', caption: 'Mulai kerja lebih siang', icon: Sunrise },
    { value: 'early_departure', title: 'Pulang cepat', caption: 'Selesai kerja lebih awal', icon: Sunset },
];
const range = computed(() => {
    if (!props.shift || !/^\d{4}-\d{2}-\d{2}$/.test(form.tanggal_lembur)) return null;
    const start = new Date(`${form.tanggal_lembur}T${props.shift.start_time}:00+07:00`);
    const end = new Date(`${form.tanggal_lembur}T${props.shift.end_time}:00+07:00`);
    if (!Number.isFinite(start.getTime()) || !Number.isFinite(end.getTime())) return null;
    if (end <= start) end.setTime(end.getTime() + 86400000);
    const minutes = Math.max(0, Number(form.duration_minutes) || 0);
    const boundary = new Date(form.usage_type === 'late_arrival' ? start.getTime() + minutes * 60000 : end.getTime() - minutes * 60000);
    const format = (date: Date) =>
        new Intl.DateTimeFormat('id-ID', {
            timeZone: 'Asia/Jakarta',
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(date);
    return {
        label: form.usage_type === 'late_arrival' ? 'Boleh masuk sampai' : 'Boleh pulang mulai',
        time: format(boundary),
        shiftMinutes: (end.getTime() - start.getTime()) / 60000,
    };
});
const remaining = computed(() => Math.max(0, props.balance.available_minutes - (Number(form.duration_minutes) || 0)));
function leave() {
    if (form.processing) return;
    if (form.isDirty && !submitted) discardOpen.value = true;
    else mobileGoBack(route('absensi.mobile.lembur.index'));
}
function discard() {
    submitted = true;
    discardOpen.value = false;
    mobileGoBack(route('absensi.mobile.lembur.index'));
}
function submit() {
    if (form.processing || props.unavailableReason || !props.shift) return;
    form.clearErrors();
    if (!Number.isInteger(Number(form.duration_minutes)) || Number(form.duration_minutes) < 1)
        form.setError('duration_minutes', 'Isi durasi dalam menit, minimal 1 menit.');
    else if (Number(form.duration_minutes) > props.balance.available_minutes) form.setError('duration_minutes', 'Saldo lembur tidak mencukupi.');
    else if (range.value && Number(form.duration_minutes) >= range.value.shiftMinutes)
        form.setError('duration_minutes', 'Durasi harus lebih singkat dari shift.');
    if (!form.alasan.trim()) form.setError('alasan', 'Alasan penggunaan wajib diisi.');
    if (form.hasErrors) return;
    form.transform((data) => ({ ...data, alasan: data.alasan.trim() })).post(route('absensi.mobile.lembur.usage.store'), {
        onSuccess: () => {
            submitted = true;
        },
    });
}
onMounted(() => {
    removeBack = registerMobileBackHandler(() => {
        if (form.processing) return true;
        if (discardOpen.value) {
            discardOpen.value = false;
            return true;
        }
        if (form.isDirty && !submitted) {
            discardOpen.value = true;
            return true;
        }
        return false;
    });
    removeGuard = router.on('before', (event) => {
        if (event.detail.visit.method !== 'get') return;
        if (form.processing) return false;
        if (form.isDirty && !submitted) {
            discardOpen.value = true;
            return false;
        }
    });
});
onUnmounted(() => {
    removeBack?.();
    removeGuard?.();
});
</script>

<template>
    <Head title="Gunakan waktu lembur" />
    <main class="usage-workspace">
        <div class="usage-shell">
            <header class="usage-header">
                <button type="button" class="back-button" aria-label="Kembali ke lembur" :disabled="form.processing" @click="leave">
                    <ArrowLeft />
                </button>
                <div>
                    <span>LEMBUR · KOMPENSASI WAKTU</span>
                    <h1>Gunakan waktu lembur</h1>
                </div>
                <Timer aria-hidden="true" />
            </header>
            <form id="usage-form" class="usage-content" :aria-busy="form.processing" @submit.prevent="submit">
                <section class="balance-card">
                    <span class="eyebrow">SALDO TERSEDIA</span><strong>{{ duration(balance.available_minutes) }}</strong>
                    <p>Dari lembur berkompensasi cuti pengganti yang sudah disetujui dan selesai.</p>
                    <div>
                        <span
                            >Dicadangkan <b>{{ duration(balance.reserved_minutes) }}</b></span
                        ><span
                            >Terpakai <b>{{ duration(balance.used_minutes) }}</b></span
                        >
                    </div>
                </section>
                <p v-if="unavailableReason || errors.lembur" class="notice" role="alert"><Info />{{ unavailableReason || errors.lembur }}</p>
                <p v-if="!balance.available_minutes" class="notice">
                    <Info />Belum ada saldo yang dapat digunakan. Pilih kompensasi cuti pengganti saat mengajukan lembur.
                </p>
                <section v-if="employee" class="employee-card">
                    <strong>{{ employee.name }}</strong>
                    <p>{{ employee.position }} · {{ employee.unit }}</p>
                </section>
                <fieldset :disabled="form.processing || !!unavailableReason">
                    <legend>Keperluan penggunaan</legend>
                    <div class="usage-options">
                        <label v-for="option in options" :key="option.value" :class="{ selected: form.usage_type === option.value }">
                            <input v-model="form.usage_type" type="radio" name="usage_type" :value="option.value" />
                            <component :is="option.icon" /><strong>{{ option.title }}</strong
                            ><small>{{ option.caption }}</small>
                        </label>
                    </div>
                    <p v-if="errors.usage_type" class="field-error" role="alert">{{ errors.usage_type }}</p>
                    <label class="field" for="usage-date"
                        ><span>Tanggal penggunaan <b>*</b></span
                        ><input
                            id="usage-date"
                            v-model="form.tanggal_lembur"
                            type="date"
                            :min="today"
                            max="2100-12-31"
                            required
                            :aria-invalid="!!errors.tanggal_lembur"
                    /></label>
                    <p v-if="errors.tanggal_lembur" class="field-error" role="alert">{{ errors.tanggal_lembur }}</p>
                    <div v-if="shift" class="shift-info">
                        <Clock3 /><span
                            >Shift {{ shift.name }}<strong>{{ shift.start_time }} – {{ shift.end_time }} WIB</strong></span
                        >
                    </div>
                    <p v-if="errors.shift_id" class="field-error" role="alert">{{ errors.shift_id }}</p>
                    <label class="field" for="usage-minutes"
                        ><span>Durasi penggunaan (menit) <b>*</b></span
                        ><input
                            id="usage-minutes"
                            v-model.number="form.duration_minutes"
                            type="number"
                            min="1"
                            :max="Math.min(balance.available_minutes, 1439)"
                            step="1"
                            inputmode="numeric"
                            required
                            :aria-invalid="!!errors.duration_minutes"
                    /></label>
                    <div class="quick-durations">
                        <button
                            v-for="minutes in [30, 60, 90, 120]"
                            :key="minutes"
                            type="button"
                            :disabled="minutes > balance.available_minutes"
                            :aria-pressed="form.duration_minutes === minutes"
                            @click="form.duration_minutes = minutes"
                        >
                            {{ duration(minutes) }}
                        </button>
                    </div>
                    <p v-if="errors.duration_minutes" class="field-error" role="alert">{{ errors.duration_minutes }}</p>
                    <div v-if="range" class="time-preview" aria-live="polite">
                        <span
                            >{{ range.label }}<strong>{{ range.time }} WIB</strong></span
                        ><Timer />
                    </div>
                    <label class="field" for="usage-reason"
                        ><span
                            >Alasan penggunaan <b>*</b><small>{{ form.alasan.length }}/200</small></span
                        ><textarea
                            id="usage-reason"
                            v-model="form.alasan"
                            rows="3"
                            maxlength="200"
                            required
                            placeholder="Jelaskan keperluan Anda"
                            :aria-invalid="!!errors.alasan"
                        />
                    </label>
                    <p v-if="errors.alasan" class="field-error" role="alert">{{ errors.alasan }}</p>
                </fieldset>
                <section class="approval-card">
                    <h2><ShieldCheck />Alur persetujuan lembur</h2>
                    <p>Penggunaan berlaku setelah seluruh pemberi persetujuan menyetujui.</p>
                    <ol>
                        <li v-for="step in approvalPreview" :key="step.level">
                            <span>{{ step.level }}</span
                            ><strong>{{ step.name }}</strong>
                        </li>
                    </ol>
                </section>
                <p class="balance-note">
                    Setelah diajukan, {{ duration(Number(form.duration_minutes) || 0) }} dicadangkan. Sisa saldo {{ duration(remaining) }}. Saldo
                    dikembalikan bila pengajuan ditolak atau dibatalkan.
                </p>
            </form>
            <footer class="usage-footer">
                <span
                    >Gunakan <strong>{{ duration(Number(form.duration_minutes) || 0) }}</strong></span
                ><button type="submit" form="usage-form" :disabled="form.processing || !!unavailableReason || !balance.available_minutes">
                    {{ form.processing ? 'Mengirim…' : 'Ajukan penggunaan' }}<ArrowRight />
                </button>
            </footer>
        </div>
    </main>
    <CutiSheet
        :open="discardOpen"
        title="Tinggalkan pengajuan?"
        description="Isian penggunaan waktu lembur belum dikirim."
        @close="discardOpen = false"
    >
        <div class="discard-actions">
            <button type="button" @click="discardOpen = false">Lanjut mengisi</button><button type="button" @click="discard">Tinggalkan</button>
        </div>
    </CutiSheet>
</template>

<style scoped>
.usage-workspace {
    color: #233d35;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    background: #eaf0e9;
}
.usage-shell {
    display: flex;
    flex-direction: column;
    max-width: 500px;
    height: 100vh;
    height: 100dvh;
    margin: auto;
    background: #f6f7f2;
    overflow: hidden;
}
button,
input,
textarea {
    font: inherit;
}
button {
    cursor: pointer;
}
button:disabled {
    cursor: default;
    opacity: 0.5;
}
.usage-header {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 24px;
    align-items: center;
    gap: 10px;
    padding: calc(18px + env(safe-area-inset-top, 0px)) 20px 18px;
    border-bottom: 1px solid #e5e9df;
    background: #fafbf7;
}
.usage-header div {
    text-align: center;
}
.usage-header span,
.eyebrow {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.12em;
}
.usage-header span {
    color: #927e53;
}
h1 {
    margin: 6px 0 0;
    font-size: 18px;
    font-weight: 800;
}
.back-button {
    display: grid;
    place-items: center;
    padding: 8px;
    border: 1px solid #dfe5da;
    border-radius: 12px;
    background: white;
}
svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
.usage-content {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    padding: 22px 20px;
}
.balance-card {
    padding: 22px;
    border-radius: 22px;
    color: #fff;
    background: #234d3e;
}
.balance-card > strong {
    display: block;
    margin-top: 8px;
    font-size: 34px;
    line-height: 1.2;
}
.balance-card p {
    color: #d8e4d6;
    font-size: 12px;
    line-height: 1.7;
    margin: 12px 0 18px;
}
.balance-card > div {
    display: flex;
    gap: 22px;
    padding-top: 14px;
    border-top: 1px solid #ffffff25;
    font-size: 11px;
}
.balance-card b {
    display: block;
    margin-top: 5px;
    font-size: 15px;
}
.notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-radius: 12px;
    padding: 14px;
    background: #fff0d9;
    color: #7e581b;
    font-size: 12px;
    line-height: 1.7;
}
.employee-card {
    margin: 20px 0;
    font-size: 14px;
}
.employee-card p,
.approval-card p,
.balance-note {
    color: #6d7b71;
    font-size: 12px;
    line-height: 1.7;
}
fieldset {
    border: 0;
    padding: 0;
    margin: 24px 0;
    min-width: 0;
}
legend {
    font-size: 15px;
    font-weight: 800;
    padding: 0 0 14px;
}
.usage-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.usage-options label {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 16px 12px;
    background: #fff;
    border: 1px solid #dfe5da;
    border-radius: 16px;
    cursor: pointer;
}
.usage-options label.selected {
    border-color: #426a4f;
    background: #edf2e6;
}
.usage-options label:focus-within {
    outline: 2px solid #426a4f;
    outline-offset: 2px;
}
.usage-options input {
    position: absolute;
    top: 16px;
    right: 12px;
    accent-color: #315640;
}
.usage-options strong {
    font-size: 13px;
}
.usage-options small {
    font-size: 10px;
    color: #6d7b71;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 9px;
    margin-top: 24px;
    font-size: 12px;
    font-weight: 700;
}
.field > span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.field b {
    color: #a4513f;
}
.field small {
    margin-left: auto;
    font-weight: 400;
    color: #6d7b71;
}
.field input,
.field textarea {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    padding: 13px 14px;
    border: 1px solid #dfe5da;
    border-radius: 12px;
    background: #fff;
    color: #233d35;
    font-size: 16px;
    font-weight: 400;
}
.field textarea {
    resize: vertical;
}
.field-error {
    color: #a23e33;
    font-size: 12px;
    line-height: 1.6;
    margin: 8px 0;
}
.shift-info {
    display: flex;
    gap: 10px;
    margin-top: 14px;
    padding: 14px;
    border-radius: 12px;
    background: #ebeee6;
    font-size: 12px;
}
.shift-info strong {
    display: block;
    margin-top: 4px;
}
.quick-durations {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.quick-durations button {
    padding: 8px 12px;
    border: 1px solid #dfe5da;
    border-radius: 10px;
    background: #fff;
    font-size: 11px;
}
.quick-durations [aria-pressed='true'] {
    background: #315640;
    color: #fff;
}
.time-preview {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px;
    margin-top: 18px;
    background: #e8efdf;
    border-radius: 14px;
    font-size: 12px;
}
.time-preview strong {
    display: block;
    margin-top: 5px;
    font-size: 16px;
}
.approval-card {
    border: 1px solid #dfe5da;
    border-radius: 18px;
    background: #fff;
    padding: 18px;
}
h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-size: 14px;
}
ol {
    list-style: none;
    margin: 16px 0 0;
    padding: 0;
}
li {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
    font-size: 12px;
}
li > span {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #eef2e6;
    font-weight: 800;
}
.balance-note {
    margin-top: 18px;
}
.usage-footer {
    display: flex;
    flex-shrink: 0;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 16px 20px calc(16px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #dfe5da;
    background: #fafbf7;
}
.usage-footer > span {
    font-size: 10px;
    color: #6d7b71;
}
.usage-footer strong {
    display: block;
    color: #233d35;
    font-size: 18px;
    margin-top: 4px;
}
.usage-footer button {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    border: 0;
    border-radius: 14px;
    padding: 14px;
    background: #315640;
    color: #fff;
    font-size: 12px;
    font-weight: 800;
}
.discard-actions {
    display: flex;
    gap: 12px;
}
.discard-actions button {
    flex: 1;
    padding: 12px;
    border-radius: 12px;
    border: 1px solid #dfe5da;
    background: #f6f7f2;
}
@media (max-width: 360px) {
    .usage-content {
        padding: 18px 14px;
    }
    .usage-header,
    .usage-footer {
        padding-left: 14px;
        padding-right: 14px;
    }
    h1 {
        font-size: 16px;
    }
    .usage-footer button {
        padding: 12px 10px;
        font-size: 11px;
    }
}
</style>
