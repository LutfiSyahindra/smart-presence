<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlarmClock, ArrowRight, Clock3, LoaderCircle, Moon, Pencil, Plus, Save, Search, Sun, Sunrise, Trash2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmDelete from '../../setting/components/ConfirmDelete.vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import Pagination from '../../setting/components/Pagination.vue';
import type { Paginated } from '../../setting/types';

interface ShiftRow {
    id: number;
    nama: string;
    jam_masuk: string;
    jam_keluar: string;
    created_at: string | null;
    updated_at: string | null;
}

interface ShiftInput {
    [key: string]: string | number;
    inputKey: number;
    nama: string;
    jam_masuk: string;
    jam_keluar: string;
}

const props = defineProps<{
    shifts: Paginated<ShiftRow>;
    overview: { total: number; morning: number; afternoon: number; night: number };
    filters: { search?: string };
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const baseUrl = '/absensi/admin-web/master-data/shift';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Master Data', href: baseUrl },
    { title: 'Shift', href: baseUrl },
];

const search = ref(props.filters.search ?? '');
const creating = ref(false);
const editing = ref<ShiftRow | null>(null);
const selected = ref<ShiftRow | null>(null);
const deleting = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let nextInputKey = 0;

const newShiftInput = (): ShiftInput => ({
    inputKey: ++nextInputKey,
    nama: '',
    jam_masuk: '',
    jam_keluar: '',
});

const form = useForm<{
    nama: string;
    jam_masuk: string;
    jam_keluar: string;
    shifts: ShiftInput[];
}>({
    nama: '',
    jam_masuk: '',
    jam_keluar: '',
    shifts: [newShiftInput()],
});

const resultSummary = computed(() => {
    if (!props.shifts.total) return 'Belum ada data';
    return `${props.shifts.from}-${props.shifts.to} dari ${props.shifts.total} shift`;
});

const summaryCards = computed(() => [
    { label: 'Total shift', value: props.overview.total, note: 'Jadwal kerja tersimpan', icon: AlarmClock, tone: 'indigo' },
    { label: 'Mulai pagi', value: props.overview.morning, note: 'Sebelum pukul 12.00', icon: Sunrise, tone: 'amber' },
    { label: 'Mulai siang', value: props.overview.afternoon, note: 'Pukul 12.00–17.59', icon: Sun, tone: 'cyan' },
    { label: 'Mulai malam', value: props.overview.night, note: 'Mulai pukul 18.00', icon: Moon, tone: 'violet' },
]);

const resetForm = () => {
    form.defaults({ nama: '', jam_masuk: '', jam_keluar: '', shifts: [newShiftInput()] });
    form.reset();
    form.clearErrors();
};

const openCreate = () => {
    editing.value = null;
    resetForm();
    creating.value = true;
};

const openEdit = (shift: ShiftRow) => {
    creating.value = false;
    editing.value = shift;
    form.clearErrors();
    form.defaults({
        nama: shift.nama,
        jam_masuk: shift.jam_masuk,
        jam_keluar: shift.jam_keluar,
        shifts: [],
    });
    form.reset();
};

const closeForm = () => {
    if (form.processing) return;
    creating.value = false;
    editing.value = null;
    resetForm();
};

const addShiftInput = () => {
    if (form.shifts.length < 25) form.shifts.push(newShiftInput());
};

const removeShiftInput = (index: number) => {
    if (form.shifts.length === 1) return;
    form.shifts.splice(index, 1);
    form.clearErrors();
};

const inputError = (index: number, field: 'nama' | 'jam_masuk' | 'jam_keluar') =>
    (form.errors as Record<string, string | undefined>)[`shifts.${index}.${field}`];

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => closeForm() };

    if (editing.value) form.put(`${baseUrl}/${editing.value.id}`, options);
    else form.post(baseUrl, options);
};

const applyFilters = () => {
    if (searchTimer) clearTimeout(searchTimer);
    router.get(baseUrl, { search: search.value.trim() || undefined }, { preserveState: true, preserveScroll: true, replace: true });
};

watch(search, (value, previousValue) => {
    if (value === previousValue) return;
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 450);
});

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});

