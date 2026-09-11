<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PermissionForm from '../components/PermissionForm.vue';
import SettingNavigation from '../components/SettingNavigation.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Permission', href: '/absensi/admin-web/setting/permissions' },
    { title: 'Tambah Permission', href: '/absensi/admin-web/setting/permissions/create' },
];
</script>

<template>
    <Head title="Tambah Permission" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingNavigation />
        <PermissionForm submit-url="/absensi/admin-web/setting/permissions" />
    </AppLayout>
</template>
