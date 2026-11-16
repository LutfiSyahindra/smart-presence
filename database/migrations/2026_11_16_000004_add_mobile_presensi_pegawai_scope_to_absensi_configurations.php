<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->string('mobile_presensi_pegawai_scope', 30)->default('unit-kerja');
            $table->string('mobile_presensi_pegawai_approval_type', 30)->default('presensi');
        });
    }

    public function down(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->dropColumn(['mobile_presensi_pegawai_scope', 'mobile_presensi_pegawai_approval_type']);
        });
    }
};
