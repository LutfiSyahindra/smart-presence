<?php

use App\Http\Controllers\Absensi\AbsensiMobile\AccountController as MobileAccountController;
use App\Http\Controllers\Absensi\AbsensiMobile\AttendanceController as MobileAttendanceController;
use App\Http\Controllers\Absensi\AbsensiMobile\ClockInController as MobileClockInController;
use App\Http\Controllers\Absensi\AbsensiMobile\ClockOutController as MobileClockOutController;
use App\Http\Controllers\Absensi\AbsensiMobile\HomeController as MobileHomeController;
use App\Http\Controllers\Absensi\AdminWeb\ApprovalLine\ApprovalLineController;
use App\Http\Controllers\Absensi\AdminWeb\Cuti\CutiController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Cuti\MasterCutiController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Geolocation\OfficeLocationController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Jabatan\JabatanController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Pegawai\PegawaiController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift\ShiftController;
use App\Http\Controllers\Absensi\AdminWeb\Notifikasi\NotifikasiController;
use App\Http\Controllers\Absensi\AdminWeb\Presensi\PresensiController;
use App\Http\Controllers\Absensi\AdminWeb\Setting\Permission\PermissionController;
use App\Http\Controllers\Absensi\AdminWeb\Setting\Role\RoleController;
use App\Http\Controllers\Absensi\AdminWeb\Setting\User\UserController;
use App\Http\Controllers\DashboardController;
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

Route::middleware(['auth', 'verified', 'can:'.AccessPermission::MOBILE_ACCESS])
    ->prefix('absensi/mobile')
    ->name('absensi.mobile.')
    ->group(function () {
        Route::get('/', MobileHomeController::class)->name('home');
        Route::get('presensi', MobileAttendanceController::class)->name('attendance');
        Route::get('clock-in', [MobileClockInController::class, 'create'])->name('clock-in.create');
        Route::post('clock-in', [MobileClockInController::class, 'store'])->name('clock-in.store');
        Route::get('clock-in/berhasil', [MobileClockInController::class, 'success'])->name('clock-in.success');
        Route::get('clock-out', [MobileClockOutController::class, 'create'])->name('clock-out.create');
        Route::post('clock-out', [MobileClockOutController::class, 'store'])->name('clock-out.store');
        Route::get('clock-out/berhasil', [MobileClockOutController::class, 'success'])->name('clock-out.success');

        Route::middleware('can:'.AccessPermission::MOBILE_ACCOUNT)->group(function () {
            Route::get('akun', [MobileAccountController::class, 'show'])->name('account.show');
            Route::post('akun/foto-profil', [MobileAccountController::class, 'updatePhoto'])->name('account.photo.update');
            Route::get('akun/foto-profil', [MobileAccountController::class, 'photo'])->name('account.photo.show');
        });
    });

