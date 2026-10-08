<script setup lang="ts">
import { getAndroidAttendanceLocation, isAndroidAttendance } from '@/lib/android-attendance-location';
import type { SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    Building2,
    Camera,
    Check,
    CheckCircle2,
    ChevronRight,
    CircleAlert,
    Crosshair,
    Focus,
    Info,
    LoaderCircle,
    LocateFixed,
    MapPin,
    Navigation,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import ClockInMap from './ClockInMap.vue';

interface CurrentLocation {
    latitude: number;
    longitude: number;
    accuracy: number;
}

interface LocationSample extends CurrentLocation {
    [key: string]: number | boolean | undefined;
    captured_at: number;
    is_mock?: boolean;
}

interface ExtendedGeolocationCoordinates extends GeolocationCoordinates {
    mocked?: boolean;
    isMock?: boolean;
    isFromMockProvider?: boolean;
}

const props = defineProps<{
    mode?: 'in' | 'out';
    schedule: {
        date: string;
        date_label: string;
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
    };
    office: {
        id: number;
        name: string;
        address: string | null;
        latitude: number;
        longitude: number;
        radius_meters: number;
    };
    locationChallenge: {
        token: string;
        min_samples: number;
        collection_seconds: number;
        max_accuracy_meters: number;
        max_speed_meters_per_second: number;
    };
    profilePhotoUrl: string | null;
}>();

const isClockOut = computed(() => props.mode === 'out');
const actionTitle = computed(() => (isClockOut.value ? 'Clock Out' : 'Clock In'));
const actionSlug = computed(() => (isClockOut.value ? 'clock-out' : 'clock-in'));

const page = usePage<SharedData>();
const currentStep = ref<1 | 2>(1);
const currentLocation = ref<CurrentLocation | null>(null);
const locationSamples = ref<LocationSample[]>([]);
const locationSampleProgress = ref(0);
const locating = ref(false);
const locationError = ref('');
const outsideDialogOpen = ref(false);
const outsideNote = ref('');
const outsideNoteError = ref('');
const photoError = ref('');
const cameraVideo = ref<HTMLVideoElement | null>(null);
const cameraStream = ref<MediaStream | null>(null);
const cameraStarting = ref(false);
const cameraActive = ref(false);
const capturingPhoto = ref(false);
const checkingLocation = ref(false);
const cameraError = ref('');
const verificationElapsedSeconds = ref(0);
let cameraRequestId = 0;
let locationRequestId = 0;
let locationWatchId: number | null = null;
let locationSampleTimer: ReturnType<typeof window.setInterval> | null = null;
let locationDeadlineTimer: ReturnType<typeof window.setTimeout> | null = null;
let verificationTimer: ReturnType<typeof window.setInterval> | null = null;

const form = useForm<{
    latitude: number | null;
    longitude: number | null;
    accuracy: number | null;
    location_token: string;
    location_samples: LocationSample[];
    location_source: 'android_native' | 'browser';
    outside_radius_confirmed: boolean;
    outside_radius_note: string;
    photo: File | null;
}>({
    latitude: null,
    longitude: null,
    accuracy: null,
    location_token: props.locationChallenge.token,
    location_samples: [],
    location_source: isAndroidAttendance() ? 'android_native' : 'browser',
    outside_radius_confirmed: false,
    outside_radius_note: '',
    photo: null,
});

const initials = computed(() =>
    page.props.auth.user.name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase(),
);

const distanceMeters = computed<number | null>(() => {
    if (!currentLocation.value) return null;

    const earthRadius = 6_371_000;
    const latitudeDelta = ((props.office.latitude - currentLocation.value.latitude) * Math.PI) / 180;
    const longitudeDelta = ((props.office.longitude - currentLocation.value.longitude) * Math.PI) / 180;
    const originLatitude = (currentLocation.value.latitude * Math.PI) / 180;
    const destinationLatitude = (props.office.latitude * Math.PI) / 180;
    const a = Math.sin(latitudeDelta / 2) ** 2 + Math.cos(originLatitude) * Math.cos(destinationLatitude) * Math.sin(longitudeDelta / 2) ** 2;
    const normalized = Math.min(1, Math.max(0, a));

    return earthRadius * (2 * Math.atan2(Math.sqrt(normalized), Math.sqrt(1 - normalized)));
});

const isInsideRadius = computed<boolean | null>(() => {
    if (distanceMeters.value === null) return null;
    return distanceMeters.value <= props.office.radius_meters;
});

const formattedDistance = computed(() => {
    if (distanceMeters.value === null) return 'Belum tersedia';
    if (distanceMeters.value >= 1000) return `${(distanceMeters.value / 1000).toFixed(1).replace('.', ',')} km`;
    return `${Math.round(distanceMeters.value)} m`;
});

const accuracyState = computed(() => {
    if (locating.value) {
        return {
            label: 'Memverifikasi GPS',
            tone: 'waiting',
            detail: `Sampel ${locationSampleProgress.value}/${props.locationChallenge.min_samples} · jangan berpindah halaman`,
        };
    }

    const accuracy = currentLocation.value?.accuracy;
    if (accuracy === undefined) return { label: 'Menunggu lokasi', tone: 'waiting', detail: 'Mengambil lokasi dari perangkat' };
    if (accuracy <= 25) return { label: 'Akurasi sangat baik', tone: 'excellent', detail: `Akurat hingga ±${Math.round(accuracy)} m` };
    if (accuracy <= 75) return { label: 'Akurasi baik', tone: 'good', detail: `Akurat hingga ±${Math.round(accuracy)} m` };
    return { label: 'Akurasi GPS rendah', tone: 'low', detail: `Penyimpangan dapat mencapai ±${Math.round(accuracy)} m` };
});

const globalFormError = computed(() => (form.errors as Record<string, string>).attendance);
const locationProofError = computed(() => (form.errors as Record<string, string>).location_proof);
const verificationButtonLabel = computed(() => {
    if (checkingLocation.value) return 'Memeriksa keamanan GPS...';
    if (capturingPhoto.value) return 'Mengambil foto wajah...';
    if (!form.processing) return `Verifikasi & ${actionTitle.value}`;
    if (verificationElapsedSeconds.value < 2) return 'Mengirim foto...';
    return `Memverifikasi wajah... ${verificationElapsedSeconds.value} dtk`;
});
const locationSubmissionError = computed(
    () =>
        locationProofError.value ||
        form.errors.location_token ||
        form.errors.location_samples ||
        form.errors.latitude ||
        form.errors.longitude ||
        form.errors.accuracy ||
        form.errors.outside_radius_confirmed,
);
const locationActionLabel = computed(() => {
    if (!currentLocation.value) return 'Temukan lokasi saya';
    if (isInsideRadius.value === false) return 'Lanjut dengan catatan';

    return 'Lanjut verifikasi wajah';
});

const locationErrorMessage = (error: GeolocationPositionError) => {
    if (error.code === error.PERMISSION_DENIED) return 'Izin lokasi ditolak. Aktifkan akses lokasi pada pengaturan browser lalu coba lagi.';
    if (error.code === error.POSITION_UNAVAILABLE) return 'Sinyal lokasi belum tersedia. Berpindahlah ke area yang lebih terbuka lalu coba lagi.';
    if (error.code === error.TIMEOUT) return 'Pencarian lokasi terlalu lama. Pastikan GPS aktif lalu ulangi pencarian.';
    return 'Lokasi perangkat belum berhasil ditemukan.';
};

const distanceBetween = (from: CurrentLocation, to: CurrentLocation) => {
    const earthRadius = 6_371_000;
    const latitudeDelta = ((to.latitude - from.latitude) * Math.PI) / 180;
    const longitudeDelta = ((to.longitude - from.longitude) * Math.PI) / 180;
    const originLatitude = (from.latitude * Math.PI) / 180;
    const destinationLatitude = (to.latitude * Math.PI) / 180;
    const a = Math.sin(latitudeDelta / 2) ** 2 + Math.cos(originLatitude) * Math.cos(destinationLatitude) * Math.sin(longitudeDelta / 2) ** 2;
    const normalized = Math.min(1, Math.max(0, a));

    return earthRadius * (2 * Math.atan2(Math.sqrt(normalized), Math.sqrt(1 - normalized)));
};

const median = (values: number[]) => {
    const sorted = [...values].sort((left, right) => left - right);
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 1 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
};

const stopLocationCollection = () => {
    if (locationWatchId !== null && navigator.geolocation) navigator.geolocation.clearWatch(locationWatchId);
    if (locationSampleTimer !== null) window.clearInterval(locationSampleTimer);
    if (locationDeadlineTimer !== null) window.clearTimeout(locationDeadlineTimer);

    locationWatchId = null;
    locationSampleTimer = null;
    locationDeadlineTimer = null;
};

const requestLocation = () => {
    const requestId = ++locationRequestId;
    stopLocationCollection();
    locationError.value = '';
    form.clearErrors();

    if (!window.isSecureContext) {
        locationError.value = 'Akses lokasi memerlukan koneksi HTTPS yang aman. Buka kembali aplikasi melalui alamat HTTPS.';
        return;
    }

    if (!isAndroidAttendance() && !navigator.geolocation) {
        locationError.value = 'Perangkat ini tidak mendukung layanan lokasi.';
        return;
    }

    currentLocation.value = null;
    locationSamples.value = [];
    locationSampleProgress.value = 0;
    locating.value = true;
    const collectionMilliseconds = props.locationChallenge.collection_seconds * 1000;
    const sampleIntervalMilliseconds = collectionMilliseconds / (props.locationChallenge.min_samples - 1);
    const deadlineMilliseconds = Math.max(7_000, collectionMilliseconds + 5_000);
    let latestAccurateLocation: CurrentLocation | null = null;
    let previousObservedLocation: (CurrentLocation & { observed_at: number }) | null = null;
    let lastGeolocationError: GeolocationPositionError | null = null;

    const failCollection = (message: string) => {
        if (requestId !== locationRequestId) return;

        locationRequestId += 1;
        stopLocationCollection();
        currentLocation.value = null;
        locationSamples.value = [];
        locationSampleProgress.value = 0;
        locationError.value = message;
        locating.value = false;
    };

    const completeCollection = () => {
        if (requestId !== locationRequestId || locationSamples.value.length < props.locationChallenge.min_samples) return;

        locationRequestId += 1;
        stopLocationCollection();
        currentLocation.value = {
            latitude: median(locationSamples.value.map((sample) => sample.latitude)),
            longitude: median(locationSamples.value.map((sample) => sample.longitude)),
            accuracy: Math.max(...locationSamples.value.map((sample) => sample.accuracy)),
        };
        locating.value = false;
    };

    const captureLatestSample = () => {
        if (requestId !== locationRequestId || !latestAccurateLocation) return;

        const lastCapturedAt = locationSamples.value.at(-1)?.captured_at ?? 0;
        locationSamples.value.push({
            ...latestAccurateLocation,
            captured_at: Math.max(Date.now(), lastCapturedAt + 1),
        });
        locationSampleProgress.value = Math.min(locationSamples.value.length, props.locationChallenge.min_samples);

        if (locationSamples.value.length >= props.locationChallenge.min_samples) completeCollection();
    };

    if (isAndroidAttendance()) {
        locationDeadlineTimer = window.setTimeout(
            () => {
                failCollection('Verifikasi GPS melewati batas waktu. Pastikan GPS aktif dan coba di area terbuka.');
            },
            Math.max(45_000, collectionMilliseconds + 30_000),
        );

        void (async () => {
            try {
                // Each sample is a new Android fix, including its mock flag and
                // provider timestamp. Do not resample a cached WebView position.
                while (requestId === locationRequestId && locationSamples.value.length < 20) {
                    const sample = await getAndroidAttendanceLocation();
                    if (requestId !== locationRequestId) return;
                    const previous = locationSamples.value.at(-1);
                    if (sample.accuracy <= props.locationChallenge.max_accuracy_meters && (!previous || sample.captured_at > previous.captured_at)) {
                        locationSamples.value.push(sample);
                        locationSampleProgress.value = Math.min(locationSamples.value.length, props.locationChallenge.min_samples);
                        const span = sample.captured_at - locationSamples.value[0].captured_at;
                        if (locationSamples.value.length >= props.locationChallenge.min_samples && span >= collectionMilliseconds) {
                            completeCollection();
                            return;
                        }
                    }
                    await new Promise((resolve) => window.setTimeout(resolve, Math.ceil(sampleIntervalMilliseconds)));
                }
                failCollection('GPS belum stabil. Aktifkan lokasi presisi dan ambil lokasi kembali.');
            } catch (error) {
                failCollection((error as Error)?.message || 'Pemeriksaan keamanan GPS gagal. Ambil lokasi kembali.');
            }
        })();
        return;
    }

    locationWatchId = navigator.geolocation.watchPosition(
        (position) => {
            if (requestId !== locationRequestId) return;

            const coordinates = position.coords as ExtendedGeolocationCoordinates;
            if (coordinates.mocked === true || coordinates.isMock === true || coordinates.isFromMockProvider === true) {
                failCollection('Perangkat melaporkan lokasi tiruan. Nonaktifkan aplikasi Fake GPS lalu coba kembali.');
                return;
            }

            const observedAt = Date.now();
            const observedLocation = {
                latitude: Number(coordinates.latitude.toFixed(7)),
                longitude: Number(coordinates.longitude.toFixed(7)),
                accuracy: Number(coordinates.accuracy.toFixed(1)),
                observed_at: observedAt,
            };

            if (previousObservedLocation) {
                const elapsedSeconds = (observedAt - previousObservedLocation.observed_at) / 1000;
                const uncertaintyMeters = previousObservedLocation.accuracy + observedLocation.accuracy;
                const credibleMovementMeters = Math.max(0, distanceBetween(previousObservedLocation, observedLocation) - uncertaintyMeters);

                if (elapsedSeconds > 0 && credibleMovementMeters / elapsedSeconds > props.locationChallenge.max_speed_meters_per_second) {
                    failCollection('Terdeteksi perpindahan GPS yang tidak wajar. Nonaktifkan aplikasi Fake GPS lalu ambil lokasi kembali.');
                    return;
                }
            }

            previousObservedLocation = observedLocation;
            lastGeolocationError = null;

            if (observedLocation.accuracy > props.locationChallenge.max_accuracy_meters) return;

            latestAccurateLocation = {
                latitude: observedLocation.latitude,
                longitude: observedLocation.longitude,
                accuracy: observedLocation.accuracy,
            };
            if (locationSamples.value.length === 0) {
                captureLatestSample();
                locationSampleTimer = window.setInterval(captureLatestSample, sampleIntervalMilliseconds);
            }
        },
        (error) => {
            if (requestId !== locationRequestId) return;
            if (error.code === error.PERMISSION_DENIED) {
                failCollection(locationErrorMessage(error));
                return;
            }

            lastGeolocationError = error;
        },
        {
            enableHighAccuracy: true,
            timeout: deadlineMilliseconds,
            maximumAge: 0,
        },
    );

    locationDeadlineTimer = window.setTimeout(() => {
        if (requestId !== locationRequestId) return;

        if (!latestAccurateLocation) {
            failCollection(
                lastGeolocationError
                    ? locationErrorMessage(lastGeolocationError)
                    : `Akurasi GPS belum mencapai ±${Math.round(props.locationChallenge.max_accuracy_meters)} m. Aktifkan akurasi tinggi lalu coba kembali.`,
            );
            return;
        }

        failCollection('Verifikasi GPS melewati batas waktu. Pastikan GPS aktif lalu coba kembali.');
    }, deadlineMilliseconds);
};

const retryLocation = () => {
    if (!locationProofError.value) {
        void requestLocation();
        return;
    }

    router.reload({
        only: ['locationChallenge'],
        onSuccess: () => {
            form.location_token = props.locationChallenge.token;
            void requestLocation();
        },
    });
};

const cameraErrorMessage = (error: unknown) => {
    if (!(error instanceof DOMException)) return 'Kamera belum berhasil dibuka. Silakan coba lagi.';
    if (error.name === 'NotAllowedError' || error.name === 'SecurityError') {
        return 'Izin kamera ditolak. Izinkan akses kamera pada browser lalu coba lagi.';
    }
    if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
        return 'Kamera tidak ditemukan pada perangkat ini.';
    }
    if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
        return 'Kamera sedang digunakan aplikasi lain atau tidak dapat diakses.';
    }

    return 'Kamera belum berhasil dibuka. Silakan coba lagi.';
};

