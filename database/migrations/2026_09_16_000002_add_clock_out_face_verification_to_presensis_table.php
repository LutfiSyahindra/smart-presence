<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensis', function (Blueprint $table) {
            $table->timestamp('clock_out_face_verified_at')->nullable()->after('clock_out_photo_path');
            $table->decimal('clock_out_face_similarity', 5, 2)->nullable()->after('clock_out_face_verified_at');
            $table->string('clock_out_face_verification_provider', 50)->nullable()->after('clock_out_face_similarity');
        });
    }

    public function down(): void
    {
        Schema::table('presensis', function (Blueprint $table) {
            $table->dropColumn([
                'clock_out_face_verified_at',
                'clock_out_face_similarity',
                'clock_out_face_verification_provider',
            ]);
        });
    }
};
