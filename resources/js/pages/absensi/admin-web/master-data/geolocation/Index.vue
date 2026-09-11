<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    CircleDot,
    Crosshair,
    LoaderCircle,
    LocateFixed,
    MapPin,
    MapPinned,
    Navigation,
    Pencil,
    Plus,
    Ruler,
    Save,
    ShieldCheck,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ConfirmDelete from '../../setting/components/ConfirmDelete.vue';
import CrudModal from '../../setting/components/CrudModal.vue';
import FlashMessage from '../../setting/components/FlashMessage.vue';
import OfficeMapPicker from './OfficeMapPicker.vue';

interface OfficeLocation {
    id: number;
    name: string;
    address: string | null;
    latitude: number;
    longitude: number;
    radius_meters: number;
    updated_at: string | null;
}

const props = defineProps<{
    locations: OfficeLocation[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const baseUrl = '/absensi/admin-web/master-data/geolocation';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Pengaturan', href: baseUrl },
    { title: 'Geolokasi', href: baseUrl },
];

const creating = ref(false);
const editing = ref<OfficeLocation | null>(null);
const selected = ref<OfficeLocation | null>(null);
const deleting = ref(false);
const locating = ref(false);
const geolocationMessage = ref('');
const geolocationError = ref('');

const form = useForm<{
    name: string;
    address: string;
    latitude: number | string;
    longitude: number | string;
    radius_meters: number;
}>({
    name: '',
    address: '',
    latitude: '',
    longitude: '',
    radius_meters: 100,
});

const totalCoverage = computed(() => props.locations.reduce((total, location) => total + location.radius_meters, 0));
const averageRadius = computed(() => (props.locations.length ? Math.round(totalCoverage.value / props.locations.length) : 0));
const coordinateReady = computed(() => form.latitude !== '' && form.longitude !== '');

const resetForm = () => {
    form.defaults({ name: '', address: '', latitude: '', longitude: '', radius_meters: 100 });
    form.reset();
    form.clearErrors();
    geolocationMessage.value = '';
    geolocationError.value = '';
};

const openCreate = () => {
    editing.value = null;
    resetForm();
    creating.value = true;
};

const openEdit = (location: OfficeLocation) => {
    creating.value = false;
    editing.value = location;
    form.clearErrors();
    geolocationMessage.value = '';
    geolocationError.value = '';
    form.defaults({
        name: location.name,
        address: location.address ?? '',
        latitude: location.latitude,
        longitude: location.longitude,
        radius_meters: location.radius_meters,
    });
    form.reset();
};

const closeForm = () => {
    if (form.processing) return;
    creating.value = false;
    editing.value = null;
    resetForm();
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => closeForm() };
    if (editing.value) form.put(`${baseUrl}/${editing.value.id}`, options);
    else form.post(baseUrl, options);
};

const locationErrorMessage = (error: GeolocationPositionError) => {
    if (error.code === error.PERMISSION_DENIED) return 'Izin lokasi ditolak. Aktifkan izin lokasi pada browser, lalu coba kembali.';
    if (error.code === error.POSITION_UNAVAILABLE) return 'Lokasi perangkat tidak tersedia saat ini.';
    if (error.code === error.TIMEOUT) return 'Pencarian lokasi melewati batas waktu. Silakan coba kembali.';
    return 'Lokasi perangkat gagal ditemukan.';
};

const useCurrentLocation = () => {
    geolocationMessage.value = '';
    geolocationError.value = '';

    if (!navigator.geolocation) {
        geolocationError.value = 'Browser ini tidak mendukung pengambilan lokasi.';
        return;
    }

    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            form.latitude = Number(position.coords.latitude.toFixed(7));
            form.longitude = Number(position.coords.longitude.toFixed(7));
            geolocationMessage.value = `Lokasi berhasil diambil dengan akurasi sekitar ${Math.round(position.coords.accuracy)} meter.`;
            locating.value = false;
        },
        (error) => {
            geolocationError.value = locationErrorMessage(error);
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
};