const releaseCameraStream = () => {
    cameraStream.value?.getTracks().forEach((track) => track.stop());
    cameraStream.value = null;
    cameraActive.value = false;

    if (cameraVideo.value) cameraVideo.value.srcObject = null;
};

const stopCamera = () => {
    cameraRequestId += 1;
    cameraStarting.value = false;
    releaseCameraStream();
};

const startCamera = async () => {
    if (cameraStarting.value || cameraStream.value) return;

    cameraError.value = '';
    photoError.value = '';
    form.clearErrors('photo');

    if (!window.isSecureContext) {
        cameraError.value = 'Akses kamera memerlukan koneksi HTTPS yang aman atau localhost.';
        return;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
        cameraError.value = 'Browser ini tidak mendukung akses kamera langsung.';
        return;
    }

    const requestId = ++cameraRequestId;
    cameraStarting.value = true;

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'user' },
                width: { ideal: 1280 },
                height: { ideal: 1280 },
            },
        });

        if (requestId !== cameraRequestId || currentStep.value !== 2) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        cameraStream.value = stream;
        cameraActive.value = true;
        await nextTick();

        if (!cameraVideo.value) throw new Error('Elemen video kamera tidak tersedia.');
        cameraVideo.value.srcObject = stream;
        await cameraVideo.value.play();
    } catch (error) {
        if (requestId !== cameraRequestId) return;
        releaseCameraStream();
        cameraError.value = cameraErrorMessage(error);
    } finally {
        if (requestId === cameraRequestId) cameraStarting.value = false;
    }
};

