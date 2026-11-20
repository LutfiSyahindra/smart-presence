<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CalendarDays, ChevronDown, ChevronLeft, ChevronRight, Clock3, LogIn, LogOut, MapPin, Search, UsersRound } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import MobileBackLink from './components/MobileBackLink.vue';

type Filters = { search: string | null; date_from: string; date_to: string; status: string | null };
type Clock = {
    time: string | null;
    date_time: string | null;
    approval_status: string | null;
    photo_url: string | null;
    location: { name: string | null; address: string | null; map_url: string | null } | null;
};
type Record = {
    id: number | null;
    pegawai_id: number;
    has_presensi: boolean;
    cuti: { nama: string | null; tanggal_mulai: string; tanggal_selesai: string } | null;
    pegawai: { nama: string; nik: string | null; unit: string | null; jabatan: string | null; profile_photo_url: string | null };
    tanggal_label: string;
    status: string | null;
    shift: { nama: string; jam_masuk: string | null; jam_keluar: string | null; note: string | null } | null;
    clock_in: Clock;
    clock_out: Clock;
    late_minutes: number;
    early_departure_minutes: number;
    overtime_minutes: number;
    notes: string | null;
};
const props = defineProps<{
    filters: Filters;
    records: { data: Record[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
    overview: { total: number; present: number; late: number; day_off: number };
    unitName: string | null;
    scopeMode: 'unit-kerja' | 'approval-line' | 'all';
    approvalTypeLabel: string | null;
    today: string;
}>();
const draft = reactive({ ...props.filters });
const loading = ref(false);
const error = ref('');
const base = '/absensi/mobile/presensi-pegawai';
const scopeHeading = computed(() => {
    if (props.scopeMode === 'all') {
        return { eyebrow: 'SELURUH PEGAWAI', title: 'Seluruh unit kerja', description: 'Pantau kehadiran seluruh pegawai sesuai akses role Anda.' };
    }
    if (props.scopeMode === 'approval-line') {
        return {
            eyebrow: 'APPROVAL LINE',
            title: `Approval line ${props.approvalTypeLabel}`,
            description: 'Pantau kehadiran pegawai sesuai penugasan approval Anda.',
        };
    }
    return { eyebrow: 'UNIT KERJA', title: props.unitName, description: 'Pantau kehadiran pegawai dalam unit Anda.' };
});
const statuses: { value: string; label: string }[] = [
    { value: '', label: 'Semua status' },
    { value: 'present', label: 'Hadir' },
    { value: 'late', label: 'Terlambat' },
    { value: 'on-time', label: 'Tepat waktu' },
    { value: 'incomplete', label: 'Belum lengkap' },
    { value: 'unrecorded', label: 'Belum ada presensi' },
    { value: 'cuti', label: 'Cuti' },
    { value: 'day-off', label: 'Hari libur' },
    { value: 'overtime', label: 'Lembur' },
    { value: 'approval-pending', label: 'Menunggu persetujuan' },
    { value: 'approval-rejected', label: 'Presensi ditolak' },
];
const statusLabels: { [key: string]: string } = {
    late: 'Terlambat',
    'on-time': 'Tepat waktu',
    incomplete: 'Belum lengkap',
    cuti: 'Cuti',
    'day-off': 'Hari libur',
    'approval-pending': 'Menunggu persetujuan',
    'approval-rejected': 'Ditolak',
};
const approvalLabels: { [key: string]: string } = { pending: 'Menunggu persetujuan', rejected: 'Ditolak', approved: 'Disetujui' };
watch(
    () => props.filters,
    (filters) => Object.assign(draft, filters),
);

function applyFilters(reset = false) {
    const filters = reset ? { search: '', date_from: props.today, date_to: props.today, status: '' } : { ...draft };
    error.value = '';
    router.get(base, filters, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onError: (errors) => (error.value = Object.values(errors).join(' ')),
        onFinish: () => (loading.value = false),
    });
}

function formatDate(date: string): string {
    return date.split('-').reverse().join('/');
}
</script>

<template>
    <Head title="Presensi Pegawai" />
    <main class="approval-workspace">
        <div class="approval-shell">
            <header class="approval-header">
                <div class="header-row">
                    <MobileBackLink class="icon-button" aria-label="Kembali ke beranda"><ArrowLeft /></MobileBackLink>
                    <div class="header-title">
                        <h1>Presensi Pegawai</h1>
                        <span>KEHADIRAN TIM</span>
                    </div>
                    <span class="icon-button" aria-hidden="true"><UsersRound /></span>
                </div>
            </header>

            <div class="team-content" :aria-busy="loading">
                <section class="team-overview" aria-labelledby="unit-title">
                    <span class="team-eyebrow"><UsersRound /> {{ scopeHeading.eyebrow }}</span>
                    <h2 id="unit-title">{{ scopeHeading.title }}</h2>
                    <p>{{ scopeHeading.description }}</p>
                    <dl class="metrics">
                        <div>
                            <dt>Catatan</dt>
                            <dd>{{ overview.total }}</dd>
                        </div>
                        <div>
                            <dt>Hadir</dt>
                            <dd>{{ overview.present }}</dd>
                        </div>
                        <div>
                            <dt>Terlambat</dt>
                            <dd>{{ overview.late }}</dd>
                        </div>
                        <div>
                            <dt>Hari libur</dt>
                            <dd>{{ overview.day_off }}</dd>
                        </div>
                    </dl>
                    <small>Ringkasan mengikuti periode dan pencarian, mencakup semua halaman.</small>
                </section>

                <form class="filter-card" @submit.prevent="applyFilters()">
                    <div class="filter-heading">
                        <h2><CalendarDays /> Periode presensi</h2>
                        <button type="button" :disabled="loading" @click="applyFilters(true)">Hari ini</button>
                    </div>
                    <div class="date-fields">
                        <label>Dari tanggal<input v-model="draft.date_from" type="date" min="1900-01-01" max="2100-12-31" required /></label>
                        <label>Sampai tanggal<input v-model="draft.date_to" type="date" min="1900-01-01" max="2100-12-31" required /></label>
                    </div>
                    <label class="search-field"
                        ><Search /><input
                            v-model="draft.search"
                            type="search"
                            maxlength="100"
                            placeholder="Cari nama, NIK, atau jabatan"
                            aria-label="Cari pegawai"
                    /></label>
                    <label class="status-field"
                        >Status presensi<select v-model="draft.status">
                            <option v-for="status in statuses" :key="status.value" :value="status.value || null">{{ status.label }}</option>
                        </select></label
                    >
                    <button type="submit" class="apply-button" :disabled="loading">
                        {{ loading ? 'Memuat presensi...' : 'Tampilkan presensi' }}
                    </button>
                    <p v-if="error" class="error-note" role="alert">{{ error }}</p>
                </form>

                <section aria-labelledby="list-title" class="team-list">
                    <div class="list-heading">
                        <h2 id="list-title">Daftar presensi</h2>
                        <span>{{ records.total }} hasil</span>
                    </div>
                    <p class="list-note">Waktu dalam WIB. Ketuk catatan untuk melihat detail.</p>
                    <div v-if="!records.data.length" class="empty-card">
                        <UsersRound aria-hidden="true" />
                        <h3>Belum ada hasil</h3>
                        <p>Belum ada pegawai atau presensi yang sesuai dengan pencarian dan filter Anda.</p>
                    </div>
                    <article v-for="record in records.data" :key="record.id ?? `pegawai-${record.pegawai_id}`" class="attendance-card">
                        <div class="employee-heading">
                            <div class="employee-identity">
                                <Avatar class="employee-avatar">
                                    <AvatarImage
                                        v-if="record.pegawai.profile_photo_url"
                                        :src="record.pegawai.profile_photo_url"
                                        :alt="`Foto profil ${record.pegawai.nama}`"
                                    />
                                    <AvatarFallback class="employee-initials">{{ getInitials(record.pegawai.nama) }}</AvatarFallback>
                                </Avatar>
                                <div class="employee-name">
                                    <h3>{{ record.pegawai.nama }}</h3>
                                    <p>{{ record.pegawai.jabatan || 'Jabatan belum diatur' }}</p>
                                    <p v-if="scopeMode !== 'unit-kerja'">{{ record.pegawai.unit || 'Unit belum diatur' }}</p>
                                    <small v-if="record.pegawai.nik">NIK {{ record.pegawai.nik }}</small>
                                </div>
                            </div>
                            <span class="status-badge" :class="record.status || 'unrecorded'">{{
                                record.status ? statusLabels[record.status] : 'Belum ada presensi'
                            }}</span>
                        </div>
                        <p v-if="record.has_presensi || record.cuti" class="record-date">
                            <CalendarDays /> {{ record.tanggal_label }}
                            <span v-if="record.cuti">CUTI · {{ record.cuti.nama || 'Cuti' }}</span>
                            <span v-else-if="record.shift">{{ record.shift.nama }}</span>
                        </p>
                        <div v-if="record.cuti" class="cuti-details">
                            <p>{{ formatDate(record.cuti.tanggal_mulai) }} – {{ formatDate(record.cuti.tanggal_selesai) }}</p>
                            <p>Cuti disetujui.</p>
                        </div>
                        <template v-else-if="record.has_presensi">
                            <div class="clock-times">
                                <div>
                                    <LogIn /><span
                                        >Masuk<strong>{{ record.clock_in.time || '--:--' }}</strong></span
                                    >
                                </div>
                                <div>
                                    <LogOut /><span
                                        >Pulang<strong>{{ record.clock_out.time || '--:--' }}</strong></span
                                    >
                                </div>
                            </div>
                            <details class="record-details">
                                <summary>Lihat detail presensi <ChevronDown /></summary>
                                <div class="detail-body">
                                    <p v-if="record.shift">
                                        <Clock3 /> Jadwal: {{ record.shift.jam_masuk || '--:--' }}–{{ record.shift.jam_keluar || '--:--' }} WIB
                                    </p>
                                    <p v-if="record.shift?.note">{{ record.shift.note }}</p>
                                    <p v-if="record.late_minutes > 0">Terlambat {{ record.late_minutes }} menit.</p>
                                    <p v-if="record.early_departure_minutes > 0">Pulang lebih awal {{ record.early_departure_minutes }} menit.</p>
                                    <p v-if="record.overtime_minutes > 0">Lembur tercatat {{ record.overtime_minutes }} menit.</p>
                                    <section
                                        v-for="clock in [
                                            { label: 'Masuk', value: record.clock_in },
                                            { label: 'Pulang', value: record.clock_out },
                                        ]"
                                        :key="clock.label"
                                        class="clock-detail"
                                    >
                                        <h4>{{ clock.label }}</h4>
                                        <p>{{ clock.value.date_time || 'Belum tercatat' }}{{ clock.value.date_time ? ' WIB' : '' }}</p>
                                        <p v-if="clock.value.approval_status">{{ approvalLabels[clock.value.approval_status] }}</p>
                                        <p v-if="clock.value.location?.name">{{ clock.value.location.name }}</p>
                                        <p v-if="clock.value.location?.address">{{ clock.value.location.address }}</p>
                                        <a
                                            v-if="clock.value.location?.map_url"
                                            :href="clock.value.location.map_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            ><MapPin /> Lihat lokasi</a
                                        >
                                        <img
                                            v-if="clock.value.photo_url"
                                            :src="clock.value.photo_url"
                                            :alt="`Foto presensi ${clock.label.toLowerCase()} ${record.pegawai.nama}`"
                                            loading="lazy"
                                        />
                                    </section>
                                    <p v-if="record.notes" class="record-notes">Catatan: {{ record.notes }}</p>
                                </div>
                            </details>
                        </template>
                        <p v-else class="unrecorded-note">Belum ada catatan presensi pada periode ini.</p>
                    </article>
                    <nav v-if="records.last_page > 1" class="team-pagination" aria-label="Halaman presensi pegawai">
                        <Link
                            v-if="records.prev_page_url"
                            :href="records.prev_page_url"
                            preserve-state
                            preserve-scroll
                            aria-label="Halaman sebelumnya"
                            ><ChevronLeft /></Link
                        ><span v-else class="disabled"><ChevronLeft /></span>
                        <span>Halaman {{ records.current_page }} / {{ records.last_page }}</span>
                        <Link
                            v-if="records.next_page_url"
                            :href="records.next_page_url"
                            preserve-state
                            preserve-scroll
                            aria-label="Halaman berikutnya"
                            ><ChevronRight /></Link
                        ><span v-else class="disabled"><ChevronRight /></span>
                    </nav>
                </section>
            </div>
        </div>
    </main>
