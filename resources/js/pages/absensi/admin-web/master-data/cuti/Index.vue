<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Download, FileSpreadsheet, Pencil, Plus, Search, Trash2, UsersRound } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import SearchableMultiSelect from '../../approval-line/components/SearchableMultiSelect.vue';
import '../../approval-workflow.css';
import ConfirmDelete from '../../setting/components/ConfirmDelete.vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import Pagination from '../../setting/components/Pagination.vue';
import type { Paginated } from '../../setting/types';

interface Master {
    id: number;
    kode: string;
    nama: string;
    jenis: 'tahunan' | null;
    durasi_hari: number;
    deskripsi: string | null;
    aktif: boolean;
    penerima_count: number;
}
interface Employee {
    id: number;
    nik: string | null;
    nama: string;
    email: string;
    unit: string | null;
    jabatan: string | null;
    cutis: { id: number; nama: string }[];
    kuota: number | null;
    terpakai: number;
    menunggu: number;
    sisa: number | null;
}
const props = defineProps<{
    masters: Master[];
    employees: Paginated<Employee>;
    employeeOptions: { value: number; label: string; description: string }[];
    filters: { tahun: number; search: string };
    overview: { types: number; employees: number; annual_assigned: number };
    annualId: number | null;
    can: { create: boolean; update: boolean; delete: boolean; assign: boolean };
}>();
const base = '/absensi/admin-web/master-data/cuti';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Master Cuti', href: base },
];
const year = ref(props.filters.tahun);
const search = ref(props.filters.search);
const editing = ref<Master | null>(null);
const masterOpen = ref(false);
const allocating = ref<Master | null>(null);
const annualOpen = ref(false);
const deleting = ref<Master | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const masterForm = useForm({ kode: '', nama: '', jenis: '', durasi_hari: 12, deskripsi: '', aktif: true });
const allocationForm = useForm({ scope: 'selected', pegawai_ids: [] as number[], tahun: props.filters.tahun });
const annualForm = useForm({ scope: 'selected', pegawai_ids: [] as number[], tahun: props.filters.tahun, jumlah_hari: 12 });
const importForm = useForm({ tahun: props.filters.tahun, file: null as File | null });
const deleteForm = useForm({});
const masterErrors = computed(() => Object.values(masterForm.errors));
const allocationErrors = computed(() => Object.values(allocationForm.errors));
const annualErrors = computed(() => Object.values(annualForm.errors));
const importErrors = computed(() => Object.values(importForm.errors));
const annualMaster = computed(() => props.masters.find((master) => master.id === props.annualId));
const filter = () =>
    router.get(base, { tahun: year.value, search: search.value.trim() || undefined }, { preserveState: true, preserveScroll: true, replace: true });
watch(
    () => props.filters,
    (filters) => {
        year.value = filters.tahun;
        search.value = filters.search;
        importForm.tahun = filters.tahun;
    },
);
const openMaster = (master: Master | null = null) => {
    editing.value = master;
    masterForm.reset();
    masterForm.clearErrors();
    if (master)
        Object.assign(masterForm, {
            kode: master.kode,
            nama: master.nama,
            jenis: master.jenis ?? '',
            durasi_hari: master.durasi_hari,
            deskripsi: master.deskripsi ?? '',
            aktif: master.aktif,
        });
    masterOpen.value = true;
};
const saveMaster = () => {
    const options = { preserveScroll: true, onSuccess: () => (masterOpen.value = false) };
    if (editing.value) masterForm.put(`${base}/${editing.value.id}`, options);
    else masterForm.post(base, options);
};
const openAllocation = (master: Master) => {
    allocationForm.reset();
    allocationForm.clearErrors();
    allocationForm.tahun = props.filters.tahun;
    allocating.value = master;
};
const allocate = () => {
    if (!allocating.value) return;
    allocationForm.post(`${base}/${allocating.value.id}/allocate`, { preserveScroll: true, onSuccess: () => (allocating.value = null) });
};
const openAnnual = (employee: Employee | null = null) => {
    annualForm.reset();
    annualForm.clearErrors();
    annualForm.tahun = props.filters.tahun;
    annualForm.jumlah_hari = employee?.kuota ?? annualMaster.value?.durasi_hari ?? 12;
    annualForm.pegawai_ids = employee ? [employee.id] : [];
    annualOpen.value = true;
};
const saveAnnual = () => annualForm.put(`${base}/tahunan`, { preserveScroll: true, onSuccess: () => (annualOpen.value = false) });
const chooseFile = (event: Event) => {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    importForm.clearErrors();
};
const importExcel = () =>
    importForm.post(`${base}/import`, {
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset('file');
            if (fileInput.value) fileInput.value.value = '';
        },
    });
