<?php

use App\Services\MobileDataChanges;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Used only by the isolated WebSocket integration test (in-memory SQLite).
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Schema::create('integration_data', function ($table) {
    $table->id();
    $table->string('value');
});
DB::transaction(function () {
    DB::table('integration_data')->insert(['value' => 'private value']);
    DB::table('integration_data')->update(['value' => 'changed private value']);
});
$app->make(MobileDataChanges::class)->flush();
