<script setup lang="ts">
import { computed } from 'vue';
import ClockInSuccess from './ClockInSuccess.vue';

const props = defineProps<{
    attendance: {
        date: string | null;
        date_label: string | null;
        clock_out_time: string;
        early_departure_minutes: number;
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
        location_name: string | null;
        location_address: string | null;
        approval_status?: string | null;
        outside_radius: boolean;
        face_verified: boolean;
        face_similarity: number | null;
    };
}>();

const normalizedAttendance = computed(() => ({
    date: props.attendance.date,
    date_label: props.attendance.date_label,
    clock_in_time: props.attendance.clock_out_time,
    late_minutes: props.attendance.early_departure_minutes,
    shift_name: props.attendance.shift_name,
    start_time: props.attendance.start_time,
    end_time: props.attendance.end_time,
    location_name: props.attendance.location_name,
    location_address: props.attendance.location_address,
    approval_status: props.attendance.approval_status,
    outside_radius: props.attendance.outside_radius,
    face_verified: props.attendance.face_verified,
    face_similarity: props.attendance.face_similarity,
}));
</script>

<template>
    <ClockInSuccess :attendance="normalizedAttendance" mode="out" />
</template>
