<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('pegawai_id')->constrained('pegawais')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('presensi_id')->nullable()->constrained('presensis')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('action', 16);
            $table->string('status', 16)->default('pending');
            $table->string('request_key', 64)->unique();
            // Null on completion: later attempts after a rejected photo remain possible.
            $table->string('active_key', 100)->nullable()->unique();
            $table->timestamp('received_at', 6);
            $table->timestamp('processing_started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamp('last_dispatched_at', 6)->nullable();
            $table->uuid('processing_token')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('photo_path');
            $table->string('profile_snapshot_path')->nullable();
            $table->json('attendance_attributes');
            $table->boolean('outside_radius')->default(false);
            $table->string('error_field', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'action', 'received_at']);
            $table->index(['status', 'last_dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_submissions');
    }
};
