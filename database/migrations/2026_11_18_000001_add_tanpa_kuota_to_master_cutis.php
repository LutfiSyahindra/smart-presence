<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_cutis', function (Blueprint $table) {
            $table->boolean('tanpa_kuota')->default(false)->after('durasi_hari');
        });
    }

    public function down(): void
    {
        Schema::table('master_cutis', function (Blueprint $table) {
            $table->dropColumn('tanpa_kuota');
        });
    }
};
