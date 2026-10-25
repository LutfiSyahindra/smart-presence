<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perubahan_shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pegawai_id')->nullable()->constrained('pegawais')->nullOnDelete();
            $table->foreignId('jabatan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('requested_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('nama_pegawai');
            $table->string('nama_jabatan');
            $table->string('nama_unit');
            $table->date('tanggal_perubahan');
            $table->json('original_shift');
            $table->json('requested_shift');
            $table->string('alasan', 200);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tanggal_perubahan']);
            $table->index(['pegawai_id', 'tanggal_perubahan', 'status'], 'perubahan_shift_employee_date_status');
        });

        Schema::create('perubahan_shift_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perubahan_shift_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approver_name');
            $table->unsignedTinyInteger('level');
            $table->string('status', 20)->default('waiting');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unique(['perubahan_shift_request_id', 'level'], 'perubahan_shift_approval_level');
            $table->unique(['perubahan_shift_request_id', 'user_id'], 'perubahan_shift_approval_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perubahan_shift_approvals');
        Schema::dropIfExists('perubahan_shift_requests');
    }
};