const capturePhoto = async (): Promise<File | null> => {
    const video = cameraVideo.value;
    if (!video || video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || !video.videoWidth || !video.videoHeight) {
        cameraError.value = 'Kamera belum siap. Tunggu sebentar lalu coba lagi.';
        return null;
    }

    capturingPhoto.value = true;
    photoError.value = '';
    form.clearErrors();

    try {
        const maximumSide = 1024;
        const scale = Math.min(1, maximumSide / Math.max(video.videoWidth, video.videoHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);

        const context = canvas.getContext('2d');
        if (!context) throw new Error('Foto kamera tidak dapat diproses.');
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.86));
        if (!blob) throw new Error('Foto kamera tidak dapat diproses.');

        cameraError.value = '';
        return new File([blob], `${actionSlug.value}-${Date.now()}.jpg`, { type: 'image/jpeg' });
    } catch {
        photoError.value = 'Foto kamera gagal diambil. Silakan coba lagi.';
        return null;
    } finally {
        capturingPhoto.value = false;
    }
};

const openFaceVerification = () => {
    currentStep.value = 2;
    void startCamera();
};

const continueFromLocation = () => {
    if (!currentLocation.value || locationSamples.value.length < props.locationChallenge.min_samples) {
        void requestLocation();
        return;
    }

    if (isInsideRadius.value === false) {
        outsideNoteError.value = '';
        outsideDialogOpen.value = true;
        return;
    }

    openFaceVerification();
};

const continueOutsideRadius = () => {
    if (outsideNote.value.trim().length < 5) {
        outsideNoteError.value = 'Tuliskan alasan singkat minimal 5 karakter.';
        return;
    }

    outsideDialogOpen.value = false;
    openFaceVerification();
};

const checkLocationAgain = () => {
    outsideDialogOpen.value = false;
    void requestLocation();
};

const goBack = () => {
    if (form.processing || checkingLocation.value) return;
    if (currentStep.value === 2) {
        stopCamera();
        currentStep.value = 1;
        return;
    }

    router.visit(route('absensi.mobile.home'));
};

const returnToLocation = () => {
    if (form.processing || checkingLocation.value) return;
    stopCamera();
    currentStep.value = 1;
};

const submitClockIn = async () => {
    if (!currentLocation.value || form.processing || capturingPhoto.value || checkingLocation.value) return;

    if (!cameraActive.value) {
        cameraError.value = 'Kamera perlu aktif untuk mengambil foto saat verifikasi.';
        void startCamera();
        return;
    }

    const requestId = locationRequestId;
    const photo = await capturePhoto();
    if (!photo || requestId !== locationRequestId || currentStep.value !== 2 || !currentLocation.value) return;

    if (isAndroidAttendance()) {
        checkingLocation.value = true;
        try {
            const latest = await getAndroidAttendanceLocation();
            if (requestId !== locationRequestId || currentStep.value !== 2 || !currentLocation.value) return;
            if (
                latest.accuracy > props.locationChallenge.max_accuracy_meters ||
                latest.captured_at <= (locationSamples.value.at(-1)?.captured_at ?? 0) ||
                distanceBetween(currentLocation.value, latest) > currentLocation.value.accuracy + latest.accuracy + 25
            ) {
                throw new Error('Lokasi berubah atau GPS belum stabil. Ambil lokasi kembali sebelum presensi.');
            }
            locationSamples.value = [...locationSamples.value.slice(0, 19), latest];
        } catch (error) {
            if (requestId !== locationRequestId) return;
            stopCamera();
            currentStep.value = 1;
            currentLocation.value = null;
            locationSamples.value = [];
            locationSampleProgress.value = 0;
            form.photo = null;
            form.location_samples = [];
            locationError.value = (error as Error)?.message || 'Pemeriksaan keamanan GPS gagal. Ambil lokasi kembali.';
            return;
        } finally {
            checkingLocation.value = false;
        }
    }

    form.latitude = currentLocation.value.latitude;
    form.longitude = currentLocation.value.longitude;
    form.accuracy = currentLocation.value.accuracy;
    form.location_token = props.locationChallenge.token;
    form.location_samples = locationSamples.value;
    form.outside_radius_confirmed = isInsideRadius.value === false;
    form.outside_radius_note = isInsideRadius.value === false ? outsideNote.value.trim() : '';
    form.photo = photo;
    form.post(route(`absensi.mobile.${actionSlug.value}.store`), {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => {
            verificationElapsedSeconds.value = 0;
            verificationTimer = window.setInterval(() => {
                verificationElapsedSeconds.value += 1;
            }, 1000);
        },
        onError: () => {
            if (form.errors.outside_radius_confirmed || form.errors.outside_radius_note) {
                stopCamera();
                currentStep.value = 1;
                outsideNoteError.value = form.errors.outside_radius_note || form.errors.outside_radius_confirmed || '';
                outsideDialogOpen.value = true;
            } else if (
                locationProofError.value ||
                form.errors.location_token ||
                form.errors.location_samples ||
                form.errors.latitude ||
                form.errors.longitude ||
                form.errors.accuracy
            ) {
                stopCamera();
                currentStep.value = 1;
            }
        },
        onFinish: () => {
            if (verificationTimer !== null) window.clearInterval(verificationTimer);
            verificationTimer = null;
        },
    });
};

