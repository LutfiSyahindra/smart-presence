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
            $table->decimal('face_minimum_similarity', 5, 2)->default(40);
        });

        DB::table('absensi_configurations')->update([
            'face_minimum_similarity' => round(max(1, min(100, (float) config('face-verification.minimum_similarity', 40))), 2),
        ]);
    }

    public function down(): void
    {
        Schema::table('absensi_configurations', function (Blueprint $table) {
            $table->dropColumn('face_minimum_similarity');
        });
    }
};
