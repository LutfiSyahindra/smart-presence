<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->foreignId('unit_kerja_id')
                ->nullable()
                ->after('id')
                ->constrained('unit_kerjas')
                ->nullOnDelete();
            $table->foreignId('jabatan_id')
                ->nullable()
                ->after('unit_kerja_id')
                ->constrained('jabatans')
                ->nullOnDelete();
            $table->string('nik', 50)->nullable()->unique()->after('jabatan_id');
            $table->string('profesi', 150)->nullable()->after('nama');
            $table->string('jenis_kelamin', 20)->nullable()->index()->after('profesi');
            $table->string('status_pegawai', 20)->nullable()->index()->after('jenis_kelamin');
            $table->date('tanggal_mulai_bekerja')->nullable()->index()->after('status_pegawai');

            $table->date('tanggal_lahir')->nullable()->change();
            $table->string('nomor_telepon', 20)->nullable()->change();

            $table->index(['unit_kerja_id', 'jabatan_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropIndex(['unit_kerja_id', 'jabatan_id']);
            $table->dropConstrainedForeignId('jabatan_id');
            $table->dropConstrainedForeignId('unit_kerja_id');
            $table->dropColumn([
                'nik',
                'profesi',
                'jenis_kelamin',
                'status_pegawai',
                'tanggal_mulai_bekerja',
            ]);
        });
    }
};
