<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawais')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->date('tanggal_presensi');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->foreignId('clock_in_location_id')->nullable()->constrained('office_locations')->nullOnDelete();
            $table->foreignId('clock_out_location_id')->nullable()->constrained('office_locations')->nullOnDelete();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->string('clock_in_address', 500)->nullable();
            $table->string('clock_out_address', 500)->nullable();
            $table->string('clock_in_photo_path', 500)->nullable();
            $table->string('clock_out_photo_path', 500)->nullable();
            $table->boolean('is_day_off')->default(false);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pegawai_id', 'tanggal_presensi']);
            $table->index(['tanggal_presensi', 'is_day_off']);
            $table->index(['tanggal_presensi', 'overtime_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presensis');
    }
};
