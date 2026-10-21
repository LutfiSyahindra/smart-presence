<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hari_liburs', function (Blueprint $table) {
            $table->string('source', 50)->nullable()->index();
            $table->timestamp('synced_at')->nullable();
        });

        Schema::create('holiday_calendar_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('source_url', 2048);
            $table->json('source_references')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar_syncs');

        Schema::table('hari_liburs', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'synced_at']);
        });
    }
};