const remove = () => {
    if (!deleting.value) return;
    deleteForm.delete(`${base}/${deleting.value.id}`, { preserveScroll: true, onSuccess: () => (deleting.value = null) });
};
</script>

<template>
    <Head title="Master Cuti" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="al-page mc-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><CalendarDays /> Master data pegawai</span>
                    <h1>Master Cuti</h1>
                    <p>Atur jenis cuti, tentukan durasinya, dan berikan hak cuti kepada pegawai. Kelola kuota tahunan per pegawai atau sekaligus.</p>
                </div>
                <button v-if="can.create" class="btn btn-primary al-button" type="button" @click="openMaster()"><Plus /> Tambah jenis cuti</button>
            </section>
            <div class="al-stats">
                <article>
                    <span class="al-stat-icon"><CalendarDays /></span>
                    <div>
                        <strong>{{ overview.types }}</strong
                        ><span>Jenis cuti tersedia</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon cyan"><UsersRound /></span>
                    <div>
                        <strong>{{ overview.employees }}</strong
                        ><span>Pegawai dengan akun user</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon amber"><CalendarDays /></span>
                    <div>
                        <strong>{{ overview.annual_assigned }}</strong
                        ><span>Penerima cuti tahunan {{ filters.tahun }}</span>
                    </div>
                </article>
            </div>
            <form class="mc-year" @submit.prevent="filter">
                <label for="cuti-year">Tahun kuota cuti tahunan</label>
                <input id="cuti-year" v-model="year" class="form-control" type="number" min="2000" max="2100" required />
                <button class="btn btn-outline-primary" type="submit">Tampilkan</button>
                <small>Kuota tiap tahun dicatat terpisah.</small>
            </form>
            <section class="al-panel">
                <header class="al-panel-header">
                    <div>
                        <h2>Jenis & durasi cuti</h2>
                        <p>Durasi cuti biasa adalah batas per pengajuan. Durasi cuti tahunan menjadi kuota awal per tahun.</p>
                    </div>
                </header>
                <div v-if="masters.length" class="mc-types">
                    <article v-for="master in masters" :key="master.id" class="mc-type" :class="{ inactive: !master.aktif }">
                        <div class="mc-type-heading">
                            <span class="mc-code">{{ master.kode }}</span
                            ><span class="mc-badge" :class="{ annual: master.jenis === 'tahunan' }">{{
                                master.jenis === 'tahunan' ? 'Tahunan' : 'Cuti biasa'
                            }}</span>
                        </div>
                        <h3>{{ master.nama }}</h3>
                        <p class="mc-duration">
                            <strong>{{ master.durasi_hari }}</strong> hari
                            <small>{{ master.jenis === 'tahunan' ? 'kuota awal / tahun' : 'maksimal / pengajuan' }}</small>
                        </p>
                        <p class="mc-description">{{ master.deskripsi || 'Belum ada keterangan tambahan.' }}</p>
                        <div class="mc-type-meta">
                            <span>{{ master.penerima_count }} penerima{{ master.jenis === 'tahunan' ? ` · ${filters.tahun}` : '' }}</span
                            ><span>{{ master.aktif ? 'Aktif' : 'Nonaktif' }}</span>
                        </div>
                        <footer>
                            <button
                                v-if="can.assign"
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                :disabled="!master.aktif"
                                @click="openAllocation(master)"
                            >
                                <UsersRound /> Berikan cuti
                            </button>
                            <button
                                v-if="can.update"
                                type="button"
                                class="btn btn-sm btn-light"
                                :aria-label="`Edit ${master.nama}`"
                                @click="openMaster(master)"
                            >
                                <Pencil />
                            </button>
                            <button
                                v-if="can.delete"
                                type="button"
                                class="btn btn-sm btn-light text-danger"
                                :aria-label="`Hapus ${master.nama}`"
                                @click="
                                    deleting = master;
                                    deleteForm.clearErrors();
                                "
                            >
                                <Trash2 />
                            </button>
                        </footer>
                    </article>
                </div>
                <div v-else class="al-empty">
                    <CalendarDays />
                    <h3>Belum ada jenis cuti</h3>
                    <p>Tambahkan jenis cuti, lalu berikan kepada pegawai yang memiliki akun user.</p>
                </div>
            </section>
            <section v-if="can.assign" class="al-panel">
                <header class="al-panel-header">
                    <div>
                        <h2>Kelola cuti tahunan {{ filters.tahun }}</h2>
                        <p>Isi kuota total, termasuk hari terpakai dan pengajuan yang menunggu. Sisa dihitung otomatis.</p>
                    </div>
                    <button class="btn btn-primary al-button" type="button" :disabled="!annualId" @click="openAnnual()">
                        <UsersRound /> Set kuota pegawai
                    </button>
                </header>
                <div v-if="!annualId" class="mc-notice">
                    Tambahkan jenis cuti dengan kategori <strong>Cuti Tahunan</strong> untuk mulai mengatur kuota.
                </div>
                <form v-else class="mc-import" @submit.prevent="importExcel">
                    <span class="mc-excel-icon"><FileSpreadsheet /></span>
                    <div class="mc-import-copy">
                        <strong>Impor kuota dari Excel</strong
                        ><small>Unduh template, isi jumlah hari berdasarkan NIK, lalu unggah. Maksimal 1.000 baris, 5 MB.</small
                        ><a :href="`${base}/template`" class="mc-download"><Download /> Unduh template Excel</a>
                    </div>
                    <div class="mc-import-input">
                        <label for="annual-excel" class="visually-hidden">File Excel cuti tahunan</label
                        ><input
                            id="annual-excel"
                            ref="fileInput"
                            type="file"
                            accept=".xlsx,.xls"
                            class="form-control"
                            :disabled="importForm.processing"
                            @change="chooseFile"
                        /><small>Impor mengganti kuota pegawai yang diisi untuk tahun {{ filters.tahun }}.</small>
                    </div>
                    <button class="btn btn-outline-primary" type="submit" :disabled="!importForm.file || importForm.processing">
                        {{ importForm.processing ? 'Mengimpor...' : 'Impor Excel' }}
                    </button>
                    <p v-for="error in importErrors" :key="error" class="al-error mc-import-error" role="alert">{{ error }}</p>
                </form>
            </section>
            <section class="al-panel">
                <header class="al-panel-header">
                    <div>
                        <h2>Hak cuti pegawai</h2>
                        <p>Pegawai dengan akun user · kuota tahunan {{ filters.tahun }}</p>
                    </div>
                    <form class="al-search" role="search" @submit.prevent="filter">
                        <Search /><input
                            v-model="search"
                            type="search"
                            maxlength="150"
                            placeholder="Cari nama, NIK, email..."
                            aria-label="Cari pegawai"
                        /><button type="submit" class="btn btn-sm btn-light">Cari</button>
                    </form>
                </header>
                <div v-if="employees.data.length" class="al-table-wrap">
                    <table class="al-table mc-table">
                        <thead>
                            <tr>
                                <th>Pegawai</th>
                                <th>Jenis cuti diberikan</th>
                                <th>Kuota tahunan</th>
                                <th>Terpakai</th>
                                <th>Menunggu</th>
                                <th>Sisa</th>
                                <th v-if="can.assign" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="employee in employees.data" :key="employee.id">
                                <td>
                                    <strong>{{ employee.nama }}</strong
                                    ><small>{{ employee.nik || 'NIK belum diisi' }} · {{ employee.unit || 'Unit belum diatur' }}</small
                                    ><small>{{ employee.email }}</small>
                                </td>
                                <td>
                                    <div v-if="employee.cutis.length" class="mc-tags">
                                        <span v-for="cuti in employee.cutis" :key="cuti.id">{{ cuti.nama }}</span>
                                    </div>
                                    <small v-else>Belum diberikan</small>
                                </td>
                                <td>
                                    <strong>{{ employee.kuota === null ? 'Belum diatur' : `${employee.kuota} hari` }}</strong>
                                </td>
                                <td>{{ employee.terpakai }} hari</td>
                                <td>{{ employee.menunggu }} hari</td>
                                <td>
                                    <span class="mc-balance">{{ employee.sisa === null ? '—' : `${employee.sisa} hari` }}</span>
                                </td>
                                <td v-if="can.assign" class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" type="button" :disabled="!annualId" @click="openAnnual(employee)">
                                        Set tahunan
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="al-empty">
                    <UsersRound />
                    <h3>{{ search ? 'Pegawai tidak ditemukan' : 'Belum ada pegawai dengan akun user' }}</h3>
                    <p>
                        {{
                            search ? 'Ubah pencarian untuk melihat pegawai lain.' : 'Buat akun user dari menu Pegawai agar hak cuti dapat diberikan.'
                        }}
                    </p>
                </div>
                <Pagination :links="employees.links" :from="employees.from" :to="employees.to" :total="employees.total" />
            </section>
        </div>

        <CrudModal
            :open="masterOpen"
            :title="editing ? 'Edit jenis cuti' : 'Tambah jenis cuti'"
            description="Tentukan durasi hari dan kategori cuti."
            @close="!masterForm.processing && (masterOpen = false)"
        >
            <form class="al-form" @submit.prevent="saveMaster">
                <div class="al-form-body">
                    <p v-for="error in masterErrors" :key="error" class="al-error" role="alert">{{ error }}</p>
                    <div class="al-date-fields">
                        <label class="al-field"
                            ><span>Kode cuti <b>*</b></span
                            ><input v-model="masterForm.kode" maxlength="30" placeholder="Contoh: TAHUNAN" required /></label
                        ><label class="al-field"
                            ><span>Nama cuti <b>*</b></span
                            ><input v-model="masterForm.nama" maxlength="150" placeholder="Contoh: Cuti Tahunan" required
                        /></label>
                    </div>
                    <label class="al-field"
                        ><span>Kategori</span
                        ><select v-model="masterForm.jenis">
                            <option value="">Cuti biasa</option>
                            <option value="tahunan">Cuti Tahunan</option>
                        </select></label
                    >
                    <label class="al-field"
                        ><span>{{ masterForm.jenis === 'tahunan' ? 'Kuota awal per tahun' : 'Durasi maksimal per pengajuan' }} <b>*</b></span
                        ><input v-model="masterForm.durasi_hari" type="number" min="1" max="366" required /><small class="al-help"
                            >Dihitung dalam hari kalender, termasuk tanggal mulai dan selesai.</small
                        ></label
                    >
                    <p v-if="masterForm.jenis === 'tahunan'" class="al-info">
                        Kuota awal dipakai saat pertama kali diberikan. Kuota pegawai yang sudah ada dapat diubah melalui pengaturan cuti tahunan.
                    </p>
                    <label class="al-field"><span>Keterangan</span><textarea v-model="masterForm.deskripsi" rows="3" maxlength="2000" /></label>
                    <label class="mc-checkbox"><input v-model="masterForm.aktif" type="checkbox" /> Jenis cuti aktif</label>
                </div>
                <footer class="al-form-footer">
                    <button class="btn btn-outline-secondary" type="button" :disabled="masterForm.processing" @click="masterOpen = false">
                        Batal</button
                    ><button class="btn btn-primary" type="submit" :disabled="masterForm.processing">
                        {{ masterForm.processing ? 'Menyimpan...' : 'Simpan jenis cuti' }}
                    </button>
                </footer>
            </form>
        </CrudModal>
        <CrudModal
            :open="!!allocating"
            :title="`Berikan ${allocating?.nama ?? 'cuti'}`"
            description="Pilih seluruh pegawai atau pegawai tertentu yang memiliki akun user."
            @close="!allocationForm.processing && (allocating = null)"
        >
            <form class="al-form" @submit.prevent="allocate">
                <div class="al-form-body">
                    <p v-for="error in allocationErrors" :key="error" class="al-error" role="alert">{{ error }}</p>
                    <div class="al-info">
                        {{ allocating?.durasi_hari }} hari{{
                            allocating?.jenis === 'tahunan'
                                ? ` kuota awal untuk tahun ${allocationForm.tahun}. Kuota manual yang sudah ada tetap dipertahankan.`
                                : ' maksimal per pengajuan.'
                        }}
                    </div>
                    <label class="al-field"
                        ><span>Penerima cuti</span
                        ><select v-model="allocationForm.scope">
                            <option value="selected">Pegawai yang dipilih</option>
                            <option value="all">Seluruh pegawai dengan akun user</option>
                        </select></label
                    >
                    <SearchableMultiSelect
                        v-if="allocationForm.scope === 'selected'"
                        v-model="allocationForm.pegawai_ids"
                        :options="employeeOptions"
                        label="Pilih pegawai penerima"
                        search-placeholder="Cari nama, NIK, atau unit..."
                        :max-selected="1000"
                        :disabled="allocationForm.processing"
                    />
                    <p v-else class="al-info">
                        Cuti akan diberikan kepada seluruh {{ overview.employees }} pegawai dengan akun user, termasuk pegawai di luar hasil
                        pencarian.
                    </p>
                </div>
                <footer class="al-form-footer">
                    <button class="btn btn-outline-secondary" type="button" :disabled="allocationForm.processing" @click="allocating = null">
                        Batal</button
                    ><button
                        class="btn btn-primary"
                        type="submit"
                        :disabled="allocationForm.processing || (allocationForm.scope === 'selected' && !allocationForm.pegawai_ids.length)"
                    >
                        {{ allocationForm.processing ? 'Menyimpan...' : 'Berikan cuti' }}
                    </button>
                </footer>
            </form>
        </CrudModal>
        <CrudModal
            :open="annualOpen"
            :title="`Set cuti tahunan ${annualForm.tahun}`"
            description="Atur kuota total tahunan. Nilai ini mengganti kuota pegawai yang dipilih."
            @close="!annualForm.processing && (annualOpen = false)"
        >
            <form class="al-form" @submit.prevent="saveAnnual">
                <div class="al-form-body">
                    <p v-for="error in annualErrors" :key="error" class="al-error" role="alert">{{ error }}</p>
                    <label class="al-field"
                        ><span>Jumlah cuti tahunan <b>*</b></span
                        ><input v-model="annualForm.jumlah_hari" type="number" min="0" max="366" required /><small class="al-help"
                            >Kuota total = terpakai + menunggu + sisa. Nilai 0 diperbolehkan bila belum ada pemakaian.</small
                        ></label
                    >
                    <label class="al-field"
                        ><span>Terapkan kepada</span
                        ><select v-model="annualForm.scope">
                            <option value="selected">Pegawai yang dipilih</option>
                            <option value="all">Seluruh pegawai dengan akun user</option>
                        </select></label
                    >
                    <SearchableMultiSelect
                        v-if="annualForm.scope === 'selected'"
                        v-model="annualForm.pegawai_ids"
                        :options="employeeOptions"
                        label="Pilih pegawai"
                        search-placeholder="Cari nama, NIK, atau unit..."
                        :max-selected="1000"
                        :disabled="annualForm.processing"
                    />
                    <p v-else class="al-info">
                        Kuota seluruh {{ overview.employees }} pegawai akan diatur menjadi {{ annualForm.jumlah_hari }} hari pada tahun
                        {{ annualForm.tahun }}, termasuk pegawai di luar hasil pencarian.
                    </p>
                    <p class="al-help">
                        Pegawai yang belum menerima cuti tahunan akan otomatis mendapat alokasi. Kuota tidak boleh kurang dari jumlah hari terpakai
                        dan menunggu.
                    </p>
                </div>
                <footer class="al-form-footer">
                    <button class="btn btn-outline-secondary" type="button" :disabled="annualForm.processing" @click="annualOpen = false">
                        Batal</button
                    ><button
                        class="btn btn-primary"
                        type="submit"
                        :disabled="annualForm.processing || (annualForm.scope === 'selected' && !annualForm.pegawai_ids.length)"
                    >
                        {{ annualForm.processing ? 'Menyimpan...' : 'Simpan kuota tahunan' }}
                    </button>
                </footer>
            </form>
        </CrudModal>
        <ConfirmDelete
            :open="!!deleting"
            title="Hapus jenis cuti?"
            :description="`Jenis ${deleting?.nama ?? ''} beserta alokasi pegawainya akan dihapus. Jenis yang sudah digunakan pada pengajuan harus dinonaktifkan.`"
            :processing="deleteForm.processing"
            @close="!deleteForm.processing && (deleting = null)"
            @confirm="remove"
        />
    </AppLayout>
