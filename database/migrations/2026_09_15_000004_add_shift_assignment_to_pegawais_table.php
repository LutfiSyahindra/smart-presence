<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->foreignId('shift_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();
            $table->timestamp('shift_assigned_at')->nullable()->after('shift_id');
            $table->foreignId('shift_assigned_by')
                ->nullable()
                ->after('shift_assigned_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['user_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'shift_id']);
            $table->dropConstrainedForeignId('shift_assigned_by');
            $table->dropColumn('shift_assigned_at');
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
