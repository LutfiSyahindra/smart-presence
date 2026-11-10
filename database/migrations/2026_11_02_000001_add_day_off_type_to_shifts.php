<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_day_off')->default(false);
            $table->time('jam_masuk')->nullable()->change();
            $table->time('jam_keluar')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('shifts')->whereNull('jam_masuk')->update(['jam_masuk' => '00:00:00']);
        DB::table('shifts')->whereNull('jam_keluar')->update(['jam_keluar' => '00:00:00']);

        Schema::table('shifts', function (Blueprint $table) {
            $table->time('jam_masuk')->nullable(false)->change();
            $table->time('jam_keluar')->nullable(false)->change();
            $table->dropColumn('is_day_off');
        });
    }
};
