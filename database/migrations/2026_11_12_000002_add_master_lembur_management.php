<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = ['master-lembur.view', 'master-lembur.create', 'master-lembur.cancel'];

    public function up(): void
    {
        Schema::table('lembur_requests', function (Blueprint $table) {
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_note', 1000)->nullable();
        });

        foreach ($this->permissions as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            foreach (Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->get() as $role) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('lembur_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancellation_note');
        });
        Permission::query()->where('guard_name', 'web')->whereIn('name', $this->permissions)->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
