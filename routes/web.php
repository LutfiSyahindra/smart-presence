<?php

use App\Http\Controllers\PortalSwitchController;
use App\Support\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function (Request $request) {
    return to_route(Portal::homeRouteName($request));
})
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::post('portal/{portal}', PortalSwitchController::class)
    ->middleware(['auth', 'verified'])
    ->whereIn('portal', Portal::all())
    ->name('portal.switch');

require __DIR__.'/settings.php';
require __DIR__.'/absensi.php';
require __DIR__.'/auth.php';
