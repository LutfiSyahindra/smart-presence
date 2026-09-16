<script setup lang="ts">
import { AlarmClock, ExternalLink, Eye, ImageOff, MapPin } from 'lucide-vue-next';
import { computed } from 'vue';

interface LocationData {
    name: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    map_url: string | null;
}

interface ClockData {
    time: string | null;
    date_time: string | null;
    location: LocationData | null;
    photo_url: string | null;
}

const props = defineProps<{
    type: 'in' | 'out';
    clock: ClockData;
    employee: string;
    empty?: boolean;
    lateMinutes?: number;
}>();

const emit = defineEmits<{
    preview: [payload: { url: string; label: string }];
}>();

const locationLabel = (location: LocationData) => location.name || location.address || 'Lokasi presensi';
const hasClockData = computed(() => Boolean(props.clock.time || props.clock.location || props.clock.photo_url));

const showPhoto = () => {
    if (!props.clock.photo_url) return;
    emit('preview', {
        url: props.clock.photo_url,
        label: `${props.type === 'in' ? 'Clock-in' : 'Clock-out'} · ${props.employee}`,
    });
};
</script>

<template>
    <span v-if="empty || !hasClockData" class="empty-presensi">-</span>
    <div v-else class="clock-detail" :class="type">
        <button
            type="button"
            class="face-photo"
            :class="{ empty: !clock.photo_url }"
            :title="clock.photo_url ? 'Lihat foto wajah' : 'Foto tidak tersedia'"
            :disabled="!clock.photo_url"
            @click="showPhoto"
        >
            <template v-if="clock.photo_url">
                <img :src="clock.photo_url" :alt="`Foto ${type} ${employee}`" />
                <Eye aria-hidden="true" />
            </template>
            <ImageOff v-else aria-hidden="true" />
        </button>
        <div>
            <strong>{{ clock.time || '--:--' }}</strong>
            <small v-if="type === 'in' && lateMinutes && lateMinutes > 0" class="late-indicator">
                <AlarmClock aria-hidden="true" />Terlambat {{ lateMinutes }} menit
            </small>
            <a
                v-if="clock.location?.map_url"
                :href="clock.location.map_url"
                target="_blank"
                rel="noopener"
                :title="clock.location.address || clock.location.name || undefined"
            >
                <MapPin aria-hidden="true" />{{ locationLabel(clock.location) }}<ExternalLink class="external-icon" />
            </a>
            <span v-else-if="clock.location" :title="clock.location.address || clock.location.name || undefined">
                <MapPin aria-hidden="true" />{{ locationLabel(clock.location) }}
            </span>
            <small v-else>Lokasi belum tersedia</small>
        </div>
    </div>
</template>
