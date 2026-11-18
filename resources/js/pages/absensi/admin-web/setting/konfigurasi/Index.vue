<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    Clock3,
    GitPullRequestArrow,
    RotateCcw,
    Save,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Timer,
    UsersRound,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FlashMessage from '../components/FlashMessage.vue';
import SettingNavigation from '../components/SettingNavigation.vue';

interface Configuration {
    cancel_approved_cuti: boolean;
    cuti_past_date: boolean;
    cancel_approved_lembur: boolean;
    cancel_approved_perubahan_shift: boolean;
    perubahan_shift_after_clock_in: boolean;
    perubahan_shift_same_day: boolean;
    perubahan_shift_past_date: boolean;
    perubahan_shift_without_default: boolean;
    clock_in_tolerance_minutes: number;
    clock_out_without_clock_in: boolean;
    face_minimum_similarity: number;
    approve_past_requests: boolean;
    allow_self_approval: boolean;
    mobile_presensi_pegawai_scope: 'unit-kerja' | 'approval-line';
    mobile_presensi_pegawai_approval_type: string;
    mobile_presensi_pegawai_all_role_ids: number[];
}

const props = defineProps<{
    configuration: Configuration;
    approvalTypeOptions: { value: string; label: string }[];
    roleOptions: { id: number; name: string }[];
    can: { update: boolean };
}>();
const form = useForm({ ...props.configuration });
const copyConfiguration = (value: Configuration): Configuration => ({
    ...value,
    mobile_presensi_pegawai_all_role_ids: [...value.mobile_presensi_pegawai_all_role_ids],
});
const savedConfiguration = ref(copyConfiguration(props.configuration));
const activeCategory = ref('Presensi');
const search = ref('');
const roleSearch = ref('');
const filteredRoles = computed(() => props.roleOptions.filter((role) => role.name.toLowerCase().includes(roleSearch.value.trim().toLowerCase())));
const roleError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('mobile_presensi_pegawai_all_role_ids'))?.[1]);
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan', href: '/absensi/admin-web/setting/konfigurasi' },
    { title: 'Konfigurasi', href: '/absensi/admin-web/setting/konfigurasi' },
];
const groups = [
    {
        title: 'Presensi',
        icon: Clock3,
        fields: [
            {
                key: 'clock_out_without_clock_in',
                label: 'Izinkan clock-out tanpa clock-in',
                description:
                    'Pegawai dapat melakukan clock-out meskipun belum clock-in pada jadwal tersebut. Jam clock-in tetap kosong. Verifikasi lokasi, wajah, dan persetujuan di luar radius tetap berlaku. Nonaktifkan untuk mewajibkan clock-in terlebih dahulu.',
            },
        ],
    },
    {
        title: 'Pengajuan cuti',
        icon: CalendarDays,
        fields: [
            {
                key: 'cuti_past_date',
                label: 'Izinkan pengajuan cuti untuk tanggal yang sudah lewat',
                description:
                    'Pegawai dapat mengajukan cuti untuk kemarin atau tanggal sebelumnya. Kuota mengikuti tahun tanggal mulai cuti. Persetujuan mengikuti pengaturan approval pengajuan yang sudah lewat.',
            },
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
                key: 'perubahan_shift_without_default',
                label: 'Izinkan pengajuan perubahan shift tanpa shift default',
                description:
                    'Pegawai yang belum memiliki shift default dapat mengajukan perubahan selama sudah ada jadwal shift pada tanggal yang dipilih. Shift asal mengikuti jadwal tanggal tersebut.',
            },
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
            {
                key: 'perubahan_shift_same_day',
                label: 'Izinkan perubahan shift pada hari H',
                description:
                    'Pengajuan dan persetujuan pada tanggal shift tetap diizinkan meskipun shift sudah dimulai atau clock-in dan clock-out sudah dilakukan. Waktu dan bukti presensi tetap tersimpan.',
            },
            {
                key: 'perubahan_shift_past_date',
                label: 'Izinkan pengajuan perubahan shift untuk tanggal yang sudah lewat',
                description:
                    'Pegawai dapat mengajukan perubahan shift untuk kemarin atau tanggal sebelumnya, termasuk setelah clock-in dan clock-out. Bukti presensi tetap tersimpan. Persetujuan mengikuti pengaturan approval pengajuan yang sudah lewat.',
            },
        ],
    },
    {
        title: 'Approval',
        icon: ShieldCheck,
        fields: [
            {
                key: 'allow_self_approval',
                label: 'Izinkan approval diri sendiri',
                description:
                    'Pemohon yang tercantum sebagai approver dapat menyetujui atau menolak pengajuannya sendiri sesuai urutan approval. Berlaku untuk cuti, lembur, penggunaan lembur, perubahan shift, dan presensi di web maupun mobile.',
            },
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
    fields: {
        key: Exclude<
            keyof Configuration,
            | 'clock_in_tolerance_minutes'
            | 'face_minimum_similarity'
            | 'mobile_presensi_pegawai_scope'
            | 'mobile_presensi_pegawai_approval_type'
            | 'mobile_presensi_pegawai_all_role_ids'
        >;
        label: string;
        description: string;
    }[];
}[];
type PolicyKey = (typeof groups)[number]['fields'][number]['key'];
const fieldSummaries: Record<PolicyKey, string> = {
    clock_out_without_clock_in: 'Jam masuk tetap kosong. Verifikasi lokasi dan wajah tetap berlaku.',
    cuti_past_date: 'Pengajuan untuk kemarin atau sebelumnya, dengan kuota sesuai tahun cuti.',
    cancel_approved_cuti: 'Cuti yang sudah disetujui dapat dibatalkan dan saldonya dikembalikan.',
    cancel_approved_lembur: 'Berlaku untuk pengajuan dan penggunaan lembur yang telah disetujui.',
    perubahan_shift_without_default: 'Tetap memerlukan jadwal shift pada tanggal yang dipilih.',
    cancel_approved_perubahan_shift: 'Kembali ke shift asal. Waktu dan bukti presensi tetap tersimpan.',
    perubahan_shift_after_clock_in: 'Perubahan dapat diajukan setelah pegawai mencatat jam masuk.',
    perubahan_shift_same_day: 'Pengajuan dan approval pada hari H, termasuk setelah presensi.',
    perubahan_shift_past_date: 'Pengajuan tanggal lewat tetap menyimpan seluruh bukti presensi.',
    allow_self_approval: 'Pemohon yang menjadi approver dapat memutuskan pengajuannya sendiri.',
    approve_past_requests: 'Approval dan penolakan tetap tersedia setelah tanggal pengajuan lewat.',
};
const groupDescriptions: Record<string, string> = {
    Presensi: 'Atur urutan presensi, batas keterlambatan, dan similarity wajah pegawai.',
    'Pengajuan cuti': 'Tentukan waktu pengajuan dan ketentuan pembatalan cuti.',
    Lembur: 'Atur pembatalan pengajuan dan penggunaan lembur.',
    'Perubahan shift': 'Tentukan kapan pegawai dapat mengubah atau membatalkan shift.',
    Approval: 'Atur kewenangan approver dan keputusan untuk tanggal yang sudah lewat.',
};
const shortLabel = (label: string) => label.replace(/^Izinkan /, '').replace(/^./, (letter) => letter.toUpperCase());
const changedKeys = computed(() =>
    (Object.keys(savedConfiguration.value) as (keyof Configuration)[]).filter((key) => {
        if (key === 'mobile_presensi_pegawai_all_role_ids') {
            return [...form[key]].sort((a, b) => a - b).join(',') !== [...savedConfiguration.value[key]].sort((a, b) => a - b).join(',');
        }
        return form[key] !== savedConfiguration.value[key];
    }),
);
const changed = (key: keyof Configuration) => changedKeys.value.includes(key);
const categoryFor = (key: string) =>
    key.startsWith('mobile_presensi_pegawai_')
        ? 'mobile'
        : key === 'clock_in_tolerance_minutes' || key === 'face_minimum_similarity'
          ? 'Presensi'
          : groups.find((group) => group.fields.some((field) => field.key === key))?.title;
const categories = computed(() =>
    [
        ...groups.map((group) => ({
            key: group.title,
            title: group.title,
            icon: group.icon,
            count: group.fields.filter((field) => form[field.key]).length + '/' + group.fields.length,
            detail:
                group.title === 'Presensi'
                    ? 'Clock-out, toleransi & wajah'
                    : group.title === 'Pengajuan cuti'
                      ? 'Pengajuan & pembatalan'
                      : group.title === 'Lembur'
                        ? 'Pembatalan lembur'
                        : group.title === 'Perubahan shift'
                          ? 'Jadwal & pembatalan'
                          : 'Kewenangan & batas waktu',
        })),
        {
            key: 'mobile',
            title: 'Presensi Pegawai',
            icon: UsersRound,
            count: form.mobile_presensi_pegawai_all_role_ids.length + ' role',
            detail: 'Cakupan akses mobile',
        },
    ].map((category) => ({
        ...category,
        changes: changedKeys.value.filter((key) => categoryFor(key) === category.key).length,
        hasError: Object.keys(form.errors).some((key) => categoryFor(key) === category.key),
    })),
);
const policyCount = groups.reduce((total, group) => total + group.fields.length, 0);
const allowedCount = computed(() => groups.reduce((total, group) => total + group.fields.filter((field) => form[field.key]).length, 0));
const matchesSearch = (...values: string[]) =>
    search.value
        .toLowerCase()
        .trim()
        .split(/\s+/)
        .every((word) => values.join(' ').toLowerCase().includes(word));
const visibleGroups = computed(() =>
    groups
        .map((group, index) => ({
            ...group,
            id: index,
            fields: group.fields.filter((field) => matchesSearch(group.title, field.label, field.description, fieldSummaries[field.key])),
            showTolerance:
                group.title === 'Presensi' && matchesSearch('Presensi toleransi clock-in keterlambatan menit dispensasi penggunaan lembur'),
            showFaceSimilarity:
                group.title === 'Presensi' && matchesSearch('Presensi similarity kemiripan kecocokan verifikasi wajah face detection persen minimum'),
        }))
        .filter((group) =>
            search.value.trim() ? group.fields.length || group.showTolerance || group.showFaceSimilarity : group.title === activeCategory.value,
        ),
);
const showMobile = computed(() =>
    search.value.trim()
        ? matchesSearch('Presensi Pegawai mobile cakupan akses kepala unit kerja approval line kategori role seluruh absensi foto')
        : activeCategory.value === 'mobile',
);
const resultCount = computed(
    () =>
        visibleGroups.value.reduce(
            (total, group) => total + group.fields.length + Number(group.showTolerance) + Number(group.showFaceSimilarity),
            0,
        ) + (showMobile.value ? (form.mobile_presensi_pegawai_scope === 'approval-line' ? 3 : 2) : 0),
);
const selectCategory = (key: string) => {
    activeCategory.value = key;
    search.value = '';
};
const reset = () => {
    form.reset();
    form.clearErrors();
};
const example = computed(() => {
    const minutes = Math.max(0, Math.min(1440, Number(form.clock_in_tolerance_minutes) || 0));
    const total = 8 * 60 + minutes;
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}.${String(total % 60).padStart(2, '0')}${total >= 1440 ? ' hari berikutnya' : ''}`;
});
const save = () =>
    form.put('/absensi/admin-web/setting/konfigurasi', {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            savedConfiguration.value = copyConfiguration(form.data());
        },
        onError: (errors) => {
            search.value = '';
            activeCategory.value = categoryFor(Object.keys(errors)[0] ?? '') ?? activeCategory.value;
        },
    });
</script>
<template>
    <Head title="Konfigurasi Absensi" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="configuration-page">
            <SettingNavigation />
            <FlashMessage />
            <header class="configuration-heading">
                <span class="configuration-icon"><SlidersHorizontal :size="22" /></span>
                <div class="configuration-heading-copy">
                    <h1>Konfigurasi Absensi</h1>
                    <p>Sesuaikan kebijakan presensi, pengajuan, dan akses pegawai.</p>
                </div>
                <span class="configuration-overview"><span></span>{{ allowedCount }} dari {{ policyCount }} kebijakan diizinkan</span>
            </header>
            <form novalidate @submit.prevent="save">
                <div v-if="!can.update" class="configuration-notice" role="status">
                    <ShieldCheck :size="17" /><span>Mode lihat. Hubungi administrator untuk mengubah konfigurasi.</span>
                </div>
                <div v-if="Object.keys(form.errors).length" class="configuration-error-summary" role="alert">
                    Perubahan belum tersimpan. Periksa pengaturan yang ditandai pada kategori.
                </div>
                <div class="configuration-toolbar" role="search" aria-label="Pencarian konfigurasi">
                    <label for="configuration-search" class="configuration-search">
                        <Search :size="18" /><input
                            id="configuration-search"
                            v-model="search"
                            type="search"
                            aria-label="Cari pengaturan konfigurasi"
                            placeholder="Cari clock-out, cuti, role..."
                            autocomplete="off"
                            @keydown.enter.prevent
                        />
                        <button v-if="search" type="button" aria-label="Hapus pencarian" @click="search = ''"><X :size="15" /></button>
                    </label>
                    <span aria-live="polite">{{ search.trim() ? resultCount + ' pengaturan ditemukan' : '6 kategori pengaturan' }}</span>
                </div>
                <div class="configuration-workspace">
                    <nav class="configuration-categories" aria-label="Kategori konfigurasi">
                        <span class="configuration-nav-label">PILIH KATEGORI</span>
                        <button
                            v-for="category in categories"
                            :key="category.key"
                            type="button"
                            class="configuration-category"
                            :class="{ active: !search.trim() && activeCategory === category.key, 'has-error': category.hasError }"
                            :aria-current="!search.trim() && activeCategory === category.key ? 'true' : undefined"
                            aria-controls="configuration-content"
                            @click="selectCategory(category.key)"
                        >
                            <span class="configuration-category-icon"><component :is="category.icon" :size="18" /></span>
                            <span class="configuration-category-copy"
                                ><strong>{{ category.title }}</strong
                                ><small>{{ category.detail }}</small></span
                            >
                            <span class="configuration-category-count">{{ category.count }}</span>
                            <span
                                v-if="category.hasError"
                                class="configuration-category-mark error"
                                aria-label="Ada pengaturan yang perlu diperiksa"
                            ></span>
                            <span
                                v-else-if="category.changes"
                                class="configuration-category-mark"
                                :aria-label="category.changes + ' perubahan belum disimpan'"
                            ></span>
                        </button>
                        <p class="configuration-nav-note">Angka x/y menunjukkan kebijakan yang diizinkan dari total kebijakan kategori.</p>
                    </nav>
                    <div id="configuration-content" class="configuration-content">
                        <section
                            v-for="group in visibleGroups"
                            :key="group.title"
                            class="configuration-panel"
                            :aria-labelledby="'configuration-heading-' + group.id"
                        >
                            <header class="configuration-panel-heading">
                                <span class="configuration-panel-icon"><component :is="group.icon" :size="20" /></span>
                                <div>
                                    <h2 :id="'configuration-heading-' + group.id">{{ group.title }}</h2>
                                    <p>{{ groupDescriptions[group.title] }}</p>
                                </div>
                                <span class="configuration-panel-count"
                                    >{{ group.fields.length + Number(group.showTolerance) + Number(group.showFaceSimilarity) }} pengaturan</span
                                >
                            </header>
                            <div
                                v-for="field in group.fields"
                                :key="field.key"
                                class="configuration-policy"
                                :class="{ modified: changed(field.key) }"
                            >
                                <div class="configuration-policy-main">
                                    <div class="configuration-policy-copy">
                                        <label :for="field.key"
                                            >{{ shortLabel(field.label)
                                            }}<span v-if="changed(field.key)" class="configuration-change-label">Diubah</span></label
                                        >
                                        <p :id="field.key + '-help'">{{ fieldSummaries[field.key] }}</p>
                                    </div>
                                    <div class="configuration-switch-control">
                                        <span class="configuration-state" :class="{ enabled: form[field.key] }">{{
                                            form[field.key] ? 'Diizinkan' : 'Tidak diizinkan'
                                        }}</span>
                                        <div class="form-check form-switch">
                                            <input
                                                :id="field.key"
                                                v-model="form[field.key]"
                                                class="form-check-input"
                                                type="checkbox"
                                                role="switch"
                                                :disabled="!can.update || form.processing"
                                                :aria-describedby="field.key + '-help' + (form.errors[field.key] ? ' ' + field.key + '-error' : '')"
                                                :aria-invalid="!!form.errors[field.key]"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <details class="configuration-details">
                                    <summary>Detail aturan <ChevronDown :size="13" /></summary>
                                    <p>{{ field.description }}</p>
                                </details>
                                <p v-if="form.errors[field.key]" :id="field.key + '-error'" class="configuration-field-error" role="alert">
                                    {{ form.errors[field.key] }}
                                </p>
                            </div>
                            <div
                                v-if="group.showTolerance"
                                class="configuration-tolerance"
                                :class="{ modified: changed('clock_in_tolerance_minutes') }"
                            >
                                <div class="configuration-field-heading">
                                    <div>
                                        <label for="clock-in-tolerance"
                                            >Toleransi keterlambatan<span
                                                v-if="changed('clock_in_tolerance_minutes')"
                                                class="configuration-change-label"
                                                >Diubah</span
                                            ></label
                                        >
                                        <p>Batas tambahan sebelum clock-in dihitung terlambat.</p>
                                    </div>
                                    <div class="configuration-minutes">
                                        <input
                                            id="clock-in-tolerance"
                                            v-model.number="form.clock_in_tolerance_minutes"
                                            type="number"
                                            min="0"
                                            max="1440"
                                            step="1"
                                            required
                                            :disabled="!can.update || form.processing"
                                            :aria-invalid="!!form.errors.clock_in_tolerance_minutes"
                                            aria-describedby="tolerance-help tolerance-error"
                                        /><span>menit</span>
                                    </div>
                                </div>
                                <div class="configuration-presets" aria-label="Pilihan cepat toleransi">
                                    <span>Pilihan cepat</span>
                                    <button
                                        v-for="minutes in [0, 5, 10, 15, 30]"
                                        :key="minutes"
                                        type="button"
                                        :class="{ selected: form.clock_in_tolerance_minutes === minutes }"
                                        :aria-pressed="form.clock_in_tolerance_minutes === minutes"
                                        :disabled="!can.update || form.processing"
                                        @click="form.clock_in_tolerance_minutes = minutes"
                                    >
                                        {{ minutes === 0 ? 'Tanpa toleransi' : minutes + ' menit' }}
                                    </button>
                                </div>
                                <div id="tolerance-help" class="configuration-preview">
                                    <Clock3 :size="17" />
                                    <p>
                                        Masuk <strong>08.00</strong><span class="configuration-preview-arrow">→</span>clock-in sampai
                                        <strong>{{ example }}</strong> masih tepat waktu.
                                    </p>
                                </div>
                                <details class="configuration-details">
                                    <summary>Cara perhitungan <ChevronDown :size="13" /></summary>
                                    <p>
                                        Toleransi ditambahkan setelah dispensasi datang terlambat dari penggunaan lembur yang disetujui. Menit
                                        terlambat dihitung setelah batas ini. Perubahan berlaku pada riwayat dan laporan presensi. Isi 0 untuk tanpa
                                        toleransi; maksimal 1440 menit.
                                    </p>
                                </details>
                                <p v-if="form.errors.clock_in_tolerance_minutes" id="tolerance-error" class="configuration-field-error" role="alert">
                                    {{ form.errors.clock_in_tolerance_minutes }}
                                </p>
                            </div>
                            <div
                                v-if="group.showFaceSimilarity"
                                class="configuration-tolerance"
                                :class="{ modified: changed('face_minimum_similarity') }"
                            >
                                <div class="configuration-field-heading">
                                    <div>
                                        <label for="face-minimum-similarity"
                                            >Batas minimal similarity wajah<span
                                                v-if="changed('face_minimum_similarity')"
                                                class="configuration-change-label"
                                                >Diubah</span
                                            ></label
                                        >
                                        <p>Persentase kecocokan minimal untuk verifikasi wajah saat clock-in dan clock-out.</p>
                                    </div>
                                    <div class="configuration-minutes">
                                        <input
                                            id="face-minimum-similarity"
                                            v-model.number="form.face_minimum_similarity"
                                            type="number"
                                            min="1"
                                            max="100"
                                            step="0.01"
                                            required
                                            :disabled="!can.update || form.processing"
                                            :aria-invalid="!!form.errors.face_minimum_similarity"
                                            :aria-describedby="
                                                'face-similarity-help' + (form.errors.face_minimum_similarity ? ' face-similarity-error' : '')
                                            "
                                        /><span>%</span>
                                    </div>
                                </div>
                                <p id="face-similarity-help" class="configuration-field-help">
                                    Isi 1–100%, maksimal 2 angka desimal. Nilai lebih tinggi memperketat kecocokan. Setelah disimpan, berlaku pada
                                    verifikasi berikutnya.
                                </p>
                                <details class="configuration-details">
                                    <summary>Detail verifikasi <ChevronDown :size="13" /></summary>
                                    <p>
                                        Batas ini menentukan kecocokan wajah. Nilai rendah lebih longgar dan meningkatkan kemungkinan wajah orang
                                        berbeda diterima. Foto tetap harus memuat satu wajah dan lolos pemeriksaan keaslian. Persentase ini adalah
                                        skor kecocokan, bukan tingkat akurasi sistem. Perubahan tidak menghitung ulang presensi yang sudah tersimpan.
                                    </p>
                                </details>
                                <p
                                    v-if="form.errors.face_minimum_similarity"
                                    id="face-similarity-error"
                                    class="configuration-field-error"
                                    role="alert"
                                >
                                    {{ form.errors.face_minimum_similarity }}
                                </p>
                            </div>
                        </section>
                        <section v-if="showMobile" class="configuration-panel" aria-labelledby="configuration-mobile-heading">
                            <header class="configuration-panel-heading">
                                <span class="configuration-panel-icon"><UsersRound :size="20" /></span>
                                <div>
                                    <h2 id="configuration-mobile-heading">Presensi Pegawai mobile</h2>
                                    <p>Atur data pegawai yang dapat dilihat dari aplikasi mobile.</p>
                                </div>
                            </header>
                            <div class="configuration-mobile-body">
                                <fieldset
                                    class="configuration-scope"
                                    :disabled="!can.update || form.processing"
                                    aria-describedby="presensi-pegawai-scope-help presensi-pegawai-scope-error"
                                >
                                    <legend>
                                        Cakupan data pegawai<span v-if="changed('mobile_presensi_pegawai_scope')" class="configuration-change-label"
                                            >Diubah</span
                                        >
                                    </legend>
                                    <div class="configuration-scope-options">
                                        <label
                                            class="configuration-scope-option"
                                            :class="{ selected: form.mobile_presensi_pegawai_scope === 'unit-kerja' }"
                                            ><input v-model="form.mobile_presensi_pegawai_scope" type="radio" value="unit-kerja" /><span
                                                ><strong>Kepala unit</strong><small>Pegawai lain dalam unit kerja sendiri.</small></span
                                            ></label
                                        >
                                        <label
                                            class="configuration-scope-option"
                                            :class="{ selected: form.mobile_presensi_pegawai_scope === 'approval-line' }"
                                            ><input v-model="form.mobile_presensi_pegawai_scope" type="radio" value="approval-line" /><span
                                                ><strong>Approval line</strong><small>Pegawai dalam jalur approval, lintas unit.</small></span
                                            ></label
                                        >
                                    </div>
                                </fieldset>
                                <p id="presensi-pegawai-scope-help" class="configuration-field-help">
                                    {{
                                        form.mobile_presensi_pegawai_scope === 'unit-kerja'
                                            ? 'Kepala unit melihat pegawai lain dalam unit kerjanya.'
                                            : 'Menampilkan pegawai yang menempatkan pengguna sebagai approver pada salah satu tingkat.'
                                    }}
                                </p>
                                <p
                                    v-if="form.errors.mobile_presensi_pegawai_scope"
                                    id="presensi-pegawai-scope-error"
                                    class="configuration-field-error"
                                    role="alert"
                                >
                                    {{ form.errors.mobile_presensi_pegawai_scope }}
                                </p>
                                <div v-if="form.mobile_presensi_pegawai_scope === 'approval-line'" class="configuration-approval-type">
                                    <label for="mobile-presensi-pegawai-approval-type"
                                        >Kategori approval line<span
                                            v-if="changed('mobile_presensi_pegawai_approval_type')"
                                            class="configuration-change-label"
                                            >Diubah</span
                                        ></label
                                    >
                                    <select
                                        id="mobile-presensi-pegawai-approval-type"
                                        v-model="form.mobile_presensi_pegawai_approval_type"
                                        class="form-select"
                                        :disabled="!can.update || form.processing"
                                        aria-describedby="presensi-pegawai-approval-help presensi-pegawai-approval-error"
                                        :aria-invalid="!!form.errors.mobile_presensi_pegawai_approval_type"
                                    >
                                        <option v-for="option in approvalTypeOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <p id="presensi-pegawai-approval-help" class="configuration-field-help">
                                        Mengikuti penugasan terbaru pada menu Aproval Line untuk kategori ini.
                                    </p>
                                    <p
                                        v-if="form.errors.mobile_presensi_pegawai_approval_type"
                                        id="presensi-pegawai-approval-error"
                                        class="configuration-field-error"
                                        role="alert"
                                    >
                                        {{ form.errors.mobile_presensi_pegawai_approval_type }}
                                    </p>
                                </div>
                                <div class="configuration-roles">
                                    <div class="configuration-field-heading">
                                        <div>
                                            <label for="presensi-pegawai-role-search"
                                                >Role akses seluruh pegawai<span
                                                    v-if="changed('mobile_presensi_pegawai_all_role_ids')"
                                                    class="configuration-change-label"
                                                    >Diubah</span
                                                ></label
                                            >
                                            <p>Role terpilih dapat melihat seluruh pegawai lintas unit, termasuk diri sendiri.</p>
                                        </div>
                                        <span class="configuration-selected-count" aria-live="polite"
                                            >{{ form.mobile_presensi_pegawai_all_role_ids.length }} dipilih</span
                                        >
                                    </div>
                                    <label class="configuration-search configuration-role-search"
                                        ><Search :size="16" /><input
                                            id="presensi-pegawai-role-search"
                                            v-model="roleSearch"
                                            type="search"
                                            placeholder="Cari nama role..."
                                            autocomplete="off"
                                            aria-describedby="presensi-pegawai-role-help"
                                            @keydown.enter.prevent
                                    /></label>
                                    <fieldset
                                        class="configuration-role-list"
                                        :disabled="!can.update || form.processing"
                                        aria-describedby="presensi-pegawai-role-help presensi-pegawai-role-error"
                                    >
                                        <legend class="visually-hidden">Pilih role akses seluruh absensi pegawai</legend>
                                        <label
                                            v-for="role in filteredRoles"
                                            :key="role.id"
                                            class="configuration-role-option"
                                            :class="{ selected: form.mobile_presensi_pegawai_all_role_ids.includes(role.id) }"
                                            ><input
                                                v-model="form.mobile_presensi_pegawai_all_role_ids"
                                                type="checkbox"
                                                class="form-check-input"
                                                :value="role.id"
                                            /><span>{{ role.name }}</span></label
                                        >
                                        <p v-if="!filteredRoles.length" class="configuration-role-empty">
                                            {{ roleOptions.length ? 'Role tidak ditemukan. Coba kata lain.' : 'Belum ada role tersedia.' }}
                                        </p>
                                    </fieldset>
                                    <p id="presensi-pegawai-role-help" class="configuration-field-help">
                                        Kosongkan untuk memakai cakupan kepala unit atau approval line bagi semua pengguna. Role yang tidak dipilih
                                        tetap mengikuti cakupan di atas.
                                    </p>
                                    <p v-if="roleError" id="presensi-pegawai-role-error" class="configuration-field-error" role="alert">
                                        {{ roleError }}
                                    </p>
                                </div>
                                <div class="configuration-mobile-note">
                                    <ShieldCheck :size="17" />
                                    <p>
                                        Pengguna tetap memerlukan permission Presensi Pegawai dan akses mobile. Cakupan berlaku pada daftar,
                                        ringkasan, dan foto. Pengguna tanpa penugasan approval line memerlukan role akses seluruh pegawai.
                                    </p>
                                </div>
                            </div>
                        </section>
                        <div v-if="!resultCount" class="configuration-empty" role="status">
                            <Search :size="30" />
                            <h2>Pengaturan tidak ditemukan</h2>
                            <p>Coba kata lain, seperti clock-out, toleransi, atau role.</p>
                            <button type="button" class="configuration-text-button" @click="search = ''">Hapus pencarian</button>
                        </div>
                    </div>
                </div>
                <footer class="configuration-actions">
                    <div class="configuration-save-status" role="status" aria-live="polite">
                        <span class="configuration-save-icon" :class="{ pending: changedKeys.length }"
                            ><Save v-if="changedKeys.length" :size="18" /><CheckCircle2 v-else :size="18"
                        /></span>
                        <div>
                            <strong>{{ changedKeys.length ? changedKeys.length + ' pengaturan diubah' : 'Konfigurasi tersimpan' }}</strong
                            ><small>{{
                                changedKeys.length ? 'Simpan untuk menerapkan perubahan.' : 'Berlaku untuk admin web dan aplikasi mobile.'
                            }}</small>
                        </div>
                    </div>
                    <div v-if="can.update" class="configuration-save-buttons">
                        <button type="button" class="configuration-reset" :disabled="form.processing || !changedKeys.length" @click="reset">
                            <RotateCcw :size="15" /><span>Batalkan perubahan</span></button
                        ><button type="submit" class="configuration-save" :disabled="form.processing || !changedKeys.length">
                            <Save :size="16" />{{ form.processing ? 'Menyimpan...' : 'Simpan perubahan' }}
                        </button>
                    </div>
                    <span v-else class="configuration-readonly-label"><ShieldCheck :size="15" />Hanya lihat</span>
                </footer>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.configuration-page {
    --config-bg: #fff;
    --config-soft: #f7f8fc;
    --config-text: #29364f;
    --config-muted: #68768c;
    --config-border: #e5e9f2;
    --config-accent: #5b4acb;
    --config-accent-bg: #f0edff;
    max-width: 1240px;
    margin: 0 auto;
    color: var(--config-text);
}
.configuration-heading {
    display: flex;
    align-items: center;
    gap: 13px;
    margin: 22px 0;
}
.configuration-icon,
.configuration-panel-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    color: var(--config-accent);
    background: var(--config-accent-bg);
    border-radius: 12px;
}
.configuration-icon {
    width: 46px;
    height: 46px;
}
.configuration-heading-copy {
    flex: 1;
    min-width: 0;
}
h1 {
    margin: 0 0 5px;
    color: var(--config-text);
    font-size: 22px;
    font-weight: 700;
}
.configuration-heading p,
.configuration-panel-heading p {
    margin: 0;
    color: var(--config-muted);
    font-size: 13px;
    line-height: 1.6;
}
.configuration-overview {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: var(--config-muted);
    white-space: nowrap;
}
.configuration-overview > span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #20986d;
}
.configuration-toolbar {
    display: flex;
    align-items: center;
    gap: 18px;
    justify-content: space-between;
    margin-bottom: 18px;
}
.configuration-toolbar > span {
    color: var(--config-muted);
    font-size: 12px;
    white-space: nowrap;
}
.configuration-search {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 10px 13px;
    border: 1px solid var(--config-border);
    background: var(--config-bg);
    border-radius: 10px;
    color: var(--config-muted);
    width: min(100%, 440px);
    transition:
        border-color 0.15s,
        box-shadow 0.15s;
}
.configuration-search:focus-within {
    border-color: var(--config-accent);
    box-shadow: 0 0 0 3px #5b4acb12;
}
.configuration-search > svg {
    flex-shrink: 0;
}
.configuration-search input {
    width: 100%;
    min-width: 0;
    padding: 0;
    border: 0;
    outline: 0;
    background: transparent;
    box-shadow: none;
    color: var(--config-text);
    font: inherit;
    font-size: 13px;
}
.configuration-search input::-webkit-search-cancel-button {
    appearance: none;
}
.configuration-search button {
    display: grid;
    place-items: center;
    border: 0;
    background: transparent;
    color: var(--config-muted);
    padding: 2px;
}
.configuration-workspace {
    display: grid;
    grid-template-columns: 225px minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}
.configuration-categories {
    position: sticky;
    top: 90px;
    display: grid;
    gap: 5px;
}
.configuration-nav-label {
    color: var(--config-muted);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.3px;
    padding: 2px 12px 8px;
}
.configuration-category {
    position: relative;
    display: grid;
    grid-template-columns: 20px minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    text-align: left;
    padding: 13px 10px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    color: var(--config-muted);
    transition:
        background 0.15s,
        border-color 0.15s,
        color 0.15s;
}
.configuration-category:hover {
    background: var(--config-bg);
    color: var(--config-text);
}
.configuration-category.active {
    background: var(--config-bg);
    border-color: var(--config-border);
    box-shadow: 0 3px 10px #27334d04;
    color: var(--config-accent);
}
.configuration-category-icon {
    display: grid;
    place-items: center;
}
.configuration-category-copy {
    min-width: 0;
}
.configuration-category-copy strong {
    display: block;
    font-size: 12px;
    font-weight: 650;
    line-height: 1.5;
}
.configuration-category-copy small {
    display: block;
    font-size: 10px;
    margin-top: 3px;
    color: var(--config-muted);
    line-height: 1.5;
}
.configuration-category-count {
    border-radius: 6px;
    padding: 3px 6px;
    background: var(--config-soft);
    font-size: 10px;
    font-weight: 600;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.configuration-category.active .configuration-category-count {
    background: var(--config-accent-bg);
}
.configuration-category-mark {
    position: absolute;
    top: 9px;
    right: 7px;
    width: 6px;
    height: 6px;
    background: #d29326;
    border-radius: 50%;
}
.configuration-category-mark.error {
    background: #d44858;
}
.configuration-nav-note {
    color: var(--config-muted);
    margin: 11px 12px 0;
    line-height: 1.7;
    font-size: 11px;
}
.configuration-content {
    display: grid;
    gap: 16px;
    min-width: 0;
}
.configuration-panel {
    border: 1px solid var(--config-border);
    border-radius: 14px;
    overflow: hidden;
    background: var(--config-bg);
    box-shadow: 0 3px 16px #27334d03;
}
.configuration-panel-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 22px;
    border-bottom: 1px solid var(--config-border);
}
.configuration-panel-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
}
.configuration-panel-heading > div {
    flex: 1;
    min-width: 0;
}
h2 {
    margin: 0 0 4px;
    font-size: 16px;
    font-weight: 650;
    color: var(--config-text);
}
.configuration-panel-heading p {
    font-size: 12px;
}
.configuration-panel-count {
    font-size: 10px;
    color: var(--config-muted);
    padding: 5px 8px;
    background: var(--config-soft);
    border-radius: 6px;
    white-space: nowrap;
}
.configuration-policy,
.configuration-tolerance {
    padding: 18px 22px;
    border-left: 3px solid transparent;
}
.configuration-policy + .configuration-policy,
.configuration-tolerance {
    border-top: 1px solid var(--config-border);
}
.configuration-policy.modified,
.configuration-tolerance.modified {
    border-left-color: #b295ec;
}
.configuration-policy-main,
.configuration-field-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
}
.configuration-policy-copy {
    flex: 1;
    min-width: 0;
}
.configuration-policy label,
.configuration-field-heading label,
.configuration-scope legend,
.configuration-approval-type > label {
    display: block;
    margin: 0;
    color: var(--config-text);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.6;
}
.configuration-policy label {
    cursor: pointer;
}
.configuration-policy-copy > p,
.configuration-field-heading > div > p,
.configuration-field-help {
    margin: 5px 0 0;
    font-size: 12px;
    color: var(--config-muted);
    line-height: 1.7;
}
.configuration-switch-control {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-top: 1px;
    flex-shrink: 0;
}
.configuration-state {
    font-size: 10px;
    color: var(--config-muted);
    white-space: nowrap;
}
.configuration-state.enabled {
    color: #167653;
}
.form-switch {
    padding-left: 0;
    min-height: 0;
    margin: 0;
}
.form-switch .form-check-input {
    float: none;
    width: 2.5em;
    height: 1.35em;
    margin: 0;
    cursor: pointer;
}
.form-check-input:checked {
    background-color: var(--config-accent);
    border-color: var(--config-accent);
}
.form-check-input:focus {
    border-color: var(--config-accent);
    box-shadow: 0 0 0 3px #5b4acb20;
}
.configuration-change-label {
    display: inline-block;
    margin-left: 8px;
    padding: 0 5px;
    border-radius: 4px;
    font-size: 9px;
    font-weight: 600;
    color: #8b631f;
    background: #fff5df;
    vertical-align: middle;
}
.configuration-details {
    margin-top: 8px;
}
.configuration-details summary {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--config-muted);
    font-size: 11px;
    cursor: pointer;
    list-style: none;
}
.configuration-details summary::-webkit-details-marker {
    display: none;
}
.configuration-details summary:hover {
    color: var(--config-accent);
}
.configuration-details summary svg {
    transition: transform 0.15s;
}
.configuration-details[open] summary svg {
    transform: rotate(180deg);
}
.configuration-details > p {
    margin: 9px 0 0;
    padding: 11px 13px;
    background: var(--config-soft);
    border-radius: 7px;
    color: var(--config-muted);
    font-size: 12px;
    line-height: 1.8;
}
.configuration-minutes {
    display: flex;
    align-items: center;
    border: 1px solid var(--config-border);
    border-radius: 8px;
    background: var(--config-bg);
    overflow: hidden;
    flex-shrink: 0;
}
.configuration-minutes input {
    width: 73px;
    padding: 9px 6px 9px 12px;
    border: 0;
    background: transparent;
    color: var(--config-text);
    box-shadow: none;
    font-size: 14px;
    font-weight: 650;
}
.configuration-minutes > span {
    padding: 0 11px 0 4px;
    font-size: 11px;
    color: var(--config-muted);
}
.configuration-minutes:focus-within {
    border-color: var(--config-accent);
}
.configuration-presets {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin: 14px 0;
}
.configuration-presets > span {
    font-size: 11px;
    color: var(--config-muted);
    margin-right: 4px;
}
.configuration-presets button {
    padding: 5px 9px;
    border: 1px solid var(--config-border);
    border-radius: 6px;
    background: var(--config-bg);
    color: var(--config-muted);
    font-size: 11px;
    transition:
        color 0.15s,
        background 0.15s;
}
.configuration-presets button.selected {
    color: var(--config-accent);
    background: var(--config-accent-bg);
    border-color: #cfc5f3;
}
.configuration-preview {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 11px 13px;
    border: 1px solid var(--config-border);
    background: var(--config-soft);
    border-radius: 8px;
    color: var(--config-accent);
}
.configuration-preview > svg {
    flex-shrink: 0;
}
.configuration-preview p {
    margin: 0;
    color: var(--config-muted);
    font-size: 12px;
    line-height: 1.8;
}
.configuration-preview strong {
    color: var(--config-text);
}
.configuration-preview-arrow {
    padding: 0 9px;
}
.configuration-mobile-body {
    padding: 20px 22px;
}
.configuration-scope {
    padding: 0;
    margin: 0;
    border: 0;
}
.configuration-scope legend {
    float: none;
    width: auto;
    margin-bottom: 10px;
}
.configuration-scope-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.configuration-scope-option {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 13px;
    border: 1px solid var(--config-border);
    border-radius: 9px;
    cursor: pointer;
}
.configuration-scope-option.selected {
    border-color: #cfc5f3;
    background: var(--config-accent-bg);
}
.configuration-scope-option input {
    flex-shrink: 0;
    width: 15px;
    height: 15px;
    margin-top: 2px;
    accent-color: var(--config-accent);
}
.configuration-scope-option strong {
    display: block;
    font-size: 12px;
    font-weight: 600;
}
.configuration-scope-option small {
    display: block;
    margin-top: 4px;
    color: var(--config-muted);
    font-size: 11px;
    line-height: 1.6;
}
.configuration-approval-type {
    margin-top: 18px;
}
.configuration-approval-type select {
    margin-top: 7px;
    max-width: 310px;
    font-size: 13px;
    background-color: var(--config-bg);
    color: var(--config-text);
    border-color: var(--config-border);
}
.configuration-roles {
    margin-top: 22px;
    padding-top: 20px;
    border-top: 1px solid var(--config-border);
}
.configuration-selected-count {
    flex-shrink: 0;
    padding: 5px 8px;
    color: var(--config-accent);
    background: var(--config-accent-bg);
    border-radius: 6px;
    font-size: 10px;
    font-weight: 600;
}
.configuration-role-search {
    width: 100%;
    margin-top: 12px;
    padding: 8px 11px;
}
.configuration-role-list {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    max-height: 184px;
    overflow-y: auto;
    margin: 10px 0 0;
    padding: 0;
    border: 0;
}
.configuration-role-option {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 11px;
    border: 1px solid var(--config-border);
    border-radius: 7px;
    font-size: 12px;
    overflow-wrap: anywhere;
    cursor: pointer;
}
.configuration-role-option.selected {
    background: var(--config-accent-bg);
    border-color: #cfc5f3;
}
.configuration-role-option input {
    flex-shrink: 0;
    margin: 0;
    width: 15px;
    height: 15px;
}
.configuration-role-empty {
    grid-column: 1 / -1;
    margin: 0;
    padding: 15px;
    color: var(--config-muted);
    text-align: center;
    font-size: 12px;
    background: var(--config-soft);
    border-radius: 7px;
}
.configuration-mobile-note {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    margin-top: 19px;
    padding: 12px;
    background: var(--config-soft);
    border-radius: 8px;
    color: var(--config-muted);
}
.configuration-mobile-note > svg {
    flex-shrink: 0;
    margin-top: 2px;
}
.configuration-mobile-note p {
    margin: 0;
    font-size: 11px;
    line-height: 1.8;
}
.configuration-field-error {
    margin: 8px 0 0;
    font-size: 12px;
    color: #c33d50;
    line-height: 1.6;
}
.configuration-notice,
.configuration-error-summary {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 15px;
    padding: 11px 14px;
    background: var(--config-accent-bg);
    border-radius: 8px;
    color: var(--config-accent);
    font-size: 12px;
    line-height: 1.7;
}
.configuration-notice > svg {
    flex-shrink: 0;
}
.configuration-error-summary {
    background: #fff0f1;
    color: #b72e43;
}
.configuration-empty {
    display: grid;
    justify-items: center;
    text-align: center;
    padding: 50px 20px;
    border: 1px dashed var(--config-border);
    border-radius: 12px;
    background: var(--config-bg);
    color: var(--config-muted);
}
.configuration-empty h2 {
    margin-top: 14px;
    font-size: 15px;
}
.configuration-empty p {
    margin: 5px 0 14px;
    font-size: 12px;
    line-height: 1.7;
}
.configuration-text-button {
    border: 0;
    background: transparent;
    color: var(--config-accent);
    font-size: 12px;
    font-weight: 600;
}
.configuration-actions {
    position: sticky;
    bottom: 16px;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 14px 18px;
    margin-top: 20px;
    border: 1px solid var(--config-border);
    border-radius: 12px;
    background: var(--config-bg);
    box-shadow: 0 6px 28px #27334d0c;
}
.configuration-save-status {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.configuration-save-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 35px;
    height: 35px;
    border-radius: 9px;
    background: #eaf6f1;
    color: #197b59;
}
.configuration-save-icon.pending {
    color: var(--config-accent);
    background: var(--config-accent-bg);
}
.configuration-save-status strong {
    display: block;
    font-size: 12px;
    font-weight: 600;
}
.configuration-save-status small {
    display: block;
    margin-top: 3px;
    color: var(--config-muted);
    font-size: 11px;
    line-height: 1.5;
}
.configuration-save-buttons {
    display: flex;
    gap: 9px;
    flex-shrink: 0;
}
.configuration-reset,
.configuration-save {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 8px;
    padding: 10px 13px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.5;
    transition:
        background 0.15s,
        opacity 0.15s;
}
.configuration-reset {
    background: var(--config-bg);
    border: 1px solid var(--config-border);
    color: var(--config-muted);
}
.configuration-reset:hover:enabled {
    background: var(--config-soft);
    color: var(--config-text);
}
.configuration-save {
    border: 1px solid var(--config-accent);
    background: var(--config-accent);
    color: #fff;
}
.configuration-save:hover:enabled {
    filter: brightness(1.08);
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
.configuration-readonly-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--config-muted);
    font-size: 12px;
}
.configuration-page button:focus-visible,
.configuration-page summary:focus-visible {
    outline: 2px solid var(--config-accent);
    outline-offset: 3px;
}
:global(.dark) .configuration-page {
    --config-bg: #172438;
    --config-soft: #1d2c43;
    --config-text: #e2e9f5;
    --config-muted: #a1aec4;
    --config-border: #304057;
    --config-accent: #b0a2ff;
    --config-accent-bg: #2e2b4b;
}
:global(.dark) .configuration-save {
    background: #6956d4;
    border-color: #6956d4;
}
:global(.dark) .configuration-state.enabled {
    color: #75d2ab;
}
@media (min-width: 721px) and (max-width: 1050px) {
    .configuration-workspace {
        grid-template-columns: 190px minmax(0, 1fr);
        gap: 14px;
    }
    .configuration-category {
        gap: 7px;
        padding: 12px 8px;
    }
    .configuration-category-copy small {
        font-size: 9px;
    }
    .configuration-overview {
        display: none;
    }
    .configuration-switch-control {
        flex-direction: column-reverse;
        align-items: flex-end;
        gap: 5px;
    }
    .configuration-panel-count {
        display: none;
    }
}
@media (max-width: 720px) {
    .configuration-heading {
        flex-wrap: wrap;
        margin: 17px 0;
        gap: 11px;
    }
    h1 {
        font-size: 20px;
    }
    .configuration-heading p {
        font-size: 12px;
    }
    .configuration-overview {
        margin-left: 57px;
        font-size: 11px;
    }
    .configuration-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        margin-bottom: 14px;
    }
    .configuration-search {
        width: 100%;
    }
    .configuration-toolbar > span {
        font-size: 11px;
    }
    .configuration-workspace {
        grid-template-columns: minmax(0, 1fr);
        gap: 15px;
    }
    .configuration-categories {
        position: static;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 6px;
    }
    .configuration-nav-label,
    .configuration-nav-note {
        display: none;
    }
    .configuration-category {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        align-content: center;
        gap: 5px;
        padding: 10px 5px;
        text-align: center;
        border-color: var(--config-border);
        background: var(--config-bg);
        min-height: 70px;
    }
    .configuration-category.active {
        border-color: #cfc5f3;
        background: var(--config-accent-bg);
    }
    .configuration-category-copy {
        flex-basis: 100%;
        order: 1;
    }
    .configuration-category-copy strong {
        font-size: 11px;
    }
    .configuration-category-copy small {
        display: none;
    }
    .configuration-category-count {
        font-size: 9px;
        background: transparent;
        padding: 0 2px;
    }
    .configuration-panel-heading {
        padding: 16px;
        gap: 10px;
    }
    .configuration-panel-heading h2 {
        font-size: 15px;
    }
    .configuration-panel-count {
        display: none;
    }
    .configuration-policy,
    .configuration-tolerance {
        padding: 16px 14px;
    }
    .configuration-policy-main {
        gap: 12px;
    }
    .configuration-switch-control {
        flex-direction: column-reverse;
        align-items: flex-end;
        gap: 6px;
    }
    .configuration-state {
        font-size: 9px;
    }
    .configuration-policy label,
    .configuration-field-heading label {
        font-size: 12px;
    }
    .configuration-policy-copy > p,
    .configuration-field-heading > div > p {
        font-size: 11px;
    }
    .configuration-field-heading {
        gap: 10px;
    }
    .configuration-minutes input {
        width: 61px;
        padding-left: 8px;
        font-size: 13px;
    }
    .configuration-minutes > span {
        padding-right: 8px;
    }
    .configuration-presets {
        gap: 5px;
    }
    .configuration-presets > span {
        flex-basis: 100%;
        margin-bottom: 2px;
    }
    .configuration-preview {
        align-items: flex-start;
    }
    .configuration-preview > svg {
        margin-top: 3px;
    }
    .configuration-mobile-body {
        padding: 16px;
    }
    .configuration-scope-options {
        grid-template-columns: 1fr;
        gap: 7px;
    }
    .configuration-scope-option {
        padding: 11px;
    }
    .configuration-role-list {
        grid-template-columns: minmax(0, 1fr);
    }
    .configuration-actions {
        bottom: 9px;
        margin-top: 14px;
        flex-wrap: wrap;
        gap: 12px;
        padding: 12px;
    }
    .configuration-save-status {
        flex: 1;
    }
    .configuration-save-status small {
        font-size: 10px;
    }
    .configuration-save-buttons {
        width: 100%;
    }
    .configuration-reset {
        flex: 1;
        padding: 10px 8px;
        font-size: 11px;
    }
    .configuration-save {
        flex: 1.2;
        padding: 10px 9px;
        font-size: 11px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .configuration-page *,
    .configuration-page *::before,
    .configuration-page *::after {
        transition: none !important;
    }
}
</style>
