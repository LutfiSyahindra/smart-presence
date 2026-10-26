<script setup lang="ts">
import { mobileGoBack, registerMobileBackHandler } from '@/lib/mobile-navigation';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Check,
    CheckCheck,
    ChevronDown,
    Clock3,
    Coffee,
    Eye,
    FileText,
    Info,
    Paperclip,
    Plus,
    Send,
    ShieldCheck,
    Sunrise,
    Sunset,
    Timer,
    Trash2,
    UserRound,
    Wallet,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import CutiSheet from './components/CutiSheet.vue';
import LemburAttachmentPreview from './components/LemburAttachmentPreview.vue';
import { lemburDuration } from './lembur/types';

type ShiftOption = { id: number; name: string; start_time: string; end_time: string };
const props = defineProps<{
    today: string;
    employee: { name: string; position: string | null; unit: string | null } | null;
    defaultShiftId: number | null;
    shifts: ShiftOption[];
    approvalPreview: { level: number; name: string }[];
    unavailableReason: string | null;
}>();
const form = useForm({
    tanggal_lembur: props.today,
    shift_id: props.defaultShiftId ? String(props.defaultShiftId) : '',
    before_minutes: 0,
    before_break_minutes: 0,
    after_minutes: 0,
    after_break_minutes: 0,
    compensation: 'pay',
    alasan: '',
    attachments: [] as File[],
});
const errors = computed(() => form.errors as Record<string, string | undefined>);
watch(() => props.defaultShiftId, (id, previous) => {
    if (form.shift_id === (previous ? String(previous) : '')) form.shift_id = id ? String(id) : '';
});
const fileInput = ref<HTMLInputElement | null>(null);
const fileError = ref('');
const previewFile = ref<File | null>(null);
const discardOpen = ref(false);
const submitted = ref(false);
let removeBackHandler: (() => void) | undefined;
let removeNavigationGuard: (() => void) | undefined;
const selectedShift = computed(() => props.shifts.find((shift) => shift.id === Number(form.shift_id)));
const beforeNet = computed(() => Math.max(0, Number(form.before_minutes || 0) - Number(form.before_break_minutes || 0)));
const afterNet = computed(() => Math.max(0, Number(form.after_minutes || 0) - Number(form.after_break_minutes || 0)));
const total = computed(() => beforeNet.value + afterNet.value);
const totalBreak = computed(() => Number(form.before_break_minutes || 0) + Number(form.after_break_minutes || 0));
const grossTotal = computed(() => Number(form.before_minutes || 0) + Number(form.after_minutes || 0));
const parts = [
    {
        key: 'before',
        title: 'Sebelum shift',
        icon: Sunrise,
        tone: 'morning',
        caption: 'Mulai lebih awal',
        duration: 'before_minutes',
        rest: 'before_break_minutes',
    },
    {
        key: 'after',
        title: 'Setelah shift',
        icon: Sunset,
        tone: 'evening',
        caption: 'Lanjutkan kontribusi',
        duration: 'after_minutes',
        rest: 'after_break_minutes',
    },
] as const;
const compensationOptions = [
    { value: 'pay', title: 'Uang lembur', caption: 'Kompensasi finansial', icon: Wallet },
    { value: 'time_off', title: 'Cuti pengganti', caption: 'Kompensasi waktu', icon: CalendarDays },
];
const acceptedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'docx', 'doc', 'csv', 'txt', 'ppt', 'pptx'];
const accept = acceptedExtensions.map((extension) => `.${extension}`).join(',');

