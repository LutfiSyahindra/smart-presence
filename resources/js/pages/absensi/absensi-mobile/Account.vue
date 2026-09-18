<script setup lang="ts">
import type { SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    CalendarDays,
    Camera,
    Check,
    ChevronRight,
    CircleUserRound,
    FileText,
    FolderOpen,
    GraduationCap,
    Home,
    ImagePlus,
    LockKeyhole,
    LogOut,
    PhoneCall,
    ShieldCheck,
    Sparkles,
    TimerReset,
    TriangleAlert,
    UserRound,
    UsersRound,
    WalletCards,
    X,
} from 'lucide-vue-next';
import { computed, onUnmounted, ref } from 'vue';

type Profile = {
    name: string;
    email: string;
    nik: string | null;
    profession: string | null;
    position: string | null;
    unit: string | null;
    phone: string | null;
    has_employee_profile: boolean;
    photo_url: string | null;
    photo_updated_at: string | null;
};

const props = defineProps<{ profile: Profile }>();
const page = usePage<SharedData>();
const galleryInput = ref<HTMLInputElement>();
const cameraInput = ref<HTMLInputElement>();
const previewUrl = ref<string | null>(null);
const localError = ref('');

const form = useForm<{ photo: File | null }>({ photo: null });

const initials = computed(() =>
    props.profile.name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase(),
);

const roleLabel = computed(() => props.profile.position || props.profile.profession || 'Pegawai RSARSY');
const placementLabel = computed(() => props.profile.unit || 'RS Abdurrahman Syamsuri');
const photoSource = computed(() => previewUrl.value || props.profile.photo_url);
const hasSavedPhoto = computed(() => Boolean(props.profile.photo_url));
const fileSize = computed(() => (form.photo ? `${(form.photo.size / 1024 / 1024).toFixed(1)} MB` : ''));
const updatedLabel = computed(() => {
    if (!props.profile.photo_updated_at) return null;

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
        .format(new Date(props.profile.photo_updated_at))
        .replace('.', ':');
});

const infoMenus = [
    { label: 'Info personal', caption: 'Identitas dan kontak utama', icon: UserRound },
    { label: 'Info pekerjaan', caption: 'Unit, jabatan, dan status', icon: BriefcaseBusiness },
    { label: 'Kontak darurat', caption: 'Kontak saat keadaan darurat', icon: PhoneCall },
    { label: 'Info keluarga', caption: 'Data anggota keluarga', icon: UsersRound },
    { label: 'Pendidikan & pengalaman', caption: 'Riwayat profesional', icon: GraduationCap },
    { label: 'Info payroll', caption: 'Informasi penggajian', icon: WalletCards },
    { label: 'File saya', caption: 'Dokumen kepegawaian', icon: FolderOpen },
    { label: 'Peringatan', caption: 'Catatan dan pemberitahuan', icon: TriangleAlert },
];

const settingsMenus = [
    { label: 'Ubah kata sandi', caption: 'Keamanan akses akun', icon: LockKeyhole },
    { label: 'Privasi & keamanan', caption: 'Kontrol data pribadi', icon: ShieldCheck },
];

const clearPreview = () => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
    form.photo = null;
    localError.value = '';
    form.clearErrors('photo');
    if (galleryInput.value) galleryInput.value.value = '';
    if (cameraInput.value) cameraInput.value.value = '';
};

const selectPhoto = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) return;

    localError.value = '';
    form.clearErrors('photo');
    form.photo = null;

    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        localError.value = 'Gunakan foto JPG, PNG, atau WebP.';
        input.value = '';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        localError.value = 'Ukuran foto maksimal 5 MB.';
        input.value = '';
        return;
    }

    form.photo = file;
    previewUrl.value = URL.createObjectURL(file);
};

const uploadPhoto = () => {
    if (!form.photo) return;

    form.post(route('absensi.mobile.account.photo.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: clearPreview,
    });
};

