<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    Archive,
    ArrowDownToLine,
    ArrowRight,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    Clock3,
    ExternalLink,
    FileArchive,
    FileCheck2,
    FileUp,
    HardDrive,
    History,
    Info,
    LoaderCircle,
    Pencil,
    Rocket,
    ShieldCheck,
    Smartphone,
    Trash2,
    X,
} from 'lucide-vue-next';
import { ref } from 'vue';
import FlashMessage from '../components/FlashMessage.vue';
import Pagination from '../components/Pagination.vue';
import SettingNavigation from '../components/SettingNavigation.vue';
import type { Paginated } from '../types';
import ReleaseModal from './ReleaseModal.vue';

interface ReleaseMetadata {
    id: number;
    version_name: string;
    version_code: number;
    min_sdk: number;
    size_bytes: number;
    sha256: string;
    release_notes: string;
    minimum_version_code: number;
    required_at: string | null;
    published_at: string | null;
}

interface Release extends ReleaseMetadata {
    status: 'active' | 'draft' | 'archived';
    uploaded_by: string | null;
    published_by: string | null;
    withdrawn_by: string | null;
    withdrawn_at: string | null;
    created_at: string;
    preview_url: string;
    certificate_sha256: string;
}

const props = defineProps<{
    releases: Paginated<Release>;
    activeRelease: ReleaseMetadata | null;
    maxUploadMb: number;
    can: { manage: boolean; publish: boolean };
}>();

const base = '/absensi/admin-web/setting/app-releases';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengaturan', href: base },
    { title: 'Versi Aplikasi', href: base },
];
const form = useForm({ apk: null as File | null, release_notes: '', minimum_version_code: 1, required_at: '' });
const editing = ref<Release | null>(null);
const formOpen = ref(false);
const selected = ref<Release | null>(null);
const action = ref<'publish' | 'withdraw' | 'delete' | null>(null);
const actionTitles = { publish: 'Terbitkan rilis APK', withdraw: 'Tarik rilis APK', delete: 'Hapus draf APK' };
const tested = ref(false);
const processing = ref(false);
const actionError = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(value)) + ' WIB'
        : '—';
const size = (bytes: number) => `${(bytes / 1048576).toLocaleString('id-ID', { maximumFractionDigits: 1 })} MB`;
const statusLabel = (release: Release) =>
    release.status === 'active' ? 'Aktif' : release.status === 'draft' ? 'Draf' : release.withdrawn_at ? 'Ditarik' : 'Arsip';
const selectFile = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    form.clearErrors('apk');
    form.apk = null;
    if (file && !file.name.toLowerCase().endsWith('.apk')) {
        form.setError('apk', 'Pilih file APK Android dengan ekstensi .apk.');
    } else if (file && file.size > props.maxUploadMb * 1048576) {
        form.setError('apk', `Ukuran APK melebihi batas ${props.maxUploadMb} MB.`);
    } else {
        form.apk = file;
    }
    if (!form.apk) input.value = '';
};
const removeFile = () => {
    form.apk = null;
    form.clearErrors('apk');
    if (fileInput.value) fileInput.value.value = '';
    fileInput.value?.focus();
};
const localDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('sv-SE', {
              timeZone: 'Asia/Jakarta',
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          })
              .format(new Date(value))
              .replace(' ', 'T')
        : '';
const openForm = (release: Release | null = null) => {
    editing.value = release;
    form.reset();
    form.clearErrors();
    if (release) {
        form.release_notes = release.release_notes;
        form.minimum_version_code = release.minimum_version_code;
        form.required_at = localDate(release.required_at);
    }
    formOpen.value = true;
};
const submit = () => {
    if (form.processing || (!editing.value && !form.apk)) return;
    form.transform((data) => ({
        ...(editing.value ? {} : { apk: data.apk }),
        release_notes: data.release_notes,
        minimum_version_code: data.minimum_version_code,
        required_at: data.required_at ? `${data.required_at}:00+07:00` : null,
    }));
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
            form.reset();
        },
    };
    if (editing.value) form.put(`${base}/${editing.value.id}`, options);
    else form.post(base, { ...options, forceFormData: true });
};
const confirm = (release: Release, next: 'publish' | 'withdraw' | 'delete') => {
    selected.value = release;
    action.value = next;
    tested.value = false;
    actionError.value = '';
};
const execute = () => {
    if (!selected.value || !action.value || processing.value) return;
    if (action.value === 'publish' && !tested.value) return;
    actionError.value = '';
    processing.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            action.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
        onError: (errors: Record<string, string>) => {
            actionError.value = Object.values(errors)[0] || 'Tindakan belum berhasil. Silakan coba kembali.';
        },
    };
    if (action.value === 'delete') router.delete(`${base}/${selected.value.id}`, options);
    else router.post(`${base}/${selected.value.id}/${action.value}`, {}, options);
};
</script>

