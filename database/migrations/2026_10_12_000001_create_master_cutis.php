<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_cutis', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique();
            $table->string('nama', 150);
            // NULL permits multiple ordinary types; the unique value identifies annual leave.
            $table->string('jenis', 20)->nullable()->unique();
            $table->unsignedSmallInteger('durasi_hari');
            $table->text('deskripsi')->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('pegawai_cutis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('master_cuti_id')->constrained('master_cutis')->cascadeOnDelete();
            $table->foreignId('pegawai_id')->constrained('pegawais')->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun')->default(0);
            $table->unsignedSmallInteger('jumlah_hari');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['master_cuti_id', 'pegawai_id', 'tahun']);
        });

        Schema::table('cuti_requests', function (Blueprint $table) {
            $table->foreignId('master_cuti_id')->nullable()->constrained('master_cutis')->restrictOnDelete();
            $table->string('nama_cuti', 150)->nullable();
            $table->unsignedSmallInteger('jumlah_hari')->nullable();
            $table->unsignedSmallInteger('tahun_cuti')->nullable();
            $table->boolean('is_tahunan')->default(false);
            $table->index(['pegawai_id', 'master_cuti_id', 'tahun_cuti', 'status'], 'cuti_balance_index');
        });
    }

    public function down(): void
    {
        Schema::table('cuti_requests', function (Blueprint $table) {
            $table->dropIndex('cuti_balance_index');
            $table->dropConstrainedForeignId('master_cuti_id');
            $table->dropColumn(['nama_cuti', 'jumlah_hari', 'tahun_cuti', 'is_tahunan']);
        });
        Schema::dropIfExists('pegawai_cutis');
        Schema::dropIfExists('master_cutis');
    }
};
