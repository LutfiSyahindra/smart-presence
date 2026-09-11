<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jabatans', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama', 150)->unique();
            $table->string('deskripsi', 500)->nullable();
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();

            $table->index(['aktif', 'nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jabatans');
    }
};
