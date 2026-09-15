<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->renamePermission('attendance.access', 'mobile.access');
    }

    public function down(): void
    {
        $this->renamePermission('mobile.access', 'attendance.access');
    }

    private function renamePermission(string $from, string $to): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionTable = $tables['permissions'];
        $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';

        DB::transaction(function () use ($tables, $permissionTable, $permissionKey, $from, $to): void {
            $source = DB::table($permissionTable)
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->first();

            if ($source === null) {
                return;
            }

            $target = DB::table($permissionTable)
                ->where('name', $to)
                ->where('guard_name', 'web')
                ->first();

            if ($target === null) {
                DB::table($permissionTable)
                    ->where('id', $source->id)
                    ->update(['name' => $to]);

                return;
            }

            foreach (DB::table($tables['role_has_permissions'])->where($permissionKey, $source->id)->get() as $assignment) {
                $values = (array) $assignment;
                $values[$permissionKey] = $target->id;

                DB::table($tables['role_has_permissions'])->insertOrIgnore($values);
            }

            foreach (DB::table($tables['model_has_permissions'])->where($permissionKey, $source->id)->get() as $assignment) {
                $values = (array) $assignment;
                $values[$permissionKey] = $target->id;

                DB::table($tables['model_has_permissions'])->insertOrIgnore($values);
            }

            DB::table($permissionTable)->where('id', $source->id)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
