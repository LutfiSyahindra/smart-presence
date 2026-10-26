<script setup lang="ts">
import { usePermission } from '@/composables/usePermission';
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import type { SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleX,
    Clock3,
    FileText,
    Leaf,
    Plus,
    ShieldCheck,
    SlidersHorizontal,
    Sparkles,
    UsersRound,
} from 'lucide-vue-next';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { date, days, labels } from '../admin-web/cuti/formatters';
import type { CutiRow } from '../admin-web/cuti/types';
import CutiSheet from './components/CutiSheet.vue';
import CutiTypePicker from './components/CutiTypePicker.vue';
import MobileBackLink from './components/MobileBackLink.vue';
import MobileNotificationBell from './components/MobileNotificationBell.vue';

type Scope = 'mine' | 'delegation';
type LeaveOption = { id: number; nama: string; is_tahunan: boolean; tahun: number; jumlah_hari: number; sisa: number };
const props = defineProps<{
    filters: { scope: Scope; month: string; status: string };
    requests: { data: CutiRow[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    balance: { name: string; year: number; total: number; available: number; used: number; pending: number } | null;
    delegationCount: number;
    leaveOptions: LeaveOption[];
    employee: { nama: string; jabatan: string | null; unit: string | null } | null;
    approvalPreview: { level: number; name: string }[];
    unavailableReason: string | null;
    today: string;
    initialRequest?: CutiRow | null;
}>();
const page = usePage<SharedData>();
const sheet = ref<'balance' | 'filter' | 'create' | null>(null);
const selected = ref<CutiRow | null>(null);
const form = useForm({ master_cuti_id: '', tanggal_mulai: '', tanggal_selesai: '', alasan: '' });
const decision = useForm({ decision: 'approved', note: '' });
const cancellation = useForm({});
const confirmingCancellation = ref(false);
const formErrors = computed(() => form.errors as Record<string, string | undefined>);
const decisionErrors = computed(() => decision.errors as Record<string, string | undefined>);
const cancellationErrors = computed(() => cancellation.errors as Record<string, string | undefined>);
const optionYear = computed(() => Number((form.tanggal_mulai || props.today).slice(0, 4)));
const availableTypes = computed(() => props.leaveOptions.filter((option) => !option.is_tahunan || option.tahun === optionYear.value));
const selectedType = computed(() => availableTypes.value.find((option) => String(option.id) === String(form.master_cuti_id)));
const duration = computed(() => days(form.tanggal_mulai, form.tanggal_selesai));
const usedPercent = computed(() => (props.balance?.total ? Math.min(100, (props.balance.used / props.balance.total) * 100) : 0));
const pendingPercent = computed(() =>
    props.balance?.total ? Math.min(100 - usedPercent.value, (props.balance.pending / props.balance.total) * 100) : 0,
);
const periodLabel = computed(() =>
    new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${props.filters.month}-01T12:00:00`)),
);
const balanceOptions = computed(() =>
    props.leaveOptions.filter((option) => !option.is_tahunan || option.tahun === Number(props.filters.month.slice(0, 4))),
);
const statuses = [
    { value: '', label: 'Semua status', description: 'Tampilkan seluruh pengajuan', icon: FileText },
    { value: 'pending', label: 'Menunggu', description: 'Sedang dalam proses persetujuan', icon: Clock3 },
    { value: 'approved', label: 'Disetujui', description: 'Pengajuan yang telah disetujui', icon: CheckCircle2 },
    { value: 'rejected', label: 'Ditolak', description: 'Pengajuan yang tidak disetujui', icon: FileText },
    { value: 'cancelled', label: 'Dibatalkan', description: 'Pengajuan yang telah dibatalkan', icon: CircleX },
];

function applyFilters(changes: Partial<typeof props.filters>) {
    sheet.value = null;
    router.get(route('absensi.mobile.cuti.index'), { ...props.filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
}
function changeMonth(event: Event) {
    const month = (event.target as HTMLInputElement).value;
    if (/^\d{4}-\d{2}$/.test(month)) applyFilters({ month });
}
function create() {
    form.reset();
    const annualType = availableTypes.value.find((option) => option.is_tahunan);
    form.master_cuti_id = annualType ? String(annualType.id) : '';
    form.clearErrors();
    sheet.value = 'create';
}
function submit() {
    form.post(route('absensi.mobile.cuti.store'), {
        replace: true,
        preserveScroll: true,
        onError: () => nextTick(() => document.querySelector<HTMLElement>('#cuti-request-form [aria-invalid="true"]')?.focus()),
        onSuccess: () => {
            sheet.value = null;
            form.reset();
        },
    });
}
function openDetail(cuti: CutiRow) {
    decision.reset();
    decision.clearErrors();
    cancellation.clearErrors();
    confirmingCancellation.value = false;
    selected.value = cuti;
}
function cancel() {
    if (!selected.value?.can_cancel || !confirmingCancellation.value || cancellation.processing) return;
    cancellation.post(route('absensi.mobile.cuti.cancel', { cutiRequest: selected.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
            confirmingCancellation.value = false;
        },
    });
}
function decide() {
    if (!selected.value) return;
    decision.post(route('absensi.mobile.cuti.decide', { cutiRequest: selected.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = null;
            decision.reset();
        },
    });
}
watch(
    () => props.initialRequest,
    (cuti, previous) => {
        if (cuti && cuti.id !== previous?.id) openDetail(cuti);
        else if (cuti && selected.value?.id === cuti.id) selected.value = cuti;
    },
    { immediate: true },
);
watch(
    () => props.requests.data,
    (requests) => {
        const updated = requests.find((cuti) => cuti.id === selected.value?.id);
        if (updated) selected.value = updated;
    },
);
const removeBackHandler = registerMobileBackHandler(() => {
    if (form.processing || decision.processing || cancellation.processing) return true;
    if (confirmingCancellation.value) confirmingCancellation.value = false;
    else if (selected.value) selected.value = null;
    else if (sheet.value) sheet.value = null;
    else return false;
    return true;
});
onUnmounted(removeBackHandler);
const { can } = usePermission();
</script>

<template>
    <Head title="Cuti" />
    <main class="leave-workspace">
        <div class="leave-shell">
            <header class="leave-header">
                <MobileBackLink :href="route('absensi.mobile.home')" class="icon-button" aria-label="Kembali"><ArrowLeft /></MobileBackLink>
                <div>
                    <h1>Cuti</h1>
                    <p>Kelola pengajuan dan saldo cuti</p>
                </div>
                <MobileNotificationBell />
            </header>

            <div class="leave-tabs" role="group" aria-label="Daftar cuti">
                <button
                    type="button"
                    :class="{ active: filters.scope === 'mine' }"
                    :aria-pressed="filters.scope === 'mine'"
                    @click="applyFilters({ scope: 'mine' })"
                >
                    <CalendarDays /> Pengajuan saya
                </button>
                <button
                    type="button"
                    :class="{ active: filters.scope === 'delegation' }"
                    :aria-pressed="filters.scope === 'delegation'"
                    @click="applyFilters({ scope: 'delegation' })"
                >
                    <UsersRound /> Delegasi
                    <span v-if="delegationCount" class="tab-count" :aria-label="`${delegationCount} pengajuan menunggu persetujuan`">{{
                        delegationCount > 99 ? '99+' : delegationCount
                    }}</span>
                </button>
            </div>

            <div class="leave-content">
                <div v-if="page.props.flash.success" class="notice success" role="status">
                    <CheckCircle2 /><span>{{ page.props.flash.success }}</span>
                </div>
                <div v-if="page.props.flash.error" class="notice" role="alert">{{ page.props.flash.error }}</div>

                <section class="balance-card" aria-labelledby="balance-title">
                    <div class="balance-top">
                        <span><span class="gold-dot"></span> SALDO SAYA</span
                        ><span class="year-badge">{{ filters.month.slice(0, 4) }} <Sparkles /></span>
                    </div>
                    <div class="balance-main">
                        <div>
                            <h2 id="balance-title">{{ balance?.name || 'Cuti Tahunan' }}</h2>
                            <p class="balance-number">{{ balance ? balance.available : '—' }} <span>hari</span></p>
                            <p class="balance-caption">{{ balance ? 'Saldo tersedia' : 'Kuota tahun ini belum diberikan' }}</p>
                        </div>
                    </div>
                    <div
                        class="balance-track"
                        role="img"
                        :aria-label="
                            balance
                                ? `${balance.used} hari terpakai, ${balance.pending} hari menunggu, dari ${balance.total} hari`
                                : 'Belum ada kuota'
                        "
                    >
                        <span :style="{ width: `${usedPercent}%` }"></span><i :style="{ width: `${pendingPercent}%` }"></i>
                    </div>
                    <dl class="balance-stats">
                        <div>
                            <dt>Terpakai</dt>
                            <dd>{{ balance?.used ?? '—' }} <small>hari</small></dd>
                        </div>
                        <div>
                            <dt>Menunggu</dt>
                            <dd>{{ balance?.pending ?? '—' }} <small>hari</small></dd>
                        </div>
                        <div>
                            <dt>Total kuota</dt>
                            <dd>{{ balance?.total ?? '—' }} <small>hari</small></dd>
                        </div>
                    </dl>
                    <button type="button" class="balance-link" @click="sheet = 'balance'"><span>Lihat rincian saldo</span><ArrowUpRight /></button>
                </section>

                <div v-if="unavailableReason" class="notice unavailable">
                    <ShieldCheck /><span>{{ unavailableReason }}</span>
                </div>

                <section class="activity-section" aria-labelledby="activity-title">
                    <header class="activity-heading">
                        <div>
                            <h2 id="activity-title">{{ filters.scope === 'mine' ? 'Aktivitas pengajuan' : 'Delegasi untuk Anda' }}</h2>
                        </div>
                        <span class="request-count">{{ requests.total }} pengajuan</span>
                    </header>
                    <p v-if="filters.scope === 'delegation'" class="delegation-help">Pengajuan yang menugaskan Anda sebagai pemberi persetujuan.</p>
                    <Link
                        v-if="can('mobile.cuti.approve') && filters.scope === 'delegation' && delegationCount"
                        href="/absensi/mobile/notifikasi/cuti"
                        class="bulk-approval-link"
                    >
                        <CheckCircle2 /> Tandai &amp; setujui sekaligus <ArrowRight />
                    </Link>
                    <div class="filter-row">
                        <label class="month-picker"
                            ><CalendarDays /><span>{{ periodLabel }}</span
                            ><ChevronDown /><input type="month" :value="filters.month" aria-label="Pilih bulan dan tahun cuti" @change="changeMonth"
                        /></label>
                        <button
                            type="button"
                            class="filter-button"
                            :class="{ 'has-filter': filters.status }"
                            aria-label="Filter status pengajuan"
                            @click="sheet = 'filter'"
                        >
                            <SlidersHorizontal /><span>Filter</span><i v-if="filters.status"></i>
                        </button>
                    </div>
                    <button v-if="filters.status" type="button" class="active-filter" @click="applyFilters({ status: '' })">
                        {{ labels[filters.status] }} <span>×</span><span class="sr-only">Hapus filter status</span>
                    </button>

                    <div v-if="requests.data.length" class="request-list">
                        <button v-for="cuti in requests.data" :key="cuti.id" type="button" class="request-card" @click="openDetail(cuti)">
                            <div class="request-top">
                                <span class="request-icon" :class="cuti.status"><CalendarDays /></span>
                                <div>
                                    <small>{{
                                        filters.scope === 'delegation' ? cuti.nama_pegawai : `CUTI · ${String(cuti.id).padStart(4, '0')}`
                                    }}</small>
                                    <h3>{{ cuti.nama_cuti || 'Pengajuan cuti' }}</h3>
                                </div>
                                <ChevronRight />
                            </div>
                            <p class="request-dates">
                                <time :datetime="cuti.tanggal_mulai">{{ date(cuti.tanggal_mulai) }}</time>
                                <ArrowRight aria-label="sampai" />
                                <time :datetime="cuti.tanggal_selesai">{{ date(cuti.tanggal_selesai) }}</time>
                            </p>
                            <div class="request-bottom">
                                <span class="status-pill" :class="cuti.status"><i></i>{{ labels[cuti.status] }}</span
                                ><span>{{ cuti.jumlah_hari || days(cuti.tanggal_mulai, cuti.tanggal_selesai) }} hari</span>
                            </div>
                            <p v-if="cuti.can_decide" class="request-turn"><ShieldCheck /> Menunggu persetujuan Anda</p>
                        </button>
                        <nav v-if="requests.last_page > 1" class="pagination" aria-label="Halaman pengajuan">
                            <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" preserve-scroll aria-label="Halaman sebelumnya"
                                ><ChevronLeft /></Link
                            ><span v-else></span><span>{{ requests.current_page }} / {{ requests.last_page }}</span
                            ><Link v-if="requests.next_page_url" :href="requests.next_page_url" preserve-scroll aria-label="Halaman berikutnya"
                                ><ChevronRight /></Link
                            ><span v-else></span>
                        </nav>
                    </div>
                    <div v-else class="empty-state">
                        <div class="empty-art" aria-hidden="true">
                            <span class="art-orbit"></span><span class="art-spark spark-one">✦</span><span class="art-spark spark-two">✧</span>
                            <div class="art-calendar">
                                <span></span><CalendarDays /><i><Leaf /></i>
                            </div>
                        </div>
                        <h3>
                            {{
                                filters.status
                                    ? 'Belum ada hasil yang sesuai'
                                    : filters.scope === 'mine'
                                      ? 'Belum ada pengajuan'
                                      : 'Semua sudah tertangani'
                            }}
                        </h3>
                        <p>
                            {{
                                filters.status
                                    ? 'Coba status lain untuk melihat pengajuan pada periode ini.'
                                    : filters.scope === 'mine'
                                      ? 'Rencanakan waktu rehat Anda. Pengajuan cuti akan tersimpan rapi di sini.'
                                      : 'Pengajuan yang didelegasikan kepada Anda akan muncul di sini sesuai periode yang dipilih.'
                            }}
                        </p>
                        <button v-if="filters.status" type="button" class="text-button" @click="applyFilters({ status: '' })">
                            Tampilkan semua status <ArrowRight />
                        </button>
                        <span v-else-if="filters.scope === 'mine'" class="empty-note"><ShieldCheck /> Mudah diajukan, jelas setiap prosesnya</span>
                    </div>
                </section>
            </div>
            <footer class="leave-action-bar">
                <button v-if="can('mobile.cuti.create')" type="button" class="primary-button" :disabled="!!unavailableReason" @click="create">
                    <Plus /><span>Ajukan cuti</span><ArrowUpRight />
                </button>
            </footer>
        </div>

        <CutiSheet
            :open="sheet === 'balance'"
            title="Rincian saldo cuti"
            :description="`Hak cuti Anda untuk tahun ${filters.month.slice(0, 4)}.`"
            @close="sheet = null"
        >
            <div class="sheet-body">
                <div v-if="balance" class="balance-summary">
                    <span class="soft-icon"><CalendarDays /></span>
                    <div>
                        <span>Saldo cuti tahunan tersedia</span><strong>{{ balance.available }} <small>hari</small></strong>
                    </div>
                </div>
                <dl v-if="balance" class="balance-breakdown">
                    <div>
                        <dt>Kuota tahunan</dt>
                        <dd>{{ balance.total }} hari</dd>
                    </div>
                    <div>
                        <dt>Sudah disetujui</dt>
                        <dd>{{ balance.used }} hari</dd>
                    </div>
                    <div>
                        <dt>Dicadangkan · menunggu</dt>
                        <dd>{{ balance.pending }} hari</dd>
                    </div>
                </dl>
                <p class="sheet-note">
                    <ShieldCheck /> Pengajuan menunggu sudah mengurangi saldo tersedia. Saldo kembali jika pengajuan ditolak atau dibatalkan.
                </p>
                <h3 class="small-heading">Jenis cuti yang Anda miliki</h3>
                <div v-for="option in balanceOptions" :key="`${option.id}-${option.tahun}`" class="allocation-row">
                    <span class="soft-icon"><CalendarDays /></span>
                    <div>
                        <strong>{{ option.nama }}</strong
                        ><small>{{ option.is_tahunan ? `Tahun ${option.tahun}` : 'Batas per pengajuan' }}</small>
                    </div>
                    <b>{{ option.is_tahunan ? option.sisa : option.jumlah_hari }} <small>hari</small></b>
                </div>
                <p v-if="!balanceOptions.length" class="sheet-note">
                    Belum ada hak cuti untuk periode ini. Hubungi administrator untuk pemberian kuota.
                </p>
            </div>
        </CutiSheet>

        <CutiSheet :open="sheet === 'filter'" title="Filter pengajuan" description="Lihat pengajuan berdasarkan statusnya." @close="sheet = null"
            ><div class="sheet-body filter-options">
                <button
                    v-for="status in statuses"
                    :key="status.value"
                    type="button"
                    :class="{ chosen: filters.status === status.value }"
                    :aria-pressed="filters.status === status.value"
                    @click="applyFilters({ status: status.value })"
                >
                    <span class="soft-icon"><component :is="status.icon" /></span
                    ><span
                        ><strong>{{ status.label }}</strong
                        ><small>{{ status.description }}</small></span
                    ><Check v-if="filters.status === status.value" />
                </button></div
        ></CutiSheet>

        <CutiSheet
            :open="sheet === 'create'"
            title="Ajukan cuti"
            description="Lengkapi data pengajuan cuti Anda."
            :busy="form.processing"
            @close="sheet = null"
        >
            <form id="cuti-request-form" class="sheet-body leave-form" @submit.prevent="submit">
                <div class="employee-card">
                    <span class="soft-icon"><Leaf /></span>
                    <div>
                        <strong>{{ employee?.nama }}</strong
                        ><small>{{ employee?.unit }} · {{ employee?.jabatan }}</small>
                    </div>
                </div>
                <p v-if="formErrors.cuti" class="field-error" role="alert">{{ formErrors.cuti }}</p>
                <CutiTypePicker
                    v-model="form.master_cuti_id"
                    :options="availableTypes"
                    :year="optionYear"
                    :error="form.errors.master_cuti_id"
                    :disabled="form.processing"
                />
                <div class="date-fields">
                    <label class="form-field"
                        ><span>Mulai tanggal <b>*</b></span
                        ><input v-model="form.tanggal_mulai" type="date" :min="today" required :aria-invalid="!!form.errors.tanggal_mulai" /><small
                            v-if="form.errors.tanggal_mulai"
                            class="field-error"
                            >{{ form.errors.tanggal_mulai }}</small
                        ></label
                    ><label class="form-field"
                        ><span>Sampai tanggal <b>*</b></span
                        ><input
                            v-model="form.tanggal_selesai"
                            type="date"
                            :min="form.tanggal_mulai || today"
                            required
                            :aria-invalid="!!form.errors.tanggal_selesai"
                        /><small v-if="form.errors.tanggal_selesai" class="field-error">{{ form.errors.tanggal_selesai }}</small></label
                    >
                </div>
                <div v-if="duration" class="duration-note">
                    <CalendarDays /><span
                        ><strong>{{ duration }} hari kalender</strong
                        ><small>{{
                            selectedType
                                ? selectedType.is_tahunan
                                    ? `Saldo tersedia ${selectedType.sisa} hari pada ${optionYear}`
                                    : `Maksimal ${selectedType.jumlah_hari} hari per pengajuan`
                                : 'Termasuk tanggal mulai dan selesai'
                        }}</small></span
                    >
                </div>
                <p class="field-help">Kuota tahunan mengikuti tahun tanggal mulai. Cuti lintas tahun diajukan terpisah.</p>
                <label class="form-field"
                    ><span>Alasan cuti <b>*</b></span
                    ><textarea
                        v-model="form.alasan"
                        rows="3"
                        required
                        maxlength="2000"
                        placeholder="Ceritakan keperluan cuti Anda..."
                        :aria-invalid="!!form.errors.alasan"
                    ></textarea
                    ><small v-if="form.errors.alasan" class="field-error">{{ form.errors.alasan }}</small></label
                >
                <div class="approval-preview">
                    <h3><ShieldCheck /> Alur persetujuan</h3>
                    <ol>
                        <li v-for="step in approvalPreview" :key="step.level">
                            <span>{{ step.level }}</span>
                            <div>
                                <small>Pemberi persetujuan {{ step.level }}</small
                                ><strong>{{ step.name }}</strong>
                            </div>
                        </li>
                    </ol>
                </div>
            </form>
            <template #footer>
                <button
                    type="submit"
                    form="cuti-request-form"
                    class="primary-button"
                    :disabled="form.processing || !!unavailableReason || !selectedType"
                >
                    <span>{{ form.processing ? 'Mengirim pengajuan...' : 'Kirim pengajuan' }}</span
                    ><ArrowUpRight />
                </button>
            </template>
        </CutiSheet>

        <CutiSheet
            :open="!!selected"
            title="Detail pengajuan"
            :description="selected ? `CUTI · ${String(selected.id).padStart(4, '0')} · ${selected.created_at}` : ''"
            :busy="decision.processing || cancellation.processing"
            @close="selected = null"
        >
            <div v-if="selected" class="sheet-body detail-body">
                <div class="detail-heading">
                    <h3>{{ selected.nama_cuti || 'Pengajuan cuti' }}</h3>
                    <span class="status-pill" :class="selected.status"><i></i>{{ labels[selected.status] }}</span>
                </div>
                <div class="employee-card">
                    <span class="soft-icon"><CalendarDays /></span>
                    <div>
                        <strong>{{ date(selected.tanggal_mulai) }} – {{ date(selected.tanggal_selesai) }}</strong
                        ><small>{{ selected.jumlah_hari || days(selected.tanggal_mulai, selected.tanggal_selesai) }} hari kalender</small>
                    </div>
                </div>
                <div>
                    <h4 class="small-heading">Pemohon</h4>
                    <p>{{ selected.nama_pegawai }}</p>
                    <small class="field-help">{{ selected.unit }} · {{ selected.jabatan }}</small>
                </div>
                <div>
                    <h4 class="small-heading">Alasan cuti</h4>
                    <p class="reason-text">{{ selected.alasan }}</p>
                </div>
                <div class="approval-preview">
                    <h3><ShieldCheck /> Perjalanan persetujuan</h3>
                    <ol>
                        <li v-for="step in selected.approvals" :key="step.level" :class="step.status">
                            <span
                                ><Check v-if="step.status === 'approved'" /><Clock3 v-else-if="step.status === 'pending'" /><template v-else>{{
                                    step.level
                                }}</template></span
                            >
                            <div>
                                <strong>{{ step.name }}</strong
                                ><small
                                    >{{ labels[step.status] }}<template v-if="step.decided_at"> · {{ step.decided_at }}</template></small
                                >
                                <p v-if="step.note" class="approval-note">{{ step.note }}</p>
                            </div>
                        </li>
                    </ol>
                </div>
                <form v-if="selected.can_decide" id="cuti-decision-form" class="leave-form decision-form" @submit.prevent="decide">
                    <h4 class="small-heading">Giliran Anda memberikan keputusan</h4>
                    <label class="form-field"
                        ><span>Keputusan</span
                        ><select v-model="decision.decision">
                            <option value="approved">Setujui pengajuan</option>
                            <option value="rejected">Tolak pengajuan</option>
                        </select></label
                    ><label class="form-field"
                        ><span>Catatan {{ decision.decision === 'rejected' ? '(wajib)' : '(opsional)' }}</span
                        ><textarea
                            v-model="decision.note"
                            rows="3"
                            maxlength="1000"
                            :required="decision.decision === 'rejected'"
                            :aria-invalid="!!decision.errors.note"
                            placeholder="Tuliskan catatan untuk pemohon..."
                        ></textarea
                        ><small v-if="decision.errors.note" class="field-error">{{ decision.errors.note }}</small></label
                    >
                    <p v-if="decisionErrors.decision" class="field-error" role="alert">{{ decisionErrors.decision }}</p>
                </form>
            </div>
            <template v-if="selected?.can_decide || selected?.can_cancel" #footer>
                <button
                    v-if="selected.can_decide"
                    type="submit"
                    form="cuti-decision-form"
                    class="primary-button"
                    :class="{ 'reject-button': decision.decision === 'rejected' }"
                    :disabled="decision.processing"
                >
                    {{ decision.processing ? 'Menyimpan...' : decision.decision === 'rejected' ? 'Tolak pengajuan' : 'Simpan persetujuan'
                    }}<ArrowRight />
                </button>
                <div v-else class="cancellation-actions">
                    <p v-if="cancellationErrors.cancel" class="field-error" role="alert">{{ cancellationErrors.cancel }}</p>
                    <template v-if="confirmingCancellation">
                        <p id="cancel-confirmation" class="confirmation-question">Batalkan pengajuan cuti ini?</p>
                        <p class="field-help">
                            Alur persetujuan dihentikan dan saldo cuti dikembalikan. Anda dapat mengajukan kembali jika diperlukan.
                        </p>
                        <div class="confirmation-buttons">
                            <button
                                type="button"
                                class="secondary-button"
                                :disabled="cancellation.processing"
                                @click="confirmingCancellation = false"
                            >
                                Kembali
                            </button>
                            <button
                                type="button"
                                class="primary-button reject-button"
                                aria-describedby="cancel-confirmation"
                                :disabled="cancellation.processing"
                                @click="cancel"
                            >
                                {{ cancellation.processing ? 'Membatalkan...' : 'Ya, batalkan' }}
                            </button>
                        </div>
                    </template>
                    <button v-else type="button" class="secondary-button cancel-button" @click="confirmingCancellation = true">
                        <CircleX /> Batalkan cuti
                    </button>
                </div>
            </template>
        </CutiSheet>
    </main>
</template>

<style scoped>
.leave-workspace {
    --page-gutter: clamp(16px, 4vw, 24px);
    min-height: 100svh;
    background: radial-gradient(ellipse at 10% 10%, #d8e9e1, transparent 50%), #e9efeb;
    color: #173b3a;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    color-scheme: light;
}
.leave-shell {
    width: 100%;
    max-width: 500px;
    min-height: 100svh;
    margin: auto;
    background: #f8faf7;
    box-shadow: 0 0 80px #163e3414;
}
button,
a,
input,
select,
textarea {
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
}
button,
input,
select,
textarea {
    font: inherit;
}
button,
a {
    transition:
        background 0.18s,
        border-color 0.18s,
        box-shadow 0.18s;
}
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 3px solid #b99754;
    outline-offset: 3px;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
.leave-header {
    position: sticky;
    z-index: 20;
    top: 0;
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 44px;
    align-items: center;
    gap: 12px;
    padding: calc(16px + env(safe-area-inset-top)) var(--page-gutter) 18px;
    border-bottom: 1px solid #e1e8df;
    background: #f8faf7;
}
.leave-header > div {
    min-width: 0;
    text-align: center;
}
.icon-button {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    flex: 0 0 auto;
    border: 1px solid #dce5db;
    border-radius: 14px;
    background: #fff;
    color: #234a43;
}
.icon-button svg {
    width: 20px;
    height: 20px;
}
.leave-header h1 {
    margin: 0;
    font-size: 24px;
    line-height: 1.25;
    letter-spacing: -0.04em;
    font-weight: 800;
}
.leave-header p {
    margin: 4px 0 0;
    color: #63756b;
    font-size: 12px;
}
.header-mark {
    display: grid;
    place-items: center;
    justify-self: end;
    width: 36px;
    height: 36px;
    border: 1px solid #dfdfcd;
    border-radius: 50%;
    color: #8b7546;
    background: #f4f1e7;
}
.header-mark svg {
    width: 18px;
    height: 18px;
}
.leave-tabs {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 4px;
    margin: 0 var(--page-gutter) 18px;
    padding: 4px;
    border: 1px solid #e0e7dd;
    border-radius: 16px;
    background: #edf1eb;
}
.leave-tabs button {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 0;
    min-height: 44px;
    gap: 6px;
    padding: 8px 6px;
    border-radius: 12px;
    color: #5d7264;
    font-size: 13px;
    font-weight: 650;
}
.leave-tabs button svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.leave-tabs button.active {
    background: #fff;
    color: #245b47;
    box-shadow: 0 2px 8px #273f3610;
}
.tab-count {
    flex-shrink: 0;
    border-radius: 6px;
    background: #e0eadd;
    padding: 1px 5px;
    font-size: 11px;
}
.leave-content {
    padding: 0 var(--page-gutter) calc(112px + env(safe-area-inset-bottom));
}
.balance-card {
    overflow: hidden;
    border: 1px solid #355d4e;
    border-radius: 22px;
    padding: 16px 18px 0;
    color: #fff;
    background: radial-gradient(ellipse at 100% 0, #4d7052a6, transparent 65%), linear-gradient(115deg, #193f36, #244e3e);
    box-shadow: 0 10px 24px -14px #234e3f55;
}
.balance-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.balance-top > span:first-child {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #e4d5b1;
    font-size: 11px;
    font-weight: 650;
    letter-spacing: 0.1em;
}
.gold-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #debf7c;
}
.year-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #ffffff26;
    border-radius: 8px;
    padding: 4px 8px;
    background: #ffffff0d;
    color: #ecdfbd;
    font-size: 12px;
}
.year-badge svg {
    width: 12px;
    height: 12px;
}
.balance-main {
    margin: 10px 0 14px;
}
.balance-main h2 {
    margin: 0;
    font-size: 14px;
    line-height: 1.5;
    font-weight: 500;
    color: #e1eade;
    overflow-wrap: anywhere;
}
.balance-number {
    margin: 2px 0;
    font-size: 44px;
    line-height: 1.15;
    font-weight: 650;
    letter-spacing: -0.05em;
}
.balance-number > span {
    margin-left: 3px;
    color: #e6d4a7;
    font-size: 16px;
    letter-spacing: 0;
    font-weight: 400;
}
.balance-caption {
    margin: 0;
    color: #c3d3c7;
    font-size: 12px;
}
.balance-track {
    display: flex;
    height: 5px;
    overflow: hidden;
    border-radius: 10px;
    background: #ffffff26;
}
.balance-track span {
    background: #ceaf75;
}
.balance-track i {
    background: #8cafa0;
}
.balance-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    margin: 12px 0 14px;
}
.balance-stats > div + div {
    border-left: 1px solid #ffffff24;
    padding-left: 12px;
}
.balance-stats dt {
    color: #c3d3c7;
    font-size: 11px;
    font-weight: 400;
}
.balance-stats dd {
    margin: 3px 0 0;
    color: #faf6e8;
    font-size: 16px;
    font-weight: 650;
}
.balance-stats small {
    font-size: 11px;
    font-weight: 400;
}
.balance-link {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: calc(100% + 36px);
    min-height: 44px;
    margin: 0 -18px;
    padding: 10px 18px;
    border-top: 1px solid #ffffff1c;
    background: #ffffff06;
    color: #f0e3c5;
    font-size: 12px;
    font-weight: 550;
}
.balance-link:hover {
    background: #ffffff10;
}
.balance-link svg {
    width: 17px;
    height: 17px;
}
.activity-section {
    margin-top: 24px;
}
.activity-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.activity-heading h2 {
    margin: 0;
    font-size: 18px;
    line-height: 1.4;
    letter-spacing: -0.03em;
    font-weight: 750;
}
.request-count {
    color: #637561;
    font-size: 12px;
    white-space: nowrap;
}
.filter-row {
    display: flex;
    gap: 8px;
    margin-top: 14px;
}
.month-picker {
    display: flex;
    align-items: center;
    position: relative;
    flex: 1;
    min-width: 0;
    min-height: 46px;
    gap: 8px;
    border: 1px solid #dce5d8;
    border-radius: 12px;
    padding: 11px 12px;
    background: #fff;
    font-size: 13px;
    font-weight: 550;
}
.month-picker > span {
    min-width: 0;
}
.month-picker > svg {
    width: 17px;
    height: 17px;
    flex-shrink: 0;
    color: #63775f;
}
.month-picker > svg:last-of-type {
    margin-left: auto;
    width: 13px;
}
.month-picker input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
    color-scheme: light;
    font-size: 16px;
}
.month-picker input::-webkit-calendar-picker-indicator {
    position: absolute;
    inset: 0;
    width: auto;
    height: auto;
    cursor: pointer;
}
.month-picker:focus-within {
    outline: 3px solid #b99754;
    outline-offset: 3px;
}
.filter-button {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    flex-shrink: 0;
    min-height: 46px;
    gap: 7px;
    border: 1px solid #dce5d8;
    border-radius: 12px;
    padding: 10px 12px;
    background: #fff;
    color: #345740;
    font-size: 13px;
    font-weight: 550;
}
.filter-button svg {
    width: 17px;
    height: 17px;
}
.filter-button.has-filter {
    border-color: #60835a;
    background: #edf4ec;
}
.filter-button i {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #8c6b2c;
}
.active-filter {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    gap: 14px;
    margin-top: 10px;
    border: 1px solid #d5e2d2;
    border-radius: 10px;
    padding: 8px 12px;
    background: #edf3e9;
    color: #35613e;
    font-size: 13px;
}
.active-filter > span:first-child {
    font-size: 20px;
    line-height: 1;
}
.empty-state {
    padding: 24px 8px 12px;
    text-align: center;
}
.empty-art {
    display: grid;
    place-items: center;
    position: relative;
    width: 120px;
    height: 100px;
    margin: 0 auto 16px;
}
.art-orbit {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: radial-gradient(circle, #e4ecdc, #eff3e9 62%, transparent 63%);
}
.art-calendar {
    display: grid;
    place-items: center;
    position: relative;
    width: 62px;
    height: 68px;
    border: 1px solid #fff;
    border-radius: 14px;
    background: linear-gradient(130deg, #fff, #edf1e9);
    box-shadow: 6px 10px 20px #2c49311c;
    transform: rotate(-9deg);
}
.art-calendar > span {
    position: absolute;
    top: -3px;
    width: 36px;
    height: 12px;
    border-inline: 4px solid #c2b184;
}
.art-calendar > svg {
    width: 34px;
    height: 34px;
    color: #688567;
    stroke-width: 1.3;
}
.art-calendar i {
    display: grid;
    place-items: center;
    position: absolute;
    right: -14px;
    bottom: -7px;
    width: 36px;
    height: 36px;
    border: 3px solid #f2f5ed;
    border-radius: 50%;
    background: #31583c;
    color: #e4cd94;
}
.art-calendar i svg {
    width: 18px;
    height: 18px;
}
.art-spark {
    position: absolute;
    z-index: 1;
    color: #a48b52;
}
.spark-one {
    top: 10px;
    right: 5px;
}
.spark-two {
    bottom: 5px;
    left: 5px;
    font-size: 20px;
}
.empty-state h3 {
    margin: 0 0 8px;
    font-size: 17px;
    font-weight: 750;
    letter-spacing: -0.025em;
}
.empty-state p {
    max-width: 300px;
    margin: auto;
    color: #65775f;
    font-size: 13px;
    line-height: 1.7;
}
.empty-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-top: 16px;
    color: #687a60;
    font-size: 11px;
}
.empty-note svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}
.leave-action-bar {
    position: fixed;
    z-index: 10;
    bottom: 0;
    left: 50%;
    width: 100%;
    max-width: 500px;
    padding: 12px var(--page-gutter) calc(12px + env(safe-area-inset-bottom));
    border-top: 1px solid #e1e8df;
    background: #f8faf7f5;
    backdrop-filter: blur(12px);
    transform: translateX(-50%);
}
.primary-button {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    min-height: 52px;
    gap: 10px;
    border: 1px solid #285143;
    border-radius: 14px;
    padding: 13px 16px;
    background: linear-gradient(110deg, #224c3d, #366447);
    color: #fff;
    font-size: 15px;
    line-height: 1.5;
    font-weight: 650;
    box-shadow: 0 5px 14px #28523a20;
}
.primary-button svg {
    width: 19px;
    height: 19px;
    flex-shrink: 0;
}
.leave-action-bar .primary-button > svg:last-child {
    margin-left: auto;
    color: #dbc58b;
}
.leave-action-bar .primary-button > span {
    flex: 1;
    text-align: left;
}
.primary-button:not(:disabled):hover {
    box-shadow: 0 6px 18px #28523a42;
}
.notice {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 16px;
    border: 1px solid #e5d5b0;
    border-radius: 12px;
    padding: 12px;
    background: #fbf7ed;
    color: #78602e;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.notice svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    margin-top: 2px;
}
.notice.success {
    border-color: #d6e5ce;
    background: #eff7e9;
    color: #356c43;
}
.notice.unavailable {
    margin: 16px 0 0;
}
.delegation-help {
    margin: 8px 0 0;
    color: #65775f;
    font-size: 13px;
    line-height: 1.6;
}
.bulk-approval-link {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    margin-top: 8px;
    color: #356c43;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
.bulk-approval-link svg {
    flex-shrink: 0;
    width: 17px;
    height: 17px;
}
.request-list {
    display: grid;
    gap: 12px;
    margin-top: 14px;
}
.request-card {
    width: 100%;
    min-width: 0;
    border: 1px solid #dfe6da;
    border-radius: 16px;
    padding: 14px;
    background: #fff;
    text-align: left;
}
.request-card:hover {
    border-color: #91ab87;
}
.request-top {
    display: flex;
    align-items: center;
    gap: 10px;
}
.request-icon {
    display: grid;
    place-items: center;
    flex: 0 0 40px;
    height: 40px;
    border-radius: 12px;
    background: #f5f0e4;
    color: #887135;
}
.request-icon.approved {
    background: #eff5e9;
    color: #547f50;
}
.request-icon.rejected {
    background: #f9eee9;
    color: #a65d4c;
}
.request-icon.cancelled {
    background: #eef1ee;
    color: #617063;
}
.request-icon svg {
    width: 20px;
    height: 20px;
}
.request-top > div {
    min-width: 0;
    flex: 1;
    overflow-wrap: anywhere;
}
.request-top small {
    display: block;
    color: #64775c;
    font-size: 11px;
}
.request-top h3 {
    margin: 3px 0 0;
    font-size: 15px;
    line-height: 1.5;
    font-weight: 650;
}
.request-top > svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: #76896b;
}
.request-dates {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    margin: 14px 0;
    color: #5f7356;
    font-size: 13px;
}
.request-dates time {
    white-space: nowrap;
}
.request-dates svg {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
}
.request-bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border-top: 1px solid #edf0e9;
    padding-top: 12px;
}
.request-bottom > span:last-child {
    color: #5c7152;
    font-size: 13px;
    font-weight: 550;
}
.request-turn {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 12px 0 0;
    border-radius: 8px;
    padding: 8px;
    background: #fbf5e8;
    color: #806224;
    font-size: 12px;
}
.request-turn svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    gap: 6px;
    border-radius: 8px;
    padding: 5px 9px;
    background: #f9f3e3;
    color: #7f621f;
    font-size: 12px;
    line-height: 1.5;
    white-space: nowrap;
}
.status-pill i {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.status-pill.approved {
    background: #eff5e9;
    color: #426d39;
}
.status-pill.rejected {
    background: #f9eee9;
    color: #994f3e;
}
.status-pill.cancelled {
    background: #eef1ee;
    color: #526354;
}
.pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    color: #5f7456;
    font-size: 13px;
}
.pagination > * {
    min-width: 44px;
}
.pagination a {
    display: grid;
    place-items: center;
    min-height: 44px;
    border: 1px solid #dbe5d4;
    border-radius: 11px;
    background: #fff;
}
.pagination svg {
    width: 18px;
    height: 18px;
}
.sheet-body {
    color: #173b3a;
    font-size: 14px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.soft-icon {
    display: grid;
    place-items: center;
    flex: 0 0 40px;
    height: 40px;
    border-radius: 12px;
    background: #eef3e9;
    color: #56764a;
}
.soft-icon svg {
    width: 20px;
    height: 20px;
}
.balance-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    border-radius: 16px;
    padding: 16px;
    background: #edf3e8;
}
.balance-summary > div {
    min-width: 0;
}
.balance-summary span {
    color: #5e7354;
    font-size: 13px;
}
.balance-summary strong {
    display: block;
    font-size: 32px;
    font-weight: 650;
    letter-spacing: -0.04em;
}
.balance-summary strong small {
    font-size: 14px;
    font-weight: 400;
    letter-spacing: 0;
}
.balance-breakdown {
    margin: 16px 0;
}
.balance-breakdown > div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid #e7ece1;
    padding: 12px 0;
}
.balance-breakdown dt {
    color: #627457;
    font-size: 13px;
    font-weight: 400;
}
.balance-breakdown dd {
    flex-shrink: 0;
    margin: 0;
    font-weight: 600;
}
.sheet-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border-radius: 10px;
    padding: 12px;
    background: #f5f2e8;
    color: #776638;
    font-size: 13px;
    line-height: 1.6;
}
.sheet-note svg {
    width: 17px;
    height: 17px;
    flex-shrink: 0;
    margin-top: 2px;
}
.small-heading {
    margin: 22px 0 10px;
    font-size: 14px;
    font-weight: 650;
}
.allocation-row {
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid #e8ece2;
    padding: 14px 0;
}
.allocation-row > div {
    min-width: 0;
    flex: 1;
}
.allocation-row strong,
.allocation-row small {
    display: block;
    font-size: 13px;
}
.allocation-row small {
    color: #64765a;
    font-size: 12px;
}
.allocation-row b {
    flex-shrink: 0;
    font-size: 17px;
    font-weight: 600;
}
.allocation-row b small {
    display: inline;
    font-weight: 400;
}
.filter-options {
    display: grid;
    gap: 10px;
}
.filter-options > button {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 64px;
    border: 1px solid #dfe7d8;
    border-radius: 14px;
    padding: 12px;
    background: #fff;
    text-align: left;
}
.filter-options > button.chosen {
    border-color: #7c9c6e;
    background: #f0f5eb;
}
.filter-options > button > span:last-of-type {
    min-width: 0;
    flex: 1;
}
.filter-options strong,
.filter-options small {
    display: block;
}
.filter-options strong {
    font-size: 14px;
}
.filter-options small {
    margin-top: 3px;
    color: #63775a;
    font-size: 12px;
    line-height: 1.5;
}
.filter-options > button > svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: #56784a;
}
.employee-card {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #e1e9d9;
    border-radius: 13px;
    padding: 12px;
    background: #f0f4ec;
}
.employee-card > div {
    min-width: 0;
}
.employee-card strong {
    display: block;
    font-size: 14px;
    font-weight: 600;
}
.employee-card small {
    display: block;
    margin-top: 3px;
    color: #607453;
    font-size: 12px;
    line-height: 1.6;
}
.leave-form {
    display: grid;
    gap: 18px;
}
.form-field {
    display: flex;
    flex-direction: column;
    min-width: 0;
    gap: 8px;
    margin: 0;
    font-size: 14px;
    font-weight: 550;
}
.form-field > span > b {
    color: #946c34;
    font-weight: 400;
}
.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    min-width: 0;
    min-height: 48px;
    border: 1px solid #d6e1cd;
    border-radius: 11px;
    padding: 12px;
    background-color: #fff;
    color: #244735;
    font-size: 16px;
    line-height: 1.5;
    font-weight: 400;
}
.form-field select {
    padding-right: 34px;
    text-overflow: ellipsis;
}
.form-field input[type='date'] {
    appearance: none;
    color-scheme: light;
}
.form-field input[aria-invalid='true'],
.form-field select[aria-invalid='true'],
.form-field textarea[aria-invalid='true'] {
    border-color: #ac503c;
}
.form-field textarea {
    resize: vertical;
}
.form-field textarea::placeholder {
    color: #75866a;
}
.date-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.field-error {
    color: #a34834;
    font-size: 13px;
    line-height: 1.6;
    font-weight: 400;
}
.field-help {
    color: #627456;
    font-size: 12px;
    line-height: 1.7;
    font-weight: 400;
}
.leave-form > .field-help {
    margin: -6px 0 0;
}
.duration-note {
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 11px;
    padding: 12px;
    background: #f5f2e8;
    color: #79602b;
}
.duration-note svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
.duration-note strong,
.duration-note small {
    display: block;
    font-size: 14px;
}
.duration-note small {
    margin-top: 2px;
    color: #776a46;
    font-size: 12px;
}
.approval-preview {
    border-top: 1px solid #e5ebdd;
    padding-top: 18px;
}
.approval-preview h3 {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 16px;
    font-size: 14px;
    font-weight: 650;
}
.approval-preview h3 svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: #637e53;
}
.approval-preview ol {
    margin: 0;
    padding: 0;
    list-style: none;
}
.approval-preview li {
    display: flex;
    position: relative;
    gap: 12px;
    padding-bottom: 20px;
}
.approval-preview li:last-child {
    padding-bottom: 0;
}
.approval-preview li:not(:last-child)::before {
    content: '';
    position: absolute;
    top: 32px;
    bottom: 0;
    left: 15px;
    width: 1px;
    background: #dfe7d7;
}
.approval-preview li > span {
    display: grid;
    place-items: center;
    flex: 0 0 32px;
    height: 32px;
    border: 1px solid #dce6d3;
    border-radius: 50%;
    background: #eff3e9;
    color: #587a43;
    font-size: 12px;
}
.approval-preview li > div {
    min-width: 0;
    flex: 1;
}
.approval-preview li.approved > span {
    background: #5b7e4f;
    color: #fff;
}
.approval-preview li.pending > span {
    border-color: #eaddb5;
    background: #f7efdb;
    color: #80662c;
}
.approval-preview li > span svg {
    width: 15px;
    height: 15px;
}
.approval-preview small,
.approval-preview strong {
    display: block;
    font-size: 12px;
    line-height: 1.6;
    color: #647658;
}
.approval-preview strong {
    color: #244735;
    font-size: 14px;
    font-weight: 550;
}
.approval-note {
    margin: 7px 0 0;
    border-radius: 8px;
    padding: 8px 10px;
    background: #f4f3ec;
    color: #6d704e;
    font-size: 13px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.detail-body {
    display: grid;
    gap: 20px;
}
.detail-body .small-heading {
    margin-top: 0;
}
.detail-heading {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}
.detail-heading h3 {
    min-width: 0;
    flex: 1 1 170px;
    margin: 0;
    font-size: 19px;
    line-height: 1.5;
    font-weight: 650;
    letter-spacing: -0.025em;
}
.detail-body p {
    margin: 0;
}
.detail-body .reason-text {
    color: #5e7353;
    font-size: 14px;
    line-height: 1.7;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.decision-form {
    gap: 16px;
    border-top: 1px solid #e4ebdc;
    padding-top: 20px;
}
.reject-button {
    border-color: #994936;
    background: #a45643;
}
.cancellation-actions {
    display: grid;
    gap: 10px;
}
.cancellation-actions p {
    margin: 0;
}
.confirmation-question {
    font-size: 14px;
    font-weight: 650;
}
.confirmation-buttons {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
    gap: 10px;
}
.secondary-button {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 52px;
    width: 100%;
    gap: 8px;
    border: 1px solid #d6e1cd;
    border-radius: 14px;
    padding: 12px;
    background: #fff;
    color: #345740;
    font-size: 14px;
    line-height: 1.5;
    font-weight: 600;
}
.cancel-button {
    border-color: #e2c6bd;
    background: #fffaf8;
    color: #994f3e;
}
.cancel-button svg {
    width: 19px;
    height: 19px;
}
.text-button {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    gap: 7px;
    margin-top: 12px;
    color: #456c36;
    font-size: 13px;
    font-weight: 550;
}
.text-button svg {
    width: 16px;
    height: 16px;
}
@media (max-width: 380px) {
    .header-mark {
        display: none;
    }
    .date-fields {
        grid-template-columns: minmax(0, 1fr);
    }
    .balance-stats > div + div {
        padding-left: 8px;
    }
}
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        transition: none !important;
    }
}
</style>
