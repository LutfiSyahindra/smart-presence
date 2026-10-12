<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jabatan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('cuti');
            $table->timestamps();
            $table->unique(['jabatan_id', 'type']);
        });

        Schema::create('approval_line_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->unique(['approval_line_id', 'level']);
            $table->unique(['approval_line_id', 'user_id']);
        });

        Schema::create('cuti_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pegawai_id')->nullable()->constrained('pegawais')->nullOnDelete();
            $table->foreignId('jabatan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nama_pegawai');
            $table->string('nama_jabatan');
            $table->string('nama_unit');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->text('alasan');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('current_level')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['pegawai_id', 'tanggal_mulai', 'tanggal_selesai']);
        });

        Schema::create('cuti_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuti_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('approver_name');
            $table->unsignedTinyInteger('level');
            $table->string('status', 20)->default('waiting');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unique(['cuti_request_id', 'level']);
            $table->unique(['cuti_request_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuti_approvals');
        Schema::dropIfExists('cuti_requests');
        Schema::dropIfExists('approval_line_steps');
        Schema::dropIfExists('approval_lines');
    }
};
