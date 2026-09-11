<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import SettingNavigation from '../components/SettingNavigation.vue';
import UserForm from '../components/UserForm.vue';
import type { PermissionGroup, SelectOption } from '../types';

const props = defineProps<{
    user: { id: number; name: string; email: string; role_ids: number[]; permission_ids: number[] };
    roles: SelectOption[];
    permissionGroups: PermissionGroup[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pengguna', href: '/absensi/admin-web/setting/users' },
    { title: 'Edit Pengguna', href: `/absensi/admin-web/setting/users/${props.user.id}/edit` },
];
</script>

<template>
    <Head :title="`Edit ${user.name}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <SettingNavigation />
        <UserForm
            :roles="roles"
            :permission-groups="permissionGroups"
            :submit-url="`/absensi/admin-web/setting/users/${user.id}`"
            :initial="user"
            editing
        />
    </AppLayout>
</template>
