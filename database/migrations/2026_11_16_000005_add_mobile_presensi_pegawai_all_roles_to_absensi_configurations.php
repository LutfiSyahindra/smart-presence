<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->json('mobile_presensi_pegawai_all_role_ids')->nullable();
        });

        DB::table('absensi_configurations')->update(['mobile_presensi_pegawai_all_role_ids' => '[]']);
    }

    public function down(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->dropColumn('mobile_presensi_pegawai_all_role_ids');
        });
    }
};
