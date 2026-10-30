<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { CalendarDays, Clock3, GitPullRequestArrow, Save, ShieldCheck, SlidersHorizontal, Timer } from 'lucide-vue-next';
import { computed } from 'vue';
import FlashMessage from '../components/FlashMessage.vue';
import SettingNavigation from '../components/SettingNavigation.vue';

interface Configuration {
    cancel_approved_cuti: boolean;
    cancel_approved_lembur: boolean;
    cancel_approved_perubahan_shift: boolean;
    perubahan_shift_after_clock_in: boolean;
    clock_in_tolerance_minutes: number;
    approve_past_requests: boolean;
}

const props = defineProps<{ configuration: Configuration; can: { update: boolean } }>();
const form = useForm({ ...props.configuration });
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan', href: '/absensi/admin-web/setting/konfigurasi' },
    { title: 'Konfigurasi', href: '/absensi/admin-web/setting/konfigurasi' },
];
const groups = [
    {
        title: 'Pengajuan cuti',
        icon: CalendarDays,
        fields: [
            {
                key: 'cancel_approved_cuti',
                label: 'Izinkan pembatalan cuti yang sudah disetujui',
                description: 'Pemohon dapat membatalkan cuti setelah seluruh approval selesai. Saldo cuti dikembalikan.',
            },
        ],
    },
    {
        title: 'Lembur',
        icon: Timer,
        fields: [
            {
                key: 'cancel_approved_lembur',
                label: 'Izinkan pembatalan lembur yang sudah disetujui',
                description:
                    'Berlaku untuk pengajuan dan penggunaan lembur. Lembur dengan saldo yang masih dialokasikan ke penggunaan aktif harus diselesaikan terlebih dahulu.',
            },
        ],
    },
    {
        title: 'Perubahan shift',
        icon: GitPullRequestArrow,
        fields: [
            {
                key: 'cancel_approved_perubahan_shift',
                label: 'Izinkan pembatalan perubahan shift yang sudah disetujui',
                description: 'Jadwal pada tanggal tersebut dikembalikan ke shift asal. Waktu clock-in dan clock-out tetap tersimpan.',
            },
            {
                key: 'perubahan_shift_after_clock_in',
                label: 'Izinkan pengajuan perubahan shift setelah clock-in',
                description: 'Pengajuan dapat dibuat dan diterapkan pada tanggal yang sudah memiliki clock-in, termasuk saat shift sudah dimulai.',
            },
        ],
    },
    {
        title: 'Approval',
        icon: ShieldCheck,
        fields: [
            {
                key: 'approve_past_requests',
                label: 'Izinkan persetujuan dan penolakan pengajuan yang sudah lewat',
                description:
                    'Pengajuan dengan tanggal mulai cuti, tanggal lembur, atau tanggal perubahan shift sebelum hari ini (WIB) tetap dapat disetujui atau ditolak. Cuti yang disetujui belakangan tetap berlaku pada periode cuti yang diajukan.',
            },
        ],
    },
] satisfies {
    title: string;
    icon: unknown;
    fields: { key: Exclude<keyof Configuration, 'clock_in_tolerance_minutes'>; label: string; description: string }[];
}[];
const example = computed(() => {
    const minutes = Math.max(0, Math.min(1440, Number(form.clock_in_tolerance_minutes) || 0));
    const total = 8 * 60 + minutes;
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}.${String(total % 60).padStart(2, '0')}${total >= 1440 ? ' hari berikutnya' : ''}`;
});
const save = () =>
    form.put('/absensi/admin-web/setting/konfigurasi', {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
</script>

<template>
    <Head title="Konfigurasi Absensi" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="configuration-page">
            <SettingNavigation />
            <FlashMessage />
            <header class="configuration-heading">
                <span class="configuration-icon"><SlidersHorizontal :size="25" /></span>
                <div>
                    <h1>Konfigurasi Absensi</h1>
                    <p>Atur kebijakan pengajuan, persetujuan, dan toleransi kehadiran untuk seluruh pegawai.</p>
                </div>
            </header>
            <form @submit.prevent="save">
                <div v-if="!can.update" class="alert alert-info" role="status">
                    Anda hanya dapat melihat konfigurasi. Hubungi administrator untuk mendapatkan akses mengubah pengaturan.
                </div>
                <div class="configuration-grid">
                    <section v-for="group in groups" :key="group.title" class="card configuration-card">
                        <div class="card-body">
                            <h2><component :is="group.icon" :size="20" />{{ group.title }}</h2>
                            <div v-for="field in group.fields" :key="field.key" class="configuration-field">
                                <div class="configuration-switch-row">
                                    <label :for="field.key">{{ field.label }}</label>
                                    <div class="form-check form-switch">
                                        <input
                                            :id="field.key"
                                            v-model="form[field.key]"
                                            class="form-check-input"
                                            type="checkbox"
                                            role="switch"
                                            :disabled="!can.update || form.processing"
                                            :aria-describedby="`${field.key}-help`"
                                        />
                                    </div>
                                </div>
                                <p :id="`${field.key}-help`">{{ field.description }}</p>
                                <span class="configuration-state" :class="{ enabled: form[field.key] }">{{
                                    form[field.key] ? 'Diizinkan' : 'Tidak diizinkan'
                                }}</span>
                                <p v-if="form.errors[field.key]" class="text-danger" role="alert">{{ form.errors[field.key] }}</p>
                            </div>
                        </div>
                    </section>
                    <section class="card configuration-card configuration-tolerance">
                        <div class="card-body">
                            <h2><Clock3 :size="20" />Toleransi clock-in</h2>
                            <label for="clock-in-tolerance" class="form-label">Toleransi keterlambatan (menit)</label>
                            <div class="input-group configuration-minutes">
                                <input
                                    id="clock-in-tolerance"
                                    v-model="form.clock_in_tolerance_minutes"
                                    type="number"
                                    min="0"
                                    max="1440"
                                    step="1"
                                    required
                                    class="form-control"
                                    :disabled="!can.update || form.processing"
                                    aria-describedby="tolerance-help tolerance-error"
                                />
                                <span class="input-group-text">menit</span>
                            </div>
                            <p id="tolerance-help">
                                Shift masuk pukul 08.00: clock-in sampai pukul <strong>{{ example }}</strong> masih tepat waktu. Menit keterlambatan
                                dihitung setelah batas toleransi. Isi 0 untuk tanpa toleransi.
                            </p>
                            <p class="configuration-note">
                                Toleransi ditambahkan setelah dispensasi datang terlambat dari penggunaan lembur yang disetujui. Perubahan juga
                                berlaku pada perhitungan riwayat dan laporan presensi.
                            </p>
                            <p v-if="form.errors.clock_in_tolerance_minutes" id="tolerance-error" class="text-danger" role="alert">
                                {{ form.errors.clock_in_tolerance_minutes }}
                            </p>
                        </div>
                    </section>
                </div>
                <footer v-if="can.update" class="configuration-actions">
                    <span>{{
                        form.isDirty ? 'Ada perubahan yang belum disimpan.' : 'Konfigurasi berlaku untuk admin web dan aplikasi mobile.'
                    }}</span>
                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2" :disabled="form.processing || !form.isDirty">
                        <Save :size="17" />{{ form.processing ? 'Menyimpan...' : 'Simpan konfigurasi' }}
                    </button>
                </footer>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.configuration-page {
    max-width: 1180px;
    margin: 0 auto;
}
.configuration-heading {
    display: flex;
    align-items: center;
    gap: 16px;
    margin: 26px 0;
}
.configuration-icon {
    display: grid;
    place-items: center;
    width: 56px;
    height: 56px;
    flex-shrink: 0;
    border-radius: 16px;
    color: #5146bd;
    background: #eeecff;
}
h1 {
    font-size: 25px;
    font-weight: 700;
    margin: 0 0 7px;
}
.configuration-heading p {
    color: #657187;
    margin: 0;
}
.configuration-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}
.configuration-card {
    border: 1px solid #e4e8f0;
    border-radius: 14px;
    box-shadow: 0 3px 14px #172e4e05;
}
.configuration-card .card-body {
    padding: 24px;
}
h2 {
    display: flex;
    gap: 10px;
    align-items: center;
    font-size: 17px;
    font-weight: 650;
    margin-bottom: 23px;
    color: #344168;
}
.configuration-field + .configuration-field {
    margin-top: 22px;
    padding-top: 22px;
    border-top: 1px solid #edf0f5;
}
.configuration-switch-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
}
.configuration-switch-row label {
    font-weight: 600;
    line-height: 1.6;
    cursor: pointer;
}
.form-switch {
    padding-left: 2.8em;
    flex-shrink: 0;
}
.form-check-input {
    cursor: pointer;
    width: 2.5em;
    height: 1.35em;
}
.configuration-field p,
.configuration-tolerance p {
    color: #657187;
    line-height: 1.7;
    margin: 10px 0;
    font-size: 13px;
}
.configuration-state {
    display: inline-block;
    border-radius: 6px;
    padding: 3px 9px;
    background: #f0f2f6;
    color: #677287;
    font-size: 11px;
    font-weight: 600;
}
.configuration-state.enabled {
    color: #13714e;
    background: #e7f7ef;
}
.configuration-tolerance {
    grid-column: 1 / -1;
}
.configuration-minutes {
    max-width: 240px;
}
.configuration-note {
    padding: 12px 15px;
    background: #f7f8fc;
    border-radius: 8px;
}
.configuration-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 24px 0;
}
.configuration-actions span {
    color: #657187;
    font-size: 13px;
}
@media (max-width: 767px) {
    .configuration-grid {
        grid-template-columns: 1fr;
    }
    .configuration-actions {
        align-items: stretch;
        flex-direction: column;
    }
    .configuration-actions button {
        justify-content: center;
    }
    h1 {
        font-size: 21px;
    }
}
</style>
