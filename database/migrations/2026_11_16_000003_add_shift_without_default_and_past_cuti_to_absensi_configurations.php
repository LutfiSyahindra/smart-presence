<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->boolean('perubahan_shift_without_default')->default(false);
            $table->boolean('cuti_past_date')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->dropColumn(['perubahan_shift_without_default', 'cuti_past_date']);
        });
    }
};