<template>
    <Head title="Versi Aplikasi" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <FlashMessage />
        <div class="release-page">
            <SettingNavigation />

            <section class="release-hero" aria-labelledby="release-page-title">
                <div class="release-hero-copy">
                    <span class="release-eyebrow"><Smartphone :size="14" /> DISTRIBUSI APLIKASI</span>
                    <h1 id="release-page-title">Versi aplikasi.<br /><span>Selangkah lebih baik.</span></h1>
                    <p>Hadirkan pembaruan terbaik untuk pegawai. Kelola APK, uji setiap perubahan, dan terbitkan saat semuanya siap.</p>
                    <div class="release-hero-actions">
                        <button v-if="can.manage" type="button" class="release-button release-button-primary" @click="openForm()">
                            <FileUp :size="17" /> Unggah APK baru <ArrowRight :size="16" />
                        </button>
                        <a href="/" target="_blank" rel="noopener" class="release-button release-button-hero">
                            Halaman unduhan <ExternalLink :size="15" /><span class="visually-hidden"> (buka tab baru)</span>
                        </a>
                    </div>
                </div>
                <div class="release-app-card">
                    <div class="release-app-identity">
                        <span class="release-app-icon"><Smartphone :size="30" :stroke-width="1.5" /></span>
                        <div>
                            <span class="release-kicker">ANDROID APP</span><strong>ARSY Presensi</strong><small>Aplikasi presensi pegawai</small>
                        </div>
                    </div>
                    <div class="release-app-stats">
                        <div>
                            <span>Total rilis</span><strong>{{ releases.total.toLocaleString('id-ID') }} <small>versi</small></strong>
                        </div>
                        <div>
                            <span>Batas unggah</span><strong>{{ maxUploadMb }} <small>MB / APK</small></strong>
                        </div>
                    </div>
                    <div class="release-app-footnote"><ShieldCheck :size="14" /> Versi dan tanda tangan diverifikasi saat unggah</div>
                </div>
            </section>

            <div class="release-overview">
                <section class="release-panel release-active" aria-labelledby="active-release-title">
                    <div class="release-section-heading">
                        <span class="release-section-icon"><Rocket :size="19" /></span>
                        <div>
                            <span class="release-kicker">TERSEDIA UNTUK PEGAWAI</span>
                            <h2 id="active-release-title">Rilis aktif</h2>
                        </div>
                        <span v-if="activeRelease" class="release-status active"><span></span> Aktif</span>
                        <span v-else class="release-status archived">Belum diterbitkan</span>
                    </div>
                    <template v-if="activeRelease">
                        <div class="release-active-version">
                            <h3><span>v</span>{{ activeRelease.version_name }}</h3>
                            <span class="release-build">Build {{ activeRelease.version_code }}</span>
                        </div>
                        <div class="release-active-meta">
                            <span><HardDrive :size="14" /> {{ size(activeRelease.size_bytes) }}</span>
                            <span><CalendarDays :size="14" /> {{ formatDate(activeRelease.published_at) }}</span>
                        </div>
                        <p class="release-active-notes">{{ activeRelease.release_notes }}</p>
                        <div class="release-policy" :class="{ mandatory: activeRelease.minimum_version_code > 1 }">
                            <ShieldCheck :size="18" />
                            <div>
                                <strong>{{ activeRelease.minimum_version_code > 1 ? 'Pembaruan wajib' : 'Pembaruan opsional' }}</strong>
                                <p v-if="activeRelease.minimum_version_code > 1">
                                    Untuk build di bawah {{ activeRelease.minimum_version_code }}. Berlaku
                                    {{ activeRelease.required_at ? formatDate(activeRelease.required_at) : 'segera setelah diterbitkan' }}.
                                </p>
                                <p v-else>Pegawai dapat memperbarui aplikasi saat mereka siap.</p>
                            </div>
                        </div>
                    </template>
                    <div v-else class="release-active-empty">
                        <span><Smartphone :size="28" :stroke-width="1.5" /></span>
                        <h3>{{ releases.total ? 'Belum ada rilis aktif' : 'Rilis pertama dimulai di sini' }}</h3>
                        <p>Belum ada rilis aktif. APK yang diterbitkan akan tampil di sini dan tersedia untuk pegawai.</p>
                        <button v-if="can.manage" type="button" class="release-text-button" @click="openForm()">
                            {{ releases.total ? 'Unggah APK baru' : 'Siapkan APK pertama' }} <ArrowRight :size="15" />
                        </button>
                    </div>
                </section>

                <aside class="release-panel release-guide" aria-labelledby="release-guide-title">
                    <span class="release-kicker">DARI DRAF KE PERANGKAT</span>
                    <h2 id="release-guide-title">Tiga langkah, satu pembaruan.</h2>
                    <p class="release-guide-intro">Pastikan setiap versi siap digunakan sebelum dibagikan.</p>
                    <ol class="release-steps">
                        <li>
                            <span><FileUp :size="17" /></span>
                            <div>
                                <strong>01 <b>Unggah & verifikasi</b></strong>
                                <p>APK diperiksa dan disimpan sebagai draf.</p>
                            </div>
                        </li>
                        <li>
                            <span><FileCheck2 :size="17" /></span>
                            <div>
                                <strong>02 <b>Uji pada perangkat</b></strong>
                                <p>Periksa login, kamera, GPS, dan notifikasi.</p>
                            </div>
                        </li>
                        <li>
                            <span><Rocket :size="17" /></span>
                            <div>
                                <strong>03 <b>Terbitkan untuk pegawai</b></strong>
                                <p>Rilis tersedia di halaman unduhan dan pembaruan.</p>
                            </div>
                        </li>
                    </ol>
                    <div class="release-guide-note"><Info :size="15" /><span>Draf hanya dapat diunduh oleh admin yang memiliki akses.</span></div>
                </aside>
            </div>

            <section class="release-panel release-history" aria-labelledby="release-history-title">
                <header class="release-history-header">
                    <div class="release-section-heading">
                        <span class="release-section-icon"><History :size="19" /></span>
                        <div>
                            <h2 id="release-history-title">Riwayat rilis</h2>
                            <p>Setiap versi, catatan pembaruan, dan aktivitasnya.</p>
                        </div>
                    </div>
                    <span class="release-count">{{ releases.total.toLocaleString('id-ID') }} rilis</span>
                </header>
                <div v-if="!releases.data.length" class="release-empty">
                    <span class="release-empty-icon"><FileArchive :size="32" :stroke-width="1.4" /></span>
                    <h3>Belum ada cerita pembaruan</h3>
                    <p>Unggah APK pertama untuk mulai menyusun riwayat versi aplikasi.</p>
                    <button v-if="can.manage" type="button" class="release-button release-button-primary" @click="openForm()">
                        <FileUp :size="16" /> Unggah APK pertama
                    </button>
                </div>
                <div v-else class="release-list">
                    <article
                        v-for="release in releases.data"
                        :key="release.id"
                        class="release-item"
                        :class="{ 'is-active': release.status === 'active' }"
                    >
                        <div class="release-item-main">
                            <span class="release-version-icon" :class="release.status"
                                ><Archive v-if="release.status === 'archived'" :size="21" /><FileArchive v-else :size="21"
                            /></span>
                            <div class="release-item-copy">
                                <div class="release-item-title">
                                    <h3>Versi {{ release.version_name }}</h3>
                                    <span class="release-status" :class="release.status"
                                        ><span v-if="release.status === 'active'"></span>{{ statusLabel(release) }}</span
                                    >
                                </div>
                                <div class="release-item-meta">
                                    <span>Build {{ release.version_code }}</span
                                    ><span>{{ size(release.size_bytes) }}</span
                                    ><span>{{ release.minimum_version_code > 1 ? 'Pembaruan wajib' : 'Pembaruan opsional' }}</span>
                                </div>
                                <p class="release-item-excerpt">{{ release.release_notes }}</p>
                            </div>
                            <div class="release-item-date">
                                <span>{{ release.published_at ? 'Diterbitkan' : 'Diunggah' }}</span
                                ><strong>{{ formatDate(release.published_at || release.created_at) }}</strong>
                            </div>
                        </div>
                        <div class="release-item-toolbar">
                            <span class="release-item-author"
                                ><Clock3 :size="13" /> Diunggah oleh {{ release.uploaded_by || 'Pengguna dihapus' }}</span
                            >
                            <div class="release-row-actions">
                                <a
                                    :href="release.preview_url"
                                    class="release-button release-button-small release-button-secondary"
                                    :aria-label="`Unduh APK versi ${release.version_name} untuk pengujian`"
                                    ><ArrowDownToLine :size="14" /> Unduh APK</a
                                >
                                <button
                                    v-if="can.manage && (release.status === 'draft' || (release.status === 'active' && can.publish))"
                                    type="button"
                                    class="release-button release-button-small release-button-secondary"
                                    :aria-label="`Ubah pengaturan versi ${release.version_name}`"
                                    @click="openForm(release)"
                                >
                                    <Pencil :size="13" /> Ubah
                                </button>
                                <button
                                    v-if="can.publish && release.status === 'draft'"
                                    type="button"
                                    class="release-button release-button-small release-button-primary"
                                    @click="confirm(release, 'publish')"
                                >
                                    <Rocket :size="14" /> Terbitkan
                                </button>
                                <button
                                    v-if="can.publish && release.status === 'active'"
                                    type="button"
                                    class="release-button release-button-small release-button-danger-quiet"
                                    @click="confirm(release, 'withdraw')"
                                >
                                    Tarik rilis
                                </button>
                                <button
                                    v-if="can.manage && release.status === 'draft'"
                                    type="button"
                                    class="release-button release-button-small release-button-danger-quiet release-icon-button"
                                    :aria-label="`Hapus draf versi ${release.version_name}`"
                                    title="Hapus draf"
                                    @click="confirm(release, 'delete')"
                                >
                                    <Trash2 :size="15" />
                                </button>
                            </div>
                        </div>
                        <details class="release-details">
                            <summary>Lihat catatan & detail rilis <ChevronDown :size="14" /></summary>
                            <div class="release-detail-grid">
                                <div class="release-detail-notes">
                                    <h4>Catatan pembaruan</h4>
                                    <p>{{ release.release_notes }}</p>
                                </div>
                                <div class="release-detail-data">
                                    <h4>Informasi APK</h4>
                                    <dl>
                                        <div>
                                            <dt>Minimum Android</dt>
                                            <dd>API {{ release.min_sdk }}</dd>
                                        </div>
                                        <div>
                                            <dt>Kebijakan pembaruan</dt>
                                            <dd>
                                                {{
                                                    release.minimum_version_code > 1
                                                        ? `Wajib untuk build di bawah ${release.minimum_version_code}`
                                                        : 'Opsional'
                                                }}
                                            </dd>
                                        </div>
                                        <div v-if="release.minimum_version_code > 1">
                                            <dt>Mulai wajib</dt>
                                            <dd>{{ release.required_at ? formatDate(release.required_at) : 'Segera saat diterbitkan' }}</dd>
                                        </div>
                                    </dl>
                                    <h4 class="release-detail-subheading">Jejak aktivitas</h4>
                                    <ul class="release-audit">
                                        <li>
                                            <span>Diunggah</span><strong>{{ release.uploaded_by || 'Pengguna dihapus' }}</strong
                                            ><small>{{ formatDate(release.created_at) }}</small>
                                        </li>
                                        <li v-if="release.published_at">
                                            <span>Diterbitkan</span><strong>{{ release.published_by || 'Pengguna dihapus' }}</strong
                                            ><small>{{ formatDate(release.published_at) }}</small>
                                        </li>
                                        <li v-if="release.withdrawn_at">
                                            <span>Ditarik</span><strong>{{ release.withdrawn_by || 'Pengguna dihapus' }}</strong
                                            ><small>{{ formatDate(release.withdrawn_at) }}</small>
                                        </li>
                                    </ul>
                                </div>
                                <div class="release-fingerprints">
                                    <div>
                                        <span>SHA-256 APK</span><code>{{ release.sha256 }}</code>
                                    </div>
                                    <div>
                                        <span>SHA-256 sertifikat</span><code>{{ release.certificate_sha256 }}</code>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </article>
                </div>
                <Pagination :links="releases.links" :from="releases.from" :to="releases.to" :total="releases.total" />
            </section>
            <p class="release-page-footnote"><ShieldCheck :size="14" /> APK diverifikasi sebelum diterbitkan. Seluruh aktivitas rilis tercatat.</p>
        </div>

        <ReleaseModal
            :open="formOpen"
            :busy="form.processing"
            :title="editing ? `Pengaturan versi ${editing.version_name}` : 'Siapkan pembaruan baru'"
            :description="editing ? 'Kelola catatan dan kebijakan pembaruan versi ini.' : 'Unggah APK, tambahkan catatan, lalu simpan sebagai draf.'"
            @close="!form.processing && (formOpen = false)"
        >
            <form class="release-form" @submit.prevent="submit">
                <div class="release-form-body">
                    <div class="release-form-preview">
                        <span class="release-section-icon"><Pencil v-if="editing" :size="20" /><FileUp v-else :size="20" /></span>
                        <div>
                            <strong>{{ editing ? `Versi ${editing.version_name}` : 'Rilis dimulai sebagai draf' }}</strong>
                            <p>
                                {{
                                    editing
                                        ? `Build ${editing.version_code} · ${size(editing.size_bytes)}`
                                        : 'Uji APK pada perangkat sebelum menerbitkannya.'
                                }}
                            </p>
                        </div>
                        <span class="release-status" :class="editing?.status || 'draft'">{{ editing ? statusLabel(editing) : 'Draf baru' }}</span>
                    </div>
                    <section v-if="!editing" class="release-form-section" aria-labelledby="release-file-heading">
                        <div class="release-form-section-title">
                            <span>01</span>
                            <h3 id="release-file-heading">File aplikasi</h3>
                            <span class="release-field-required">Wajib</span>
                        </div>
                        <div
                            class="release-upload-zone"
                            :class="{ 'has-file': form.apk, 'has-error': form.errors.apk, 'is-disabled': form.processing }"
                        >
                            <input
                                id="release-apk"
                                ref="fileInput"
                                type="file"
                                accept=".apk"
                                required
                                :disabled="form.processing"
                                aria-label="Pilih atau seret file APK Android"
                                aria-describedby="release-apk-help release-apk-error"
                                :aria-invalid="Boolean(form.errors.apk)"
                                @change="selectFile"
                            />
                            <span class="release-upload-icon"><FileCheck2 v-if="form.apk" :size="25" /><FileUp v-else :size="25" /></span>
                            <strong>{{ form.apk ? form.apk.name : 'Pilih file APK atau seret ke sini' }}</strong>
                            <span>{{
                                form.apk ? `${size(form.apk.size)} · Klik untuk mengganti file` : `Format .apk, maksimal ${maxUploadMb} MB`
                            }}</span>
                        </div>
                        <div v-if="form.apk" class="release-file-selected">
                            <span><CheckCircle2 :size="14" /> File siap diunggah</span
                            ><button type="button" :disabled="form.processing" @click="removeFile"><X :size="13" /> Hapus file</button>
                        </div>
                        <p id="release-apk-help" class="release-field-help">Versi, build, dan sertifikat dibaca otomatis dari APK.</p>
                        <p id="release-apk-error" class="release-field-error" role="alert">{{ form.errors.apk }}</p>
                    </section>
                    <section class="release-form-section" aria-labelledby="release-notes-heading">
                        <div class="release-form-section-title">
                            <span>{{ editing ? '01' : '02' }}</span>
                            <h3 id="release-notes-heading">Apa yang baru?</h3>
                            <span class="release-field-required">Wajib</span>
                        </div>
                        <label for="release-notes" class="visually-hidden">Catatan pembaruan</label>
                        <textarea
                            id="release-notes"
                            v-model="form.release_notes"
                            class="release-input"
                            rows="4"
                            maxlength="10000"
                            required
                            :disabled="form.processing"
                            :aria-invalid="Boolean(form.errors.release_notes)"
                            aria-describedby="release-notes-error"
                            placeholder="Contoh:&#10;• Peningkatan akurasi lokasi presensi&#10;• Perbaikan notifikasi dan stabilitas aplikasi"
                        />
                        <div class="release-field-caption">
                            <span>Catatan ini akan ditampilkan kepada pegawai.</span
                            ><span>{{ form.release_notes.length.toLocaleString('id-ID') }} / 10.000</span>
                        </div>
                        <p id="release-notes-error" class="release-field-error" role="alert">{{ form.errors.release_notes }}</p>
                    </section>
                    <section class="release-form-section" aria-labelledby="release-policy-heading">
                        <div class="release-form-section-title">
                            <span>{{ editing ? '02' : '03' }}</span>
                            <h3 id="release-policy-heading">Kebijakan pembaruan</h3>
                        </div>
                        <div class="release-policy-fields">
                            <div>
                                <label for="release-minimum" class="release-field-label">Minimum build yang didukung</label
                                ><input
                                    id="release-minimum"
                                    v-model.number="form.minimum_version_code"
                                    type="number"
                                    min="1"
                                    :max="editing?.version_code ?? 2100000000"
                                    class="release-input"
                                    required
                                    :disabled="form.processing"
                                    :aria-invalid="Boolean(form.errors.minimum_version_code)"
                                    aria-describedby="release-minimum-help release-minimum-error"
                                />
                                <p id="release-minimum-help" class="release-field-help">
                                    Isi 1 untuk opsional. Build di bawah nilai yang lebih tinggi wajib diperbarui.
                                </p>
                                <p id="release-minimum-error" class="release-field-error" role="alert">{{ form.errors.minimum_version_code }}</p>
                            </div>
                            <div v-if="form.minimum_version_code > 1">
                                <label for="release-required" class="release-field-label">Mulai wajib diperbarui <span>WIB</span></label
                                ><input
                                    id="release-required"
                                    v-model="form.required_at"
                                    type="datetime-local"
                                    class="release-input"
                                    :disabled="form.processing"
                                    :aria-invalid="Boolean(form.errors.required_at)"
                                    aria-describedby="release-required-help release-required-error"
                                />
                                <p id="release-required-help" class="release-field-help">Kosongkan untuk berlaku segera saat diterbitkan.</p>
                                <p id="release-required-error" class="release-field-error" role="alert">{{ form.errors.required_at }}</p>
                            </div>
                        </div>
                        <div class="release-form-notice" :class="{ warning: editing?.status === 'active' }">
                            <AlertCircle v-if="editing?.status === 'active'" :size="17" /><Info v-else :size="17" />
                            <p>
                                {{
                                    editing?.status === 'active'
                                        ? 'Perubahan kebijakan ini langsung berlaku untuk rilis aktif.'
                                        : form.minimum_version_code > 1
                                          ? 'Pegawai dengan build di bawah batas minimum akan diminta memperbarui aplikasi setelah masa tenggang.'
                                          : 'Pembaruan bersifat opsional. Pegawai tetap dapat menggunakan versi yang terpasang.'
                                }}
                            </p>
                        </div>
                    </section>
                    <div v-if="form.progress" class="release-upload-progress" role="status" aria-live="polite">
                        <div>
                            <span>Mengunggah APK</span><strong>{{ form.progress.percentage ?? 0 }}%</strong>
                        </div>
                        <progress :value="form.progress.percentage ?? 0" max="100" aria-label="Progres unggah APK" />
                    </div>
                </div>
                <footer class="release-form-footer">
                    <span><ShieldCheck :size="14" /> {{ editing ? 'Pengaturan rilis' : 'Verifikasi sebelum disimpan' }}</span>
                    <div>
                        <button type="button" class="release-button release-button-secondary" :disabled="form.processing" @click="formOpen = false">
                            Batal</button
                        ><button type="submit" class="release-button release-button-primary" :disabled="form.processing || (!editing && !form.apk)">
                            <LoaderCircle v-if="form.processing" :size="16" class="release-spinner" /><Check v-else :size="16" />{{
                                form.processing ? 'Memproses…' : editing ? 'Simpan pengaturan' : 'Verifikasi & simpan draf'
                            }}
                        </button>
                    </div>
                </footer>
            </form>
        </ReleaseModal>

        <ReleaseModal :open="action !== null" :busy="processing" :title="action ? actionTitles[action] : ''" @close="!processing && (action = null)">
            <div v-if="selected" class="release-confirm">
                <div class="release-confirm-body">
                    <span class="release-confirm-icon" :class="{ danger: action !== 'publish' }"
                        ><Rocket v-if="action === 'publish'" :size="27" /><Trash2 v-else-if="action === 'delete'" :size="27" /><Archive
                            v-else
                            :size="27"
                    /></span>
                    <span class="release-kicker">ARSY PRESENSI · ANDROID</span>
                    <h3>Versi {{ selected.version_name }}</h3>
                    <p class="release-confirm-build">Build {{ selected.version_code }} · {{ size(selected.size_bytes) }}</p>
                    <p v-if="action === 'publish'" class="release-confirm-description">
                        APK ini akan tersedia di halaman unduhan dan ditawarkan sebagai pembaruan kepada pegawai.
                    </p>
                    <p v-else-if="action === 'delete'" class="release-confirm-description">
                        Draf dan file APK ini akan dihapus. Anda dapat mengunggah APK pengganti dengan nomor build yang sama selama belum pernah
                        diterbitkan.
                    </p>
                    <p v-else class="release-confirm-description">
                        Unduhan dan pemberitahuan pembaruan akan dihentikan. Aplikasi yang terpasang tetap ada di HP pegawai. Untuk memperbaikinya,
                        terbitkan APK dengan build lebih tinggi.
                    </p>
                    <label v-if="action === 'publish'" class="release-tested" :class="{ checked: tested }"
                        ><input v-model="tested" type="checkbox" :disabled="processing" /><span
                            ><strong>APK sudah diuji pada perangkat</strong
                            ><small>Saya telah memeriksa login, kamera, GPS, dan notifikasi yang digunakan.</small></span
                        ><CheckCircle2 v-if="tested" :size="18"
                    /></label>
                    <p v-if="actionError" class="release-action-error" role="alert"><AlertCircle :size="17" />{{ actionError }}</p>
                </div>
                <footer class="release-form-footer">
                    <div>
                        <button type="button" class="release-button release-button-secondary" :disabled="processing" @click="action = null">
                            Batal</button
                        ><button
                            type="button"
                            class="release-button"
                            :class="action === 'publish' ? 'release-button-primary' : 'release-button-danger'"
                            :disabled="processing || (action === 'publish' && !tested)"
                            @click="execute"
                        >
                            <LoaderCircle v-if="processing" :size="16" class="release-spinner" /><ShieldCheck
                                v-else-if="action === 'publish'"
                                :size="16"
                            />{{
                                processing
                                    ? 'Memproses…'
                                    : action === 'publish'
                                      ? 'Terbitkan sekarang'
                                      : action === 'delete'
                                        ? 'Hapus draf'
                                        : 'Tarik rilis'
                            }}
                        </button>
                    </div>
                </footer>
            </div>
        </ReleaseModal>
    </AppLayout>
</template>

<style scoped src="./app-release.css"></style>
