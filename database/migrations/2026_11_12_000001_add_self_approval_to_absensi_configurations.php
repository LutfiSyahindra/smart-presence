<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->boolean('allow_self_approval')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->dropColumn('allow_self_approval');
        });
    }
};
