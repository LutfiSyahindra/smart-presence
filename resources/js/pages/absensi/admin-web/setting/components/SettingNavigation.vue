<script setup lang="ts">
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();
const currentPath = computed(() => page.url.split('?')[0]);
const isSuperAdmin = computed(() => page.props.auth.roles.includes('super-admin'));
const can = (permission: string) => isSuperAdmin.value || page.props.auth.permissions.includes(permission);

const items = [
    { title: 'Konfigurasi', href: '/absensi/admin-web/setting/konfigurasi', permission: 'configuration.view' },
    { title: 'Pengguna', href: '/absensi/admin-web/setting/users', permission: 'users.view' },
    { title: 'Role', href: '/absensi/admin-web/setting/roles', permission: 'roles.view' },
    { title: 'Permission', href: '/absensi/admin-web/setting/permissions', permission: 'permissions.view' },
    { title: 'Versi Aplikasi', href: '/absensi/admin-web/setting/app-releases', permission: 'app-releases.view' },
];
</script>

<template>
    <div class="card mb-4">
        <div class="card-body py-2">
            <nav class="admin-setting-tabs" aria-label="Navigasi pengaturan akses">
                <Link
                    v-for="item in items.filter((item) => can(item.permission))"
                    :key="item.href"
                    :href="item.href"
                    class="admin-setting-tab"
                    :class="{ active: currentPath.startsWith(item.href) }"
                >
                    {{ item.title }}
                </Link>
            </nav>
        </div>
    </div>
</template>