const destroy = () => {
    if (!selected.value) return;
    deleting.value = true;
    router.delete(`${baseUrl}/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};

const timeInMinutes = (time: string) => {
    const [hour = 0, minute = 0] = time.split(':').map(Number);
    return hour * 60 + minute;
};

const durationLabel = (start: string, end: string) => {
    if (!start || !end) return 'Durasi otomatis';
    const duration = (timeInMinutes(end) - timeInMinutes(start) + 1440) % 1440 || 1440;
    const hours = Math.floor(duration / 60);
    const minutes = duration % 60;
    return `${hours} jam${minutes ? ` ${minutes} menit` : ''}`;
};

const crossesMidnight = (start: string, end: string) => Boolean(start && end && timeInMinutes(end) <= timeInMinutes(start));

type TimeField = 'jam_masuk' | 'jam_keluar';

const timeInputValue = (event: Event) => (event.target as HTMLInputElement).value;

const format24HourInput = (value: string) => {
    const cleaned = value.replace(/[^\d:]/g, '').replace(/:{2,}/g, ':');

    if (cleaned.includes(':')) {
        const [hour = '', minute = ''] = cleaned.split(':');
        return `${hour.slice(0, 2)}:${minute.slice(0, 2)}`;
    }

    const digits = cleaned.slice(0, 4);
    return digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits;
};

const normalize24Hour = (value: string) => {
    const match = value.trim().match(/^(\d{1,2}):?(\d{2})$/);
    if (!match) return value;

    const hour = Number(match[1]);
    const minute = Number(match[2]);
    if (hour > 23 || minute > 59) return value;

    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
};

const updateEditTime = (field: TimeField, event: Event) => {
    form[field] = format24HourInput(timeInputValue(event));
};

const normalizeEditTime = (field: TimeField) => {
    form[field] = normalize24Hour(form[field]);
};

const updateDynamicTime = (shift: ShiftInput, field: TimeField, event: Event) => {
    shift[field] = format24HourInput(timeInputValue(event));
};

const normalizeDynamicTime = (shift: ShiftInput, field: TimeField) => {
    shift[field] = normalize24Hour(shift[field]);
};
</script>

<template>
    <Head title="Master Shift" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="shift-page">
            <FlashMessage />

            <section class="shift-hero" aria-labelledby="shift-title">
                <div class="shift-hero-glow" aria-hidden="true"></div>
                <div class="shift-hero-copy">
                    <span class="shift-eyebrow"><Clock3 /> Pengaturan waktu kerja</span>
                    <h1 id="shift-title">Kelola shift dengan <span>lebih teratur.</span></h1>
                    <p>Tentukan nama dan rentang waktu setiap shift sebagai dasar penjadwalan kehadiran pegawai.</p>
                    <div class="shift-hero-points">
                        <span><i></i> Mendukung shift lintas hari</span>
                        <span><i></i> Input banyak data sekaligus</span>
                    </div>
                </div>
                <div class="shift-hero-panel">
                    <div class="shift-clock-card">
                        <span><Sunrise /></span>
                        <div>
                            <small>Jadwal terdaftar</small>
                            <strong>{{ overview.total.toLocaleString('id-ID') }} shift</strong>
                            <p>Siap dipakai untuk pengaturan jadwal kerja.</p>
                        </div>
                    </div>
                    <button v-if="can.create" type="button" class="shift-create-button" @click="openCreate">
                        <span><Plus /></span>
                        <div><strong>Tambah shift</strong><small>Gunakan input dinamis</small></div>
                        <ArrowRight />
                    </button>
                </div>
            </section>

            <section class="shift-overview" aria-label="Ringkasan shift">
                <article v-for="card in summaryCards" :key="card.label" :class="`tone-${card.tone}`">
                    <span class="shift-overview-icon"><component :is="card.icon" /></span>
                    <div>
                        <small>{{ card.label }}</small>
                        <strong>{{ card.value.toLocaleString('id-ID') }}</strong>
                        <span>{{ card.note }}</span>
                    </div>
                </article>
            </section>

            <section class="shift-directory" aria-labelledby="shift-directory-title">
                <header class="shift-directory-header">
                    <div>
                        <span>Direktori waktu kerja</span>
                        <h2 id="shift-directory-title">Master shift</h2>
                        <p>Cari, ubah, dan kelola rentang waktu shift.</p>
                    </div>
                    <strong>{{ resultSummary }}</strong>
                </header>

                <form class="shift-filter" role="search" @submit.prevent="applyFilters">
                    <label>
                        <Search />
                        <span class="visually-hidden">Cari shift</span>
                        <input v-model="search" type="search" placeholder="Cari nama atau jam shift..." autocomplete="off" />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="search = ''"><X /></button>
                    </label>
                    <button type="submit"><Search /> Cari</button>
                </form>

                <template v-if="shifts.data.length">
                    <div class="shift-table-wrap">
                        <table class="shift-table">
                            <thead>
                                <tr>
                                    <th>Nama shift</th>
                                    <th>Jam masuk</th>
                                    <th>Jam keluar</th>
                                    <th>Durasi</th>
                                    <th>Diperbarui</th>
                                    <th><span class="visually-hidden">Aksi</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="shift in shifts.data" :key="shift.id">
                                    <td>
                                        <div class="shift-name">
                                            <span><AlarmClock /></span>
                                            <div>
                                                <strong>{{ shift.nama }}</strong
                                                ><small>ID #{{ String(shift.id).padStart(4, '0') }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="shift-time start"><Sunrise /> {{ shift.jam_masuk }}</span>
                                    </td>
                                    <td>
                                        <span class="shift-time end"><Moon /> {{ shift.jam_keluar }}</span>
                                    </td>
                                    <td>
                                        <span class="shift-duration">{{ durationLabel(shift.jam_masuk, shift.jam_keluar) }}</span>
                                        <small v-if="crossesMidnight(shift.jam_masuk, shift.jam_keluar)" class="shift-next-day">Lintas hari</small>
                                    </td>
                                    <td>
                                        <span class="shift-updated">{{ shift.updated_at ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <div class="shift-actions">
                                            <button
                                                v-if="can.update"
                                                type="button"
                                                aria-label="Edit shift"
                                                title="Edit shift"
                                                @click="openEdit(shift)"
                                            >
                                                <Pencil />
                                            </button>
                                            <button
                                                v-if="can.delete"
                                                type="button"
                                                class="danger"
                                                aria-label="Hapus shift"
                                                title="Hapus shift"
                                                @click="selected = shift"
                                            >
                                                <Trash2 />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="shift-mobile-list">
                        <article v-for="shift in shifts.data" :key="shift.id">
                            <header>
                                <div class="shift-name">
                                    <span><AlarmClock /></span>
                                    <div>
                                        <strong>{{ shift.nama }}</strong
                                        ><small>ID #{{ String(shift.id).padStart(4, '0') }}</small>
                                    </div>
                                </div>
                                <div class="shift-actions">
                                    <button v-if="can.update" type="button" aria-label="Edit shift" @click="openEdit(shift)"><Pencil /></button>
                                    <button v-if="can.delete" type="button" class="danger" aria-label="Hapus shift" @click="selected = shift">
                                        <Trash2 />
                                    </button>
                                </div>
                            </header>
                            <div class="shift-mobile-times">
                                <span
                                    ><small>Masuk</small><strong>{{ shift.jam_masuk }}</strong></span
                                >
                                <ArrowRight />
                                <span
                                    ><small>Keluar</small><strong>{{ shift.jam_keluar }}</strong></span
                                >
                            </div>
                            <footer>
                                <span>{{ durationLabel(shift.jam_masuk, shift.jam_keluar) }}</span>
                                <small v-if="crossesMidnight(shift.jam_masuk, shift.jam_keluar)">Lintas hari</small>
                            </footer>
                        </article>
                    </div>
                </template>

                <div v-else class="shift-empty">
                    <span><AlarmClock /></span>
                    <small>{{ search ? 'Pencarian selesai' : 'Direktori masih kosong' }}</small>
                    <h3>{{ search ? 'Shift tidak ditemukan' : 'Belum ada shift' }}</h3>
                    <p>{{ search ? 'Coba gunakan nama atau jam lain.' : 'Tambahkan shift pertama untuk mulai mengatur waktu kerja.' }}</p>
                    <button v-if="search" type="button" @click="search = ''">Tampilkan semua shift</button>
                    <button v-else-if="can.create" type="button" @click="openCreate"><Plus /> Tambah shift</button>
                </div>

                <Pagination :links="shifts.links" :from="shifts.from" :to="shifts.to" :total="shifts.total" />
            </section>
        </div>

        <CrudModal
            :open="creating || Boolean(editing)"
            :title="editing ? `Edit shift: ${editing.nama}` : 'Tambah shift baru'"
            :description="editing ? 'Perbarui nama dan rentang waktu shift.' : 'Tambahkan hingga 25 shift dalam satu kali penyimpanan.'"
            @close="closeForm"
        >
            <form class="shift-form" @submit.prevent="submit">
                <div class="admin-modal-body shift-form-body">
                    <div class="shift-form-summary">
                        <span><Clock3 /></span>
                        <div v-if="editing">
                            <small>Pratinjau waktu</small>
                            <strong>{{ form.nama.trim() || 'Nama shift' }}</strong>
                            <p>
                                {{ form.jam_masuk || '--:--' }} <ArrowRight /> {{ form.jam_keluar || '--:--' }} ·
                                {{ durationLabel(form.jam_masuk, form.jam_keluar) }}
                            </p>
                        </div>
                        <div v-else>
                            <small>Input dinamis</small>
                            <strong>{{ form.shifts.length }} shift siap dilengkapi</strong>
                            <p>Tambah atau hapus baris sesuai kebutuhan.</p>
                        </div>
                        <em v-if="!editing">{{ form.shifts.length }}/25 input</em>
                    </div>

                    <section v-if="editing" class="shift-input-card single">
                        <div class="shift-fields">
                            <div class="shift-field name-field">
                                <label for="nama">Nama shift <b>*</b></label>
                                <div :class="{ invalid: form.errors.nama }">
                                    <AlarmClock /><input
                                        id="nama"
                                        v-model="form.nama"
                                        maxlength="100"
                                        placeholder="Contoh: Shift Pagi"
                                        autocomplete="off"
                                        autofocus
                                    />
                                </div>
                                <p v-if="form.errors.nama">{{ form.errors.nama }}</p>
                            </div>
                            <div class="shift-field">
                                <label for="jam-masuk">Jam masuk <b>*</b><small>24 jam</small></label>
                                <div :class="{ invalid: form.errors.jam_masuk }">
                                    <Sunrise /><input
                                        id="jam-masuk"
                                        :value="form.jam_masuk"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="5"
                                        placeholder="HH:mm"
                                        autocomplete="off"
                                        aria-label="Jam masuk dalam format 24 jam"
                                        @input="updateEditTime('jam_masuk', $event)"
                                        @blur="normalizeEditTime('jam_masuk')"
                                    />
                                </div>
                                <p v-if="form.errors.jam_masuk">{{ form.errors.jam_masuk }}</p>
                            </div>
                            <div class="shift-field">
                                <label for="jam-keluar">Jam keluar <b>*</b><small>24 jam</small></label>
                                <div :class="{ invalid: form.errors.jam_keluar }">
                                    <Moon /><input
                                        id="jam-keluar"
                                        :value="form.jam_keluar"
                                        type="text"
                                        inputmode="numeric"
                                        maxlength="5"
                                        placeholder="HH:mm"
                                        autocomplete="off"
                                        aria-label="Jam keluar dalam format 24 jam"
                                        @input="updateEditTime('jam_keluar', $event)"
                                        @blur="normalizeEditTime('jam_keluar')"
                                    />
                                </div>
                                <p v-if="form.errors.jam_keluar">{{ form.errors.jam_keluar }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-else class="shift-dynamic-section">
                        <header>
                            <div>
                                <small>Daftar input</small>
                                <h3>Detail shift</h3>
                                <p>Setiap baris akan dibuat menjadi satu data shift.</p>
                            </div>
                            <button type="button" :disabled="form.shifts.length >= 25" @click="addShiftInput"><Plus /> Tambah input</button>
                        </header>

                        <p v-if="form.errors.shifts" class="shift-root-error">{{ form.errors.shifts }}</p>

                        <div class="shift-dynamic-list">
                            <article v-for="(shiftInput, index) in form.shifts" :key="shiftInput.inputKey" class="shift-input-card">
                                <header>
                                    <span>{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div>
                                        <strong>Shift {{ index + 1 }}</strong
                                        ><small>{{ shiftInput.nama.trim() || 'Input shift baru' }}</small>
                                    </div>
                                    <button
                                        type="button"
                                        :disabled="form.shifts.length === 1"
                                        :aria-label="`Hapus input shift ${index + 1}`"
                                        @click="removeShiftInput(index)"
                                    >
                                        <Trash2 />
                                    </button>
                                </header>
                                <div class="shift-fields">
                                    <div class="shift-field name-field">
                                        <label :for="`nama-${shiftInput.inputKey}`">Nama shift <b>*</b></label>
                                        <div :class="{ invalid: inputError(index, 'nama') }">
                                            <AlarmClock /><input
                                                :id="`nama-${shiftInput.inputKey}`"
                                                v-model="shiftInput.nama"
                                                maxlength="100"
                                                placeholder="Contoh: Shift Pagi"
                                                autocomplete="off"
                                                :autofocus="index === 0"
                                            />
                                        </div>
                                        <p v-if="inputError(index, 'nama')">{{ inputError(index, 'nama') }}</p>
                                    </div>
                                    <div class="shift-field">
                                        <label :for="`masuk-${shiftInput.inputKey}`">Jam masuk <b>*</b><small>24 jam</small></label>
                                        <div :class="{ invalid: inputError(index, 'jam_masuk') }">
                                            <Sunrise /><input
                                                :id="`masuk-${shiftInput.inputKey}`"
                                                :value="shiftInput.jam_masuk"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="5"
                                                placeholder="HH:mm"
                                                autocomplete="off"
                                                aria-label="Jam masuk dalam format 24 jam"
                                                @input="updateDynamicTime(shiftInput, 'jam_masuk', $event)"
                                                @blur="normalizeDynamicTime(shiftInput, 'jam_masuk')"
                                            />
                                        </div>
                                        <p v-if="inputError(index, 'jam_masuk')">{{ inputError(index, 'jam_masuk') }}</p>
                                    </div>
                                    <div class="shift-field">
                                        <label :for="`keluar-${shiftInput.inputKey}`">Jam keluar <b>*</b><small>24 jam</small></label>
                                        <div :class="{ invalid: inputError(index, 'jam_keluar') }">
                                            <Moon /><input
                                                :id="`keluar-${shiftInput.inputKey}`"
                                                :value="shiftInput.jam_keluar"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="5"
                                                placeholder="HH:mm"
                                                autocomplete="off"
                                                aria-label="Jam keluar dalam format 24 jam"
                                                @input="updateDynamicTime(shiftInput, 'jam_keluar', $event)"
                                                @blur="normalizeDynamicTime(shiftInput, 'jam_keluar')"
                                            />
                                        </div>
                                        <p v-if="inputError(index, 'jam_keluar')">{{ inputError(index, 'jam_keluar') }}</p>
                                    </div>
                                </div>
                                <footer>
                                    <Clock3 /> {{ durationLabel(shiftInput.jam_masuk, shiftInput.jam_keluar) }}
                                    <span v-if="crossesMidnight(shiftInput.jam_masuk, shiftInput.jam_keluar)">Berakhir hari berikutnya</span>
                                </footer>
                            </article>
                        </div>

                        <button type="button" class="shift-add-bottom" :disabled="form.shifts.length >= 25" @click="addShiftInput">
                            <Plus /> Tambah input shift lainnya
                        </button>
                    </section>
                </div>

                <div class="admin-modal-footer shift-form-footer">
                    <span>{{ editing ? 'Pastikan waktu shift sudah sesuai.' : `${form.shifts.length} data akan disimpan sekaligus.` }}</span>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="closeForm">Batal</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing || (Boolean(editing) && !form.isDirty)">
                            <LoaderCircle v-if="form.processing" class="shift-spinner" /><Save v-else />
                            {{ form.processing ? 'Menyimpan...' : editing ? 'Simpan perubahan' : `Simpan ${form.shifts.length} shift` }}
                        </button>
                    </div>
                </div>
            </form>
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus shift?"
            :description="`Shift ${selected?.nama ?? ''} (${selected?.jam_masuk ?? '--:--'}–${selected?.jam_keluar ?? '--:--'}) akan dihapus permanen dari master data.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>

<style scoped>
.shift-page,
.shift-form {
    --shift-ink: #172033;
    --shift-muted: #697386;
    --shift-line: #e6eaf1;
    --shift-primary: #626fee;
}

.shift-page {
    display: grid;
    gap: 20px;
    animation: shift-enter 0.4s ease both;
}
.shift-hero {
    position: relative;
    display: grid;
    grid-template-columns: 1.35fr 0.65fr;
    min-height: 280px;
    overflow: hidden;
    padding: 40px 44px;
    border-radius: 22px;
    color: white;
    background: linear-gradient(125deg, #151c35, #242d5a 55%, #4850b8);
    box-shadow: 0 22px 48px rgba(28, 35, 84, 0.2);
}
.shift-hero::after {
    position: absolute;
    inset: 0;
    content: '';
    opacity: 0.1;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.3) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.3) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: linear-gradient(90deg, transparent, #000);
}
.shift-hero-glow {
    position: absolute;
    width: 360px;
    height: 360px;
    right: -100px;
    top: -190px;
    border-radius: 50%;
    background: rgba(71, 219, 205, 0.22);
    filter: blur(5px);
}
.shift-hero-copy,
.shift-hero-panel {
    position: relative;
    z-index: 1;
}
.shift-hero-copy {
    align-self: center;
    max-width: 680px;
}
.shift-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 11px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 999px;
    color: #cdd2ff;
    background: rgba(255, 255, 255, 0.07);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.shift-eyebrow svg {
    width: 14px;
}
.shift-hero h1 {
    max-width: 620px;
    margin: 17px 0 12px;
    font-size: clamp(30px, 4vw, 48px);
    font-weight: 750;
    line-height: 1.08;
    letter-spacing: -0.045em;
}
.shift-hero h1 span {
    color: #aeb7ff;
}
.shift-hero-copy > p {
    max-width: 610px;
    margin: 0;
    color: #c6cbe0;
    font-size: 14px;
    line-height: 1.75;
}
.shift-hero-points {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 20px;
    color: #dfe3f5;
    font-size: 12px;
}
.shift-hero-points span {
    display: flex;
    align-items: center;
    gap: 7px;
}
.shift-hero-points i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #50dcc4;
    box-shadow: 0 0 0 4px rgba(80, 220, 196, 0.12);
}
.shift-hero-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 12px;
    margin-left: 30px;
}
.shift-clock-card,
.shift-create-button {
    display: flex;
    align-items: center;
    gap: 13px;
    width: 100%;
    padding: 17px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 16px;
    color: white;
    background: rgba(255, 255, 255, 0.09);
    backdrop-filter: blur(12px);
}
.shift-clock-card > span,
.shift-create-button > span {
    display: grid;
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 13px;
    background: rgba(108, 224, 205, 0.16);
    color: #72ead7;
}
.shift-clock-card svg,
.shift-create-button svg {
    width: 20px;
}
.shift-clock-card div {
    display: grid;
}
.shift-clock-card small,
.shift-create-button small {
    color: #bfc6df;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.07em;
}
.shift-clock-card strong {
    margin-top: 2px;
    font-size: 22px;
}
.shift-clock-card p {
    margin: 3px 0 0;
    color: #bfc6df;
    font-size: 11px;
}
.shift-create-button {
    border: 0;
    text-align: left;
    cursor: pointer;
    background: white;
    color: var(--shift-ink);
    box-shadow: 0 12px 25px rgba(8, 12, 34, 0.16);
    transition: transform 0.18s ease;
}
.shift-create-button:hover {
    transform: translateY(-2px);
}
.shift-create-button > span {
    color: var(--shift-primary);
    background: #eef0ff;
}
.shift-create-button div {
    display: grid;
    flex: 1;
}
.shift-create-button small {
    margin-top: 3px;
    color: #7e879b;
}
.shift-create-button > svg {
    width: 18px;
    color: var(--shift-primary);
}

