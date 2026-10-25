<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perubahan_shift_requests', fn (Blueprint $table) => $table->timestamp('decided_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('perubahan_shift_requests', fn (Blueprint $table) => $table->dropColumn('decided_at'));
    }
};
