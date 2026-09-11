<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawais', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150)->index();
            $table->date('tanggal_lahir')->index();
            $table->string('nomor_telepon', 20)->unique();
            $table->timestamps();

            $table->index(['nama', 'tanggal_lahir']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawais');
    }
};