function timeRange(part: 'before' | 'after') {
    const shift = selectedShift.value;
    if (!shift || !form.tanggal_lembur || !form[`${part}_minutes`]) return null;
    const start = new Date(`${form.tanggal_lembur}T${shift.start_time}:00+07:00`);
    const end = new Date(`${form.tanggal_lembur}T${shift.end_time}:00+07:00`);
    if (end <= start) end.setUTCDate(end.getUTCDate() + 1);
    const from = part === 'before' ? new Date(start.getTime() - Number(form.before_minutes) * 60_000) : end;
    const to = part === 'before' ? start : new Date(end.getTime() + Number(form.after_minutes) * 60_000);
    if (!Number.isFinite(from.getTime()) || !Number.isFinite(to.getTime())) return null;
    const format = (date: Date) =>
        new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
            timeZone: 'Asia/Jakarta',
        })
            .format(date)
            .replace(/\.(?=\d{2}$)/, ':');
    return `${format(from)} — ${format(to)}`;
}
function selectFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files || []);
    input.value = '';
    fileError.value = '';
    form.clearErrors('attachments');
    const additions = files.filter(
        (file) => !form.attachments.some((item) => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified),
    );
    if (form.attachments.length + additions.length > 5) {
        fileError.value = 'Maksimal 5 file. Hapus lampiran terlebih dahulu untuk menggantinya.';
        return;
    }
    for (const file of additions) {
        if (file.size > 10 * 1024 * 1024) {
            fileError.value = `${file.name} melebihi batas 10 MB.`;
            return;
        }
        if (!acceptedExtensions.includes(file.name.split('.').pop()?.toLowerCase() || '')) {
            fileError.value = `Format ${file.name} tidak didukung.`;
            return;
        }
    }
    form.attachments = [...form.attachments, ...additions];
}
function removeFile(index: number) {
    form.attachments = form.attachments.filter((_, itemIndex) => itemIndex !== index);
    fileError.value = '';
    Object.keys(form.errors)
        .filter((key) => key.startsWith('attachments'))
        .forEach((key) => form.clearErrors(key as 'attachments'));
}
function leave() {
    if (form.processing) return;
    if (form.isDirty) discardOpen.value = true;
    else mobileGoBack(route('absensi.mobile.lembur.index'));
}
function discard() {
    discardOpen.value = false;
    form.reset();
    fileError.value = '';
    mobileGoBack(route('absensi.mobile.lembur.index'));
}
async function focusError() {
    await nextTick();
    document.querySelector<HTMLElement>('#lembur-form [aria-invalid="true"]')?.focus();
}
function submit() {
    if (form.processing || props.unavailableReason || !props.shifts.length) return;
    form.clearErrors();
    for (const part of parts) {
        const minutes = Number(form[part.duration] || 0);
        const rest = Number(form[part.rest] || 0);
        if (!Number.isInteger(minutes) || minutes < 0 || minutes > 1440)
            form.setError(part.duration, 'Isi durasi dalam menit bulat antara 0 dan 1.440.');
        if (!Number.isInteger(rest) || rest < 0 || (rest > 0 && rest >= minutes))
            form.setError(part.rest, 'Istirahat harus lebih singkat dari durasi lembur.');
    }
    if (grossTotal.value === 0) form.setError('before_minutes', 'Isi durasi sebelum atau setelah shift.');
    if (!form.alasan.trim()) form.setError('alasan', 'Alasan lembur wajib diisi.');
    if (form.hasErrors) {
        void focusError();
        return;
    }
    form.transform((data) => ({
        ...data,
        alasan: data.alasan.trim(),
        before_minutes: Number(data.before_minutes || 0),
        before_break_minutes: Number(data.before_break_minutes || 0),
        after_minutes: Number(data.after_minutes || 0),
        after_break_minutes: Number(data.after_break_minutes || 0),
    })).post(route('absensi.mobile.lembur.store'), {
        replace: true,
        forceFormData: true,
        onError: () => {
            void focusError();
        },
        onSuccess: () => {
            submitted.value = true;
            form.reset();
        },
    });
}
onMounted(() => {
    removeBackHandler = registerMobileBackHandler(() => {
        if (previewFile.value) {
            previewFile.value = null;
            return true;
        }
        if (form.processing) return true;
        if (discardOpen.value) {
            discardOpen.value = false;
            return true;
        }
        if (form.isDirty) {
            discardOpen.value = true;
            return true;
        }
        return false;
    });
    removeNavigationGuard = router.on('before', (event) => {
        if (event.detail.visit.headers?.['X-Mobile-Refresh']) return;
        if (form.processing && event.detail.visit.method === 'get' && !submitted.value) event.preventDefault();
    });
});
onUnmounted(() => {
    removeBackHandler?.();
    removeNavigationGuard?.();
});
</script>