</template>

<style scoped>
.mc-year {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px 20px;
    background: #fff;
    border: 1px solid var(--rs-surface-muted);
    border-radius: 12px;
}
.mc-year label {
    margin: 0;
    font-size: 13px;
    font-weight: 650;
}
.mc-year input {
    width: 110px;
}
.mc-year small {
    color: var(--rs-muted);
}
.mc-types {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 16px;
    padding: 0 24px 24px;
}
.mc-type {
    min-width: 0;
    padding: 20px;
    border: 1px solid var(--rs-border);
    border-radius: 13px;
    background: var(--rs-canvas);
}
.mc-type.inactive {
    background: var(--rs-canvas);
}
.mc-type-heading,
.mc-type-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.mc-code {
    font-size: 11px;
    font-weight: 750;
    color: var(--rs-muted);
    letter-spacing: 0.06em;
}
.mc-badge {
    border-radius: 6px;
    padding: 4px 8px;
    background: var(--rs-surface-muted);
    color: var(--rs-text-soft);
    font-size: 10px;
    font-weight: 650;
}
.mc-badge.annual {
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
}
.mc-type h3 {
    margin: 16px 0 10px;
    font-size: 18px;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.mc-duration {
    color: var(--rs-primary);
    margin: 0;
    font-size: 13px;
}
.mc-duration strong {
    font-size: 28px;
}
.mc-duration small {
    color: var(--rs-muted);
    margin-left: 5px;
}
.mc-description {
    margin: 12px 0;
    min-height: 38px;
    color: var(--rs-muted);
    font-size: 12px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.mc-type-meta {
    color: var(--rs-muted);
    font-size: 11px;
    padding: 12px 0;
    border-top: 1px solid var(--rs-surface-muted);
}
.mc-type footer {
    display: flex;
    gap: 8px;
}
.mc-type footer svg,
.mc-download svg {
    width: 14px;
    height: 14px;
}
.mc-type footer button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.mc-import {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    padding: 20px 24px;
    border-top: 1px solid var(--rs-surface-muted);
    background: var(--rs-canvas);
}
.mc-excel-icon {
    display: grid;
    place-items: center;
    padding: 13px;
    border-radius: 12px;
    color: #27927c;
    background: #e8f7f2;
}
.mc-import-copy {
    display: grid;
    gap: 5px;
    flex: 1;
    min-width: 220px;
}
.mc-import-copy strong {
    font-size: 13px;
}
.mc-import-copy small,
.mc-import-input small {
    font-size: 11px;
    color: var(--rs-muted);
}
.mc-download {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    font-size: 12px;
    color: var(--rs-primary);
}
.mc-import-input {
    flex: 1;
    min-width: 230px;
    display: grid;
    gap: 7px;
}
.mc-import-error {
    width: 100%;
    margin: 0;
}
.mc-notice {
    margin: 0 24px 24px;
    padding: 14px;
    border-radius: 9px;
    background: #fff6e5;
    color: #986a2f;
    font-size: 13px;
}
.mc-table {
    min-width: 1050px;
}
.mc-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    max-width: 260px;
}
.mc-tags span {
    padding: 4px 7px;
    border-radius: 6px;
    background: var(--rs-primary-soft);
    color: var(--rs-primary);
    font-size: 10px;
}
.mc-balance {
    font-weight: 750;
    color: #278b75;
    white-space: nowrap;
}
.mc-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
.mc-checkbox input {
    accent-color: var(--rs-primary);
}
@media (max-width: 760px) {
    .mc-types {
        padding: 0 16px 16px;
        grid-template-columns: 1fr;
    }
    .mc-year {
        padding: 14px;
    }
    .mc-import {
        padding: 18px;
    }
    .mc-import > button {
        width: 100%;
    }
}
</style>
