<?php

use App\Models\AbsensiConfiguration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensi_configurations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('cancel_approved_cuti')->default(false);
            $table->boolean('cancel_approved_lembur')->default(false);
            $table->boolean('cancel_approved_perubahan_shift')->default(false);
            $table->boolean('perubahan_shift_after_clock_in')->default(false);
            $table->unsignedSmallInteger('clock_in_tolerance_minutes')->default(0);
            $table->boolean('approve_past_requests')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('absensi_configurations')->insert([
            'id' => 1, ...AbsensiConfiguration::DEFAULTS, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['configuration.view', 'configuration.update'] as $name) {
            $permission = \Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
            foreach (\Spatie\Permission\Models\Role::query()->where('name', 'super-admin')->where('guard_name', 'web')->get() as $role) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi_configurations');
        \Spatie\Permission\Models\Permission::query()->where('guard_name', 'web')
            ->whereIn('name', ['configuration.view', 'configuration.update'])->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