.shift-overview {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}
.shift-overview article {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    padding: 18px;
    border: 1px solid var(--shift-line);
    border-radius: 17px;
    background: white;
    box-shadow: 0 8px 24px rgba(30, 41, 59, 0.045);
}
.shift-overview-icon {
    display: grid;
    width: 44px;
    height: 44px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 13px;
}
.shift-overview-icon svg {
    width: 21px;
}
.shift-overview article > div {
    display: grid;
    min-width: 0;
}
.shift-overview small {
    color: var(--shift-muted);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.shift-overview strong {
    margin: 1px 0;
    color: var(--shift-ink);
    font-size: 23px;
}
.shift-overview article > div > span {
    overflow: hidden;
    color: #8992a3;
    font-size: 10px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tone-indigo .shift-overview-icon {
    color: #5d68df;
    background: #eef0ff;
}
.tone-amber .shift-overview-icon {
    color: #db8a12;
    background: #fff6df;
}
.tone-cyan .shift-overview-icon {
    color: #159d9a;
    background: #e4faf7;
}
.tone-violet .shift-overview-icon {
    color: #8b5bd9;
    background: #f3ebff;
}

.shift-directory {
    overflow: hidden;
    border: 1px solid var(--shift-line);
    border-radius: 20px;
    background: white;
    box-shadow: 0 12px 35px rgba(30, 41, 59, 0.055);
}
.shift-directory-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    padding: 24px 26px 20px;
}
.shift-directory-header div > span {
    color: var(--shift-primary);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}
.shift-directory-header h2 {
    margin: 3px 0;
    color: var(--shift-ink);
    font-size: 22px;
    font-weight: 750;
}
.shift-directory-header p {
    margin: 0;
    color: var(--shift-muted);
    font-size: 12px;
}
.shift-directory-header > strong {
    color: var(--shift-muted);
    font-size: 12px;
    font-weight: 600;
}
.shift-filter {
    display: flex;
    gap: 10px;
    padding: 0 26px 20px;
}
.shift-filter label {
    position: relative;
    display: flex;
    align-items: center;
    max-width: 560px;
    flex: 1;
}
.shift-filter label > svg {
    position: absolute;
    left: 14px;
    width: 17px;
    color: #8a93a5;
}
.shift-filter input {
    width: 100%;
    height: 43px;
    padding: 0 42px;
    border: 1px solid #dfe3eb;
    border-radius: 12px;
    outline: none;
    color: var(--shift-ink);
    background: #fafbfc;
    font-size: 12px;
    transition:
        border 0.15s,
        box-shadow 0.15s;
}
.shift-filter input:focus {
    border-color: #929afa;
    box-shadow: 0 0 0 3px rgba(98, 111, 238, 0.12);
    background: white;
}
.shift-filter label button {
    position: absolute;
    right: 8px;
    display: grid;
    width: 28px;
    height: 28px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    color: #8b94a6;
    background: transparent;
}
.shift-filter label button svg {
    width: 15px;
}
.shift-filter > button {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 0 18px;
    border: 0;
    border-radius: 11px;
    color: white;
    background: var(--shift-primary);
    font-size: 12px;
    font-weight: 700;
}
.shift-filter > button svg {
    width: 15px;
}
.shift-table-wrap {
    overflow-x: auto;
    border-top: 1px solid var(--shift-line);
}
.shift-table {
    width: 100%;
    border-collapse: collapse;
}
.shift-table th {
    padding: 12px 18px;
    color: #7b8496;
    background: #fafbfc;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}
.shift-table td {
    padding: 15px 18px;
    border-top: 1px solid #edf0f4;
    color: var(--shift-ink);
    font-size: 12px;
    vertical-align: middle;
}
.shift-table tbody tr {
    transition: background 0.15s;
}
.shift-table tbody tr:hover {
    background: #fbfbff;
}
.shift-name {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 170px;
}
.shift-name > span {
    display: grid;
    width: 38px;
    height: 38px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 12px;
    color: var(--shift-primary);
    background: #eef0ff;
}
.shift-name svg {
    width: 18px;
}
.shift-name > div {
    display: grid;
}
.shift-name strong {
    color: var(--shift-ink);
    font-size: 12px;
}
.shift-name small {
    margin-top: 2px;
    color: #98a0ae;
    font-size: 9px;
}
.shift-time {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-width: 86px;
    padding: 7px 9px;
    border-radius: 9px;
    font-weight: 750;
    letter-spacing: 0.03em;
}
.shift-time svg {
    width: 14px;
}
.shift-time.start {
    color: #a76208;
    background: #fff7e6;
}
.shift-time.end {
    color: #6551b9;
    background: #f2efff;
}
.shift-duration {
    display: block;
    font-weight: 650;
    white-space: nowrap;
}
.shift-next-day {
    display: block;
    margin-top: 3px;
    color: #795bc4;
    font-size: 9px;
}
.shift-updated {
    color: var(--shift-muted);
    white-space: nowrap;
}
.shift-actions {
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}
.shift-actions button {
    display: grid;
    width: 31px;
    height: 31px;
    place-items: center;
    border: 1px solid #e2e6ed;
    border-radius: 9px;
    color: #657086;
    background: white;
    transition: 0.15s;
}
.shift-actions button:hover {
    color: var(--shift-primary);
    border-color: #cfd3ff;
    background: #f4f5ff;
}
.shift-actions button.danger:hover {
    color: #d9475b;
    border-color: #ffd2d8;
    background: #fff3f4;
}
.shift-actions svg {
    width: 14px;
}
.shift-mobile-list {
    display: none;
}
.shift-empty {
    display: grid;
    justify-items: center;
    padding: 60px 20px;
    border-top: 1px solid var(--shift-line);
    text-align: center;
}
.shift-empty > span {
    display: grid;
    width: 62px;
    height: 62px;
    margin-bottom: 15px;
    place-items: center;
    border-radius: 20px;
    color: var(--shift-primary);
    background: #eef0ff;
}
.shift-empty > span svg {
    width: 28px;
}
.shift-empty small {
    color: var(--shift-primary);
    font-weight: 750;
    text-transform: uppercase;
    letter-spacing: 0.07em;
}
.shift-empty h3 {
    margin: 5px 0;
    color: var(--shift-ink);
}
.shift-empty p {
    margin: 0 0 16px;
    color: var(--shift-muted);
    font-size: 12px;
}
.shift-empty button {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 9px 14px;
    border: 0;
    border-radius: 10px;
    color: white;
    background: var(--shift-primary);
    font-size: 12px;
    font-weight: 700;
}
.shift-empty button svg {
    width: 15px;
}

.shift-form-body {
    display: grid;
    gap: 18px;
    padding: 22px;
    background: #f7f8fb;
}
.shift-form-summary {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 16px;
    border: 1px solid #e2e6f1;
    border-radius: 15px;
    color: white;
    background: linear-gradient(120deg, #252e5b, #5c66d5);
}
.shift-form-summary > span {
    display: grid;
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 12px;
    color: #bec5ff;
    background: rgba(255, 255, 255, 0.1);
}
.shift-form-summary svg {
    width: 20px;
}
.shift-form-summary > div {
    display: grid;
    flex: 1;
}
.shift-form-summary small {
    color: #c8cee8;
    font-size: 9px;
    font-weight: 750;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.shift-form-summary strong {
    margin: 2px 0;
    font-size: 15px;
}
.shift-form-summary p {
    display: flex;
    align-items: center;
    gap: 5px;
    margin: 0;
    color: #d7dbed;
    font-size: 10px;
}
.shift-form-summary p svg {
    width: 12px;
}
.shift-form-summary em {
    padding: 5px 8px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.1);
    font-size: 10px;
    font-style: normal;
}
.shift-dynamic-section {
    display: grid;
    gap: 14px;
}
.shift-dynamic-section > header {
    display: flex;
    align-items: center;
    gap: 16px;
}
.shift-dynamic-section > header > div {
    flex: 1;
}
.shift-dynamic-section > header small {
    color: var(--shift-primary);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.shift-dynamic-section > header h3 {
    margin: 2px 0;
    color: var(--shift-ink);
    font-size: 16px;
}
.shift-dynamic-section > header p {
    margin: 0;
    color: var(--shift-muted);
    font-size: 10px;
}
.shift-dynamic-section > header button,
.shift-add-bottom {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 11px;
    border: 1px solid #cfd4ff;
    border-radius: 9px;
    color: #5965dc;
    background: #f0f2ff;
    font-size: 10px;
    font-weight: 750;
}
.shift-dynamic-section > header button:disabled,
.shift-add-bottom:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
.shift-dynamic-section > header button svg,
.shift-add-bottom svg {
    width: 14px;
}
.shift-dynamic-list {
    display: grid;
    gap: 12px;
}
.shift-input-card {
    overflow: hidden;
    border: 1px solid #e0e4ec;
    border-radius: 15px;
    background: white;
    box-shadow: 0 6px 18px rgba(30, 41, 59, 0.035);
}
.shift-input-card.single {
    padding: 18px;
}
.shift-input-card > header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 15px;
    border-bottom: 1px solid #edf0f5;
    background: #fafbfc;
}
.shift-input-card > header > span {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border-radius: 9px;
    color: var(--shift-primary);
    background: #e9ebff;
    font-size: 10px;
    font-weight: 800;
}
.shift-input-card > header > div {
    display: grid;
    flex: 1;
}
.shift-input-card > header strong {
    color: var(--shift-ink);
    font-size: 11px;
}
.shift-input-card > header small {
    color: #8b94a5;
    font-size: 9px;
}
.shift-input-card > header button {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    color: #d84d61;
    background: #fff0f2;
}
.shift-input-card > header button:disabled {
    color: #b5bbc7;
    background: #f0f2f5;
}
.shift-input-card > header button svg {
    width: 14px;
}
.shift-fields {
    display: grid;
    grid-template-columns: 1.35fr 1fr 1fr;
    gap: 12px;
    padding: 15px;
}
.single .shift-fields {
    padding: 0;
}
.shift-field {
    min-width: 0;
}
.shift-field label {
    display: block;
    margin-bottom: 6px;
    color: #475167;
    font-size: 10px;
    font-weight: 750;
}
.shift-field label b {
    color: #e04b5f;
}
.shift-field label small {
    float: right;
    color: #8b94a5;
    font-size: 8px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.shift-field > div {
    position: relative;
    display: flex;
    align-items: center;
}
.shift-field > div > svg {
    position: absolute;
    left: 11px;
    width: 15px;
    color: #8b94a5;
    pointer-events: none;
}
.shift-field input {
    width: 100%;
    height: 40px;
    padding: 0 10px 0 34px;
    border: 1px solid #dce1e9;
    border-radius: 10px;
    outline: none;
    color: var(--shift-ink);
    background: #fbfcfd;
    font-size: 11px;
}
.shift-field input:focus {
    border-color: #929afa;
    box-shadow: 0 0 0 3px rgba(98, 111, 238, 0.1);
    background: white;
}
.shift-field > div.invalid input {
    border-color: #e2576a;
    box-shadow: 0 0 0 3px rgba(226, 87, 106, 0.08);
}
.shift-field > p,
.shift-root-error {
    margin: 5px 0 0;
    color: #d73f55;
    font-size: 9px;
}
.shift-root-error {
    padding: 9px 11px;
    border-radius: 9px;
    background: #fff0f2;
}
.shift-input-card > footer {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 9px 15px;
    border-top: 1px solid #edf0f5;
    color: #6d7789;
    background: #fcfcfd;
    font-size: 9px;
}
.shift-input-card > footer svg {
    width: 13px;
}
.shift-input-card > footer span {
    margin-left: auto;
    padding: 3px 6px;
    border-radius: 6px;
    color: #7254bb;
    background: #f2edff;
}
.shift-add-bottom {
    width: 100%;
    border-style: dashed;
}
.shift-form-footer {
    justify-content: space-between;
}
.shift-form-footer > span {
    color: var(--shift-muted);
    font-size: 10px;
}
.shift-form-footer > div {
    display: flex;
    gap: 8px;
}
.shift-form-footer .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.shift-form-footer .btn svg {
    width: 15px;
}
.shift-spinner {
    animation: shift-spin 0.8s linear infinite;
}

@media (max-width: 980px) {
    .shift-hero {
        grid-template-columns: 1fr;
        gap: 25px;
    }
    .shift-hero-panel {
        display: grid;
        grid-template-columns: 1fr 1fr;
        margin-left: 0;
    }
    .shift-overview {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 720px) {
    .shift-page {
        gap: 14px;
    }
    .shift-hero {
        min-height: 0;
        padding: 28px 23px;
        border-radius: 17px;
    }
    .shift-hero h1 {
        font-size: 32px;
    }
    .shift-hero-panel {
        grid-template-columns: 1fr;
    }
    .shift-overview {
        gap: 10px;
    }
    .shift-overview article {
        padding: 13px;
    }
    .shift-overview-icon {
        width: 38px;
        height: 38px;
    }
    .shift-directory-header {
        align-items: flex-start;
        padding: 20px;
    }
    .shift-directory-header > strong {
        display: none;
    }
    .shift-filter {
        padding: 0 20px 18px;
    }
    .shift-filter > button {
        padding: 0 13px;
    }
    .shift-table-wrap {
        display: none;
    }
    .shift-mobile-list {
        display: grid;
        gap: 10px;
        padding: 14px;
        border-top: 1px solid var(--shift-line);
        background: #f8f9fb;
    }
    .shift-mobile-list > article {
        padding: 14px;
        border: 1px solid #e3e7ee;
        border-radius: 14px;
        background: white;
    }
    .shift-mobile-list header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .shift-mobile-times {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: 10px;
        margin-top: 13px;
        padding: 12px;
        border-radius: 11px;
        background: #f7f8fc;
        text-align: center;
    }
    .shift-mobile-times > span {
        display: grid;
    }
    .shift-mobile-times small {
        color: #8a93a5;
        font-size: 9px;
        text-transform: uppercase;
    }
    .shift-mobile-times strong {
        color: var(--shift-ink);
        font-size: 15px;
    }
    .shift-mobile-times > svg {
        width: 15px;
        color: #919aab;
    }
    .shift-mobile-list footer {
        display: flex;
        justify-content: space-between;
        margin-top: 10px;
        color: #667085;
        font-size: 10px;
    }
    .shift-mobile-list footer small {
        color: #7254bb;
    }
    .shift-form-body {
        padding: 15px;
    }
    .shift-fields {
        grid-template-columns: 1fr 1fr;
    }
    .shift-field.name-field {
        grid-column: 1 / -1;
    }
    .shift-form-footer {
        align-items: stretch;
        flex-direction: column;
    }
    .shift-form-footer > div {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }
    .shift-form-footer .btn {
        justify-content: center;
    }
}

@media (max-width: 480px) {
    .shift-overview {
        grid-template-columns: 1fr;
    }
    .shift-hero-points {
        display: grid;
    }
    .shift-fields {
        grid-template-columns: 1fr;
    }
    .shift-field.name-field {
        grid-column: auto;
    }
    .shift-dynamic-section > header {
        align-items: flex-start;
    }
    .shift-dynamic-section > header button {
        padding: 8px;
    }
    .shift-form-summary em {
        display: none;
    }
}

@keyframes shift-enter {
    from {
        opacity: 0;
        transform: translateY(7px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}
@keyframes shift-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
