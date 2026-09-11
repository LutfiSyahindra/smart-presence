<?php

use App\Http\Controllers\Absensi\AdminWeb\MasterData\Jabatan\JabatanController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Pegawai\PegawaiController;
use App\Http\Controllers\Absensi\AdminWeb\Setting\Permission\PermissionController;
use App\Http\Controllers\Absensi\AdminWeb\Setting\Role\RoleController;
use App\Http\Controllers\Absensi\AdminWeb\Setting\User\UserController;
use App\Support\AccessPermission;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

Route::bind('role', fn (string $value) => RoleModel::query()
    ->where('guard_name', 'web')
    ->findOrFail($value));
Route::bind('permission', fn (string $value) => PermissionModel::query()
    ->where('guard_name', 'web')
    ->findOrFail($value));

Route::middleware('auth')
    ->prefix('absensi/admin-web')
    ->name('absensi.admin-web.')
    ->group(function () {
        Route::prefix('master-data')->name('master-data.')->group(function () {
            Route::get('jabatan', [JabatanController::class, 'index'])->middleware('can:'.AccessPermission::JABATAN_VIEW)->name('jabatan.index');
            Route::post('jabatan', [JabatanController::class, 'store'])->middleware('can:'.AccessPermission::JABATAN_CREATE)->name('jabatan.store');
            Route::put('jabatan/{jabatan}', [JabatanController::class, 'update'])->middleware('can:'.AccessPermission::JABATAN_UPDATE)->name('jabatan.update');
            Route::delete('jabatan/{jabatan}', [JabatanController::class, 'destroy'])->middleware('can:'.AccessPermission::JABATAN_DELETE)->name('jabatan.destroy');

            Route::get('pegawai', [PegawaiController::class, 'index'])->middleware('can:'.AccessPermission::PEGAWAI_VIEW)->name('pegawai.index');
            Route::post('pegawai', [PegawaiController::class, 'store'])->middleware('can:'.AccessPermission::PEGAWAI_CREATE)->name('pegawai.store');
            Route::get('pegawai/template', [PegawaiController::class, 'template'])->middleware('can:'.AccessPermission::PEGAWAI_CREATE)->name('pegawai.template');
            Route::post('pegawai/import', [PegawaiController::class, 'import'])->middleware('can:'.AccessPermission::PEGAWAI_CREATE)->name('pegawai.import');
            Route::put('pegawai/{pegawai}', [PegawaiController::class, 'update'])->middleware('can:'.AccessPermission::PEGAWAI_UPDATE)->name('pegawai.update');
            Route::delete('pegawai/{pegawai}', [PegawaiController::class, 'destroy'])->middleware('can:'.AccessPermission::PEGAWAI_DELETE)->name('pegawai.destroy');
        });

        Route::prefix('setting')->name('setting.')->group(function () {
            Route::get('users', [UserController::class, 'index'])->middleware('can:'.AccessPermission::USERS_VIEW)->name('users.index');
            Route::get('users/create', [UserController::class, 'create'])->middleware('can:'.AccessPermission::USERS_CREATE)->name('users.create');
            Route::post('users', [UserController::class, 'store'])->middleware('can:'.AccessPermission::USERS_CREATE)->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:'.AccessPermission::USERS_UPDATE)->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:'.AccessPermission::USERS_UPDATE)->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:'.AccessPermission::USERS_DELETE)->name('users.destroy');

            Route::get('roles', [RoleController::class, 'index'])->middleware('can:'.AccessPermission::ROLES_VIEW)->name('roles.index');
            Route::get('roles/create', [RoleController::class, 'create'])->middleware('can:'.AccessPermission::ROLES_CREATE)->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->middleware('can:'.AccessPermission::ROLES_CREATE)->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:'.AccessPermission::ROLES_UPDATE)->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:'.AccessPermission::ROLES_UPDATE)->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:'.AccessPermission::ROLES_DELETE)->name('roles.destroy');

            Route::get('permissions', [PermissionController::class, 'index'])->middleware('can:'.AccessPermission::PERMISSIONS_VIEW)->name('permissions.index');
            Route::get('permissions/create', [PermissionController::class, 'create'])->middleware('can:'.AccessPermission::PERMISSIONS_CREATE)->name('permissions.create');
            Route::post('permissions', [PermissionController::class, 'store'])->middleware('can:'.AccessPermission::PERMISSIONS_CREATE)->name('permissions.store');
            Route::get('permissions/{permission}/edit', [PermissionController::class, 'edit'])->middleware('can:'.AccessPermission::PERMISSIONS_UPDATE)->name('permissions.edit');
            Route::put('permissions/{permission}', [PermissionController::class, 'update'])->middleware('can:'.AccessPermission::PERMISSIONS_UPDATE)->name('permissions.update');
            Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->middleware('can:'.AccessPermission::PERMISSIONS_DELETE)->name('permissions.destroy');
        });
    });