</template>

<style scoped src="./approval-workspace.css"></style>
<style scoped>
.team-content {
    padding: 20px 16px calc(28px + env(safe-area-inset-bottom));
}
.team-overview {
    padding: 22px 20px;
    border-radius: 22px;
    background: linear-gradient(135deg, #295c45, #173f35);
    color: #fff;
}
.team-eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #c7decb;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1.5px;
}
.team-eyebrow svg {
    width: 15px;
    height: 15px;
}
.team-overview h2 {
    margin: 10px 0 5px;
    font-size: 24px;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.team-overview p {
    color: #d3e1d6;
    font-size: 11px;
    line-height: 1.7;
}
.metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 5px;
    margin: 22px 0 15px;
}
.metrics dt {
    color: #d3e1d6;
    font-size: 10px;
}
.metrics dd {
    margin: 4px 0 0;
    font-size: 27px;
    font-weight: 800;
}
.team-overview > small {
    display: block;
    color: #bed2c2;
    font-size: 9px;
    line-height: 1.7;
}
.filter-card {
    margin: 18px 0 24px;
    padding: 17px;
    border: 1px solid #e0e7da;
    border-radius: 20px;
    background: #fff;
}
.filter-heading,
.list-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.filter-heading h2 {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    font-weight: 700;
}
.filter-heading svg {
    width: 16px;
    height: 16px;
}
.filter-heading button {
    color: #295c45;
    font-size: 11px;
    font-weight: 700;
}
.date-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 17px;
}
.date-fields label,
.status-field {
    display: block;
    min-width: 0;
    color: #73806c;
    font-size: 10px;
}
.date-fields input,
.status-field select {
    display: block;
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    margin-top: 6px;
    padding: 10px 7px;
    border: 1px solid #dfe6d7;
    border-radius: 10px;
    background: #fff;
    color: #344e3b;
    font-size: 12px;
}
.date-fields input {
    min-height: 43px;
}
.filter-card .search-field {
    margin-top: 14px;
    border-radius: 11px;
}
.status-field {
    margin-top: 14px;
}
.apply-button {
    width: 100%;
    margin-top: 16px;
    padding: 13px;
    border-radius: 12px;
    background: #295c45;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
button:disabled {
    cursor: wait;
    opacity: 0.6;
}
.error-note {
    margin-top: 10px;
    color: #b54738;
    font-size: 11px;
}
.list-heading h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 800;
}
.list-heading > span {
    padding: 5px 9px;
    border-radius: 8px;
    background: #e7eedf;
    color: #5d774b;
    font-size: 10px;
}
.list-note {
    margin: 8px 0 16px;
    color: #7d8875;
    font-size: 10px;
    line-height: 1.6;
}
.attendance-card {
    margin-bottom: 12px;
    padding: 17px;
    border: 1px solid #e0e7da;
    border-radius: 18px;
    background: #fff;
}
.employee-heading {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    justify-content: space-between;
}
.employee-name {
    min-width: 0;
}
.employee-identity {
    display: flex;
    flex: 1;
    align-items: center;
    min-width: 0;
    gap: 10px;
}
.employee-avatar {
    flex-shrink: 0;
    width: 42px;
    height: 42px;
    overflow: hidden;
    background: #e7eedf;
}
.employee-initials {
    display: grid;
    width: 100%;
    height: 100%;
    place-items: center;
    color: #58744b;
    font-size: 13px;
    font-weight: 800;
}
.employee-name h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 800;
    overflow-wrap: anywhere;
}
.employee-name p {
    margin: 5px 0;
    color: #7d8875;
    font-size: 10px;
    overflow-wrap: anywhere;
}
.employee-name small {
    color: #8c9687;
    font-size: 9px;
}
.status-badge {
    flex-shrink: 0;
    max-width: 108px;
    padding: 6px 8px;
    border-radius: 8px;
    background: #f0f2ec;
    color: #73806c;
    font-size: 9px;
    line-height: 1.5;
    text-align: center;
}
.status-badge.on-time {
    background: #e7f4ed;
    color: #277250;
}
.status-badge.cuti {
    background: #eaf0fb;
    color: #466a9a;
}
.status-badge.late,
.status-badge.incomplete,
.status-badge.approval-pending {
    background: #fff4df;
    color: #966825;
}
.status-badge.approval-rejected {
    background: #fcece8;
    color: #ae4e3e;
}
.record-date {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 15px;
    color: #73806c;
    font-size: 10px;
}
.record-date svg {
    width: 13px;
    height: 13px;
}
.record-date span {
    margin-left: auto;
    font-size: 9px;
}
.cuti-details {
    margin-top: 12px;
    padding: 12px;
    border-radius: 12px;
    background: #f2f5fb;
    color: #466a9a;
    font-size: 11px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.cuti-details p {
    margin: 0;
}
.clock-times {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 12px;
}
.clock-times > div {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-radius: 12px;
    background: #f5f7f2;
}
.clock-times svg {
    width: 17px;
    height: 17px;
    color: #658658;
}
.clock-times span {
    color: #839078;
    font-size: 9px;
}
.clock-times strong {
    display: block;
    margin-top: 3px;
    color: #243d32;
    font-size: 20px;
    font-weight: 800;
}
.record-details {
    margin-top: 14px;
    border-top: 1px solid #edf0e9;
}
.record-details summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 13px;
    color: #58744b;
    cursor: pointer;
    font-size: 10px;
    font-weight: 700;
    list-style: none;
}
.record-details summary::-webkit-details-marker {
    display: none;
}
.record-details summary svg {
    width: 15px;
    height: 15px;
}
.record-details[open] summary svg {
    transform: rotate(180deg);
}
.detail-body {
    padding-top: 12px;
    color: #68785f;
    font-size: 11px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}