onMounted(() => void requestLocation());
onUnmounted(() => {
    locationRequestId += 1;
    stopLocationCollection();
    stopCamera();
    if (verificationTimer !== null) window.clearInterval(verificationTimer);
});
</script>

<template>
    <Head :title="actionTitle" />

    <main class="clock-workspace">
        <div class="clock-shell">
            <header class="clock-header">
                <div class="clock-topbar">
                    <button type="button" class="round-button" aria-label="Kembali" @click="goBack"><ArrowLeft /></button>
                    <div class="clock-title">
                        <span>RSARSY PRESENCE</span>
                        <h1>{{ actionTitle }}</h1>
                    </div>
                    <button
                        v-if="currentStep === 1"
                        type="button"
                        class="round-button"
                        :class="{ rotating: locating }"
                        aria-label="Perbarui lokasi"
                        :disabled="locating"
                        @click="retryLocation"
                    >
                        <RefreshCw />
                    </button>
                    <span v-else class="round-button passive" aria-hidden="true"><ShieldCheck /></span>
                </div>

                <div class="stepper" :aria-label="`Tahapan ${actionSlug}`">
                    <div class="step" :class="{ active: currentStep >= 1 }">
                        <span><Check v-if="currentStep > 1" /><MapPin v-else /></span>
                        <div><small>Tahap 1</small><strong>Validasi lokasi</strong></div>
                    </div>
                    <i :class="{ filled: currentStep > 1 }"></i>
                    <div class="step" :class="{ active: currentStep >= 2 }">
                        <span><Camera /></span>
                        <div><small>Tahap 2</small><strong>Verifikasi wajah</strong></div>
                    </div>
                </div>

                <article class="shift-card">
                    <span class="shift-icon"><Building2 /></span>
                    <div class="shift-copy">
                        <small>{{ schedule.shift_name || 'Jadwal hari ini' }}</small>
                        <strong>{{ schedule.start_time || '--:--' }} <i>—</i> {{ schedule.end_time || '--:--' }} WIB</strong>
                        <span>{{ schedule.date_label }}</span>
                    </div>
                    <span class="shift-live"><i></i> Hari ini</span>
                </article>
            </header>

            <Transition name="step" mode="out-in">
                <section v-if="currentStep === 1" key="location" class="location-stage">
                    <div class="location-banner" :class="[accuracyState.tone, { error: locationError || locationSubmissionError }]">
                        <span class="banner-icon">
                            <CircleAlert v-if="locationError || locationSubmissionError || accuracyState.tone === 'low'" />
                            <LoaderCircle v-else-if="locating || !currentLocation" class="spin" />
                            <BadgeCheck v-else />
                        </span>
                        <div>
                            <strong>{{ locationError || locationSubmissionError || accuracyState.label }}</strong>
                            <small v-if="!locationError && !locationSubmissionError">{{ accuracyState.detail }}</small>
                        </div>
                        <button v-if="locationError || locationSubmissionError || accuracyState.tone === 'low'" type="button" @click="retryLocation">
                            Coba lagi
                        </button>
                    </div>

                    <div class="map-card">
                        <ClockInMap :office="office" :current-location="currentLocation" :is-inside="isInsideRadius" :action-label="actionSlug" />
                        <div v-if="locating && !currentLocation" class="map-locating">
                            <div class="radar-loader"><span></span><LocateFixed /></div>
                            <strong>Mencari posisi Anda</strong>
                            <small>Pastikan GPS aktif dan izinkan akses lokasi</small>
                        </div>
                        <button type="button" class="recenter-button" :disabled="locating" @click="retryLocation">
                            <Crosshair /> <span>Lokasi saya</span>
                        </button>
                    </div>

                    <div class="location-summary" :class="{ inside: isInsideRadius, outside: isInsideRadius === false }">
                        <span class="summary-icon">
                            <CheckCircle2 v-if="isInsideRadius" />
                            <Navigation v-else-if="isInsideRadius === false" />
                            <MapPin v-else />
                        </span>
                        <div class="summary-main">
                            <small>{{
                                isInsideRadius === null ? 'Lokasi tujuan' : isInsideRadius ? 'Lokasi Anda sesuai' : 'Anda berada di luar radius'
                            }}</small>
                            <strong>{{ office.name }}</strong>
                            <span>{{ office.address || `Area presensi hingga ${office.radius_meters} meter` }}</span>
                        </div>
                        <div class="distance-chip">
                            <small>Jarak</small>
                            <strong>{{ formattedDistance }}</strong>
                        </div>
                    </div>

                    <div v-if="isInsideRadius === false" class="outside-radius-notice" role="alert">
                        <CircleAlert />
                        <span>
                            <strong>Presensi tetap dapat dilanjutkan.</strong>
                            Periksa posisi Anda dan isi catatan alasan di luar radius pada langkah berikutnya.
                        </span>
                    </div>

                    <div class="location-actions">
                        <div class="privacy-note"><ShieldCheck /> Koordinat hanya digunakan untuk validasi presensi.</div>
                        <button type="button" class="primary-action" :disabled="locating" @click="continueFromLocation">
                            <span>{{ locationActionLabel }}</span>
                            <ChevronRight />
                        </button>
                    </div>
                </section>

                <section v-else key="photo" class="photo-stage">
                    <div class="verification-intro">
                        <span><Sparkles /></span>
                        <div>
                            <small>LANGKAH TERAKHIR</small>
                            <h2>Pastikan wajah terlihat jelas</h2>
                            <p>Foto akan diambil otomatis saat Anda menekan tombol Verifikasi & {{ actionTitle }}.</p>
                        </div>
                    </div>

                    <div class="camera-card" :class="{ verifying: form.processing || capturingPhoto || checkingLocation }">
                        <div class="camera-grid" aria-hidden="true"></div>
                        <video v-if="cameraActive" ref="cameraVideo" autoplay muted playsinline aria-label="Pratinjau langsung kamera depan"></video>
                        <template v-else>
                            <span class="camera-orbit orbit-one"></span>
                            <span class="camera-orbit orbit-two"></span>
                            <div class="face-guide">
                                <div class="face-avatar">
                                    <img v-if="profilePhotoUrl" :src="profilePhotoUrl" alt="" />
                                    <span v-else>{{ initials }}</span>
                                </div>
                                <i class="corner top-left"></i><i class="corner top-right"></i> <i class="corner bottom-left"></i
                                ><i class="corner bottom-right"></i>
                            </div>
                            <strong>{{ cameraStarting ? 'Menyiapkan kamera depan...' : 'Posisikan wajah di dalam bingkai' }}</strong>
                            <small>{{
                                cameraStarting ? 'Izinkan akses kamera jika diminta oleh browser' : 'Kamera akan tampil langsung di area ini'
                            }}</small>
                        </template>
                        <div v-if="cameraActive" class="face-guide live-guide" aria-hidden="true">
                            <i class="corner top-left"></i><i class="corner top-right"></i> <i class="corner bottom-left"></i
                            ><i class="corner bottom-right"></i>
                        </div>
                        <span v-if="cameraActive" class="camera-live-status"><i></i> Siap mengambil foto</span>
                        <div v-if="form.processing || capturingPhoto || checkingLocation" class="verification-overlay" aria-live="polite">
                            <span><LoaderCircle class="spin" /></span>
                            <strong>{{ verificationButtonLabel }}</strong>
                            <small>Jangan tutup halaman ini</small>
                        </div>
                    </div>

                    <button v-if="cameraError && !cameraStarting" type="button" class="camera-retry" @click="startCamera">
                        <RefreshCw /> Aktifkan ulang kamera
                    </button>
                    <p v-if="cameraError || photoError || form.errors.photo" class="field-error">
                        <CircleAlert /> {{ cameraError || photoError || form.errors.photo }}
                    </p>

                    <div class="verification-facts">
                        <div>
                            <Focus /><span><strong>Wajah terlihat utuh</strong><small>Pastikan tidak buram atau terpotong</small></span>
                        </div>
                        <div>
                            <ShieldCheck /><span
                                ><strong>Dicocokkan dengan profil</strong><small>{{ actionTitle }} berhasil hanya jika wajah cocok</small></span
                            >
                        </div>
                    </div>

                    <div class="verified-location" :class="{ outside: isInsideRadius === false }">
                        <span><CheckCircle2 v-if="isInsideRadius" /><Info v-else /></span>
                        <div>
                            <small>{{ isInsideRadius ? 'Lokasi terverifikasi' : 'Lokasi dengan catatan' }}</small>
                            <strong>{{ office.name }} · {{ formattedDistance }}</strong>
                        </div>
                        <button type="button" @click="returnToLocation">Ubah</button>
                    </div>

                    <p v-if="globalFormError" class="submit-error"><CircleAlert /> {{ globalFormError }}</p>

                    <div class="photo-actions">
                        <button type="button" class="text-action" :disabled="form.processing || checkingLocation" @click="returnToLocation">
                            Kembali ke lokasi
                        </button>
                        <button
                            type="button"
                            class="primary-action"
                            :disabled="form.processing || capturingPhoto || checkingLocation || cameraStarting || !cameraActive"
                            @click="submitClockIn"
                        >
                            <LoaderCircle v-if="form.processing || capturingPhoto || checkingLocation" class="spin" />
                            <span>{{ verificationButtonLabel }}</span>
                            <Check v-if="!form.processing && !capturingPhoto && !checkingLocation" />
                        </button>
                        <p class="auto-capture-note"><Camera /> Kamera mengambil satu foto hanya saat tombol di atas ditekan.</p>
                    </div>
                </section>
            </Transition>

            <Transition name="sheet">
                <div v-if="outsideDialogOpen" class="sheet-overlay" role="presentation" @click.self="outsideDialogOpen = false">
                    <section class="outside-sheet" role="dialog" aria-modal="true" aria-labelledby="outside-title">
                        <span class="sheet-handle" aria-hidden="true"></span>
                        <button type="button" class="sheet-close" aria-label="Tutup" @click="outsideDialogOpen = false"><X /></button>

                        <div class="outside-visual" aria-hidden="true">
                            <span class="visual-ring ring-one"></span><span class="visual-ring ring-two"></span>
                            <div class="outside-pin"><MapPin /><i>!</i></div>
                        </div>

                        <span class="outside-eyebrow"><CircleAlert /> Perlu perhatian</span>
                        <h2 id="outside-title">Anda berada di luar radius</h2>
                        <p>
                            Posisi terdeteksi <strong>{{ formattedDistance }}</strong> dari {{ office.name }}, sedangkan batas {{ actionSlug }} adalah
                            <strong>{{ office.radius_meters }} m</strong>.
                        </p>

                        <div class="outside-info">
                            <Info />
                            <span>Anda tetap dapat melanjutkan. Catatan akan disertakan pada data presensi untuk ditinjau atasan.</span>
                        </div>

                        <label class="note-field" :class="{ invalid: outsideNoteError }">
                            <span>Alasan {{ actionSlug }} di luar area <i>*</i></span>
                            <textarea
                                v-model="outsideNote"
                                rows="3"
                                maxlength="500"
                                placeholder="Contoh: Sedang bertugas mendampingi pasien di luar gedung..."
                                @input="outsideNoteError = ''"
                            ></textarea>
                            <small
                                ><span>{{ outsideNote.length }}/500</span
                                >{{ outsideNoteError || 'Catatan wajib diisi agar dapat melanjutkan' }}</small
                            >
                        </label>

                        <button type="button" class="primary-action outside-continue" @click="continueOutsideRadius">
                            Tetap lanjutkan <ChevronRight />
                        </button>
                        <button type="button" class="check-again" @click="checkLocationAgain"><RefreshCw /> Cek kembali lokasi</button>
                    </section>
                </div>
            </Transition>
        </div>
    </main>
