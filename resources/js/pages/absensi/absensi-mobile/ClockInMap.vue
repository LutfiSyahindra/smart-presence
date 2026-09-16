<script setup lang="ts">
import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Coordinates {
    latitude: number;
    longitude: number;
    accuracy: number;
}

const props = defineProps<{
    office: {
        name: string;
        latitude: number;
        longitude: number;
        radius_meters: number;
    };
    currentLocation: Coordinates | null;
    isInside: boolean | null;
    actionLabel?: string;
}>();

const mapElement = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let accuracyCircle: L.Circle | null = null;
let userMarker: L.Marker | null = null;
let connectingLine: L.Polyline | null = null;
let resizeObserver: ResizeObserver | null = null;

const officeIcon = L.divIcon({
    className: 'clock-map-marker-shell',
    html: '<span class="office-pin"><span></span></span>',
    iconSize: [44, 50],
    iconAnchor: [22, 46],
});

const userIcon = (inside: boolean | null) =>
    L.divIcon({
        className: 'clock-map-marker-shell',
        html: `<span class="user-pin ${inside === false ? 'outside' : ''}"><span class="user-pulse"></span><span class="user-core"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg></span></span>`,
        iconSize: [52, 52],
        iconAnchor: [26, 26],
    });

const updateUserLayer = (reframe = true) => {
    if (!map) return;

    if (!props.currentLocation) {
        accuracyCircle?.remove();
        userMarker?.remove();
        connectingLine?.remove();
        accuracyCircle = null;
        userMarker = null;
        connectingLine = null;
        return;
    }

    const officePoint = L.latLng(props.office.latitude, props.office.longitude);
    const userPoint = L.latLng(props.currentLocation.latitude, props.currentLocation.longitude);
    const stateColor = props.isInside === false ? '#df6c59' : '#0a8a79';

    if (!userMarker) {
        userMarker = L.marker(userPoint, { icon: userIcon(props.isInside), title: 'Lokasi Anda saat ini', zIndexOffset: 1000 }).addTo(map);
    } else {
        userMarker.setLatLng(userPoint).setIcon(userIcon(props.isInside));
    }

    if (!accuracyCircle) {
        accuracyCircle = L.circle(userPoint, {
            radius: Math.max(props.currentLocation.accuracy, 4),
            color: stateColor,
            fillColor: stateColor,
            fillOpacity: 0.08,
            opacity: 0.42,
            weight: 1,
            interactive: false,
        }).addTo(map);
    } else {
        accuracyCircle
            .setLatLng(userPoint)
            .setRadius(Math.max(props.currentLocation.accuracy, 4))
            .setStyle({ color: stateColor, fillColor: stateColor });
    }

    if (!connectingLine) {
        connectingLine = L.polyline([officePoint, userPoint], {
            color: stateColor,
            opacity: 0.58,
            weight: 2,
            dashArray: '5 8',
            interactive: false,
        }).addTo(map);
    } else {
        connectingLine.setLatLngs([officePoint, userPoint]).setStyle({ color: stateColor });
    }

    if (reframe) {
        const bounds = L.latLngBounds([officePoint, userPoint]).pad(0.28);
        map.fitBounds(bounds, { animate: true, duration: 0.55, maxZoom: 18, padding: [50, 50] });
    }
};

onMounted(async () => {
    await nextTick();
    if (!mapElement.value) return;

    const officePoint = L.latLng(props.office.latitude, props.office.longitude);
    map = L.map(mapElement.value, {
        attributionControl: true,
        zoomControl: false,
        minZoom: 3,
        maxZoom: 19,
    }).setView(officePoint, 17);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.circle(officePoint, {
        radius: props.office.radius_meters,
        color: '#0b8076',
        fillColor: '#28a88f',
        fillOpacity: 0.12,
        opacity: 0.75,
        weight: 2,
        dashArray: '5 6',
    }).addTo(map);
    L.marker(officePoint, { icon: officeIcon, title: props.office.name, zIndexOffset: 500 }).addTo(map);
    updateUserLayer(false);

    resizeObserver = new ResizeObserver(() => map?.invalidateSize());
    resizeObserver.observe(mapElement.value);
    window.setTimeout(() => map?.invalidateSize(), 200);
});

