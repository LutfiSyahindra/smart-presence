<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_push_subscriptions', function (Blueprint $table) {
            $table->string('audience')->default('admin');
        });
        Schema::create('mobile_push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_devices');
        Schema::table('web_push_subscriptions', fn (Blueprint $table) => $table->dropColumn('audience'));
    }
};
