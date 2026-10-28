<?php

use App\Http\Controllers\AppReleaseController;
use App\Http\Controllers\PortalSwitchController;
use App\Services\Mobile\AppReleaseService;
use App\Support\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request, AppReleaseService $releases) {
    return Inertia::render('Welcome', [
        ...$releases->installation(),
        'status' => $request->session()->get('status'),
    ]);
})->name('home');

// Also available before login so an outdated APK can always be updated.
Route::get('mobile-app/android/release', [AppReleaseController::class, 'check'])->middleware('throttle:1200,1')->name('app.android.release');
Route::get('mobile-app/android/releases/{release}/download', [AppReleaseController::class, 'download'])->whereNumber('release')->name('app.android.download');

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