onUnmounted(() => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
    <Head title="Akun Saya" />

    <main class="account-workspace">
        <div class="account-shell">
            <section class="account-scroll">
                <header class="account-header">
                    <div>
                        <span><Sparkles /> Ruang personal</span>
                        <h1>Akun saya</h1>
                    </div>
                    <Link :href="route('logout')" method="post" as="button" aria-label="Keluar dari akun">
                        <LogOut />
                    </Link>
                </header>

                <section class="profile-hero" aria-labelledby="profile-name">
                    <span class="hero-orb hero-orb-one" aria-hidden="true"></span>
                    <span class="hero-orb hero-orb-two" aria-hidden="true"></span>
                    <div class="hero-avatar">
                        <img v-if="profile.photo_url" :src="profile.photo_url" :alt="`Foto profil ${profile.name}`" />
                        <span v-else>{{ initials }}</span>
                        <i :class="{ ready: hasSavedPhoto }"><Check v-if="hasSavedPhoto" /><Camera v-else /></i>
                    </div>
                    <div class="hero-copy">
                        <span class="hero-status" :class="{ ready: hasSavedPhoto }">
                            <BadgeCheck v-if="hasSavedPhoto" />
                            <Camera v-else />
                            {{ hasSavedPhoto ? 'Foto siap digunakan' : 'Foto belum tersedia' }}
                        </span>
                        <h2 id="profile-name">{{ profile.name }}</h2>
                        <p>{{ roleLabel }}</p>
                        <small
                            >{{ placementLabel }}<template v-if="profile.nik"> · NIK {{ profile.nik }}</template></small
                        >
                    </div>
                </section>

                <div v-if="page.props.flash.success" class="success-banner" role="status">
                    <span><Check /></span>
                    <p><strong>Berhasil diperbarui</strong>{{ page.props.flash.success }}</p>
                </div>

                <section class="photo-card" aria-labelledby="photo-card-title">
                    <div class="photo-card-heading">
                        <div>
                            <span>Prioritas akun</span>
                            <h2 id="photo-card-title">Foto profil wajah</h2>
                        </div>
                        <span class="secure-pill"><ShieldCheck /> Privat</span>
                    </div>

                    <p class="photo-intro">
                        Foto ini disiapkan sebagai referensi verifikasi wajah saat presensi. Pastikan wajah terlihat jelas dan natural.
                    </p>

                    <div class="photo-editor" :class="{ selected: form.photo }">
                        <div class="photo-preview">
                            <img
                                v-if="photoSource"
                                :src="photoSource"
                                :alt="form.photo ? 'Pratinjau foto profil baru' : `Foto profil ${profile.name}`"
                            />
                            <span v-else>{{ initials }}</span>
                            <i></i>
                        </div>

                        <div class="photo-editor-copy">
                            <template v-if="form.photo">
                                <span class="selected-label"><Check /> Siap diunggah</span>
                                <strong>{{ form.photo.name }}</strong>
                                <small>{{ fileSize }} · Periksa posisi wajah sebelum menyimpan.</small>
                            </template>
                            <template v-else>
                                <strong>{{ hasSavedPhoto ? 'Foto wajah sudah tersimpan' : 'Tambahkan foto terbaik Anda' }}</strong>
                                <small v-if="updatedLabel">Diperbarui {{ updatedLabel }} WIB</small>
                                <small v-else>JPG, PNG, atau WebP · maksimal 5 MB</small>
                            </template>
                        </div>

                        <button v-if="form.photo" type="button" class="clear-photo" aria-label="Batalkan foto terpilih" @click="clearPreview">
                            <X />
                        </button>
                    </div>

                    <p v-if="localError || form.errors.photo" class="photo-error" role="alert">{{ localError || form.errors.photo }}</p>

                    <div v-if="!form.photo" class="photo-picker-actions">
                        <button type="button" class="primary-picker" @click="cameraInput?.click()"><Camera /> Ambil foto</button>
                        <button type="button" class="secondary-picker" @click="galleryInput?.click()"><ImagePlus /> Pilih galeri</button>
                    </div>

                    <div v-else class="photo-submit-actions">
                        <button type="button" class="secondary-picker" :disabled="form.processing" @click="clearPreview">Batal</button>
                        <button type="button" class="save-photo" :disabled="form.processing" @click="uploadPhoto">
                            <span v-if="form.processing" class="loading-ring" aria-hidden="true"></span>
                            <Check v-else />
                            {{ form.processing ? `Mengunggah ${form.progress?.percentage ?? 0}%` : 'Simpan foto' }}
                        </button>
                    </div>

                    <input
                        ref="cameraInput"
                        class="sr-only"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        capture="user"
                        @change="selectPhoto"
                    />
                    <input ref="galleryInput" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectPhoto" />

                    <div class="photo-guides" aria-label="Panduan foto wajah">
                        <span
                            ><i><Check /></i> Satu wajah</span
                        >
                        <span
                            ><i><Check /></i> Cahaya cukup</span
                        >
                        <span
                            ><i><Check /></i> Tanpa filter</span
                        >
                    </div>
                </section>

                <section v-if="!profile.has_employee_profile" class="profile-warning">
                    <TriangleAlert />
                    <p><strong>Profil pegawai belum terhubung</strong>Hubungi admin agar informasi pekerjaan Anda tampil lengkap.</p>
                </section>

                <section class="menu-card" aria-labelledby="personal-menu-title">
                    <div class="menu-heading">
                        <span>Direktori profil</span>
                        <h2 id="personal-menu-title">Informasi saya</h2>
                    </div>
                    <button v-for="item in infoMenus" :key="item.label" type="button" class="menu-row" disabled>
                        <span class="menu-icon"><component :is="item.icon" /></span>
                        <span class="menu-copy"
                            ><strong>{{ item.label }}</strong
                            ><small>{{ item.caption }}</small></span
                        >
                        <span class="coming-soon">Segera</span>
                        <ChevronRight />
                    </button>
                </section>

                <section class="menu-card settings-card" aria-labelledby="settings-menu-title">
                    <div class="menu-heading">
                        <span>Kontrol akun</span>
                        <h2 id="settings-menu-title">Pengaturan</h2>
                    </div>
                    <button v-for="item in settingsMenus" :key="item.label" type="button" class="menu-row" disabled>
                        <span class="menu-icon"><component :is="item.icon" /></span>
                        <span class="menu-copy"
                            ><strong>{{ item.label }}</strong
                            ><small>{{ item.caption }}</small></span
                        >
                        <span class="coming-soon">Segera</span>
                        <ChevronRight />
                    </button>
                    <Link :href="route('logout')" method="post" as="button" class="menu-row logout-row">
                        <span class="menu-icon"><LogOut /></span>
                        <span class="menu-copy"><strong>Keluar dari akun</strong><small>Akhiri sesi di perangkat ini</small></span>
                        <ChevronRight />
                    </Link>
                </section>

                <p class="privacy-note"><ShieldCheck /> Foto wajah tersimpan privat dan hanya digunakan untuk layanan presensi RSARSY.</p>
            </section>

            <nav class="bottom-navigation" aria-label="Navigasi utama">
                <Link :href="route('absensi.mobile.home')" class="bottom-item">
                    <span><Home /></span>
                    <small>Beranda</small>
                </Link>
                <button type="button" class="bottom-item" disabled>
                    <span><CalendarDays /></span>
                    <small>Jadwal</small>
                </button>
                <Link :href="route('absensi.mobile.attendance')" class="bottom-item attendance-main">
                    <span><TimerReset /></span>
                    <small>Presensi</small>
                </Link>
                <button type="button" class="bottom-item" disabled>
                    <span><FileText /></span>
                    <small>Pengajuan</small>
                </button>
                <button type="button" class="bottom-item active" aria-current="page">
                    <span><CircleUserRound /></span>
                    <small>Akun</small>
                </button>
            </nav>
        </div>
    </main>
</template>

<style scoped>
.account-workspace {
    --ink: #173238;
    --muted: #718187;
    --line: #e4ebe8;
    min-height: 100svh;
    color: var(--ink);
    background:
        radial-gradient(circle at 8% 7%, rgba(58, 150, 135, 0.13), transparent 26rem),
        radial-gradient(circle at 91% 88%, rgba(208, 161, 86, 0.13), transparent 29rem), #eaf0ee;
    font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
}

.account-shell {
    width: 100%;
    max-width: 500px;
    height: 100svh;
    min-height: 100svh;
    margin: 0 auto;
    display: flex;
    position: relative;
    flex-direction: column;
    overflow: hidden;
    background: #f7faf8;
    box-shadow: 0 0 80px rgba(16, 48, 51, 0.13);
}

.account-scroll {
    min-height: 0;
    padding: 0 17px 26px;
    flex: 1;
    overflow-y: auto;
    scrollbar-width: none;
}

.account-scroll::-webkit-scrollbar {
    display: none;
}

.account-header {
    padding: max(21px, env(safe-area-inset-top)) 4px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.account-header > div > span,
.photo-card-heading > div > span,
.menu-heading > span {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #9a7740;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}

.account-header > div > span svg {
    width: 11px;
    height: 11px;
}

.account-header h1 {
    margin: 3px 0 0;
    font-size: 22px;
    font-weight: 880;
    letter-spacing: -0.045em;
}

.account-header > button {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border: 1px solid #dde7e3;
    border-radius: 14px;
    color: #5c7073;
    background: rgba(255, 255, 255, 0.82);
    box-shadow: 0 8px 21px rgba(25, 61, 60, 0.07);
}

.account-header > button svg {
    width: 17px;
    height: 17px;
}

.profile-hero {
    min-height: 185px;
    padding: 22px;
    display: flex;
    align-items: center;
    gap: 18px;
    position: relative;
    isolation: isolate;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 28px;
    color: #f4fffb;
    background:
        radial-gradient(circle at 86% 15%, rgba(196, 153, 81, 0.3), transparent 8rem), linear-gradient(145deg, #124f50 0%, #0c3439 62%, #102d34 100%);
    box-shadow: 0 20px 42px rgba(16, 61, 61, 0.2);
}

.hero-orb {
    position: absolute;
    z-index: -1;
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 999px;
}

.hero-orb-one {
    width: 170px;
    height: 170px;
    right: -65px;
    bottom: -90px;
}

.hero-orb-two {
    width: 78px;
    height: 78px;
    top: -34px;
    left: 38%;
}

.hero-avatar {
    width: 96px;
    height: 96px;
    padding: 4px;
    box-sizing: border-box;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    position: relative;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 31px;
    color: #fff;
    background: linear-gradient(145deg, #2b7772, #163d40);
    box-shadow: 0 17px 34px rgba(0, 19, 22, 0.3);
    font-size: 24px;
    font-weight: 900;
    letter-spacing: 0.04em;
}

.hero-avatar::after {
    content: '';
    position: absolute;
    inset: 4px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 26px;
    z-index: 1;
    pointer-events: none;
}

.hero-avatar img {
    width: calc(100% - 8px);
    max-width: none;
    height: calc(100% - 8px);
    display: block;
    position: absolute;
    inset: 4px;
    border-radius: 26px;
    object-fit: cover;
    object-position: center;
    clip-path: inset(0 round 26px);
}

.hero-avatar > i {
    width: 27px;
    height: 27px;
    display: grid;
    place-items: center;
    position: absolute;
    z-index: 2;
    right: -4px;
    bottom: -4px;
    border: 4px solid #11373b;
    border-radius: 999px;
    color: #784e16;
    background: #efc77f;
}

.hero-avatar > i.ready {
    color: #0b655f;
    background: #a7e5ce;
}

.hero-avatar > i svg {
    width: 11px;
    height: 11px;
    stroke-width: 3;
}

.hero-copy {
    min-width: 0;
}

.hero-status {
    width: max-content;
    max-width: 100%;
    padding: 5px 8px;
    display: flex;
    align-items: center;
    gap: 5px;
    border: 1px solid rgba(239, 199, 127, 0.2);
    border-radius: 999px;
    color: #efcd91;
    background: rgba(0, 17, 20, 0.18);
    font-size: 11px;
    font-weight: 800;
}

.hero-status.ready {
    color: #b9ead9;
}

.hero-status svg {
    width: 11px;
    height: 11px;
}

.hero-copy h2 {
    margin: 9px 0 0;
    overflow: hidden;
    font-size: 19px;
    font-weight: 880;
    letter-spacing: -0.035em;
    line-height: 1.15;
    text-overflow: ellipsis;
}

.hero-copy p {
    margin: 5px 0 0;
    color: rgba(232, 248, 243, 0.78);
    font-size: 13px;
    font-weight: 650;
}

.hero-copy small {
    margin-top: 4px;
    display: block;
    color: rgba(217, 236, 232, 0.5);
    font-size: 11px;
    line-height: 1.4;
}

.success-banner,
.profile-warning {
    margin-top: 13px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #cbe8dc;
    border-radius: 17px;
    color: #275e57;
    background: #edf9f4;
}

.success-banner > span {
    width: 29px;
    height: 29px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 10px;
    color: #fff;
    background: #248b7d;
}

.success-banner svg,
.profile-warning > svg {
    width: 15px;
    height: 15px;
}

.success-banner p,
.profile-warning p {
    margin: 0;
    display: flex;
    flex-direction: column;
    color: #54736f;
    font-size: 11px;
    line-height: 1.45;
}

.success-banner strong,
.profile-warning strong {
    color: #24524d;
    font-size: 12px;
}

.photo-card,
.menu-card {
    margin-top: 15px;
    padding: 19px;
    border: 1px solid var(--line);
    border-radius: 25px;
    background: #fff;
    box-shadow: 0 13px 32px rgba(31, 68, 65, 0.065);
}

.photo-card {
    border-color: #dedfd3;
    background: radial-gradient(circle at 100% 0, rgba(209, 169, 101, 0.12), transparent 10rem), linear-gradient(155deg, #ffffff, #fdfdf9);
}

.photo-card-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.photo-card-heading h2,
.menu-heading h2 {
    margin: 4px 0 0;
    color: #1c383d;
    font-size: 17px;
    font-weight: 880;
    letter-spacing: -0.035em;
}

.secure-pill {
    padding: 6px 8px;
    display: flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #d5e9e3;
    border-radius: 999px;
    color: #34766e;
    background: #f0f9f6;
    font-size: 11px;
    font-weight: 800;
}

.secure-pill svg {
    width: 11px;
    height: 11px;
}

.photo-intro {
    margin: 9px 0 0;
    color: #768486;
    font-size: 12px;
    line-height: 1.55;
}

.photo-editor {
    margin-top: 15px;
    padding: 11px;
    display: flex;
    align-items: center;
    gap: 11px;
    position: relative;
    border: 1px dashed #cfdcd7;
    border-radius: 19px;
    background: rgba(246, 250, 248, 0.78);
}

.photo-editor.selected {
    border-color: #76b6aa;
    background: #f0faf6;
}

.photo-preview {
    width: 62px;
    height: 72px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    position: relative;
    overflow: hidden;
    border-radius: 16px;
    color: #fff;
    background: linear-gradient(145deg, #25736d, #173e42);
    box-shadow: 0 8px 17px rgba(22, 70, 69, 0.16);
    font-size: 17px;
    font-weight: 850;
}

.photo-preview img {
    width: 100%;
    max-width: 100%;
    height: 100%;
    display: block;
    border-radius: inherit;
    object-fit: cover;
    object-position: center;
}

.photo-preview i {
    position: absolute;
    inset: 7px;
    border: 1px solid rgba(255, 255, 255, 0.32);
    border-radius: 11px 11px 14px 14px;
    pointer-events: none;
}

.photo-editor-copy {
    min-width: 0;
    padding-right: 23px;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.photo-editor-copy strong {
    overflow: hidden;
    color: #2a4246;
    font-size: 12px;
    font-weight: 820;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.photo-editor-copy small {
    margin-top: 3px;
    color: #899597;
    font-size: 11px;
    line-height: 1.45;
}

.selected-label {
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 3px;
    color: #178175;
    font-size: 11px;
    font-weight: 820;
}

.selected-label svg {
    width: 10px;
    height: 10px;
}

.clear-photo {
    width: 26px;
    height: 26px;
    display: grid;
    place-items: center;
    position: absolute;
    top: 9px;
    right: 9px;
    border: 1px solid #dbe5e1;
    border-radius: 9px;
    color: #718083;
    background: #fff;
}

.clear-photo svg {
    width: 12px;
    height: 12px;
}

.photo-error {
    margin: 9px 2px 0;
    color: #b44d45;
    font-size: 11px;
    font-weight: 650;
    line-height: 1.4;
}

.photo-picker-actions,
.photo-submit-actions {
    margin-top: 12px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.primary-picker,
.secondary-picker,
.save-photo {
    min-height: 43px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: 13px;
    font-size: 12px;
    font-weight: 820;
    transition: 170ms ease;
}

.primary-picker,
.save-photo {
    border: 1px solid #0e625d;
    color: #fff;
    background: linear-gradient(145deg, #167c73, #10534f);
    box-shadow: 0 9px 18px rgba(19, 102, 94, 0.2);
}

.secondary-picker {
    border: 1px solid #d9e3df;
    color: #486164;
    background: #fff;
}

.primary-picker:hover,
.secondary-picker:hover,
.save-photo:hover {
    transform: translateY(-1px);
}

.primary-picker svg,
.secondary-picker svg,
.save-photo svg {
    width: 15px;
    height: 15px;
}

.primary-picker:disabled,
.secondary-picker:disabled,
.save-photo:disabled {
    cursor: wait;
    opacity: 0.65;
    transform: none;
}

.loading-ring {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.35);
    border-top-color: #fff;
    border-radius: 999px;
    animation: spin 700ms linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.photo-guides {
    margin-top: 13px;
    padding-top: 12px;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 5px;
    border-top: 1px solid #edf0ec;
}

.photo-guides > span {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #778586;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.photo-guides i {
    width: 15px;
    height: 15px;
    display: grid;
    place-items: center;
    border-radius: 999px;
    color: #247c71;
    background: #e4f5ef;
}

.photo-guides svg {
    width: 8px;
    height: 8px;
    stroke-width: 3;
}

.profile-warning {
    border-color: #eedebd;
    color: #9a6c26;
    background: #fff9ed;
}

.profile-warning > svg {
    flex: 0 0 auto;
}

.profile-warning strong {
    color: #765426;
}

.menu-card {
    padding: 9px 17px 7px;
}

.menu-heading {
    padding: 10px 2px 12px;
}

.menu-row {
    width: 100%;
    min-height: 59px;
    padding: 8px 1px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 0;
    border-top: 1px solid #edf1ef;
    color: #253f43;
    background: transparent;
    text-align: left;
}

.menu-row:disabled {
    cursor: default;
    opacity: 1;
}

.menu-icon {
    width: 34px;
    height: 34px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid #e2ebe7;
    border-radius: 11px;
    color: #52716f;
    background: #f6faf8;
}

.menu-icon svg {
    width: 16px;
    height: 16px;
    stroke-width: 1.9;
}

.menu-copy {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.menu-copy strong {
    font-size: 12px;
    font-weight: 790;
}

.menu-copy small {
    margin-top: 2px;
    overflow: hidden;
    color: #929d9e;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.coming-soon {
    padding: 4px 6px;
    border-radius: 999px;
    color: #9b7a48;
    background: #fff7e7;
    font-size: 11px;
    font-weight: 800;
}

.menu-row > svg {
    width: 14px;
    height: 14px;
    flex: 0 0 auto;
    color: #a2aeae;
}

.settings-card {
    margin-top: 12px;
}

.logout-row {
    color: #a3433f;
}

.logout-row .menu-icon {
    border-color: #f0dcda;
    color: #ae4c45;
    background: #fff6f5;
}

.logout-row .menu-copy small {
    color: #b9918f;
}

.privacy-note {
    max-width: 330px;
    margin: 17px auto 4px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    gap: 6px;
    color: #899695;
    font-size: 11px;
    line-height: 1.5;
    text-align: center;
}

.privacy-note svg {
    width: 12px;
    height: 12px;
    flex: 0 0 auto;
    color: #558079;
}

.bottom-navigation {
    min-height: 74px;
    padding: 8px 13px max(8px, env(safe-area-inset-bottom));
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    align-items: end;
    position: relative;
    z-index: 20;
    border-top: 1px solid rgba(216, 228, 223, 0.9);
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 -14px 35px rgba(28, 61, 59, 0.06);
    backdrop-filter: blur(18px);
}

.bottom-item {
    min-width: 0;
    min-height: 48px;
    padding: 4px 2px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-direction: column;
    gap: 3px;
    border: 0;
    border-radius: 12px;
    color: #93a09f;
    background: transparent;
}

.bottom-item:disabled {
    opacity: 1;
}

.bottom-item > span {
    width: 30px;
    height: 26px;
    display: grid;
    place-items: center;
    border-radius: 10px;
}

.bottom-item svg {
    width: 18px;
    height: 18px;
    stroke-width: 1.9;
}

.bottom-item small {
    font-size: 11px;
    font-weight: 720;
}

.bottom-item.active {
    color: #087f78;
}

.bottom-item.active > span {
    background: #e8f5f1;
}

.bottom-item.attendance-main {
    color: #315858;
}

.bottom-item.attendance-main > span {
    width: 51px;
    height: 51px;
    margin-top: -23px;
    border: 5px solid #fff;
    border-radius: 18px;
    color: #f8fffd;
    background: linear-gradient(145deg, #b68746, #d4ae6f);
    box-shadow: 0 9px 20px rgba(151, 104, 44, 0.26);
}

.bottom-item.attendance-main svg {
    width: 21px;
    height: 21px;
}

.bottom-item.attendance-main small {
    color: #7d8989;
}

.sr-only {
    width: 1px;
    height: 1px;
    padding: 0;
    position: absolute;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
    white-space: nowrap;
}

button,
a {
    -webkit-tap-highlight-color: transparent;
}

button:focus-visible,
a:focus-visible {
    outline: 3px solid rgba(26, 138, 128, 0.25);
    outline-offset: 2px;
}

@media (max-width: 380px) {
    .account-scroll {
        padding-inline: 13px;
    }

    .profile-hero {
        min-height: 165px;
        padding: 17px;
        gap: 13px;
    }

    .hero-avatar {
        width: 78px;
        height: 78px;
        border-radius: 25px;
    }

    .hero-avatar img,
    .hero-avatar::after {
        border-radius: 21px;
    }

    .hero-avatar img {
        clip-path: inset(0 round 21px);
    }

    .hero-copy h2 {
        font-size: 17px;
    }

    .photo-card {
        padding: 16px;
    }

    .photo-guides {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        row-gap: 8px;
    }

    .photo-guides > span {
        font-size: 11px;
    }
}

@media (min-width: 700px) {
    .account-shell {
        height: calc(100svh - 40px);
        min-height: calc(100svh - 40px);
        max-height: calc(100svh - 40px);
        margin-block: 20px;
        border: 1px solid rgba(210, 223, 218, 0.8);
        border-radius: 34px;
    }

    .bottom-navigation {
        border-radius: 0 0 34px 34px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
    }
}
</style>