<template>
    <Head title="Pengajuan Lembur" />
    <main class="request-workspace">
        <div class="request-shell">
            <header class="request-header">
                <button type="button" class="icon-button" aria-label="Kembali ke halaman lembur" :disabled="form.processing" @click="leave">
                    <ArrowLeft />
                </button>
                <div>
                    <p>RSARSY PRESENCE</p>
                    <h1>Pengajuan lembur</h1>
                </div>
                <span class="header-emblem" aria-hidden="true"><Timer /></span>
            </header>

            <form id="lembur-form" class="request-content" :aria-busy="form.processing" @submit.prevent="submit">
                <section class="request-intro">
                    <span class="eyebrow">WAKTU EKSTRA, KONTRIBUSI BERMAKNA</span>
                    <h2>Setiap usaha layak tercatat<span>.</span></h2>
                    <p>Lengkapi detail lembur Anda. Kami teruskan ke pemberi persetujuan yang sesuai.</p>
                    <div v-if="employee" class="employee-line">
                        <span><UserRound /></span>
                        <div>
                            <strong>{{ employee.name }}</strong
                            ><small
                                >{{ employee.position }}<template v-if="employee.unit"> · {{ employee.unit }}</template></small
                            >
                        </div>
                    </div>
                </section>

                <div v-if="unavailableReason || !shifts.length" class="notice warning" role="alert">
                    <Info /><span>{{ unavailableReason || 'Shift kerja belum tersedia. Hubungi administrator untuk menambahkan shift.' }}</span>
                </div>
                <div v-if="errors.lembur" class="notice warning" role="alert">
                    <Info /><span>{{ errors.lembur }}</span>
                </div>

                <fieldset :disabled="form.processing || !!unavailableReason || !shifts.length" class="form-body">
                    <section class="form-card schedule-card" aria-labelledby="schedule-title">
                        <div class="card-heading">
                            <span class="section-icon"><CalendarDays /></span>
                            <div>
                                <span class="eyebrow">01 / JADWAL</span>
                                <h3 id="schedule-title">Rencanakan waktu Anda</h3>
                            </div>
                        </div>
                        <label class="field" for="tanggal-lembur"
                            ><span>Tanggal lembur <b>*</b></span>
                            <div class="input-wrap">
                                <input
                                    id="tanggal-lembur"
                                    v-model="form.tanggal_lembur"
                                    type="date"
                                    min="1900-01-01"
                                    max="2100-12-31"
                                    required
                                    :aria-invalid="!!errors.tanggal_lembur"
                                    :aria-describedby="errors.tanggal_lembur ? 'date-error' : 'date-help'"
                                /></div
                        ></label>
                        <p v-if="errors.tanggal_lembur" id="date-error" class="field-error">{{ errors.tanggal_lembur }}</p>
                        <p v-else id="date-help" class="field-help">Gunakan tanggal mulai shift, termasuk untuk shift malam. Waktu mengikuti WIB.</p>
                        <label class="field shift-field" for="lembur-shift"
                            ><span>Shift kerja <b>*</b></span>
                            <div class="input-wrap">
                                <Clock3 /><select
                                    id="lembur-shift"
                                    v-model="form.shift_id"
                                    required
                                    :aria-invalid="!!errors.shift_id"
                                    :aria-describedby="errors.shift_id ? 'shift-error' : undefined"
                                >
                                    <option value="" disabled>Pilih shift kerja</option>
                                    <option v-for="shift in shifts" :key="shift.id" :value="String(shift.id)">
                                        {{ shift.name }} · {{ shift.start_time }}–{{ shift.end_time }}
                                    </option></select
                                ><ChevronDown /></div
                        ></label>
                        <p v-if="errors.shift_id" id="shift-error" class="field-error">{{ errors.shift_id }}</p>
                        <div v-if="selectedShift" class="shift-preview">
                            <span>{{ selectedShift.name }}</span
                            ><strong
                                >{{ selectedShift.start_time }}<ArrowRight />{{ selectedShift.end_time
                                }}<small v-if="selectedShift.end_time <= selectedShift.start_time">+1 hari</small></strong
                            >
                        </div>
                    </section>

                    <section class="form-card duration-card" aria-labelledby="duration-title">
                        <div class="card-heading">
                            <span class="section-icon"><Timer /></span>
                            <div>
                                <span class="eyebrow">02 / DURASI</span>
                                <h3 id="duration-title">Ruang kontribusi ekstra</h3>
                            </div>
                        </div>
                        <p class="section-description">Isi sebelum shift, setelah shift, atau keduanya. Durasi lembur mencakup waktu istirahat.</p>
                        <div v-for="part in parts" :key="part.key" class="duration-block" :class="part.tone">
                            <div class="duration-heading">
                                <span><component :is="part.icon" /></span>
                                <div>
                                    <strong>{{ part.title }}</strong
                                    ><small>{{ part.caption }}</small>
                                </div>
                                <span class="optional-badge">Opsional</span>
                            </div>
                            <div class="duration-fields">
                                <label class="field" :for="part.duration"
                                    ><span>Durasi lembur</span>
                                    <div class="input-wrap">
                                        <input
                                            :id="part.duration"
                                            v-model.number="form[part.duration]"
                                            type="number"
                                            min="0"
                                            max="1440"
                                            step="1"
                                            inputmode="numeric"
                                            placeholder="0"
                                            :aria-invalid="!!errors[part.duration]"
                                            :aria-describedby="errors[part.duration] ? `${part.duration}-error` : undefined"
                                        /><span>menit</span>
                                    </div></label
                                >
                                <label class="field" :for="part.rest"
                                    ><span><Coffee />Istirahat <small>opsional</small></span>
                                    <div class="input-wrap">
                                        <input
                                            :id="part.rest"
                                            v-model.number="form[part.rest]"
                                            type="number"
                                            min="0"
                                            max="1440"
                                            step="1"
                                            inputmode="numeric"
                                            placeholder="0"
                                            :aria-invalid="!!errors[part.rest]"
                                            :aria-describedby="errors[part.rest] ? `${part.rest}-error` : undefined"
                                        /><span>menit</span>
                                    </div></label
                                >
                            </div>
                            <p v-if="errors[part.duration]" :id="`${part.duration}-error`" class="field-error">{{ errors[part.duration] }}</p>
                            <p v-if="errors[part.rest]" :id="`${part.rest}-error`" class="field-error">{{ errors[part.rest] }}</p>
                            <div class="duration-shortcuts" :aria-label="`Pilihan durasi ${part.title.toLowerCase()}`">
                                <button
                                    v-for="minutes in [30, 60, 120, 180]"
                                    :key="minutes"
                                    type="button"
                                    :class="{ active: form[part.duration] === minutes }"
                                    :aria-pressed="form[part.duration] === minutes"
                                    @click="form[part.duration] = minutes"
                                >
                                    {{ minutes < 60 ? `${minutes} mnt` : `${minutes / 60} jam` }}</button
                                ><button
                                    v-if="form[part.duration] || form[part.rest]"
                                    type="button"
                                    class="reset-duration"
                                    @click="
                                        form[part.duration] = 0;
                                        form[part.rest] = 0;
                                    "
                                >
                                    Reset
                                </button>
                            </div>
                            <div v-if="form[part.duration]" class="duration-result">
                                <span><Clock3 />{{ timeRange(part.key) || 'Pilih tanggal dan shift terlebih dahulu' }}</span
                                ><strong>{{ lemburDuration(part.key === 'before' ? beforeNet : afterNet) }} bersih</strong>
                            </div>
                        </div>
                        <div class="duration-summary">
                            <div>
                                <span class="eyebrow">TOTAL LEMBUR BERSIH</span><strong>{{ lemburDuration(total) }}</strong>
                            </div>
                            <span
                                ><CheckCheck />{{ lemburDuration(grossTotal) }} durasi<small
                                    >− {{ lemburDuration(totalBreak) }} istirahat</small
                                ></span
                            >
                        </div>
                    </section>

                    <section class="form-card" aria-labelledby="information-title">
                        <div class="card-heading">
                            <span class="section-icon"><FileText /></span>
                            <div>
                                <span class="eyebrow">03 / DETAIL</span>
                                <h3 id="information-title">Ceritakan kontribusi Anda</h3>
                            </div>
                        </div>
                        <fieldset class="compensation-field">
                            <legend>Kompensasi <b>*</b></legend>
                            <div class="compensation-grid">
                                <label
                                    v-for="option in compensationOptions"
                                    :key="option.value"
                                    class="compensation-option"
                                    :class="{ selected: form.compensation === option.value }"
                                    ><input
                                        v-model="form.compensation"
                                        class="sr-only"
                                        type="radio"
                                        name="compensation"
                                        :value="option.value"
                                        required
                                        :aria-invalid="!!errors.compensation"
                                    /><component :is="option.icon" /><span class="radio-check"
                                        ><Check v-if="form.compensation === option.value" /></span
                                    ><strong>{{ option.title }}</strong
                                    ><small>{{ option.caption }}</small></label
                                >
                            </div>
                        </fieldset>
                        <p v-if="errors.compensation" class="field-error">{{ errors.compensation }}</p>
                        <p class="field-help">Pilihan kompensasi mengikuti kebijakan rumah sakit.</p>
                        <label class="field reason-field" for="lembur-reason"
                            ><span
                                >Alasan lembur <b>*</b><small>{{ form.alasan.length }}/200</small></span
                            ><textarea
                                id="lembur-reason"
                                v-model="form.alasan"
                                maxlength="200"
                                rows="4"
                                placeholder="Misalnya: menyelesaikan serah terima dan laporan pelayanan pasien."
                                required
                                :aria-invalid="!!errors.alasan"
                                :aria-describedby="errors.alasan ? 'reason-error' : undefined"
                            ></textarea>
                        </label>
                        <p v-if="errors.alasan" id="reason-error" class="field-error">{{ errors.alasan }}</p>
                        <div class="attachment-heading">
                            <span><Paperclip />Lampiran <small>opsional</small></span
                            ><span>{{ form.attachments.length }}/5 file</span>
                        </div>
                        <input
                            ref="fileInput"
                            class="sr-only"
                            type="file"
                            multiple
                            :accept="accept"
                            aria-label="Pilih lampiran lembur"
                            @change="selectFiles"
                        />
                        <div v-if="form.attachments.length" class="attachment-list">
                            <div v-for="(file, index) in form.attachments" :key="`${file.name}-${index}`" class="attachment-row">
                                <span class="file-icon"><FileText /></span>
                                <div>
                                    <strong>{{ file.name }}</strong
                                    ><small>{{ (file.size / 1024 / 1024).toFixed(2) }} MB</small>
                                </div>
                                <button
                                    type="button"
                                    class="preview-attachment"
                                    :aria-label="`Pratinjau lampiran ${file.name}`"
                                    @click="previewFile = file"
                                >
                                    <Eye />
                                </button>
                                <button type="button" :aria-label="`Hapus lampiran ${file.name}`" @click="removeFile(index)"><Trash2 /></button>
                            </div>
                        </div>
                        <button v-if="form.attachments.length < 5" type="button" class="add-attachment" @click="fileInput?.click()">
                            <span><Plus /></span><strong>Tambahkan lampiran</strong><small>Surat tugas, foto, atau dokumen pendukung</small>
                        </button>
                        <p class="attachment-help">
                            Maksimal 5 file, masing-masing 10 MB.<br />PDF, JPG, JPEG, PNG, XLSX, XLS, DOCX, DOC, CSV, TXT, PPT, atau PPTX.
                        </p>
                        <p v-if="fileError" class="field-error" role="alert">{{ fileError }}</p>
                        <p
                            v-for="(message, key) in Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith('attachments')))"
                            :key="key"
                            class="field-error"
                            role="alert"
                        >
                            {{ message }}
                        </p>
                    </section>

                    <section class="form-card approval-card" aria-labelledby="approval-title">
                        <div class="card-heading">
                            <span class="section-icon"><ShieldCheck /></span>
                            <div>
                                <span class="eyebrow">SESUAI JABATAN ANDA</span>
                                <h3 id="approval-title">Alur persetujuan lembur</h3>
                            </div>
                        </div>
                        <p class="section-description">Pengajuan diteruskan berurutan. Status disetujui setelah seluruh tahap selesai.</p>
                        <ol v-if="approvalPreview.length" class="approval-preview">
                            <li v-for="step in approvalPreview" :key="step.level">
                                <span>{{ step.level }}</span>
                                <div>
                                    <strong>{{ step.name }}</strong
                                    ><small>Pemberi persetujuan {{ step.level }}</small>
                                </div>
                                <Check v-if="step.level === 1" /><span v-else class="step-dot"></span>
                            </li>
                        </ol>
                        <p v-else class="field-help">Alur persetujuan belum tersedia untuk jabatan Anda.</p>
                    </section>
                </fieldset>
                <p class="reassurance"><ShieldCheck />Periksa kembali detail sebelum mengirim pengajuan.</p>
            </form>

            <footer class="request-actions">
                <div class="footer-summary">
                    <span
                        >Total lembur bersih<strong>{{ lemburDuration(total) }}</strong></span
                    ><span v-if="form.processing" role="status">{{
                        form.progress ? `Mengunggah ${form.progress.percentage}%` : 'Mengirim pengajuan…'
                    }}</span
                    ><span v-else><ShieldCheck />{{ approvalPreview.length }} tahap persetujuan</span>
                </div>
                <div class="action-buttons">
                    <button type="button" class="secondary-button" :disabled="form.processing" @click="leave">Batal</button
                    ><button
                        type="submit"
                        form="lembur-form"
                        class="primary-button"
                        :disabled="form.processing || !!unavailableReason || !shifts.length"
                    >
                        <Send /><span>{{ form.processing ? 'Mengirim…' : 'Kirim pengajuan' }}</span
                        ><ArrowRight v-if="!form.processing" />
                    </button>
                </div>
            </footer>
        </div>
    </main>
    <LemburAttachmentPreview v-if="previewFile" :file="previewFile" @close="previewFile = null" />
    <CutiSheet :open="discardOpen" title="Tinggalkan pengajuan?" description="Perubahan yang belum dikirim akan dihapus." @close="discardOpen = false"
        ><div class="discard-actions">
            <button type="button" class="secondary-button" @click="discardOpen = false">Lanjut isi</button
            ><button type="button" class="primary-button" @click="discard">Ya, tinggalkan</button>
        </div></CutiSheet
    >
