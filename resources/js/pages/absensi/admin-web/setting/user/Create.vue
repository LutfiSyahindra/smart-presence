<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import SettingNavigation from '../components/SettingNavigation.vue';
import UserForm from '../components/UserForm.vue';
import type { PermissionGroup, SelectOption } from '../types';

defineProps<{ roles: SelectOption[]; permissionGroups: PermissionGroup[] }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengguna', href: '/absensi/admin-web/setting/users' },
    { title: 'Tambah Pengguna', href: '/absensi/admin-web/setting/users/create' },
];
</script>

<template>
    <Head title="Tambah Pengguna" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingNavigation />
        <UserForm :roles="roles" :permission-groups="permissionGroups" submit-url="/absensi/admin-web/setting/users" />
    </AppLayout>
</template>
