<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';
        $roleKey = $columns['role_pivot_key'] ?? 'role_id';

        $permission = DB::table($tables['permissions'])
            ->where('name', 'mobile.account')
            ->where('guard_name', 'web')
            ->first();

        $permissionId = $permission?->id ?? DB::table($tables['permissions'])->insertGetId([
            'name' => 'mobile.account',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $defaultRoles = DB::table($tables['roles'])
            ->whereIn('name', ['super-admin', 'pegawai'])
            ->where('guard_name', 'web')
            ->get();

        foreach ($defaultRoles as $role) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore([
                $permissionKey => $permissionId,
                $roleKey => $role->id,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionTable = config('permission.table_names.permissions', 'permissions');

        DB::table($permissionTable)
            ->where('name', 'mobile.account')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