const updateLatitude = (value: number) => {
    form.latitude = value;
    form.clearErrors('latitude');
};

const updateLongitude = (value: number) => {
    form.longitude = value;
    form.clearErrors('longitude');
};

const destroy = () => {
    if (!selected.value) return;
    deleting.value = true;
    router.delete(`${baseUrl}/${selected.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
        onFinish: () => (deleting.value = false),
    });
};

const formatCoordinate = (value: number) => Number(value).toFixed(7);
const formatRadius = (meters: number) => (meters >= 1000 ? `${Number((meters / 1000).toFixed(2))} km` : `${meters} m`);
const mapsUrl = (location: OfficeLocation) =>
    `https://www.openstreetmap.org/?mlat=${location.latitude}&mlon=${location.longitude}#map=18/${location.latitude}/${location.longitude}`;
</script>

<template>
    <Head title="Pengaturan Geolokasi" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="geo-page">
            <FlashMessage />

            <section class="geo-hero" aria-labelledby="geo-title">
                <span class="geo-grid" aria-hidden="true"></span>
                <span class="geo-orbit orbit-one" aria-hidden="true"></span>
                <span class="geo-orbit orbit-two" aria-hidden="true"></span>
                <div class="geo-hero-copy">
                    <span class="geo-eyebrow"><MapPinned /> Area presensi tepercaya</span>
                    <h1 id="geo-title">Tentukan titik kantor.<br /><span>Jaga presensi tetap akurat.</span></h1>
                    <p>Atur koordinat dan radius setiap kantor untuk membentuk batas area presensi yang jelas dan mudah dikelola.</p>
                    <div class="geo-trust">
                        <span><ShieldCheck /> Koordinat tervalidasi</span>
                        <span><CircleDot /> Radius terlihat langsung</span>
                    </div>
                </div>
                <div class="geo-hero-side">
                    <div class="geo-radar" aria-hidden="true">
                        <span class="radar-ring ring-one"></span>
                        <span class="radar-ring ring-two"></span>
                        <span class="radar-ring ring-three"></span>
                        <span class="radar-sweep"></span>
                        <MapPin />
                    </div>
                    <div class="geo-hero-stats">
                        <div>
                            <strong>{{ locations.length }}</strong
                            ><span>Lokasi kantor</span>
                        </div>
                        <i></i>
                        <div>
                            <strong>{{ formatRadius(averageRadius) }}</strong
                            ><span>Rata-rata radius</span>
                        </div>
                    </div>
                    <button v-if="can.create" type="button" class="geo-primary-action" @click="openCreate">
                        <span><Plus /></span>
                        <div><strong>Tambah lokasi</strong><small>Tentukan titik kantor baru</small></div>
                    </button>
                </div>
            </section>

            <section class="geo-summary" aria-label="Ringkasan geolokasi">
                <article>
                    <span class="summary-icon indigo"><Building2 /></span>
                    <div>
                        <small>Total lokasi</small><strong>{{ locations.length }}</strong>
                        <p>Kantor terdaftar</p>
                    </div>
                </article>
                <article>
                    <span class="summary-icon teal"><Ruler /></span>
                    <div>
                        <small>Akumulasi radius</small><strong>{{ formatRadius(totalCoverage) }}</strong>
                        <p>Seluruh batas lokasi</p>
                    </div>
                </article>
                <article>
                    <span class="summary-icon amber"><Navigation /></span>
                    <div>
                        <small>Status koordinat</small><strong>{{ locations.length ? 'Siap' : 'Belum ada' }}</strong>
                        <p>{{ locations.length ? 'Dapat digunakan' : 'Tambahkan lokasi' }}</p>
                    </div>
                </article>
            </section>

            <section class="geo-directory" aria-labelledby="location-list-title">
                <header>
                    <div>
                        <span class="geo-section-kicker">Daftar area kantor</span>
                        <h2 id="location-list-title">Lokasi yang diizinkan</h2>
                        <p>Setiap radius dihitung dari titik koordinat utama kantor.</p>
                    </div>
                    <button v-if="can.create" type="button" class="geo-add-secondary" @click="openCreate"><Plus /> Tambah lokasi</button>
                </header>

                <div v-if="locations.length" class="geo-card-grid">
                    <article v-for="(location, index) in locations" :key="location.id" class="geo-location-card">
                        <div class="location-card-top">
                            <span class="location-number">{{ String(index + 1).padStart(2, '0') }}</span>
                            <div class="location-actions">
                                <button v-if="can.update" type="button" aria-label="Edit lokasi" title="Edit lokasi" @click="openEdit(location)">
                                    <Pencil />
                                </button>
                                <button
                                    v-if="can.delete"
                                    type="button"
                                    class="danger"
                                    aria-label="Hapus lokasi"
                                    title="Hapus lokasi"
                                    @click="selected = location"
                                >
                                    <Trash2 />
                                </button>
                            </div>
                        </div>
                        <div class="location-pin"><MapPin /></div>
                        <h3>{{ location.name }}</h3>
                        <p class="location-address">{{ location.address || 'Alamat belum ditambahkan' }}</p>
                        <div class="location-data">
                            <div>
                                <span>Latitude</span><strong>{{ formatCoordinate(location.latitude) }}</strong>
                            </div>
                            <div>
                                <span>Longitude</span><strong>{{ formatCoordinate(location.longitude) }}</strong>
                            </div>
                        </div>
                        <footer>
                            <span
                                ><Ruler /> Radius <strong>{{ formatRadius(location.radius_meters) }}</strong></span
                            >
                            <a :href="mapsUrl(location)" target="_blank" rel="noopener noreferrer">Buka peta <Navigation /></a>
                        </footer>
                    </article>
                </div>

                <div v-else class="geo-empty">
                    <span><MapPinned /></span>
                    <h3>Belum ada lokasi kantor</h3>
                    <p>Tambahkan titik kantor pertama agar batas area presensi dapat ditentukan.</p>
                    <button v-if="can.create" type="button" @click="openCreate"><Plus /> Tambah lokasi pertama</button>
                </div>
            </section>
        </div>

        <CrudModal
            :open="creating || Boolean(editing)"
            :title="editing ? 'Edit lokasi kantor' : 'Tambah lokasi kantor'"
            description="Klik peta, gunakan lokasi perangkat, atau masukkan koordinat secara manual."
            @close="closeForm"
        >
            <form class="geo-form" @submit.prevent="submit">
                <div class="geo-form-body">
                    <div class="geo-map-column">
                        <OfficeMapPicker
                            :latitude="form.latitude"
                            :longitude="form.longitude"
                            :radius="form.radius_meters"
                            @update:latitude="updateLatitude"
                            @update:longitude="updateLongitude"
                        />
                        <button type="button" class="current-location-button" :disabled="locating" @click="useCurrentLocation">
                            <LoaderCircle v-if="locating" class="spin" /><LocateFixed v-else />
                            {{ locating ? 'Mencari lokasi...' : 'Ambil lokasi saat ini' }}
                        </button>
                        <p v-if="geolocationMessage" class="location-feedback success">{{ geolocationMessage }}</p>
                        <p v-if="geolocationError" class="location-feedback error">{{ geolocationError }}</p>
                    </div>

                    <div class="geo-fields-column">
                        <div class="geo-field full">
                            <label for="location-name">Nama lokasi <b>*</b></label>
                            <div :class="{ invalid: form.errors.name }">
                                <Building2 /><input
                                    id="location-name"
                                    v-model="form.name"
                                    maxlength="100"
                                    placeholder="Contoh: Kantor Pusat"
                                    autocomplete="off"
                                />
                            </div>
                            <p v-if="form.errors.name">{{ form.errors.name }}</p>
                        </div>
                        <div class="geo-field full">
                            <label for="location-address">Alamat kantor</label>
                            <div class="textarea-wrap" :class="{ invalid: form.errors.address }">
                                <MapPin /><textarea
                                    id="location-address"
                                    v-model="form.address"
                                    maxlength="500"
                                    rows="3"
                                    placeholder="Alamat lengkap kantor"
                                ></textarea>
                            </div>
                            <p v-if="form.errors.address">{{ form.errors.address }}</p>
                        </div>
                        <div class="coordinate-heading">
                            <span><Crosshair /></span>
                            <div><strong>Koordinat manual</strong><small>Nilai akan mengikuti titik pada peta</small></div>
                        </div>
                        <div class="geo-field-grid">
                            <div class="geo-field">
                                <label for="location-latitude">Latitude <b>*</b></label>
                                <div :class="{ invalid: form.errors.latitude }">
                                    <input
                                        id="location-latitude"
                                        v-model.number="form.latitude"
                                        type="number"
                                        min="-90"
                                        max="90"
                                        step="0.0000001"
                                        placeholder="-6.2000000"
                                    />
                                </div>
                                <p v-if="form.errors.latitude">{{ form.errors.latitude }}</p>
                            </div>
                            <div class="geo-field">
                                <label for="location-longitude">Longitude <b>*</b></label>
                                <div :class="{ invalid: form.errors.longitude }">
                                    <input
                                        id="location-longitude"
                                        v-model.number="form.longitude"
                                        type="number"
                                        min="-180"
                                        max="180"
                                        step="0.0000001"
                                        placeholder="106.8166667"
                                    />
                                </div>
                                <p v-if="form.errors.longitude">{{ form.errors.longitude }}</p>
                            </div>
                        </div>
                        <div class="geo-field full radius-field">
                            <label for="location-radius"
                                >Radius area <b>*</b><span>{{ formatRadius(form.radius_meters || 0) }}</span></label
                            >
                            <div :class="{ invalid: form.errors.radius_meters }">
                                <Ruler /><input
                                    id="location-radius"
                                    v-model.number="form.radius_meters"
                                    type="number"
                                    min="1"
                                    max="100000"
                                    step="1"
                                    placeholder="100"
                                /><em>meter</em>
                            </div>
                            <input
                                v-model.number="form.radius_meters"
                                class="radius-slider"
                                type="range"
                                min="10"
                                max="1000"
                                step="10"
                                aria-label="Atur radius lokasi"
                            />
                            <div class="radius-scale"><span>10 m</span><span>500 m</span><span>1 km</span></div>
                            <p v-if="form.errors.radius_meters">{{ form.errors.radius_meters }}</p>
                        </div>
                        <div class="coordinate-status" :class="{ ready: coordinateReady }">
                            <CircleDot /><span>{{
                                coordinateReady ? 'Titik lokasi sudah ditentukan' : 'Pilih titik pada peta atau isi koordinat'
                            }}</span>
                        </div>
                    </div>
                </div>

                <div class="admin-modal-footer geo-form-footer">
                    <span>Radius membentuk batas dari titik kantor.</span>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="closeForm">Batal</button>
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="form.processing || !coordinateReady || (Boolean(editing) && !form.isDirty)"
                        >
                            <LoaderCircle v-if="form.processing" class="spin" /><Save v-else />
                            {{ form.processing ? 'Menyimpan...' : editing ? 'Simpan perubahan' : 'Simpan lokasi' }}
                        </button>
                    </div>
                </div>
            </form>
        </CrudModal>

        <ConfirmDelete
            :open="Boolean(selected)"
            title="Hapus lokasi kantor?"
            :description="`Lokasi ${selected?.name ?? ''} beserta pengaturan radiusnya akan dihapus permanen.`"
            :processing="deleting"
            @close="selected = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>

<style scoped>
.geo-page,
.geo-form {
    --geo-ink: #172033;
    --geo-muted: #697386;
    --geo-line: #e6eaf1;
    --geo-primary: #5549d8;
}
.geo-page {
    display: grid;
    gap: 20px;
    animation: geo-enter 0.4s ease both;
}
.geo-hero {
    position: relative;
    display: grid;
    grid-template-columns: 1.3fr 0.7fr;
    min-height: 300px;
    overflow: hidden;
    padding: 42px 46px;
    border-radius: 22px;
    color: white;
    background: linear-gradient(125deg, #111a34 0%, #25315e 54%, #4c4fbd 100%);
    box-shadow: 0 22px 48px rgba(28, 35, 84, 0.2);
}
.geo-grid {
    position: absolute;
    inset: 0;
    opacity: 0.1;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.32) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.32) 1px, transparent 1px);
    background-size: 44px 44px;
    mask-image: linear-gradient(90deg, transparent, #000);
}
.geo-orbit {
    position: absolute;
    border: 1px solid rgba(154, 250, 232, 0.22);
    border-radius: 50%;
}
.orbit-one {
    top: -180px;
    right: -90px;
    width: 500px;
    height: 500px;
}
.orbit-two {
    top: -105px;
    right: -15px;
    width: 350px;
    height: 350px;
}
.geo-hero-copy,
.geo-hero-side {
    position: relative;
    z-index: 1;
}
.geo-hero-copy {
    align-self: center;
    max-width: 700px;
}
.geo-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 19px;
    padding: 7px 11px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    color: #bff8ec;
    background: rgba(255, 255, 255, 0.08);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.11em;
    text-transform: uppercase;
}
.geo-eyebrow svg {
    width: 15px;
}
.geo-hero h1 {
    margin: 0;
    font-size: clamp(31px, 4vw, 49px);
    font-weight: 800;
    line-height: 1.08;
    letter-spacing: -0.04em;
}
.geo-hero h1 span {
    color: #92eadc;
}
.geo-hero-copy > p {
    max-width: 650px;
    margin: 17px 0 20px;
    color: rgba(238, 242, 255, 0.75);
    font-size: 14px;
    line-height: 1.7;
}
.geo-trust {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
}
.geo-trust span {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #dfe8ff;
    font-size: 12px;
    font-weight: 600;
}
.geo-trust svg {
    width: 15px;
    color: #69dbc8;
}
.geo-hero-side {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.geo-radar {
    position: relative;
    display: grid;
    width: 145px;
    height: 145px;
    place-items: center;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    background: rgba(15, 23, 42, 0.23);
    box-shadow: inset 0 0 40px rgba(110, 231, 215, 0.08);
}
.geo-radar > svg {
    z-index: 3;
    width: 34px;
    height: 34px;
    padding: 7px;
    overflow: visible;
    border-radius: 50%;
    color: white;
    background: #645ce7;
    box-shadow:
        0 0 0 7px rgba(100, 92, 231, 0.25),
        0 7px 22px rgba(0, 0, 0, 0.25);
}
.radar-ring {
    position: absolute;
    border: 1px solid rgba(139, 238, 220, 0.28);
    border-radius: 50%;
}
.ring-one {
    width: 48px;
    height: 48px;
}
.ring-two {
    width: 94px;
    height: 94px;
}
.ring-three {
    width: 136px;
    height: 136px;
}
.radar-sweep {
    position: absolute;
    inset: 50% 0 0 50%;
    transform-origin: left top;
    background: linear-gradient(45deg, rgba(95, 230, 205, 0.28), transparent 65%);
    animation: radar-sweep 4s linear infinite;
}
.geo-hero-stats {
    display: flex;
    align-items: center;
    gap: 22px;
    margin: 14px 0;
    text-align: center;
}
.geo-hero-stats div {
    display: grid;
    gap: 1px;
}
.geo-hero-stats strong {
    font-size: 18px;
}
.geo-hero-stats span {
    color: rgba(234, 240, 255, 0.68);
    font-size: 10px;
}
.geo-hero-stats i {
    width: 1px;
    height: 27px;
    background: rgba(255, 255, 255, 0.18);
}
.geo-primary-action {
    display: flex;
    width: min(275px, 100%);
    align-items: center;
    gap: 11px;
    padding: 10px 14px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 13px;
    color: #202842;
    background: white;
    box-shadow: 0 10px 24px rgba(10, 16, 42, 0.2);
    text-align: left;
}
.geo-primary-action > span {
    display: grid;
    width: 35px;
    height: 35px;
    place-items: center;
    border-radius: 10px;
    color: white;
    background: linear-gradient(145deg, #5c55df, #7768e9);
}
.geo-primary-action svg {
    width: 17px;
}
.geo-primary-action div {
    display: grid;
}
.geo-primary-action strong {
    font-size: 12px;
}
.geo-primary-action small {
    color: #7b8496;
    font-size: 10px;
}
.geo-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}
.geo-summary article {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 108px;
    padding: 20px;
    border: 1px solid var(--geo-line);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 9px 25px rgba(33, 42, 75, 0.055);
}
.summary-icon {
    display: grid;
    width: 45px;
    height: 45px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 13px;
}
.summary-icon svg {
    width: 20px;
}
.summary-icon.indigo {
    color: #5146d1;
    background: #eeecff;
}
.summary-icon.teal {
    color: #0f9688;
    background: #e5faf6;
}
.summary-icon.amber {
    color: #d27816;
    background: #fff5df;
}
.geo-summary article div {
    display: grid;
}
.geo-summary small {
    color: var(--geo-muted);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.geo-summary strong {
    margin-top: 1px;
    color: var(--geo-ink);
    font-size: 22px;
}
.geo-summary p {
    margin: 0;
    color: #8a94a6;
    font-size: 11px;
}
.geo-directory {
    padding: 27px;
    border: 1px solid var(--geo-line);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 12px 32px rgba(33, 42, 75, 0.06);
}
.geo-directory > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 23px;
}
.geo-section-kicker {
    color: #5d55d9;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.geo-directory h2 {
    margin: 3px 0 2px;
    color: var(--geo-ink);
    font-size: 21px;
}
.geo-directory header p {
    margin: 0;
    color: var(--geo-muted);
    font-size: 12px;
}
.geo-add-secondary,
.geo-empty button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 14px;
    border: 0;
    border-radius: 11px;
    color: #fff;
    background: var(--geo-primary);
    font-size: 12px;
    font-weight: 700;
}
.geo-add-secondary svg,
.geo-empty button svg {
    width: 16px;
}
.geo-card-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}
.geo-location-card {
    position: relative;
    overflow: hidden;
    padding: 18px;
    border: 1px solid #e3e7ef;
    border-radius: 17px;
    background: linear-gradient(145deg, #fff, #fbfcff);
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        border-color 0.2s ease;
}
.geo-location-card:hover {
    transform: translateY(-3px);
    border-color: #cecafa;
    box-shadow: 0 13px 27px rgba(63, 56, 135, 0.1);
}
.geo-location-card::before {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    content: '';
    background: linear-gradient(90deg, #6058db, #57c9b8);
}
.location-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.location-number {
    color: #a0a8b8;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.12em;
}
.location-actions {
    display: flex;
    gap: 5px;
}
.location-actions button {
    display: grid;
    width: 29px;
    height: 29px;
    place-items: center;
    border: 1px solid #e5e7ed;
    border-radius: 8px;
    color: #677084;
    background: #fff;
}
.location-actions button:hover {
    color: #5148ce;
    border-color: #cbc7f7;
    background: #f6f5ff;
}
.location-actions button.danger:hover {
    color: #dc3545;
    border-color: #f6c8ce;
    background: #fff6f7;
}
.location-actions svg {
    width: 14px;
}
.location-pin {
    display: grid;
    width: 44px;
    height: 44px;
    margin: 13px 0 11px;
    place-items: center;
    border-radius: 14px;
    color: #fff;
    background: linear-gradient(145deg, #5650d8, #7a65e6);
    box-shadow: 0 8px 18px rgba(78, 70, 197, 0.24);
}
.location-pin svg {
    width: 20px;
}
.geo-location-card h3 {
    margin: 0;
    color: var(--geo-ink);
    font-size: 16px;
}
.location-address {
    min-height: 36px;
    margin: 5px 0 15px;
    color: #7a8497;
    font-size: 11px;
    line-height: 1.55;
}
.location-data {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 7px;
    padding: 10px;
    border-radius: 11px;
    background: #f6f7fb;
}
.location-data div {
    display: grid;
    gap: 2px;
    min-width: 0;
}
.location-data span {
    color: #949cad;
    font-size: 9px;
    text-transform: uppercase;
}
.location-data strong {
    overflow: hidden;
    color: #394158;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 10px;
    text-overflow: ellipsis;
}
.geo-location-card footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 13px;
}
.geo-location-card footer > span,
.geo-location-card footer a {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
}
.geo-location-card footer > span {
    color: #657084;
}
.geo-location-card footer > span strong {
    color: #252e44;
}
.geo-location-card footer a {
    color: #554bd4;
    font-weight: 700;
    text-decoration: none;
}
.geo-location-card footer svg {
    width: 13px;
}
.geo-empty {
    display: grid;
    min-height: 270px;
    place-items: center;
    align-content: center;
    text-align: center;
}
.geo-empty > span {
    display: grid;
    width: 68px;
    height: 68px;
    place-items: center;
    border-radius: 20px;
    color: #635ada;
    background: #f0efff;
}
.geo-empty > span svg {
    width: 30px;
}
.geo-empty h3 {
    margin: 15px 0 5px;
    color: var(--geo-ink);
    font-size: 18px;
}
.geo-empty p {
    max-width: 380px;
    margin: 0 0 16px;
    color: var(--geo-muted);
    font-size: 12px;
}
.geo-form {
    width: min(1040px, calc(100vw - 40px));
}
.geo-form-body {
    display: grid;
    grid-template-columns: 1.1fr 0.9fr;
    gap: 22px;
    min-height: 0;
    overflow-y: auto;
    padding: 22px;
}
.geo-map-column {
    min-width: 0;
}
.current-location-button {
    display: flex;
    width: 100%;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 10px;
    padding: 11px;
    border: 1px solid #d8dcf4;
    border-radius: 11px;
    color: #4e47c4;
    background: #f7f7ff;
    font-size: 12px;
    font-weight: 700;
}
.current-location-button:hover:not(:disabled) {
    background: #efeeff;
}
.current-location-button:disabled {
    opacity: 0.65;
}
.current-location-button svg {
    width: 17px;
}
.location-feedback {
    margin: 7px 2px 0;
    font-size: 10px;
    line-height: 1.45;
}
.location-feedback.success {
    color: #148273;
}
.location-feedback.error {
    color: #dc3545;
}
.geo-fields-column {
    display: flex;
    flex-direction: column;
    gap: 13px;
}
.geo-field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.geo-field label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 0 0 6px;
    color: #3d465b;
    font-size: 11px;
    font-weight: 700;
}
.geo-field label b {
    margin-left: 3px;
    color: #dc3545;
}
.geo-field label span {
    margin-left: auto;
    color: #5c53d6;
    font-size: 10px;
}
.geo-field > div:not(.radius-scale) {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 41px;
    padding: 0 11px;
    border: 1px solid #dfe3eb;
    border-radius: 10px;
    background: #fff;
    transition:
        border-color 0.2s,
        box-shadow 0.2s;
}
.geo-field > div:focus-within {
    border-color: #756ee2;
    box-shadow: 0 0 0 3px rgba(103, 93, 220, 0.1);
}
.geo-field > div.invalid {
    border-color: #e66472;
    background: #fffafb;
}
.geo-field div > svg {
    width: 15px;
    flex: 0 0 auto;
    color: #8c95a7;
}
.geo-field input,
.geo-field textarea {
    width: 100%;
    border: 0;
    outline: 0;
    color: #273047;
    background: transparent;
    font-size: 12px;
}
.geo-field input {
    min-height: 39px;
}
.geo-field textarea {
    padding: 10px 0;
    resize: none;
}
.geo-field em {
    color: #929aaa;
    font-size: 10px;
    font-style: normal;
}
.geo-field > p {
    margin: 4px 1px 0;
    color: #dc3545;
    font-size: 10px;
}
.coordinate-heading {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-top: 3px;
}
.coordinate-heading > span {
    display: grid;
    width: 31px;
    height: 31px;
    place-items: center;
    border-radius: 9px;
    color: #5750d4;
    background: #eeedff;
}
.coordinate-heading svg {
    width: 15px;
}
.coordinate-heading div {
    display: grid;
}
.coordinate-heading strong {
    color: #30394e;
    font-size: 11px;
}
.coordinate-heading small {
    color: #929aaa;
    font-size: 9px;
}
.radius-field .radius-slider {
    display: block;
    width: 100%;
    min-height: auto;
    margin: 8px 0 3px;
    padding: 0;
    border: 0;
    accent-color: #5e55db;
    box-shadow: none;
}
.radius-scale {
    display: flex !important;
    min-height: 0 !important;
    justify-content: space-between;
    padding: 0 !important;
    border: 0 !important;
    color: #9aa2b1;
    background: transparent !important;
    font-size: 8px;
}
.coordinate-status {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 9px 10px;
    border: 1px dashed #d9dde7;
    border-radius: 10px;
    color: #8a93a4;
    background: #fafbfc;
    font-size: 10px;
    font-weight: 600;
}
.coordinate-status svg {
    width: 14px;
}
.coordinate-status.ready {
    color: #0e8878;
    border-color: #bce7df;
    background: #f0fbf8;
}
.geo-form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}
.geo-form-footer > span {
    color: #7a8496;
    font-size: 10px;
}
.geo-form-footer > div {
    display: flex;
    gap: 8px;
}
.geo-form-footer button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
}
.geo-form-footer button svg {
    width: 15px;
}
.spin {
    animation: spin 0.8s linear infinite;
}
@keyframes radar-sweep {
    to {
        transform: rotate(360deg);
    }
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@keyframes geo-enter {
    from {
        opacity: 0;
        transform: translateY(7px);
    }
}
@media (max-width: 1100px) {
    .geo-card-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .geo-form-body {
        grid-template-columns: 1fr;
    }
    .geo-map-column {
        order: 2;
    }
}
@media (max-width: 780px) {
    .geo-hero {
        grid-template-columns: 1fr;
        padding: 34px 28px;
    }
    .geo-hero-side {
        margin-top: 26px;
    }
    .geo-summary {
        grid-template-columns: 1fr;
    }
    .geo-card-grid {
        grid-template-columns: 1fr;
    }
    .geo-directory {
        padding: 20px;
    }
}
@media (max-width: 520px) {
    .geo-directory > header,
    .geo-form-footer {
        align-items: flex-start;
        flex-direction: column;
    }
    .geo-form-footer > div,
    .geo-form-footer button {
        width: 100%;
    }
    .geo-form-footer button {
        justify-content: center;
    }
    .geo-field-grid {
        grid-template-columns: 1fr;
    }
    .geo-form-body {
        padding: 15px;
    }
    .geo-trust {
        gap: 8px;
        flex-direction: column;
    }
}
:global(.dark) .geo-summary article,
:global(.dark) .geo-directory,
:global(.dark) .geo-location-card {
    border-color: #2b3446;
    background: #151c29;
}
:global(.dark) .geo-summary strong,
:global(.dark) .geo-directory h2,
:global(.dark) .geo-location-card h3 {
    color: #edf2ff;
}
:global(.dark) .location-data {
    background: #202838;
}
:global(.dark) .location-data strong {
    color: #dce4f5;
}
:global(.dark) .geo-field > div:not(.radius-scale) {
    border-color: #384254;
    background: #1a2230;
}
:global(.dark) .geo-field input,
:global(.dark) .geo-field textarea {
    color: #edf2ff;
}
:global(.dark) .geo-field label,
:global(.dark) .coordinate-heading strong {
    color: #dce4f5;
}
</style>
