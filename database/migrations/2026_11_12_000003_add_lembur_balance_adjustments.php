<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lembur_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pegawai_id')->nullable()->constrained('pegawais')->nullOnDelete();
            $table->integer('delta_minutes');
            $table->unsignedInteger('before_minutes');
            $table->unsignedInteger('after_minutes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->string('note', 1000);
            $table->timestamps();
            $table->index(['pegawai_id', 'created_at']);
        });
        Schema::table('lembur_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('manual_balance_minutes')->default(0);
        });

        foreach (['create', 'cancel'] as $action) {
            $old = Permission::query()->where('name', 'master-lembur.'.$action)->where('guard_name', 'web')->first();
            $permission = Permission::findOrCreate('lembur.'.$action, 'web');
            if ($old) {
                $this->copyAssignments($old->id, $permission->id);
                $old->delete();
            }
        }
        $view = Permission::findOrCreate('lembur.view', 'web');
        $oldView = Permission::query()->where('name', 'master-lembur.view')->where('guard_name', 'web')->first();
        if ($oldView) {
            $this->copyAssignments($oldView->id, $view->id);
        }
        Permission::findOrCreate('master-lembur.assign', 'web');
        foreach (Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo(['lembur.view', 'lembur.create', 'lembur.cancel', 'master-lembur.assign']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function copyAssignments(int $from, int $to): void
    {
        $key = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        foreach (['role_has_permissions', 'model_has_permissions'] as $name) {
            $table = config('permission.table_names.'.$name);
            foreach (DB::table($table)->where($key, $from)->get() as $assignment) {
                $copy = (array) $assignment;
                $copy[$key] = $to;
                DB::table($table)->insertOrIgnore($copy);
            }
        }
    }

    public function down(): void
    {
        Schema::table('lembur_requests', fn (Blueprint $table) => $table->dropColumn('manual_balance_minutes'));
        Schema::dropIfExists('lembur_balance_adjustments');
        foreach (['create', 'cancel'] as $action) {
            $old = Permission::findOrCreate('master-lembur.'.$action, 'web');
            $permission = Permission::query()->where('name', 'lembur.'.$action)->where('guard_name', 'web')->first();
            if ($permission) {
                $this->copyAssignments($permission->id, $old->id);
                $permission->delete();
            }
        }
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['lembur.view', 'master-lembur.assign'])->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
