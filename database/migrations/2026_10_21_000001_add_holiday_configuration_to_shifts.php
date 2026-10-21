<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('libur_sabtu')->default(false);
            $table->boolean('libur_minggu')->default(false);
            $table->boolean('libur_nasional')->default(false);
            $table->boolean('libur_cuti_bersama')->default(false);
        });

        Schema::create('hari_liburs', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('nama', 150);
            $table->string('jenis', 30);
            $table->timestamps();
            $table->unique(['tanggal', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hari_liburs');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['libur_sabtu', 'libur_minggu', 'libur_nasional', 'libur_cuti_bersama']);
        });
    }
};
