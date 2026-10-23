<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lembur_requests', function (Blueprint $table) {
            $table->string('request_kind', 20)->default('overtime');
            $table->string('usage_type', 20)->nullable();
            $table->dateTime('usage_start_at')->nullable();
            $table->dateTime('usage_end_at')->nullable();
            $table->index(['pegawai_id', 'request_kind', 'status'], 'lembur_balance_index');
        });

        Schema::create('lembur_usage_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usage_request_id')->constrained('lembur_requests')->restrictOnDelete();
            $table->foreignId('source_request_id')->constrained('lembur_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->unique(['usage_request_id', 'source_request_id'], 'lembur_usage_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lembur_usage_allocations');
        Schema::table('lembur_requests', function (Blueprint $table) {
            $table->dropIndex('lembur_balance_index');
            $table->dropColumn(['request_kind', 'usage_type', 'usage_start_at', 'usage_end_at']);
        });
    }
};