.detail-body p {
    margin: 5px 0;
}
.detail-body svg {
    display: inline;
    width: 13px;
    height: 13px;
    vertical-align: middle;
}
.clock-detail {
    margin-top: 13px;
    padding-top: 10px;
    border-top: 1px solid #edf0e9;
}
.clock-detail h4 {
    color: #344e3b;
    font-weight: 700;
}
.clock-detail a {
    color: #295c45;
    text-decoration: underline;
}
.clock-detail img {
    width: 100%;
    max-height: 280px;
    margin-top: 10px;
    border-radius: 12px;
    object-fit: contain;
    background: #f5f7f2;
}
.unrecorded-note {
    margin-top: 14px;
    color: #8c9687;
    font-size: 11px;
}
.empty-card {
    padding: 32px 22px;
    border: 1px dashed #cfdac5;
    border-radius: 18px;
    text-align: center;
}
.empty-card > svg {
    width: 34px;
    height: 34px;
    margin: 0 auto 12px;
    color: #92a281;
}
.empty-card h3 {
    font-size: 14px;
    font-weight: 700;
}
.empty-card p {
    margin-top: 8px;
    color: #839078;
    font-size: 11px;
    line-height: 1.8;
}
.team-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 18px;
    color: #73806c;
    font-size: 11px;
}
.team-pagination a,
.team-pagination .disabled {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border: 1px solid #dfe6d7;
    border-radius: 12px;
    background: #fff;
}
.team-pagination svg {
    width: 18px;
    height: 18px;
}
.team-pagination .disabled {
    opacity: 0.35;
}
@media (max-width: 359px) {
    .employee-heading {
        flex-wrap: wrap;
    }
    .employee-identity {
        flex-basis: 100%;
    }
    .status-badge {
        max-width: none;
        margin-left: 52px;
    }
    .team-content {
        padding-right: 12px;
        padding-left: 12px;
    }
    .header-title h1 {
        font-size: 19px;
    }
    .filter-card,
    .attendance-card {
        padding: 13px;
    }
    .date-fields {
        gap: 7px;
    }
    .date-fields input {
        padding: 10px 3px;
        font-size: 11px;
    }
}
</style>
