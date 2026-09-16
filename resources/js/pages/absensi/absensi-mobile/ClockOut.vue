<script setup lang="ts">
import ClockIn from './ClockIn.vue';

defineProps<{
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
    profilePhotoUrl: string | null;
}>();
</script>

<template>
    <ClockIn :schedule="schedule" :office="office" :profile-photo-url="profilePhotoUrl" mode="out" />
</template>