</template>

<style scoped>
button,
input,
select,
textarea {
    font: inherit;
}
.request-workspace {
    color: #233f35;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    background: radial-gradient(ellipse at 10% 0%, #dce8dc, transparent 65%), #eaf0e9;
}
.request-shell {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 500px;
    height: 100vh;
    height: 100dvh;
    margin-inline: auto;
    overflow: hidden;
    background: #f6f7f1;
    box-shadow: 0 0 80px #203b2c15;
}
.request-header {
    display: grid;
    flex-shrink: 0;
    grid-template-columns: 42px minmax(0, 1fr) 42px;
    align-items: center;
    gap: 9px;
    padding: calc(14px + env(safe-area-inset-top, 0px)) 20px 15px;
    border-bottom: 1px solid #e1e7da;
    background: #fafbf7;
    z-index: 20;
}
.request-header > div {
    min-width: 0;
    text-align: center;
}
.request-header p {
    margin: 0 0 5px;
    color: #9c8658;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.18em;
}
.request-header h1 {
    margin: 0;
    font-size: clamp(16px, 4.5vw, 20px);
    font-weight: 800;
    letter-spacing: -0.045em;
    line-height: 1.4;
}
.icon-button {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border: 1px solid #e0e7d9;
    border-radius: 14px;
    color: #466346;
    background: #fff;
}
.icon-button svg {
    width: 18px;
    height: 18px;
}
.header-emblem {
    display: grid;
    place-items: center;
    justify-self: end;
    width: 38px;
    height: 38px;
    border: 1px solid #e6dcc1;
    border-radius: 13px;
    background: #f5f0e2;
    color: #9c7d43;
}
.header-emblem svg {
    width: 20px;
    height: 20px;
}
.request-content {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior-y: contain;
    padding: 24px 18px 8px;
    scroll-padding-block: 20px;
    scrollbar-width: thin;
    scrollbar-color: #bfccb6 transparent;
}
.request-intro {
    padding: 0 4px 21px;
}
.eyebrow {
    display: block;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.14em;
    color: #998052;
}
.request-intro h2 {
    margin: 9px 0 8px;
    font-size: 24px;
    font-weight: 750;
    line-height: 1.4;
    letter-spacing: -0.055em;
}
.request-intro h2 > span {
    color: #b3985a;
}
.request-intro > p {
    margin: 0;
    max-width: 330px;
    font-size: 12px;
    line-height: 1.9;
    color: #7a8a72;
}
.employee-line {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 17px;
}
.employee-line > span {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 11px;
    border: 1px solid #e0e6d3;
    background: #edf1e3;
    color: #7b8e61;
}
.employee-line svg {
    width: 15px;
    height: 15px;
}
.employee-line > div {
    min-width: 0;
}
.employee-line strong {
    display: block;
    font-size: 11px;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.employee-line small {
    display: block;
    margin-top: 4px;
    color: #8f9883;
    font-size: 9px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}
.form-body {
    display: grid;
    gap: 16px;
    padding: 0;
    border: 0;
    margin: 0;
    min-width: 0;
}
.form-card {
    min-width: 0;
    border: 1px solid #e2e7d9;
    border-radius: 23px;
    padding: 21px 18px;
    background: #fff;
    box-shadow: 0 4px 15px #334c2403;
}
.card-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
}
.card-heading > div {
    min-width: 0;
}
.section-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border: 1px solid #e1e8d5;
    border-radius: 13px;
    background: #f2f5e9;
    color: #668451;
}
.section-icon svg {
    width: 18px;
    height: 18px;
}
.card-heading h3 {
    margin: 6px 0 0;
    font-size: 14px;
    line-height: 1.5;
    font-weight: 750;
    letter-spacing: -0.035em;
}
.field {
    display: block;
}
.field > span,
.compensation-field legend {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 9px;
    color: #52664c;
    font-size: 11px;
    font-weight: 700;
}
.field b,
.compensation-field b {
    color: #b3875d;
    font-weight: 500;
}
.field > span > svg {
    width: 12px;
    height: 12px;
}
.field > span > small {
    margin-left: auto;
    color: #9aab8d;
    font-size: 9px;
    font-weight: 500;
}
.input-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 48px;
    border: 1px solid #dde5d4;
    border-radius: 13px;
    background: #fbfcf8;
    padding-inline: 12px;
    transition: border-color 0.2s;
}
.input-wrap:focus-within {
    border-color: #718c61;
    box-shadow: 0 0 0 3px #718c6112;
}
.input-wrap > svg {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
    color: #91a080;
}
.input-wrap > svg:last-child {
    width: 14px;
    height: 14px;
}
.input-wrap > input,
.input-wrap > select {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    box-shadow: none;
    padding: 12px 0;
    color: #395337;
    background: transparent;
    font-size: 14px;
}
.input-wrap > select {
    appearance: none;
    background-image: none;
    padding-right: 0;
    font-size: 12px;
}
.input-wrap > span {
    color: #8a997d;
    font-size: 10px;
}
.input-wrap:has([aria-invalid='true']),
textarea[aria-invalid='true'] {
    border-color: #c98772;
    background: #fff8f3;
}
.field-help {
    margin: 8px 0 0;
    font-size: 10px;
    line-height: 1.8;
    color: #8c9a80;
}
.field-error {
    margin: 7px 0 0;
    font-size: 11px;
    line-height: 1.7;
    color: #ad6249;
}
.shift-field {
    margin-top: 20px;
}
.shift-preview {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 11px;
    border-radius: 10px;
    padding: 10px;
    background: #f1f5e9;
    color: #81936b;
    font-size: 9px;
}
.shift-preview > strong {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 650;
    color: #557348;
}
.shift-preview svg {
    width: 12px;
    height: 12px;
}
.shift-preview small {
    border-radius: 4px;
    background: #e4ebd7;
    padding: 2px 4px;
    font-size: 8px;
}
.section-description {
    margin: -6px 0 16px;
    font-size: 11px;
    line-height: 1.9;
    color: #839176;
}
.duration-block {
    padding: 15px 13px 12px;
    border: 1px solid #e7e6d7;
    border-radius: 17px;
    background: #fdfcf7;
}
.duration-block + .duration-block {
    margin-top: 12px;
}
.evening {
    background: #f8faf5;
    border-color: #e1e7d7;
}
.duration-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
}
.duration-heading > span:first-child {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: #f5ecdb;
    color: #ab8746;
}
.evening .duration-heading > span:first-child {
    background: #eaf0df;
    color: #7b935d;
}
.duration-heading svg {
    width: 17px;
    height: 17px;
}
.duration-heading strong {
    display: block;
    color: #526449;
    font-size: 12px;
    font-weight: 750;
}
.duration-heading small {
    display: block;
    margin-top: 3px;
    color: #9a9e88;
    font-size: 8px;
}
.optional-badge {
    margin-left: auto;
    border: 1px solid #e9e7d9;
    border-radius: 6px;
    padding: 4px 6px;
    color: #9b9e88;
    font-size: 8px;
}
.duration-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
}
.duration-fields .field > span {
    font-size: 10px;
}
.duration-fields .field > span > small {
    font-size: 7px;
}
.duration-fields .input-wrap {
    min-height: 46px;
    background: #fff;
    padding-inline: 10px;
}
.duration-fields input {
    font-size: 19px;
    font-weight: 650;
    padding-block: 9px;
    font-variant-numeric: tabular-nums;
}
.duration-fields input::-webkit-inner-spin-button {
    appearance: none;
}
.duration-shortcuts {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 10px;
}
.duration-shortcuts button {
    min-height: 30px;
    border: 1px solid #e4e7d8;
    border-radius: 7px;
    padding: 5px 8px;
    background: #fff;
    color: #8d967c;
    font-size: 9px;
}
.duration-shortcuts button.active {
    border-color: #bbc9a1;
    background: #eaf0da;
    color: #5f7843;
}
.duration-shortcuts .reset-duration {
    margin-left: auto;
    padding-inline: 3px;
    border-color: transparent;
    background: transparent;
    font-size: 8px;
}
.duration-result {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 7px;
    margin-top: 13px;
    border-top: 1px dashed #e3e5d5;
    padding-top: 10px;
    color: #8e9c7f;
    font-size: 8px;
}
.duration-result > span {
    display: flex;
    align-items: center;
    gap: 4px;
    line-height: 1.7;
}
.duration-result svg {
    width: 11px;
    height: 11px;
}
.duration-result > strong {
    font-weight: 700;
    font-size: 9px;
    color: #6c8254;
}
.duration-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 16px;
    border: 1px solid #254b3b;
    border-radius: 16px;
    padding: 17px 14px;
    background: radial-gradient(ellipse at 100% 0, #6884564d, transparent 70%), #264c3e;
    color: #fff;
}
.duration-summary .eyebrow {
    font-size: 7px;
    color: #c7caa5;
    letter-spacing: 0.12em;
}
.duration-summary strong {
    display: block;
    margin-top: 7px;
    font-size: 29px;
    font-weight: 650;
    line-height: 1.2;
    letter-spacing: -0.055em;
    color: #ead6a2;
}
.duration-summary > span {
    position: relative;
    padding-left: 17px;
    font-size: 9px;
    line-height: 1.8;
    color: #c6d3b9;
}
.duration-summary svg {
    position: absolute;
    left: 0;
    top: 3px;
    width: 12px;
    height: 12px;
    color: #beca9b;
}
.duration-summary small {
    display: block;
    font-size: 8px;
    color: #a9bc9b;
}
.compensation-field {
    border: 0;
    margin: 0;
    padding: 0;
    min-width: 0;
}
.compensation-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 9px;
}
.compensation-option {
    position: relative;
    border: 1px solid #e3e8d8;
    border-radius: 14px;
    padding: 13px 11px;
    background: #fcfdf9;
    cursor: pointer;
}
.compensation-option.selected {
    border-color: #91a976;
    background: #eff5e5;
}
.compensation-option:has(:focus-visible) {
    outline: 3px solid #b69b5c;
    outline-offset: 2px;
}
.compensation-option > svg {
    width: 21px;
    height: 21px;
    margin-bottom: 12px;
    color: #809468;
}
.compensation-option strong {
    display: block;
    font-size: 11px;
    font-weight: 700;
    color: #557044;
}
.compensation-option small {
    display: block;
    margin-top: 5px;
    font-size: 8px;
    color: #8e9c7e;
    line-height: 1.6;
}
.radio-check {
    position: absolute;
    top: 14px;
    right: 11px;
    display: grid;
    place-items: center;
    width: 16px;
    height: 16px;
    border: 1px solid #d5dfc6;
    border-radius: 50%;
    background: #fff;
    color: #fff;
}
.selected .radio-check {
    background: #67844f;
    border-color: #67844f;
}
.radio-check svg {
    width: 10px;
    height: 10px;
}
.reason-field {
    margin-top: 23px;
}
textarea {
    display: block;
    resize: vertical;
    min-height: 115px;
    width: 100%;
    border: 1px solid #dde5d3;
    border-radius: 13px;
    padding: 12px;
    background: #fcfdf9;
    color: #4c6341;
    font-size: 12px;
    line-height: 1.9;
    box-shadow: none;
}
textarea::placeholder {
    color: #a2ad95;
}
textarea:focus {
    outline: 0;
    border-color: #718c61;
    box-shadow: 0 0 0 3px #718c6112;
}
.attachment-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin: 24px 0 11px;
    font-size: 10px;
    color: #96a087;
}
.attachment-heading > span:first-child {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #52664a;
    font-size: 11px;
    font-weight: 700;
}
.attachment-heading svg {
    width: 14px;
    height: 14px;
}
.attachment-heading small {
    font-size: 9px;
    font-weight: 500;
    color: #9aa58d;
}
.add-attachment {
    display: grid;
    justify-items: center;
    gap: 7px;
    width: 100%;
    min-height: 115px;
    padding: 16px 9px;
    border: 1px dashed #cddabf;
    border-radius: 14px;
    background: #f9fbf4;
    color: #72945a;
}
.add-attachment > span {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border: 1px solid #dae5c9;
    border-radius: 10px;
    background: #eef3e2;
}
.add-attachment svg {
    width: 17px;
    height: 17px;
}
.add-attachment strong {
    font-size: 11px;
    font-weight: 650;
}
.add-attachment small {
    font-size: 8px;
    color: #98a68a;
    line-height: 1.6;
}
.attachment-help {
    margin: 10px 0 0;
    color: #929e85;
    font-size: 9px;
    line-height: 1.9;
}
.attachment-list {
    display: grid;
    gap: 7px;
    margin-bottom: 10px;
}
.attachment-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px;
    border: 1px solid #e1e8d5;
    border-radius: 11px;
    background: #fafcf5;
}
.file-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 35px;
    border-radius: 8px;
    background: #eef3e1;
    color: #90a372;
}
.file-icon svg {
    width: 16px;
    height: 16px;
}
.attachment-row > div {
    min-width: 0;
    flex: 1;
}
.attachment-row strong {
    display: block;
    overflow-wrap: anywhere;
    font-size: 10px;
    font-weight: 650;
    line-height: 1.7;
}
.attachment-row small {
    color: #96a585;
    font-size: 8px;
}
.attachment-row > button {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    color: #b38c73;
}
.attachment-row > button svg {
    width: 14px;
    height: 14px;
}
.attachment-row > .preview-attachment {
    color: #607f49;
}
.approval-card {
    background: linear-gradient(135deg, #fff, #fafbf5);
}
.approval-preview {
    list-style: none;
    padding: 0;
    margin: 0;
}
.approval-preview li {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 0;
}
.approval-preview li:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 43px;
    bottom: -8px;
    left: 14px;
    border-left: 1px solid #dce5cd;
}
.approval-preview li > span:first-child {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 29px;
    height: 29px;
    border: 1px solid #dbe5c9;
    border-radius: 10px;
    background: #f0f5e5;
    color: #85996c;
    font-size: 11px;
    font-weight: 700;
}
.approval-preview li:first-child > span:first-child {
    border-color: #507045;
    background: #507045;
    color: #fff;
}
.approval-preview li > div {
    min-width: 0;
}
.approval-preview strong {
    display: block;
    font-size: 11px;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.approval-preview small {
    display: block;
    margin-top: 4px;
    color: #99a58c;
    font-size: 9px;
}
.approval-preview li > svg {
    margin-left: auto;
    width: 15px;
    height: 15px;
    color: #9eb17f;
}
.step-dot {
    margin-left: auto;
    margin-right: 6px;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: #c2cdb0;
}
.notice {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 17px;
    border: 1px solid #e8d8b3;
    border-radius: 14px;
    padding: 12px;
    background: #fbf4e6;
    color: #a08244;
    font-size: 11px;
    line-height: 1.8;
}
.notice svg {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    margin-top: 2px;
}
.reassurance {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin: 18px 0;
    color: #95a084;
    font-size: 9px;
    line-height: 1.7;
}
.reassurance svg {
    width: 13px;
    height: 13px;
    flex-shrink: 0;
}
.request-actions {
    flex-shrink: 0;
    border-top: 1px solid #e1e7d8;
    padding: 13px 20px calc(15px + env(safe-area-inset-bottom, 0px));
    background: #fafbf7;
    box-shadow: 0 -6px 22px #30491e05;
    z-index: 10;
}
.footer-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.footer-summary > span:first-child {
    color: #8f9a82;
    font-size: 9px;
}
.footer-summary strong {
    margin-left: 8px;
    color: #4b6b3e;
    font-size: 15px;
    font-weight: 750;
    letter-spacing: -0.04em;
}
.footer-summary > span:last-child {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #9a9e86;
    font-size: 8px;
}
.footer-summary svg {
    width: 12px;
    height: 12px;
}
.action-buttons {
    display: grid;
    grid-template-columns: 85px minmax(0, 1fr);
    gap: 10px;
}
.primary-button,
.secondary-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 48px;
    border: 1px solid #dce4d1;
    border-radius: 14px;
    padding: 12px;
    background: #fff;
    color: #7e8f6c;
    font-size: 12px;
    font-weight: 700;
}
.primary-button {
    border-color: #294f3b;
    background: linear-gradient(120deg, #264d3f, #3d603f);
    color: #fff;
    box-shadow: 0 5px 12px #28482b17;
}
.primary-button svg {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
}
.primary-button svg:last-child {
    width: 14px;
    height: 14px;
    margin-left: auto;
    color: #d5c38b;
}
.discard-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
button,
a {
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
button {
    cursor: pointer;
}
button:disabled {
    opacity: 0.45;
    cursor: default;
}
button:focus-visible {
    outline: 3px solid #bc9b59;
    outline-offset: 3px;
}
fieldset:disabled {
    opacity: 0.65;
}
@media (max-width: 360px) {
    .request-header {
        padding-inline: 14px;
    }
    .request-content {
        padding-inline: 12px;
    }
    .form-card {
        padding-inline: 14px;
    }
    .duration-block {
        padding-inline: 10px;
    }
    .duration-fields {
        gap: 8px;
    }
    .duration-fields .field > span > small {
        display: none;
    }
    .duration-fields .input-wrap {
        padding-inline: 8px;
    }
    .duration-shortcuts button {
        padding-inline: 6px;
    }
    .request-actions {
        padding-inline: 14px;
    }
    .footer-summary strong {
        margin-left: 4px;
    }
    .footer-summary > span:last-child {
        font-size: 7px;
    }
    .request-intro h2 {
        font-size: 22px;
    }
}
@media (max-height: 500px) {
    .request-header {
        padding-block: 9px;
    }
    .request-header p {
        display: none;
    }
    .request-actions {
        padding-block: 9px;
    }
    .footer-summary {
        display: none;
    }
}
@media (prefers-reduced-motion: no-preference) {
    button,
    .compensation-option {
        transition:
            background-color 0.2s,
            border-color 0.2s;
    }
}
</style>
