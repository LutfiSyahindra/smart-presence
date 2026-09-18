<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presensis', function (Blueprint $table): void {
            $table->decimal('clock_in_accuracy', 8, 2)->nullable()->after('clock_in_longitude');
            $table->unsignedTinyInteger('clock_in_location_sample_count')->nullable()->after('clock_in_accuracy');
            $table->timestamp('clock_in_location_verified_at')->nullable()->after('clock_in_location_sample_count');
            $table->decimal('clock_out_accuracy', 8, 2)->nullable()->after('clock_out_longitude');
            $table->unsignedTinyInteger('clock_out_location_sample_count')->nullable()->after('clock_out_accuracy');
            $table->timestamp('clock_out_location_verified_at')->nullable()->after('clock_out_location_sample_count');
        });
    }

    public function down(): void
    {
        Schema::table('presensis', function (Blueprint $table): void {
            $table->dropColumn([
                'clock_in_accuracy',
                'clock_in_location_sample_count',
                'clock_in_location_verified_at',
                'clock_out_accuracy',
                'clock_out_location_sample_count',
                'clock_out_location_verified_at',
            ]);
        });
    }
};
