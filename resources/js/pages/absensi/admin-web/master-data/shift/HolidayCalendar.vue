<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CalendarDays, LoaderCircle, Pencil, Plus, RefreshCw, Save, Trash2 } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ConfirmDelete from '../../setting/components/ConfirmDelete.vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import type { HolidayRow, HolidaySyncInfo } from './types';

const props = defineProps<{
    holidays: HolidayRow[];
    year: number;
    search?: string;
    can: { create: boolean; update: boolean; delete: boolean; sync: boolean };
    syncInfo: HolidaySyncInfo;
}>();
const baseUrl = '/absensi/admin-web/master-data/shift';
const yearInput = ref(props.year);
const opened = ref(false);
const editing = ref<HolidayRow | null>(null);
const selected = ref<HolidayRow | null>(null);
const deleting = ref(false);
const form = useForm({ tanggal: '', nama: '', jenis: 'libur_nasional' as HolidayRow['jenis'] });
const syncForm = useForm({ year: props.year });
const syncCalendar = () => {
    syncForm.year = props.year;
    syncForm.post(`${baseUrl}/holidays/sync`, { preserveScroll: true });
};
const syncTimeLabel = (value: string) =>
    new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
watch(
    () => props.year,
    (year) => (yearInput.value = year),
);

const changeYear = () => {
    if (!Number.isInteger(yearInput.value) || yearInput.value < 1900 || yearInput.value > 9999) return;
    router.get(baseUrl, { holiday_year: yearInput.value, search: props.search || undefined }, { preserveState: true, preserveScroll: true });
};
const openForm = (holiday: HolidayRow | null = null) => {
    editing.value = holiday;
    form.defaults({ tanggal: holiday?.tanggal ?? '', nama: holiday?.nama ?? '', jenis: holiday?.jenis ?? 'libur_nasional' });
    form.reset();
    form.clearErrors();
    opened.value = true;
};
const closeForm = () => {
    if (!form.processing) opened.value = false;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (opened.value = false) };
    if (editing.value) form.put(`${baseUrl}/holidays/${editing.value.id}`, options);
    else form.post(`${baseUrl}/holidays`, options);
};
const destroy = () => {
    if (!selected.value) return;
    deleting.value = true;
    router.delete(`${baseUrl}/holidays/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};
const dateLabel = (date: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' }).format(new Date(`${date}T00:00:00`));
</script>

<template>
    <section class="holiday-calendar" aria-labelledby="holiday-calendar-title">
        <header>
            <div>
                <span class="calendar-eyebrow"><CalendarDays :size="16" /> Kalender kerja</span>
                <h2 id="holiday-calendar-title">Libur nasional &amp; cuti bersama</h2>
                <p>
                    Sinkronkan kalender nasional per tahun atau tambahkan hari libur manual. Kalender berlaku untuk shift yang mencentang jenis libur
                    terkait.
                </p>
            </div>
            <div class="calendar-tools">
                <button v-if="can.sync" class="btn btn-outline-primary" type="button" :disabled="syncForm.processing" @click="syncCalendar">
                    <LoaderCircle v-if="syncForm.processing" :size="16" class="sync-spinner" /><RefreshCw v-else :size="16" />
                    {{ syncForm.processing ? 'Menyinkronkan...' : `Sinkronkan ${year}` }}
                </button>
                <button v-if="can.create" class="btn btn-primary" type="button" @click="openForm()"><Plus :size="16" /> Tambah hari libur</button>
            </div>
        </header>
        <form class="calendar-year" @submit.prevent="changeYear">
            <label for="holiday-year">Tahun kalender</label>
            <input id="holiday-year" v-model.number="yearInput" type="number" min="1900" max="9999" required />
            <button class="btn btn-outline-primary" type="submit">Tampilkan</button>
            <span>{{ holidays.length }} hari libur terdaftar</span>
        </form>
        <div class="calendar-sync-info" aria-live="polite">
            <p>
                Sumber: <a :href="syncInfo.provider_url" target="_blank" rel="noopener noreferrer">{{ syncInfo.provider_name }}</a>
                (layanan publik dengan rujukan SKB pemerintah).
                <span v-if="syncInfo.synced_at">Sinkronisasi terakhir {{ syncTimeLabel(syncInfo.synced_at) }} WIB.</span>
                <span v-else>Kalender tahun {{ year }} belum pernah disinkronkan.</span>
            </p>
            <p>Entri manual dan koreksi admin tetap dipertahankan. Data tersimpan tetap digunakan saat sumber tidak tersedia.</p>
            <p v-if="syncInfo.auto_sync">Pembaruan harian pukul 02.15 WIB mengikuti scheduler aplikasi.</p>
            <a
                v-for="reference in syncInfo.references"
                :key="reference.url"
                :href="reference.url"
                target="_blank"
                rel="noopener noreferrer"
                class="calendar-reference"
                >{{ reference.title }}</a
            >
            <p v-if="syncForm.errors.year" class="calendar-sync-error" role="alert">{{ syncForm.errors.year }}</p>
        </div>
        <div v-if="holidays.length" class="calendar-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Nama hari libur</th>
                        <th>Jenis</th>
                        <th>Sumber</th>
                        <th><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="holiday in holidays" :key="holiday.id">
                        <td>{{ dateLabel(holiday.tanggal) }}</td>
                        <td>{{ holiday.nama }}</td>
                        <td>
                            <span class="holiday-type" :class="{ collective: holiday.jenis === 'cuti_bersama' }">{{
                                holiday.jenis === 'libur_nasional' ? 'Libur nasional' : 'Cuti bersama'
                            }}</span>
                        </td>
                        <td>
                            <span class="calendar-origin">{{ holiday.source === 'indonesian_holidays' ? 'Kalender nasional' : 'Manual' }}</span>
                        </td>
                        <td>
                            <div class="calendar-actions">
                                <button v-if="can.update" type="button" :aria-label="`Edit ${holiday.nama}`" @click="openForm(holiday)">
                                    <Pencil :size="16" />
                                </button>
                                <button v-if="can.delete" type="button" :aria-label="`Hapus ${holiday.nama}`" @click="selected = holiday">
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="calendar-empty">
            Belum ada tanggal libur untuk tahun {{ year }}. Sinkronkan kalender nasional atau tambahkan tanggal libur secara manual. Libur Sabtu dan
            Minggu tetap berlaku tanpa entri kalender.
        </p>
    </section>
    <CrudModal
        :open="opened"
        :title="editing ? 'Edit hari libur' : 'Tambah hari libur'"
        description="Tanggal ini berlaku pada shift yang mengikuti jenis libur yang dipilih."
        @close="closeForm"
    >
        <form @submit.prevent="submit">
            <div class="admin-modal-body calendar-form">
                <p v-if="editing?.source" class="calendar-edit-note">
                    Menyimpan perubahan akan menjadikan tanggal ini sebagai koreksi manual yang dipertahankan saat sinkronisasi berikutnya.
                </p>
                <label for="holiday-date"
                    >Tanggal libur<input id="holiday-date" v-model="form.tanggal" type="date" required /><small
                        v-if="form.errors.tanggal"
                        role="alert"
                        >{{ form.errors.tanggal }}</small
                    ></label
                >
                <label for="holiday-name"
                    >Nama hari libur<input id="holiday-name" v-model="form.nama" maxlength="150" placeholder="Nama hari libur" required /><small
                        v-if="form.errors.nama"
                        role="alert"
                        >{{ form.errors.nama }}</small
                    ></label
                >
                <label for="holiday-type"
                    >Jenis libur<select id="holiday-type" v-model="form.jenis">
                        <option value="libur_nasional">Libur nasional</option>
                        <option value="cuti_bersama">Cuti bersama</option></select
                    ><small v-if="form.errors.jenis" role="alert">{{ form.errors.jenis }}</small></label
                >
            </div>
            <div class="admin-modal-footer">
                <button class="btn btn-outline-secondary" type="button" :disabled="form.processing" @click="closeForm">Batal</button>
                <button class="btn btn-primary" type="submit" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" :size="16" /><Save v-else :size="16" />
                    {{ form.processing ? 'Menyimpan...' : 'Simpan hari libur' }}
                </button>
            </div>
        </form>
    </CrudModal>
    <ConfirmDelete
        :open="Boolean(selected)"
        title="Hapus hari libur?"
        :description="`${selected?.nama ?? ''} akan dihapus dari kalender. Tanggal yang tersedia di sumber nasional dapat muncul kembali pada sinkronisasi berikutnya.`"
        :processing="deleting"
        @close="selected = null"
        @confirm="destroy"
    />
</template>

<style scoped>
.calendar-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.calendar-sync-info {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 12px;
    background: var(--rs-canvas);
}
.calendar-sync-info p {
    margin: 0 0 5px;
    font-size: 12px;
}
.calendar-sync-info a {
    color: var(--rs-primary);
    text-decoration: underline;
}
.calendar-reference {
    display: block;
    margin-top: 6px;
    font-size: 12px;
}
.calendar-origin {
    color: var(--rs-text-soft);
    font-size: 12px;
}
.calendar-edit-note {
    margin: 0;
    color: var(--rs-text-soft);
    font-size: 12px;
    line-height: 1.6;
}
.calendar-sync-info .calendar-sync-error {
    color: #dc2626;
}
.sync-spinner {
    animation: sync-spin 1s linear infinite;
}
@keyframes sync-spin {
    to {
        transform: rotate(360deg);
    }
}
.holiday-calendar {
    padding: 26px;
    border: 1px solid var(--rs-surface-muted);
    border-radius: 20px;
    background: white;
}
.holiday-calendar header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
}
.holiday-calendar header > div {
    max-width: 700px;
}
.calendar-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--rs-primary);
    font-size: 12px;
    font-weight: 700;
}
.holiday-calendar h2 {
    margin: 8px 0;
    color: var(--rs-primary-deep);
    font-size: 21px;
    font-weight: 750;
}
.holiday-calendar p {
    color: var(--rs-text-soft);
    font-size: 13px;
    line-height: 1.7;
}
.holiday-calendar .btn,
.calendar-form + .admin-modal-footer .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.calendar-year {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin: 20px 0;
    font-size: 13px;
}
.calendar-year input {
    width: 100px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    padding: 8px 10px;
}
.calendar-year span {
    margin-left: auto;
    color: var(--rs-text-soft);
}
.calendar-table-wrap {
    overflow-x: auto;
}
.holiday-calendar table {
    width: 100%;
    text-align: left;
    font-size: 13px;
}
.holiday-calendar th {
    color: var(--rs-text-soft);
    font-weight: 600;
    background: var(--rs-canvas);
}
.holiday-calendar td,
.holiday-calendar th {
    padding: 13px 12px;
    border-bottom: 1px solid var(--rs-surface-muted);
}
.holiday-type {
    display: inline-block;
    white-space: nowrap;
    padding: 5px 9px;
    border-radius: 7px;
    color: #b42318;
    background: #fff1f0;
    font-size: 12px;
}
.holiday-type.collective {
    color: var(--rs-primary);
    background: var(--rs-canvas);
}
.calendar-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
.calendar-actions button {
    padding: 6px;
    border: 1px solid var(--rs-surface-muted);
    border-radius: 8px;
    color: var(--rs-text-soft);
}
.calendar-actions button:last-child {
    color: #b42318;
}
.calendar-empty {
    padding: 22px;
    border-radius: 12px;
    background: var(--rs-canvas);
}
.calendar-form {
    display: grid;
    gap: 18px;
}
.calendar-form label {
    display: grid;
    gap: 8px;
    color: var(--rs-primary-deep);
    font-size: 13px;
    font-weight: 600;
}
.calendar-form input,
.calendar-form select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid var(--rs-border);
    border-radius: 9px;
    font-weight: 400;
}
.calendar-form small {
    color: #dc2626;
    font-weight: 400;
}
@media (max-width: 600px) {
    .holiday-calendar {
        padding: 18px;
    }
    .calendar-year span {
        width: 100%;
        margin-left: 0;
    }
}
</style>
