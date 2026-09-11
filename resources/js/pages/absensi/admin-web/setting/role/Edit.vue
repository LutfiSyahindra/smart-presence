<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import RoleForm from '../components/RoleForm.vue';
import SettingNavigation from '../components/SettingNavigation.vue';
import type { PermissionGroup } from '../types';

const props = defineProps<{
    role: { id: number; name: string; permission_ids: number[]; is_system: boolean };
    permissionGroups: PermissionGroup[];
}>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Role', href: '/absensi/admin-web/setting/roles' },
    { title: 'Edit Role', href: `/absensi/admin-web/setting/roles/${props.role.id}/edit` },
];
</script>

<template>
    <Head :title="`Edit ${role.name}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingNavigation />
        <RoleForm :permission-groups="permissionGroups" :submit-url="`/absensi/admin-web/setting/roles/${role.id}`" :initial="role" editing />
    </AppLayout>
</template>
