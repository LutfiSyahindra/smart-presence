<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import RoleForm from '../components/RoleForm.vue';
import SettingNavigation from '../components/SettingNavigation.vue';
import type { PermissionGroup } from '../types';

defineProps<{ permissionGroups: PermissionGroup[] }>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Role', href: '/absensi/admin-web/setting/roles' },
    { title: 'Tambah Role', href: '/absensi/admin-web/setting/roles/create' },
];
</script>

<template>
    <Head title="Tambah Role" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingNavigation />
        <RoleForm :permission-groups="permissionGroups" submit-url="/absensi/admin-web/setting/roles" />
    </AppLayout>
</template>
