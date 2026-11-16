<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate([
            'name' => 'mobile.presensi-pegawai.view', 'guard_name' => 'web',
        ]);
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super-admin', 'kepala-unit'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'mobile.presensi-pegawai.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