watch(
    () => [props.currentLocation?.latitude, props.currentLocation?.longitude, props.currentLocation?.accuracy, props.isInside],
    () => updateUserLayer(),
);

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map?.remove();
    map = null;
});
</script>

<template>
    <div class="clock-map-wrap">
        <div ref="mapElement" class="clock-map" :aria-label="`Peta lokasi ${actionLabel || 'clock-in'} dan radius kantor`"></div>
        <div class="radius-legend"><i></i> Area {{ actionLabel || 'clock-in' }} {{ office.radius_meters }} m</div>
    </div>
</template>

<style scoped>
.clock-map-wrap {
    height: 100%;
    min-height: 410px;
    position: relative;
    overflow: hidden;
    background: #e9f0ed;
}

.clock-map {
    width: 100%;
    height: 100%;
    min-height: 410px;
    z-index: 1;
}

.radius-legend {
    min-height: 34px;
    padding: 0 12px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    position: absolute;
    z-index: 500;
    left: 14px;
    bottom: 15px;
    border: 1px solid rgba(255, 255, 255, 0.82);
    border-radius: 999px;
    color: #315e5c;
    background: rgba(255, 255, 255, 0.91);
    box-shadow: 0 8px 25px rgba(18, 53, 52, 0.13);
    font-size: 10px;
    font-weight: 750;
    backdrop-filter: blur(12px);
}

.radius-legend i {
    width: 8px;
    height: 8px;
    border: 2px solid rgba(10, 138, 121, 0.42);
    border-radius: 999px;
    background: rgba(40, 168, 143, 0.18);
}

:deep(.clock-map-marker-shell) {
    border: 0;
    background: transparent;
}

:deep(.office-pin) {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    position: relative;
    border: 4px solid #fff;
    border-radius: 15px 15px 15px 5px;
    background: linear-gradient(145deg, #0a8279, #0d4e52);
    box-shadow: 0 11px 25px rgba(9, 83, 79, 0.35);
    transform: rotate(45deg);
}

:deep(.office-pin span) {
    width: 13px;
    height: 13px;
    border: 3px solid rgba(255, 255, 255, 0.92);
    border-radius: 50%;
}

:deep(.user-pin) {
    width: 52px;
    height: 52px;
    display: grid;
    place-items: center;
    position: relative;
}

:deep(.user-pulse) {
    position: absolute;
    inset: 3px;
    border: 1px solid rgba(10, 138, 121, 0.38);
    border-radius: 999px;
    background: rgba(10, 138, 121, 0.14);
    animation: location-pulse 2s infinite ease-out;
}

:deep(.user-core) {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    position: relative;
    border: 4px solid #fff;
    border-radius: 999px;
    color: #fff;
    background: linear-gradient(145deg, #159d89, #0a6f69);
    box-shadow: 0 8px 22px rgba(10, 91, 85, 0.35);
}

:deep(.user-core svg) {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 2;
}

:deep(.user-pin.outside .user-pulse) {
    border-color: rgba(223, 108, 89, 0.4);
    background: rgba(223, 108, 89, 0.16);
}

:deep(.user-pin.outside .user-core) {
    background: linear-gradient(145deg, #e98470, #c64e47);
    box-shadow: 0 8px 22px rgba(170, 64, 56, 0.34);
}

:deep(.leaflet-control-attribution) {
    padding: 2px 5px;
    color: #758583;
    background: rgba(255, 255, 255, 0.72);
    font-size: 8px;
}

:deep(.leaflet-control-zoom) {
    margin-right: 14px !important;
    margin-bottom: 14px !important;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.88) !important;
    border-radius: 12px;
    box-shadow: 0 8px 20px rgba(18, 53, 52, 0.13);
}

:deep(.leaflet-control-zoom a) {
    width: 32px;
    height: 31px;
    color: #315e5c;
    background: rgba(255, 255, 255, 0.93);
    font:
        18px/31px ui-sans-serif,
        system-ui,
        sans-serif;
}

@keyframes location-pulse {
    0% {
        opacity: 0.9;
        transform: scale(0.68);
    }
    75%,
    100% {
        opacity: 0;
        transform: scale(1.25);
    }
}
</style>
