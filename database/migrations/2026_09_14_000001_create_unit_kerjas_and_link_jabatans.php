<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_kerjas', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama', 150)->unique();
            $table->string('deskripsi', 500)->nullable();
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();

            $table->index(['aktif', 'nama']);
        });

        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropUnique(['nama']);
            $table->foreignId('unit_kerja_id')
                ->nullable()
                ->after('id')
                ->constrained('unit_kerjas')
                ->cascadeOnDelete();
            $table->unique(['unit_kerja_id', 'kode']);
            $table->unique(['unit_kerja_id', 'nama']);
        });

        if (DB::table('jabatans')->exists()) {
            $unitId = DB::table('unit_kerjas')->insertGetId([
                'kode' => 'UMUM',
                'nama' => 'Unit Umum',
                'deskripsi' => 'Unit bawaan untuk menampung data jabatan yang sudah ada sebelum pengelompokan unit kerja.',
                'aktif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('jabatans')->whereNull('unit_kerja_id')->update(['unit_kerja_id' => $unitId]);
        }
    }

    public function down(): void
    {
        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropUnique(['unit_kerja_id', 'kode']);
            $table->dropUnique(['unit_kerja_id', 'nama']);
            $table->dropConstrainedForeignId('unit_kerja_id');
            $table->unique('kode');
            $table->unique('nama');
        });

        Schema::dropIfExists('unit_kerjas');
    }
};
