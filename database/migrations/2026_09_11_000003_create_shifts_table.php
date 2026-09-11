<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->time('jam_masuk');
            $table->time('jam_keluar');
            $table->timestamps();

            $table->index(['jam_masuk', 'jam_keluar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