Route::middleware(['auth', 'verified', 'can:'.AccessPermission::ADMIN_ACCESS])
    ->prefix('absensi/admin-web')
    ->name('absensi.admin-web.')
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('approval-line', [ApprovalLineController::class, 'index'])->middleware('can:'.AccessPermission::APPROVAL_LINE_VIEW)->name('approval-line.index');
        Route::post('approval-line', [ApprovalLineController::class, 'store'])->middleware('can:'.AccessPermission::APPROVAL_LINE_CREATE)->name('approval-line.store');
        Route::put('approval-line/{approvalLine}', [ApprovalLineController::class, 'update'])->middleware('can:'.AccessPermission::APPROVAL_LINE_UPDATE)->name('approval-line.update');
        Route::delete('approval-line/{approvalLine}', [ApprovalLineController::class, 'destroy'])->middleware('can:'.AccessPermission::APPROVAL_LINE_DELETE)->name('approval-line.destroy');

        Route::post('cuti', [CutiController::class, 'store'])->name('cuti.store');

        Route::prefix('presensi')->name('presensi.')->group(function () {
            Route::get('pegawai', [PresensiController::class, 'index'])->middleware('can:'.AccessPermission::PRESENSI_VIEW)->name('index');
            Route::get('set-pegawai', [PresensiController::class, 'manage'])->middleware('can:'.AccessPermission::PRESENSI_MANAGE)->name('manage');
            Route::post('set-pegawai', [PresensiController::class, 'store'])->middleware('can:'.AccessPermission::PRESENSI_MANAGE)->name('store');
            Route::put('set-pegawai/{presensi}', [PresensiController::class, 'update'])->middleware('can:'.AccessPermission::PRESENSI_MANAGE)->name('update');
            Route::get('foto/{presensi}/{type}', [PresensiController::class, 'photo'])->name('photo');
        });

        Route::prefix('master-data')->name('master-data.')->group(function () {
            Route::get('cuti', [MasterCutiController::class, 'index'])->middleware('can:'.AccessPermission::MASTER_CUTI_VIEW)->name('cuti.index');
            Route::post('cuti', [MasterCutiController::class, 'store'])->middleware('can:'.AccessPermission::MASTER_CUTI_CREATE)->name('cuti.store');
            Route::get('cuti/template', [MasterCutiController::class, 'template'])->middleware('can:'.AccessPermission::MASTER_CUTI_ASSIGN)->name('cuti.template');
            Route::post('cuti/import', [MasterCutiController::class, 'import'])->middleware('can:'.AccessPermission::MASTER_CUTI_ASSIGN)->name('cuti.import');
            Route::put('cuti/tahunan', [MasterCutiController::class, 'setAnnual'])->middleware('can:'.AccessPermission::MASTER_CUTI_ASSIGN)->name('cuti.annual');
            Route::post('cuti/{masterCuti}/allocate', [MasterCutiController::class, 'allocate'])->middleware('can:'.AccessPermission::MASTER_CUTI_ASSIGN)->name('cuti.allocate');
            Route::put('cuti/{masterCuti}', [MasterCutiController::class, 'update'])->middleware('can:'.AccessPermission::MASTER_CUTI_UPDATE)->name('cuti.update');
            Route::delete('cuti/{masterCuti}', [MasterCutiController::class, 'destroy'])->middleware('can:'.AccessPermission::MASTER_CUTI_DELETE)->name('cuti.destroy');

            Route::get('jabatan', [JabatanController::class, 'index'])->middleware('can:'.AccessPermission::JABATAN_VIEW)->name('jabatan.index');
            Route::post('jabatan/units', [JabatanController::class, 'storeUnit'])->middleware('can:'.AccessPermission::JABATAN_CREATE)->name('jabatan.units.store');
            Route::put('jabatan/units/{unitKerja}', [JabatanController::class, 'updateUnit'])->middleware('can:'.AccessPermission::JABATAN_UPDATE)->name('jabatan.units.update');
            Route::delete('jabatan/units/{unitKerja}', [JabatanController::class, 'destroyUnit'])->middleware('can:'.AccessPermission::JABATAN_DELETE)->name('jabatan.units.destroy');
            Route::post('jabatan', [JabatanController::class, 'store'])->middleware('can:'.AccessPermission::JABATAN_CREATE)->name('jabatan.store');
            Route::put('jabatan/{jabatan}', [JabatanController::class, 'update'])->middleware('can:'.AccessPermission::JABATAN_UPDATE)->name('jabatan.update');
            Route::delete('jabatan/{jabatan}', [JabatanController::class, 'destroy'])->middleware('can:'.AccessPermission::JABATAN_DELETE)->name('jabatan.destroy');

            Route::get('pegawai', [PegawaiController::class, 'index'])->middleware('can:'.AccessPermission::PEGAWAI_VIEW)->name('pegawai.index');
            Route::post('pegawai', [PegawaiController::class, 'store'])->middleware('can:'.AccessPermission::PEGAWAI_CREATE)->name('pegawai.store');
            Route::get('pegawai/template', [PegawaiController::class, 'template'])->middleware('can:'.AccessPermission::PEGAWAI_CREATE)->name('pegawai.template');
            Route::post('pegawai/import', [PegawaiController::class, 'import'])->middleware('can:'.AccessPermission::PEGAWAI_CREATE)->name('pegawai.import');
            Route::post('pegawai/user-invitations/batch', [PegawaiController::class, 'inviteManyToUsers'])->middleware('can:'.AccessPermission::USERS_CREATE)->name('pegawai.user-invitations.batch');
            Route::post('pegawai/{pegawai}/user-invitation', [PegawaiController::class, 'inviteToUser'])->middleware('can:'.AccessPermission::USERS_CREATE)->name('pegawai.user-invitation.store');
            Route::put('pegawai/{pegawai}', [PegawaiController::class, 'update'])->middleware('can:'.AccessPermission::PEGAWAI_UPDATE)->name('pegawai.update');
            Route::delete('pegawai/{pegawai}', [PegawaiController::class, 'destroy'])->middleware('can:'.AccessPermission::PEGAWAI_DELETE)->name('pegawai.destroy');

            Route::get('shift', [ShiftController::class, 'index'])->middleware('can:'.AccessPermission::SHIFT_VIEW)->name('shift.index');
            Route::post('shift', [ShiftController::class, 'store'])->middleware('can:'.AccessPermission::SHIFT_CREATE)->name('shift.store');
            Route::get('shift/assign', [ShiftAssignmentController::class, 'index'])->middleware('can:'.AccessPermission::SHIFT_VIEW)->name('shift.assign.index');
            Route::put('shift/assign', [ShiftAssignmentController::class, 'update'])->middleware('can:'.AccessPermission::SHIFT_UPDATE)->name('shift.assign.update');
            Route::put('shift/{shift}', [ShiftController::class, 'update'])->middleware('can:'.AccessPermission::SHIFT_UPDATE)->name('shift.update');
            Route::delete('shift/{shift}', [ShiftController::class, 'destroy'])->middleware('can:'.AccessPermission::SHIFT_DELETE)->name('shift.destroy');

            Route::get('geolocation', [OfficeLocationController::class, 'index'])->middleware('can:'.AccessPermission::GEOLOCATION_VIEW)->name('geolocation.index');
            Route::post('geolocation', [OfficeLocationController::class, 'store'])->middleware('can:'.AccessPermission::GEOLOCATION_CREATE)->name('geolocation.store');
            Route::put('geolocation/{officeLocation}', [OfficeLocationController::class, 'update'])->middleware('can:'.AccessPermission::GEOLOCATION_UPDATE)->name('geolocation.update');
            Route::delete('geolocation/{officeLocation}', [OfficeLocationController::class, 'destroy'])->middleware('can:'.AccessPermission::GEOLOCATION_DELETE)->name('geolocation.destroy');
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

// Assigned approvers can access their tasks without receiving other admin permissions.
Route::middleware(['auth', 'verified', 'can:cuti.access'])
    ->prefix('absensi/admin-web')
    ->name('absensi.admin-web.')
    ->group(function () {
        Route::redirect('notifikasi', '/absensi/admin-web/notifikasi/butuh-approval');
        Route::get('notifikasi/butuh-approval', [NotifikasiController::class, 'index'])->name('notifikasi.index');
        Route::get('notifikasi/disetujui', [NotifikasiController::class, 'index'])->defaults('category', 'disetujui')->name('notifikasi.approved');
        Route::get('cuti', [CutiController::class, 'index'])->name('cuti.index');
        Route::post('cuti/{cutiRequest}/decision', [CutiController::class, 'decide'])->name('cuti.decide');
    });
