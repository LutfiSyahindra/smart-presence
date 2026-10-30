<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presensi_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presensi_id')->constrained('presensis')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nama_pegawai');
            $table->string('nama_jabatan');
            $table->string('nama_unit');
            $table->date('tanggal_presensi');
            $table->string('action', 16);
            $table->timestamp('recorded_at');
            $table->json('location');
            $table->string('photo_path');
            $table->text('alasan');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['presensi_id', 'action']);
            $table->index(['status', 'user_id']);
        });
        Schema::create('presensi_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presensi_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approver_name');
            $table->unsignedTinyInteger('level');
            $table->string('status', 16);
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unique(['presensi_request_id', 'level']);
            $table->index(['user_id', 'status']);
        });
        Schema::table('presensis', function (Blueprint $table) {
            $table->string('clock_in_approval_status', 16)->nullable();
            $table->string('clock_out_approval_status', 16)->nullable();
        });
        Schema::table('attendance_submissions', function (Blueprint $table) {
            $table->json('approval_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_submissions', fn (Blueprint $table) => $table->dropColumn('approval_context'));
        Schema::table('presensis', fn (Blueprint $table) => $table->dropColumn(['clock_in_approval_status', 'clock_out_approval_status']));
        Schema::dropIfExists('presensi_approvals');
        Schema::dropIfExists('presensi_requests');
    }
};
