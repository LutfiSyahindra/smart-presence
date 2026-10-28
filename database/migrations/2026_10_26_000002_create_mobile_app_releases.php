<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('package_name');
            $table->unsignedInteger('version_code')->unique();
            $table->string('version_name', 100);
            $table->unsignedInteger('min_sdk');
            $table->string('certificate_sha256', 64);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('path')->unique();
            $table->text('release_notes');
            $table->unsignedInteger('minimum_version_code')->default(1);
            $table->timestamp('required_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        // A permanent row serializes publications, including the very first release.
        Schema::create('mobile_app_distribution', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->foreignId('release_id')->nullable()->constrained('mobile_app_releases')->restrictOnDelete();
            $table->unsignedInteger('highest_version_code')->default(0);
        });
        DB::table('mobile_app_distribution')->insert(['id' => 1, 'highest_version_code' => 0]);

        $tables = config('permission.table_names');
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $permissionKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        foreach (['app-releases.view', 'app-releases.manage', 'app-releases.publish'] as $name) {
            $id = DB::table($tables['permissions'])->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table($tables['permissions'])->insertGetId([
                    'name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
                ]);
            foreach (DB::table($tables['roles'])->where('name', 'super-admin')->where('guard_name', 'web')->pluck('id') as $roleId) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore([$permissionKey => $id, $roleKey => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_distribution');
        Schema::dropIfExists('mobile_app_releases');
        DB::table(config('permission.table_names.permissions'))->where('guard_name', 'web')
            ->whereIn('name', ['app-releases.view', 'app-releases.manage', 'app-releases.publish'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
