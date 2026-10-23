<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lembur_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pegawai_id')->nullable()->constrained('pegawais')->nullOnDelete();
            $table->foreignId('jabatan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nama_pegawai');
            $table->string('nama_jabatan');
            $table->string('nama_unit');
            $table->string('nama_shift');
            $table->date('tanggal_lembur');
            $table->dateTime('shift_start_at');
            $table->dateTime('shift_end_at');
            $table->dateTime('before_start_at')->nullable();
            $table->dateTime('before_end_at')->nullable();
            $table->dateTime('after_start_at')->nullable();
            $table->dateTime('after_end_at')->nullable();
            $table->unsignedSmallInteger('before_minutes')->default(0);
            $table->unsignedSmallInteger('before_break_minutes')->default(0);
            $table->unsignedSmallInteger('after_minutes')->default(0);
            $table->unsignedSmallInteger('after_break_minutes')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('compensation', 20);
            $table->string('alasan', 200);
            $table->json('attachments')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tanggal_lembur']);
            $table->index(['pegawai_id', 'status']);
        });

        Schema::create('lembur_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lembur_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approver_name');
            $table->unsignedTinyInteger('level');
            $table->string('status', 20)->default('waiting');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unique(['lembur_request_id', 'level']);
            $table->unique(['lembur_request_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lembur_approvals');
        Schema::dropIfExists('lembur_requests');
    }
};
