<script setup lang="ts">
import * as L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    latitude: number | string;
    longitude: number | string;
    radius: number | string;
}>();

const emit = defineEmits<{
    'update:latitude': [value: number];
    'update:longitude': [value: number];
}>();

const mapElement = ref<HTMLElement | null>(null);
const fallbackCenter: L.LatLngExpression = [-2.5489, 118.0149];
const fallbackZoom = 5;
let map: L.Map | null = null;
let marker: L.Marker | null = null;
let radiusCircle: L.Circle | null = null;
let resizeObserver: ResizeObserver | null = null;

const validCoordinates = computed(() => {
    if (props.latitude === '' || props.longitude === '') return null;

    const latitude = Number(props.latitude);
    const longitude = Number(props.longitude);

    if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
        return null;
    }

    return L.latLng(latitude, longitude);
});

const validRadius = computed(() => {
    const radius = Number(props.radius);
    return Number.isFinite(radius) && radius > 0 ? radius : 100;
});

const markerIcon = L.divIcon({
    className: 'office-map-marker-shell',
    html: '<span class="office-map-marker"><i></i></span>',
    iconSize: [34, 42],
    iconAnchor: [17, 40],
});

const emitCoordinates = (position: L.LatLng) => {
    emit('update:latitude', Number(position.lat.toFixed(7)));
    emit('update:longitude', Number(position.lng.toFixed(7)));
};

const updateLayers = (recenter = false) => {
    if (!map) return;

    const coordinates = validCoordinates.value;
    if (!coordinates) {
        marker?.remove();
        radiusCircle?.remove();
        marker = null;
        radiusCircle = null;
        return;
    }

    if (!marker) {
        marker = L.marker(coordinates, { draggable: true, icon: markerIcon, title: 'Geser untuk mengubah titik kantor' }).addTo(map);
        marker.on('dragend', () => marker && emitCoordinates(marker.getLatLng()));
    } else {
        marker.setLatLng(coordinates);
    }

    if (!radiusCircle) {
        radiusCircle = L.circle(coordinates, {
            radius: validRadius.value,
            color: '#087f78',
            fillColor: '#087f78',
            fillOpacity: 0.14,
            weight: 2,
        }).addTo(map);
    } else {
        radiusCircle.setLatLng(coordinates).setRadius(validRadius.value);
    }

    if (recenter || !map.getBounds().contains(coordinates)) map.setView(coordinates, Math.max(map.getZoom(), 16));
};

onMounted(async () => {
    await nextTick();
    if (!mapElement.value) return;

    map = L.map(mapElement.value, { zoomControl: true }).setView(
        validCoordinates.value ?? fallbackCenter,
        validCoordinates.value ? 17 : fallbackZoom,
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);
    map.on('click', (event: L.LeafletMouseEvent) => emitCoordinates(event.latlng));
    updateLayers();

    resizeObserver = new ResizeObserver(() => map?.invalidateSize());
    resizeObserver.observe(mapElement.value);
    window.setTimeout(() => map?.invalidateSize(), 250);
});

watch([validCoordinates, validRadius], ([coordinates], [previousCoordinates]) => {
    const shouldRecenter = Boolean(coordinates && (!previousCoordinates || !coordinates.equals(previousCoordinates)));
    updateLayers(shouldRecenter);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map?.remove();
    map = null;
});
</script>

<template>
    <div class="office-map-wrap">
        <div ref="mapElement" class="office-map" aria-label="Peta pemilihan lokasi kantor"></div>
        <div class="office-map-help">
            <span><i class="click-dot"></i>Klik peta untuk menentukan titik</span>
            <span><i class="drag-dot"></i>Marker dapat digeser</span>
        </div>
    </div>
</template>

<style scoped>
.office-map-wrap {
    position: relative;
    min-height: 340px;
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 16px;
    background: var(--rs-primary-soft);
}
.office-map {
    z-index: 1;
    width: 100%;
    height: 340px;
}
.office-map-help {
    position: absolute;
    z-index: 500;
    right: 12px;
    bottom: 12px;
    display: flex;
    gap: 12px;
    padding: 8px 11px;
    border: 1px solid rgba(var(--rs-border-rgb), 0.9);
    border-radius: 10px;
    color: var(--rs-ink);
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 6px 18px rgba(var(--rs-primary-deep-rgb), 0.12);
    font-size: 11px;
    font-weight: 600;
    backdrop-filter: blur(8px);
}
.office-map-help span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.office-map-help i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
}
.click-dot {
    background: var(--rs-primary);
}
.drag-dot {
    background: #14b8a6;
}
:deep(.office-map-marker-shell) {
    border: 0;
    background: transparent;
}
:deep(.office-map-marker) {
    position: relative;
    display: block;
    width: 32px;
    height: 32px;
    transform: rotate(45deg);
    border: 4px solid #fff;
    border-radius: 50% 50% 50% 8px;
    background: linear-gradient(145deg, var(--rs-primary), var(--rs-primary));
    box-shadow: 0 7px 16px rgba(var(--rs-primary-rgb), 0.42);
}
:deep(.office-map-marker i) {
    position: absolute;
    top: 8px;
    left: 8px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #fff;
}
:deep(.leaflet-control-attribution) {
    font-size: 9px;
}
@media (max-width: 620px) {
    .office-map,
    .office-map-wrap {
        min-height: 290px;
        height: 290px;
    }
    .office-map-help {
        right: 8px;
        bottom: 8px;
        flex-direction: column;
        gap: 3px;
    }
}
</style>
