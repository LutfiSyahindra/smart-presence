<?php

use App\Http\Controllers\Absensi\AbsensiMobile\AttendanceSubmissionController;
use App\Http\Controllers\Api\Mobile\AttendanceActionController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Support\AccessPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')
    ->name('api.mobile.')
    ->group(function () {
        Route::post('auth/login', [MobileAuthController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('login');

        Route::middleware(['auth:sanctum', 'can:'.AccessPermission::MOBILE_ACCESS])->group(function () {
            Route::post('auth/logout', [MobileAuthController::class, 'destroy'])->name('logout');
            Route::get('auth/user', [MobileAuthController::class, 'user'])->name('user');
            Route::get('home', [MobileController::class, 'home'])->name('home');
            Route::get('attendance', [MobileController::class, 'attendance'])->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->name('attendance');
            Route::get('attendance/history', [MobileController::class, 'history'])->middleware('can:'.AccessPermission::MOBILE_HISTORY)->name('attendance.history');
            Route::get('attendance-submissions/latest', [AttendanceSubmissionController::class, 'latest'])
                ->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->name('attendance-submissions.latest');
            Route::get('attendance-submissions/{attendanceSubmission}', [AttendanceSubmissionController::class, 'show'])
                ->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->name('attendance-submissions.show');
            Route::get('attendance/{action}', [AttendanceActionController::class, 'show'])
                ->whereIn('action', ['clock-in', 'clock-out'])
                ->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->name('attendance.action.show');
            Route::post('attendance/clock-in', [AttendanceActionController::class, 'clockIn'])->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->middleware('can:'.AccessPermission::MOBILE_CLOCK_IN)->name('clock-in');
            Route::post('attendance/clock-out', [AttendanceActionController::class, 'clockOut'])->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->middleware('can:'.AccessPermission::MOBILE_CLOCK_OUT)->name('clock-out');

            Route::middleware('can:'.AccessPermission::MOBILE_ACCOUNT)->group(function () {
                Route::get('account', [MobileController::class, 'account'])->name('account');
                Route::post('account/photo', [MobileController::class, 'updatePhoto'])->name('account.photo.update');
                Route::get('account/photo', [MobileController::class, 'photo'])->name('account.photo');
            });
        });
    });
