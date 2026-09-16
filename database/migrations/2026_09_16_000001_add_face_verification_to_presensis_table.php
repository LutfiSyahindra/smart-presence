<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensis', function (Blueprint $table) {
            $table->timestamp('face_verified_at')->nullable()->after('clock_in_photo_path');
            $table->decimal('face_similarity', 5, 2)->nullable()->after('face_verified_at');
            $table->string('face_verification_provider', 50)->nullable()->after('face_similarity');
        });
    }

    public function down(): void
    {
        Schema::table('presensis', function (Blueprint $table) {
            $table->dropColumn(['face_verified_at', 'face_similarity', 'face_verification_provider']);
        });
    }
};
