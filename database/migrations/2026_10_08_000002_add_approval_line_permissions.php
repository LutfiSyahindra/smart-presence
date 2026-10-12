<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = ['approval-line.view', 'approval-line.create', 'approval-line.update', 'approval-line.delete'];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $roleIds = DB::table($tables['roles'])->where('name', 'super-admin')->where('guard_name', 'web')->pluck('id');

        foreach ($this->permissions as $name) {
            $permissionId = DB::table($tables['permissions'])->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table($tables['permissions'])->insertGetId([
                    'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);

            foreach ($roleIds as $roleId) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore([
                    $columns['permission_pivot_key'] ?? 'permission_id' => $permissionId,
                    $columns['role_pivot_key'] ?? 'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions'))->whereIn('name', $this->permissions)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
