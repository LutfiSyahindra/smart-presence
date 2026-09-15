<?php

use App\Models\Pegawai;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });

        // Role autentikasi hanya dimiliki User. Bersihkan assignment lama yang
        // sempat melekat langsung ke model profil Pegawai.
        DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where('model_type', Pegawai::class)
            ->delete();
        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))
            ->where('model_type', Pegawai::class)
            ->delete();
    }

    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
