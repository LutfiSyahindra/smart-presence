<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_line_jabatan', function (Blueprint $table) {
            $table->foreignId('approval_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jabatan_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->default('cuti');
            $table->primary(['approval_line_id', 'jabatan_id']);
            $table->unique(['jabatan_id', 'type']);
        });

        DB::table('approval_lines')->orderBy('id')->chunkById(100, function ($lines) {
            DB::table('approval_line_jabatan')->insert($lines->map(fn ($line) => [
                'approval_line_id' => $line->id, 'jabatan_id' => $line->jabatan_id, 'type' => $line->type,
            ])->all());
        });

        Schema::table('approval_lines', function (Blueprint $table) {
            $table->dropForeign(['jabatan_id']);
            $table->dropUnique(['jabatan_id', 'type']);
            $table->dropColumn('jabatan_id');
        });
    }

    public function down(): void
    {
        Schema::table('approval_lines', function (Blueprint $table) {
            $table->foreignId('jabatan_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Split shared lines on rollback so every position keeps its approvers.
        DB::table('approval_lines')->orderBy('id')->chunkById(100, function ($lines) {
            foreach ($lines as $line) {
                $positions = DB::table('approval_line_jabatan')->where('approval_line_id', $line->id)->orderBy('jabatan_id')->pluck('jabatan_id');
                foreach ($positions as $index => $positionId) {
                    if ($index === 0) {
                        DB::table('approval_lines')->where('id', $line->id)->update(['jabatan_id' => $positionId]);

                        continue;
                    }

                    $copyId = DB::table('approval_lines')->insertGetId([
                        'jabatan_id' => $positionId, 'type' => $line->type,
                        'created_at' => $line->created_at, 'updated_at' => $line->updated_at,
                    ]);
                    foreach (DB::table('approval_line_steps')->where('approval_line_id', $line->id)->get() as $step) {
                        DB::table('approval_line_steps')->insert([
                            'approval_line_id' => $copyId, 'user_id' => $step->user_id, 'level' => $step->level,
                        ]);
                    }
                }
            }
        });

        Schema::table('approval_lines', function (Blueprint $table) {
            $table->unique(['jabatan_id', 'type']);
        });
        Schema::dropIfExists('approval_line_jabatan');
    }
};
