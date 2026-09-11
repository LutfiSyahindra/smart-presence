<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PermissionForm from '../components/PermissionForm.vue';
import SettingNavigation from '../components/SettingNavigation.vue';

const props = defineProps<{ permission: { id: number; name: string; is_system: boolean } }>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Permission', href: '/absensi/admin-web/setting/permissions' },
    {
        title: props.permission.is_system ? 'Detail Permission' : 'Edit Permission',
        href: `/absensi/admin-web/setting/permissions/${props.permission.id}/edit`,
    },
];
</script>

<template>
    <Head :title="`${permission.is_system ? 'Detail' : 'Edit'} ${permission.name}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingNavigation />
        <PermissionForm :submit-url="`/absensi/admin-web/setting/permissions/${permission.id}`" :initial="permission" editing />
    </AppLayout>
</template>