</template>

<style scoped>
.clock-workspace {
    --clock-ink: #173438;
    --clock-muted: #718184;
    --clock-teal: #087f78;
    --clock-deep: #0b393d;
    min-height: 100svh;
    color: var(--clock-ink);
    background:
        radial-gradient(circle at 8% 7%, rgba(58, 153, 139, 0.14), transparent 24rem),
        radial-gradient(circle at 93% 94%, rgba(214, 169, 93, 0.13), transparent 25rem), #e9f0ed;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}

.clock-shell {
    width: 100%;
    max-width: 500px;
    min-height: 100svh;
    margin: 0 auto;
    position: relative;
    overflow: hidden;
    background: #f8faf9;
    box-shadow: 0 0 80px rgba(18, 55, 54, 0.14);
}

.clock-header {
    padding: max(18px, env(safe-area-inset-top)) 17px 14px;
    position: relative;
    z-index: 10;
    border-bottom: 1px solid #e5ece9;
    background: rgba(248, 250, 249, 0.95);
    backdrop-filter: blur(18px);
}

.clock-topbar {
    display: grid;
    grid-template-columns: 42px 1fr 42px;
    align-items: center;
    gap: 10px;
}

.round-button {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border: 1px solid #dfe8e4;
    border-radius: 14px;
    color: #3b5b5f;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 7px 18px rgba(21, 59, 57, 0.06);
    transition: 160ms ease;
}

.round-button:hover:not(:disabled) {
    color: var(--clock-teal);
    border-color: #bddbd3;
    transform: translateY(-1px);
}

.round-button:disabled {
    cursor: wait;
    opacity: 0.65;
}

.round-button.passive {
    color: #a37b3f;
    background: #fff9ed;
}

.round-button svg {
    width: 18px;
    height: 18px;
    stroke-width: 2.15;
}

.clock-title {
    min-width: 0;
    text-align: center;
}

.clock-title span {
    display: block;
    color: #a27d45;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: 0.17em;
}

.clock-title h1 {
    margin: 1px 0 0;
    color: #153438;
    font-size: 20px;
    font-weight: 850;
    letter-spacing: -0.04em;
}

.stepper {
    margin: 18px 2px 15px;
    display: grid;
    grid-template-columns: 1fr 37px 1fr;
    align-items: center;
}

.step {
    display: flex;
    align-items: center;
    gap: 8px;
    opacity: 0.47;
    transition: 180ms ease;
}

.step:last-child {
    justify-content: flex-end;
}

.step.active {
    opacity: 1;
}

.step > span {
    width: 30px;
    height: 30px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid #dce6e2;
    border-radius: 10px;
    color: #73888a;
    background: #fff;
}

.step.active > span {
    border-color: #b8ded5;
    color: #087f78;
    background: #e9f7f2;
}

.step svg {
    width: 14px;
    height: 14px;
}

.step div {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.step small {
    color: #9aa7a8;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}

.step strong {
    margin-top: 1px;
    overflow: hidden;
    font-size: 13px;
    font-weight: 780;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.stepper > i {
    height: 1px;
    margin: 0 7px;
    position: relative;
    overflow: hidden;
    background: #dbe5e1;
}

.stepper > i::after {
    content: '';
    position: absolute;
    inset: 0;
    background: #159784;
    transform: translateX(-100%);
    transition: 280ms ease;
}

.stepper > i.filled::after {
    transform: translateX(0);
}

.shift-card {
    padding: 12px 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    overflow: hidden;
    position: relative;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 18px;
    color: #f6fffc;
    background: linear-gradient(135deg, #0c393d, #145b58 72%, #1c6d65);
    box-shadow: 0 12px 28px rgba(8, 61, 59, 0.14);
}

.shift-card::after {
    content: '';
    width: 105px;
    height: 105px;
    position: absolute;
    right: -43px;
    bottom: -68px;
    border-radius: 50%;
    background: rgba(225, 189, 126, 0.13);
}

.shift-icon {
    width: 38px;
    height: 38px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.11);
    border-radius: 12px;
    color: #eacb94;
    background: rgba(3, 33, 35, 0.22);
}

.shift-icon svg {
    width: 17px;
    height: 17px;
}

.shift-copy {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.shift-copy small {
    overflow: hidden;
    color: #9fcfc5;
    font-size: 11px;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.shift-copy strong {
    margin-top: 1px;
    color: #fff;
    font-size: 15px;
    font-weight: 820;
}

.shift-copy strong i {
    color: #d8b676;
    font-style: normal;
    font-weight: 500;
}

.shift-copy > span {
    margin-top: 1px;
    color: rgba(221, 241, 235, 0.6);
    font-size: 11px;
    font-weight: 600;
}

.shift-live {
    padding: 5px 8px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    position: relative;
    z-index: 1;
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 999px;
    color: rgba(239, 255, 250, 0.77);
    background: rgba(4, 35, 36, 0.2);
    font-size: 11px;
    font-weight: 750;
    white-space: nowrap;
}

.shift-live i {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #70d4b6;
    box-shadow: 0 0 0 3px rgba(112, 212, 182, 0.12);
}

.location-stage,
.photo-stage {
    padding: 13px 15px max(18px, env(safe-area-inset-bottom));
}

.location-banner {
    min-height: 52px;
    padding: 9px 10px;
    display: flex;
    align-items: center;
    gap: 9px;
    border: 1px solid #dfeae6;
    border-radius: 16px;
    color: #365c5c;
    background: #fff;
    box-shadow: 0 8px 21px rgba(28, 66, 62, 0.05);
}

.banner-icon {
    width: 33px;
    height: 33px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 11px;
    color: #718583;
    background: #eff4f2;
}

.location-banner.excellent .banner-icon,
.location-banner.good .banner-icon {
    color: #087f78;
    background: #e5f5ef;
}

.location-banner.low,
.location-banner.error {
    border-color: #eee0bb;
    color: #765d32;
    background: #fffaf0;
}

.location-banner.low .banner-icon,
.location-banner.error .banner-icon {
    color: #ba7b20;
    background: #fff0cc;
}

.banner-icon svg {
    width: 16px;
    height: 16px;
}

.location-banner > div {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.location-banner strong {
    font-size: 12px;
    font-weight: 800;
    line-height: 1.35;
}

.location-banner small {
    margin-top: 1px;
    color: #8a9999;
    font-size: 11px;
}

.location-banner button {
    padding: 7px 9px;
    flex: 0 0 auto;
    border: 0;
    border-radius: 9px;
    color: #8a611d;
    background: #f9e9bf;
    font-size: 11px;
    font-weight: 800;
}

.map-card {
    height: clamp(360px, 47svh, 520px);
    min-height: 360px;
    margin-top: 11px;
    position: relative;
    overflow: hidden;
    border: 1px solid #dce7e3;
    border-radius: 24px;
    background: #e7efec;
    box-shadow: 0 15px 32px rgba(21, 59, 56, 0.1);
}

.map-locating {
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    z-index: 600;
    inset: 0;
    flex-direction: column;
    color: #345958;
    background: rgba(237, 244, 241, 0.88);
    backdrop-filter: blur(4px);
}

.radar-loader {
    width: 78px;
    height: 78px;
    display: grid;
    place-items: center;
    position: relative;
    border: 1px solid rgba(11, 128, 118, 0.2);
    border-radius: 50%;
    color: #087f78;
    background: rgba(255, 255, 255, 0.76);
}

.radar-loader::before,
.radar-loader span {
    content: '';
    position: absolute;
    border: 1px solid rgba(11, 128, 118, 0.19);
    border-radius: 50%;
    animation: radar 1.8s infinite ease-out;
}

.radar-loader::before {
    inset: -14px;
}

.radar-loader span {
    inset: 6px;
    animation-delay: 0.4s;
}

.radar-loader svg {
    width: 25px;
    height: 25px;
}

.map-locating strong {
    margin-top: 25px;
    font-size: 15px;
    font-weight: 820;
}

.map-locating small {
    margin-top: 4px;
    color: #849491;
    font-size: 11px;
}

.recenter-button {
    min-height: 35px;
    padding: 0 11px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    position: absolute;
    z-index: 500;
    top: 14px;
    right: 14px;
    border: 1px solid rgba(255, 255, 255, 0.86);
    border-radius: 11px;
    color: #245c5a;
    background: rgba(255, 255, 255, 0.93);
    box-shadow: 0 8px 22px rgba(18, 53, 52, 0.13);
    font-size: 11px;
    font-weight: 780;
    backdrop-filter: blur(10px);
}

.recenter-button:disabled {
    opacity: 0.65;
}

.recenter-button svg {
    width: 14px;
    height: 14px;
}

.location-summary {
    margin: -23px 10px 0;
    padding: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    position: relative;
    z-index: 700;
    border: 1px solid rgba(255, 255, 255, 0.9);
    border-radius: 19px;
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 13px 31px rgba(21, 57, 54, 0.14);
    backdrop-filter: blur(14px);
}

.summary-icon {
    width: 37px;
    height: 37px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 12px;
    color: #718381;
    background: #eef3f1;
}

.location-summary.inside .summary-icon {
    color: #087f78;
    background: #e3f4ee;
}

.location-summary.outside .summary-icon {
    color: #c15d4e;
    background: #fff0ec;
}

.summary-icon svg {
    width: 17px;
    height: 17px;
}

.summary-main {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.summary-main small {
    color: #8a9998;
    font-size: 11px;
    font-weight: 700;
}

.location-summary.inside .summary-main small {
    color: #198878;
}

.location-summary.outside .summary-main small {
    color: #bd6255;
}

.summary-main strong {
    margin-top: 1px;
    overflow: hidden;
    font-size: 13px;
    font-weight: 830;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.summary-main span {
    margin-top: 2px;
    overflow: hidden;
    color: #94a09f;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.distance-chip {
    min-width: 62px;
    padding: 7px 8px;
    display: flex;
    flex: 0 0 auto;
    align-items: flex-end;
    flex-direction: column;
    border-radius: 10px;
    background: #f5f8f7;
}

.distance-chip small {
    color: #97a3a1;
    font-size: 11px;
}

.distance-chip strong {
    margin-top: 1px;
    color: #3d5b5c;
    font-size: 12px;
    font-weight: 830;
}

.outside-radius-notice {
    margin: 12px 10px 0;
    padding: 11px 12px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
    border: 1px solid #eed9b7;
    border-radius: 13px;
    color: #755f39;
    background: #fff9ed;
    font-size: 11px;
    line-height: 1.55;
}

.outside-radius-notice svg {
    width: 15px;
    height: 15px;
    flex: 0 0 auto;
    margin-top: 1px;
    color: #b67a24;
}

.outside-radius-notice strong {
    color: #684e25;
    font-weight: 820;
}

.location-actions,
.photo-actions {
    margin-top: 14px;
}

.privacy-note {
    margin-bottom: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    color: #879795;
    font-size: 11px;
    font-weight: 600;
}

.privacy-note svg {
    width: 11px;
    height: 11px;
    color: #6e9b91;
}

.primary-action {
    width: 100%;
    min-height: 53px;
    padding: 0 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 17px;
    color: #fff;
    background: linear-gradient(135deg, #0a7772, #07524f);
    box-shadow: 0 13px 25px rgba(6, 92, 87, 0.22);
    font-size: 13px;
    font-weight: 820;
    transition: 170ms ease;
}

.primary-action:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 16px 29px rgba(6, 92, 87, 0.27);
}

.primary-action:disabled {
    cursor: not-allowed;
    opacity: 0.52;
    box-shadow: none;
}

.primary-action svg {
    width: 16px;
    height: 16px;
}

.verification-intro {
    padding: 5px 3px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.verification-intro > span {
    width: 34px;
    height: 34px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 11px;
    color: #a77935;
    background: #fff3da;
}

.verification-intro svg {
    width: 16px;
    height: 16px;
}

.verification-intro small {
    color: #a67d42;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: 0.13em;
}

.verification-intro h2 {
    margin: 2px 0 0;
    font-size: 17px;
    font-weight: 850;
    letter-spacing: -0.035em;
}

.verification-intro p {
    margin: 4px 0 0;
    color: #7d8c8c;
    font-size: 11px;
    line-height: 1.55;
}

.camera-card {
    height: clamp(330px, 43svh, 465px);
    min-height: 330px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    flex-direction: column;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 27px;
    color: #effcf8;
    background: radial-gradient(circle at 50% 48%, rgba(58, 156, 139, 0.28), transparent 42%), linear-gradient(145deg, #0b3035, #0e4e4e 64%, #17635d);
    box-shadow: 0 19px 39px rgba(10, 55, 54, 0.18);
}

.camera-card > video {
    width: 100%;
    height: 100%;
    position: absolute;
    inset: 0;
    object-fit: cover;
    transform: scaleX(-1);
}

.camera-grid {
    position: absolute;
    z-index: 1;
    inset: 0;
    opacity: 0.16;
    pointer-events: none;
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
    background-size: 40px 40px;
    mask-image: linear-gradient(to bottom, #000, transparent 86%);
}

.camera-orbit {
    position: absolute;
    border: 1px solid rgba(132, 221, 197, 0.16);
    border-radius: 50%;
}

.camera-orbit.orbit-one {
    width: 310px;
    height: 310px;
}

.camera-orbit.orbit-two {
    width: 250px;
    height: 250px;
}

.face-guide {
    width: 188px;
    height: 224px;
    display: grid;
    place-items: center;
    position: relative;
    z-index: 1;
}

.face-guide.live-guide {
    position: absolute;
    z-index: 2;
    top: 50%;
    left: 50%;
    pointer-events: none;
    transform: translate(-50%, -50%);
}

.face-avatar {
    width: 128px;
    height: 158px;
    display: grid;
    place-items: center;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 64px 64px 54px 54px;
    color: #fff;
    background: linear-gradient(145deg, rgba(95, 188, 168, 0.4), rgba(5, 43, 46, 0.45));
    box-shadow:
        inset 0 0 0 7px rgba(255, 255, 255, 0.035),
        0 17px 35px rgba(2, 30, 32, 0.2);
    font-size: 23px;
    font-weight: 850;
    letter-spacing: 0.04em;
}

.face-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.corner {
    width: 32px;
    height: 32px;
    position: absolute;
    border-color: #ddb977;
    border-style: solid;
}

.corner.top-left {
    top: 0;
    left: 0;
    border-width: 2px 0 0 2px;
    border-radius: 13px 0 0;
}

.corner.top-right {
    top: 0;
    right: 0;
    border-width: 2px 2px 0 0;
    border-radius: 0 13px 0 0;
}

.corner.bottom-left {
    bottom: 0;
    left: 0;
    border-width: 0 0 2px 2px;
    border-radius: 0 0 0 13px;
}

.corner.bottom-right {
    right: 0;
    bottom: 0;
    border-width: 0 2px 2px 0;
    border-radius: 0 0 13px;
}

.camera-card > strong {
    position: relative;
    z-index: 1;
    font-size: 13px;
    font-weight: 800;
}

.camera-card > small {
    margin-top: 4px;
    position: relative;
    z-index: 1;
    color: rgba(221, 243, 237, 0.6);
    font-size: 11px;
}

.camera-live-status {
    padding: 7px 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    position: absolute;
    z-index: 2;
    top: 14px;
    left: 14px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 999px;
    color: #f6fffc;
    background: rgba(7, 69, 64, 0.72);
    font-size: 11px;
    font-weight: 800;
    backdrop-filter: blur(10px);
}

.camera-live-status i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #72e6bb;
    box-shadow: 0 0 0 4px rgba(114, 230, 187, 0.14);
}

.verification-overlay {
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    z-index: 4;
    inset: 0;
    flex-direction: column;
    color: #f6fffc;
    background: rgba(4, 36, 39, 0.74);
    backdrop-filter: blur(8px);
}

.verification-overlay > span {
    width: 54px;
    height: 54px;
    display: grid;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 18px;
    color: #f0c77d;
    background: rgba(255, 255, 255, 0.1);
    box-shadow: 0 16px 34px rgba(0, 20, 22, 0.2);
}

.verification-overlay svg {
    width: 23px;
    height: 23px;
}

.verification-overlay strong {
    margin-top: 13px;
    font-size: 13px;
    font-weight: 820;
}

.verification-overlay small {
    margin-top: 4px;
    color: rgba(230, 246, 241, 0.68);
    font-size: 11px;
}

.camera-retry {
    min-height: 34px;
    margin: 10px auto 0;
    padding: 0 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 1px solid #d9e5e1;
    border-radius: 11px;
    color: #35625f;
    background: #fff;
    font-size: 11px;
    font-weight: 780;
}

.camera-retry svg {
    width: 12px;
    height: 12px;
}

.field-error,
.submit-error {
    margin: 9px 0 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    color: #bd584c;
    font-size: 11px;
    font-weight: 700;
}

.field-error svg,
.submit-error svg {
    width: 12px;
    height: 12px;
}

.verification-facts {
    margin-top: 14px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.verification-facts > div {
    min-width: 0;
    padding: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #e3ebe8;
    border-radius: 13px;
    background: #fff;
}

.verification-facts > div > svg {
    width: 15px;
    height: 15px;
    flex: 0 0 auto;
    color: #168778;
}

.verification-facts span {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.verification-facts strong {
    font-size: 12px;
    font-weight: 800;
}

.verification-facts small {
    margin-top: 2px;
    overflow: hidden;
    color: #98a3a1;
    font-size: 11px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.verified-location {
    margin-top: 9px;
    padding: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #dceae5;
    border-radius: 13px;
    background: #edf8f4;
}

.verified-location.outside {
    border-color: #eee1c5;
    background: #fff9ed;
}

.verified-location > span {
    width: 28px;
    height: 28px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 9px;
    color: #0a8076;
    background: #d9efe8;
}

.verified-location.outside > span {
    color: #a36e25;
    background: #f8e9c9;
}

.verified-location svg {
    width: 14px;
    height: 14px;
}

.verified-location div {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.verified-location small {
    color: #7f9490;
    font-size: 11px;
}

.verified-location strong {
    margin-top: 1px;
    overflow: hidden;
    font-size: 12px;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.verified-location button {
    padding: 6px 8px;
    border: 0;
    border-radius: 8px;
    color: #14776e;
    background: rgba(255, 255, 255, 0.74);
    font-size: 11px;
    font-weight: 800;
}

.photo-actions {
    padding-bottom: max(2px, env(safe-area-inset-bottom));
}

.text-action {
    width: 100%;
    margin-bottom: 8px;
    border: 0;
    color: #738482;
    background: transparent;
    font-size: 11px;
    font-weight: 700;
}

.auto-capture-note {
    margin: 9px 0 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    color: #879693;
    font-size: 11px;
    font-weight: 650;
}

.auto-capture-note svg {
    width: 10px;
    height: 10px;
    color: #5f9188;
}

.sheet-overlay {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    position: fixed;
    z-index: 2000;
    inset: 0;
    padding-top: 60px;
    background: rgba(8, 30, 32, 0.58);
    backdrop-filter: blur(5px);
}

.outside-sheet {
    width: min(500px, 100%);
    max-height: calc(100svh - 38px);
    padding: 12px 22px max(20px, env(safe-area-inset-bottom));
    overflow-y: auto;
    position: relative;
    border: 1px solid rgba(255, 255, 255, 0.9);
    border-radius: 31px 31px 0 0;
    background: #fff;
    box-shadow: 0 -24px 60px rgba(8, 32, 34, 0.25);
}

.sheet-handle {
    width: 43px;
    height: 4px;
    margin: 0 auto;
    display: block;
    border-radius: 999px;
    background: #dce4e1;
}

.sheet-close {
    width: 35px;
    height: 35px;
    display: grid;
    place-items: center;
    position: absolute;
    top: 17px;
    right: 18px;
    border: 1px solid #e6ece9;
    border-radius: 11px;
    color: #687c7d;
    background: #f8faf9;
}

.sheet-close svg {
    width: 16px;
    height: 16px;
}

.outside-visual {
    width: 130px;
    height: 118px;
    margin: 12px auto 1px;
    display: grid;
    place-items: center;
    position: relative;
}

.visual-ring {
    position: absolute;
    border: 1px solid rgba(222, 104, 86, 0.16);
    border-radius: 50%;
}

.visual-ring.ring-one {
    width: 107px;
    height: 107px;
    background: rgba(255, 237, 232, 0.55);
}

.visual-ring.ring-two {
    width: 77px;
    height: 77px;
    background: rgba(255, 255, 255, 0.75);
}

.outside-pin {
    width: 57px;
    height: 57px;
    display: grid;
    place-items: center;
    position: relative;
    z-index: 1;
    border-radius: 20px 20px 20px 7px;
    color: #fff;
    background: linear-gradient(145deg, #ed8a72, #cf4d47);
    box-shadow: 0 14px 27px rgba(181, 72, 61, 0.25);
    transform: rotate(45deg);
}

.outside-pin svg {
    width: 25px;
    height: 25px;
    transform: rotate(-45deg);
}

.outside-pin i {
    width: 22px;
    height: 22px;
    display: grid;
    place-items: center;
    position: absolute;
    top: -8px;
    right: -8px;
    border: 3px solid #fff;
    border-radius: 50%;
    color: #c64741;
    background: #fff1ed;
    box-shadow: 0 4px 10px rgba(115, 42, 38, 0.13);
    font-size: 13px;
    font-style: normal;
    font-weight: 900;
    transform: rotate(-45deg);
}

.outside-eyebrow {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    color: #c16051;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}

.outside-eyebrow svg {
    width: 12px;
    height: 12px;
}

.outside-sheet h2 {
    margin: 5px 0 0;
    color: #213a3d;
    font-size: 22px;
    font-weight: 880;
    letter-spacing: -0.045em;
    text-align: center;
}

.outside-sheet > p {
    max-width: 400px;
    margin: 9px auto 0;
    color: #718182;
    font-size: 11px;
    line-height: 1.65;
    text-align: center;
}

.outside-sheet > p strong {
    color: #405c5d;
    font-weight: 800;
}

.outside-info {
    margin-top: 15px;
    padding: 11px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border: 1px solid #eee2c6;
    border-radius: 13px;
    color: #755f39;
    background: #fff9ed;
    font-size: 11px;
    line-height: 1.55;
}

.outside-info svg {
    width: 14px;
    height: 14px;
    flex: 0 0 auto;
    margin-top: 1px;
    color: #ae7a2b;
}

.note-field {
    margin-top: 13px;
    display: block;
}

.note-field > span {
    color: #435d5e;
    font-size: 11px;
    font-weight: 800;
}

.note-field > span i {
    color: #d35e50;
    font-style: normal;
}

.note-field textarea {
    width: 100%;
    min-height: 82px;
    margin-top: 7px;
    padding: 11px 12px;
    resize: none;
    border: 1px solid #dfe8e4;
    border-radius: 13px;
    outline: none;
    color: #294849;
    background: #fafcfb;
    box-shadow: none;
    font-family: inherit;
    font-size: 16px;
    line-height: 1.55;
    transition: 150ms ease;
}

.note-field textarea:focus {
    border-color: #7ec0b4;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(15, 132, 120, 0.08);
}

.note-field.invalid textarea {
    border-color: #e3a095;
}

.note-field > small {
    margin-top: 5px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    color: #9aa5a3;
    font-size: 11px;
}

.note-field.invalid > small {
    color: #bd584b;
}

.note-field > small span {
    order: 2;
    color: #a5afad;
}

.outside-continue {
    margin-top: 13px;
}

.check-again {
    width: 100%;
    min-height: 40px;
    margin-top: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 0;
    color: #5f7575;
    background: transparent;
    font-size: 11px;
    font-weight: 750;
}

.check-again svg {
    width: 13px;
    height: 13px;
}

.spin {
    animation: spin 0.8s linear infinite;
}

.round-button.rotating svg {
    animation: spin 0.85s linear infinite;
}

.step-enter-active,
.step-leave-active {
    transition: 180ms ease;
}

.step-enter-from {
    opacity: 0;
    transform: translateX(12px);
}

.step-leave-to {
    opacity: 0;
    transform: translateX(-12px);
}

.sheet-enter-active,
.sheet-leave-active {
    transition: 220ms ease;
}

.sheet-enter-active .outside-sheet,
.sheet-leave-active .outside-sheet {
    transition: 260ms cubic-bezier(0.22, 0.74, 0.24, 1);
}

.sheet-enter-from,
.sheet-leave-to {
    opacity: 0;
}

.sheet-enter-from .outside-sheet,
.sheet-leave-to .outside-sheet {
    transform: translateY(100%);
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

@keyframes radar {
    0% {
        opacity: 0.9;
        transform: scale(0.66);
    }
    100% {
        opacity: 0;
        transform: scale(1.22);
    }
}

@media (max-width: 420px) {
    .step {
        align-items: flex-start;
    }

    .step strong {
        overflow: visible;
        line-height: 1.2;
        text-overflow: clip;
        white-space: normal;
    }

    .verification-facts {
        grid-template-columns: 1fr;
    }

    .verification-facts small {
        overflow: visible;
        text-overflow: clip;
        white-space: normal;
    }
}

@media (min-width: 700px) {
    .clock-shell {
        margin-block: 22px;
        min-height: calc(100svh - 44px);
        border: 1px solid rgba(255, 255, 255, 0.75);
        border-radius: 32px;
    }

    .sheet-overlay {
        align-items: center;
        padding: 24px;
    }

    .outside-sheet {
        border-radius: 31px;
    }
}

@media (max-height: 720px) {
    .map-card {
        height: 345px;
        min-height: 345px;
    }

    .camera-card {
        height: 315px;
        min-height: 315px;
    }
}
</style>
