<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'dashboard.view' => 'admin.access',
        'notifications.view' => 'admin.access',
        'cuti.view' => 'admin.access',
        'cuti.create' => 'admin.access',
        'cuti.approve' => 'admin.access',
        'mobile.attendance' => 'mobile.access',
        'mobile.clock-in' => 'mobile.access',
        'mobile.clock-out' => 'mobile.access',
        'mobile.history' => 'mobile.access',
        'mobile.cuti.view' => 'mobile.access',
        'mobile.cuti.create' => 'mobile.access',
        'mobile.cuti.cancel' => 'mobile.access',
        'mobile.cuti.approve' => 'mobile.access',
        'mobile.notifications' => 'mobile.access',
    ];

    public function up(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';
        $roleKey = $columns['role_pivot_key'] ?? 'role_id';
        $modelKey = $columns['model_morph_key'] ?? 'model_id';

        DB::transaction(function () use ($tables, $permissionKey, $roleKey, $modelKey) {
            $superAdmins = DB::table($tables['roles'])->where('name', 'super-admin')->where('guard_name', 'web')->pluck('id');
            $approvers = DB::table('approval_line_steps')->whereNotNull('user_id')
                ->whereIn('approval_line_id', DB::table('approval_lines')->where('type', 'cuti')->select('id'))->pluck('user_id')
                ->merge(DB::table('cuti_approvals')->whereNotNull('user_id')->pluck('user_id'))->unique();

            foreach ($this->permissions as $name => $inheritedFrom) {
                $permissionId = DB::table($tables['permissions'])->where('name', $name)->where('guard_name', 'web')->value('id')
                    ?? DB::table($tables['permissions'])->insertGetId([
                        'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                $sourceId = DB::table($tables['permissions'])->where('name', $inheritedFrom)->where('guard_name', 'web')->value('id');
                $roles = $sourceId ? DB::table($tables['role_has_permissions'])->where($permissionKey, $sourceId)->pluck($roleKey) : collect();
                foreach ($roles->merge($superAdmins)->unique() as $roleId) {
                    DB::table($tables['role_has_permissions'])->insertOrIgnore([$permissionKey => $permissionId, $roleKey => $roleId]);
                }
                if ($sourceId) {
                    foreach (DB::table($tables['model_has_permissions'])->where($permissionKey, $sourceId)->get() as $assignment) {
                        $copy = (array) $assignment;
                        $copy[$permissionKey] = $permissionId;
                        DB::table($tables['model_has_permissions'])->insertOrIgnore($copy);
                    }
                }
                // Keep existing approvers' task access, including historical snapshots.
                if (in_array($name, ['notifications.view', 'cuti.view', 'cuti.approve'], true)) {
                    foreach ($approvers as $userId) {
                        DB::table($tables['model_has_permissions'])->insertOrIgnore([
                            $permissionKey => $permissionId, $modelKey => $userId,
                            'model_type' => (new User)->getMorphClass(),
                        ]);
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.permissions'))->where('guard_name', 'web')
            ->whereIn('name', array_keys($this->permissions))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
