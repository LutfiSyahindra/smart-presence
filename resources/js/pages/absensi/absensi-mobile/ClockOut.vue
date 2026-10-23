<script setup lang="ts">
import ClockIn from './ClockIn.vue';

defineProps<{
    schedule: {
        date: string;
        date_label: string;
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
        schedule_note?: string | null;
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
        server_time: number;
        min_samples: number;
        collection_seconds: number;
        max_accuracy_meters: number;
        max_speed_meters_per_second: number;
    };
    profilePhotoUrl: string | null;
}>();
</script>

<template>
    <ClockIn :schedule="schedule" :office="office" :location-challenge="locationChallenge" :profile-photo-url="profilePhotoUrl" mode="out" />
</template>
