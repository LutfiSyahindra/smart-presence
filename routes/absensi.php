<?php

use App\Http\Controllers\Absensi\AbsensiMobile\AccountController as MobileAccountController;
use App\Http\Controllers\Absensi\AbsensiMobile\AttendanceController as MobileAttendanceController;
use App\Http\Controllers\Absensi\AbsensiMobile\AttendanceLogController;
use App\Http\Controllers\Absensi\AbsensiMobile\AttendanceSubmissionController;
use App\Http\Controllers\Absensi\AbsensiMobile\ClockInController as MobileClockInController;
use App\Http\Controllers\Absensi\AbsensiMobile\ClockOutController as MobileClockOutController;
use App\Http\Controllers\Absensi\AbsensiMobile\CutiApprovalController as MobileCutiApprovalController;
use App\Http\Controllers\Absensi\AbsensiMobile\CutiController as MobileCutiController;
use App\Http\Controllers\Absensi\AbsensiMobile\HomeController as MobileHomeController;
use App\Http\Controllers\Absensi\AbsensiMobile\LemburController as MobileLemburController;
use App\Http\Controllers\Absensi\AbsensiMobile\NotificationController as MobileNotificationController;
use App\Http\Controllers\Absensi\AbsensiMobile\PerubahanShiftController as MobilePerubahanShiftController;
use App\Http\Controllers\Absensi\AbsensiMobile\PushDeviceController;
use App\Http\Controllers\Absensi\AdminWeb\ApprovalLine\ApprovalLineController;
use App\Http\Controllers\Absensi\AdminWeb\Cuti\CutiController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Cuti\MasterCutiController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Geolocation\OfficeLocationController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Jabatan\JabatanController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Pegawai\PegawaiController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift\HariLiburController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentController;
use App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift\ShiftController;
use App\Http\Controllers\Absensi\AdminWeb\Notifikasi\LemburController as WebLemburController;
use App\Http\Controllers\Absensi\AdminWeb\Notifikasi\NotificationInboxController;
use App\Http\Controllers\Absensi\AdminWeb\Notifikasi\NotifikasiController;
use App\Http\Controllers\Absensi\AdminWeb\Notifikasi\PerubahanShiftController as WebPerubahanShiftController;
use App\Http\Controllers\Absensi\AdminWeb\Notifikasi\WebPushSubscriptionController;
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
        Route::get('perubahan-shift', [MobilePerubahanShiftController::class, 'index'])->name('perubahan-shift.index');
        Route::post('perubahan-shift', [MobilePerubahanShiftController::class, 'store'])->middleware('throttle:20,1')->name('perubahan-shift.store');
        Route::get('perubahan-shift/{perubahanShiftRequest}', [MobilePerubahanShiftController::class, 'index'])->whereNumber('perubahanShiftRequest')->name('perubahan-shift.show');
        Route::post('perubahan-shift/{perubahanShiftRequest}/cancel', [MobilePerubahanShiftController::class, 'cancel'])->whereNumber('perubahanShiftRequest')->name('perubahan-shift.cancel');
        Route::post('perubahan-shift/{perubahanShiftRequest}/decision', [MobilePerubahanShiftController::class, 'decide'])->whereNumber('perubahanShiftRequest')->name('perubahan-shift.decide');
        Route::get('notifikasi/perubahan-shift', fn () => to_route('absensi.mobile.perubahan-shift.index', ['scope' => 'delegation']))->name('notifikasi.perubahan-shift');
        Route::get('lembur', [MobileLemburController::class, 'index'])->name('lembur.index');
        Route::get('lembur/ajukan', [MobileLemburController::class, 'create'])->name('lembur.create');
        Route::get('lembur/gunakan', [MobileLemburController::class, 'createUsage'])->name('lembur.usage.create');
        Route::post('lembur/gunakan', [MobileLemburController::class, 'storeUsage'])->middleware('throttle:20,1')->name('lembur.usage.store');
        Route::post('lembur', [MobileLemburController::class, 'store'])->middleware('throttle:20,1')->name('lembur.store');
        Route::get('lembur/{lemburRequest}', [MobileLemburController::class, 'index'])->whereNumber('lemburRequest')->name('lembur.show');
        Route::post('lembur/{lemburRequest}/decision', [MobileLemburController::class, 'decide'])->name('lembur.decide');
        Route::post('lembur/{lemburRequest}/cancel', [MobileLemburController::class, 'cancel'])->name('lembur.cancel');
        Route::get('lembur/{lemburRequest}/lampiran/{attachment}', [MobileLemburController::class, 'attachment'])->whereNumber('attachment')->name('lembur.attachment');
        Route::get('notifikasi/lembur', fn () => to_route('absensi.mobile.lembur.index', ['scope' => 'delegation']))->name('notifikasi.lembur');
        Route::get('presensi', MobileAttendanceController::class)->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->name('attendance');
        Route::get('presensi/log', AttendanceLogController::class)->middleware('can:'.AccessPermission::MOBILE_HISTORY)->name('attendance.log');
        Route::get('presensi/proses/{attendanceSubmission}', [AttendanceSubmissionController::class, 'show'])
            ->middleware('can:'.AccessPermission::MOBILE_ATTENDANCE)->name('attendance-submissions.show');
        Route::get('cuti', [MobileCutiController::class, 'index'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->name('cuti.index');
        Route::get('cuti/{cutiRequest}', [MobileCutiController::class, 'index'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->name('cuti.show');
        Route::get('notifikasi', [MobileNotificationController::class, 'index'])->middleware('can:'.AccessPermission::MOBILE_NOTIFICATIONS)->name('notifikasi.index');
        Route::get('notifikasi/cuti', [MobileCutiApprovalController::class, 'index'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->name('notifikasi.cuti');
        Route::post('notifikasi/cuti/approve-many', [MobileCutiApprovalController::class, 'approveMany'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->middleware('can:'.AccessPermission::MOBILE_CUTI_APPROVE)->name('notifikasi.cuti.approve-many');
        Route::get('notifikasi/feed', [MobileNotificationController::class, 'feed'])->middleware('can:'.AccessPermission::MOBILE_NOTIFICATIONS)->name('notifikasi.feed');
        Route::get('notifikasi/open/{notification}', [MobileNotificationController::class, 'open'])->middleware('can:'.AccessPermission::MOBILE_NOTIFICATIONS)->name('notifikasi.open');
        Route::post('notifikasi/read-all', [MobileNotificationController::class, 'readAll'])->middleware('can:'.AccessPermission::MOBILE_NOTIFICATIONS)->name('notifikasi.read-all');
        Route::post('notifikasi/push-subscriptions', [WebPushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->middleware('can:'.AccessPermission::MOBILE_NOTIFICATIONS)->name('notifikasi.push.store');
        Route::post('notifikasi/devices', [PushDeviceController::class, 'store'])->middleware('throttle:30,1')->middleware('can:'.AccessPermission::MOBILE_NOTIFICATIONS)->name('notifikasi.devices.store');
        Route::post('cuti', [MobileCutiController::class, 'store'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->middleware('can:'.AccessPermission::MOBILE_CUTI_CREATE)->name('cuti.store');
        Route::post('cuti/{cutiRequest}/cancel', [MobileCutiController::class, 'cancel'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->middleware('can:'.AccessPermission::MOBILE_CUTI_CANCEL)->name('cuti.cancel');
        Route::post('cuti/{cutiRequest}/decision', [MobileCutiController::class, 'decide'])->middleware('can:'.AccessPermission::MOBILE_CUTI_VIEW)->middleware('can:'.AccessPermission::MOBILE_CUTI_APPROVE)->name('cuti.decide');
        Route::get('clock-in', [MobileClockInController::class, 'create'])->middleware(['can:'.AccessPermission::MOBILE_ATTENDANCE, 'can:'.AccessPermission::MOBILE_CLOCK_IN])->name('clock-in.create');
        Route::post('clock-in', [MobileClockInController::class, 'store'])->middleware(['can:'.AccessPermission::MOBILE_ATTENDANCE, 'can:'.AccessPermission::MOBILE_CLOCK_IN])->name('clock-in.store');
        Route::get('clock-in/berhasil', [MobileClockInController::class, 'success'])->middleware(['can:'.AccessPermission::MOBILE_ATTENDANCE, 'can:'.AccessPermission::MOBILE_CLOCK_IN])->name('clock-in.success');
        Route::get('clock-out', [MobileClockOutController::class, 'create'])->middleware(['can:'.AccessPermission::MOBILE_ATTENDANCE, 'can:'.AccessPermission::MOBILE_CLOCK_OUT])->name('clock-out.create');
        Route::post('clock-out', [MobileClockOutController::class, 'store'])->middleware(['can:'.AccessPermission::MOBILE_ATTENDANCE, 'can:'.AccessPermission::MOBILE_CLOCK_OUT])->name('clock-out.store');
        Route::get('clock-out/berhasil', [MobileClockOutController::class, 'success'])->middleware(['can:'.AccessPermission::MOBILE_ATTENDANCE, 'can:'.AccessPermission::MOBILE_CLOCK_OUT])->name('clock-out.success');

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
        Route::get('dashboard', DashboardController::class)->middleware('can:'.AccessPermission::DASHBOARD_VIEW)->name('dashboard');

        Route::get('approval-line', [ApprovalLineController::class, 'index'])->middleware('can:'.AccessPermission::APPROVAL_LINE_VIEW)->name('approval-line.index');
        Route::post('approval-line', [ApprovalLineController::class, 'store'])->middleware('can:'.AccessPermission::APPROVAL_LINE_CREATE)->name('approval-line.store');
        Route::put('approval-line/{approvalLine}', [ApprovalLineController::class, 'update'])->middleware('can:'.AccessPermission::APPROVAL_LINE_UPDATE)->name('approval-line.update');
        Route::delete('approval-line/{approvalLine}', [ApprovalLineController::class, 'destroy'])->middleware('can:'.AccessPermission::APPROVAL_LINE_DELETE)->name('approval-line.destroy');

        Route::post('cuti', [CutiController::class, 'store'])->middleware('can:'.AccessPermission::CUTI_VIEW)->middleware('can:'.AccessPermission::CUTI_CREATE)->name('cuti.store');

        Route::prefix('presensi')->name('presensi.')->group(function () {
            Route::get('pegawai', [PresensiController::class, 'index'])->middleware('can:'.AccessPermission::PRESENSI_VIEW)->name('index');
            Route::get('set-pegawai', [PresensiController::class, 'manage'])->middleware('can:'.AccessPermission::PRESENSI_MANAGE)->name('manage');
            Route::post('set-pegawai', [PresensiController::class, 'store'])->middleware('can:'.AccessPermission::PRESENSI_MANAGE)->name('store');
            Route::put('set-pegawai/{presensi}', [PresensiController::class, 'update'])->middleware('can:'.AccessPermission::PRESENSI_MANAGE)->name('update');
            Route::get('foto/{presensi}/{type}', [PresensiController::class, 'photo'])->name('photo');
        });

        Route::prefix('master-data')->name('master-data.')->group(function () {
            Route::get('cuti', [MasterCutiController::class, 'index'])->middleware('can:'.AccessPermission::MASTER_CUTI_VIEW)->middleware('can:'.AccessPermission::CUTI_VIEW)->name('cuti.index');
            Route::post('cuti', [MasterCutiController::class, 'store'])->middleware('can:'.AccessPermission::MASTER_CUTI_CREATE)->middleware('can:'.AccessPermission::CUTI_VIEW)->middleware('can:'.AccessPermission::CUTI_CREATE)->name('cuti.store');
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
            Route::post('shift/holidays/sync', [HariLiburController::class, 'sync'])
                ->middleware(['can:'.AccessPermission::SHIFT_CREATE, 'can:'.AccessPermission::SHIFT_UPDATE, 'can:'.AccessPermission::SHIFT_DELETE, 'throttle:6,1'])
                ->name('shift.holidays.sync');
            Route::post('shift/holidays', [HariLiburController::class, 'store'])->middleware('can:'.AccessPermission::SHIFT_CREATE)->name('shift.holidays.store');
            Route::put('shift/holidays/{hariLibur}', [HariLiburController::class, 'update'])->middleware('can:'.AccessPermission::SHIFT_UPDATE)->name('shift.holidays.update');
            Route::delete('shift/holidays/{hariLibur}', [HariLiburController::class, 'destroy'])->middleware('can:'.AccessPermission::SHIFT_DELETE)->name('shift.holidays.destroy');
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
Route::middleware(['auth', 'verified', 'can:pengajuan.access'])
    ->prefix('absensi/admin-web')
    ->name('absensi.admin-web.')
    ->group(function () {
        Route::redirect('notifikasi', '/absensi/admin-web/notifikasi/butuh-approval')->middleware('can:'.AccessPermission::CUTI_VIEW);
        Route::get('notifikasi/semua', [NotificationInboxController::class, 'index'])->middleware('can:'.AccessPermission::NOTIFICATIONS_VIEW)->name('notifikasi.all');
        Route::get('notifikasi/feed', [NotificationInboxController::class, 'feed'])->middleware('can:'.AccessPermission::NOTIFICATIONS_VIEW)->name('notifikasi.feed');
        Route::post('notifikasi/read-all', [NotificationInboxController::class, 'readAll'])->middleware('can:'.AccessPermission::NOTIFICATIONS_VIEW)->name('notifikasi.read-all');
        Route::get('notifikasi/open/{notification}', [NotificationInboxController::class, 'open'])->middleware('can:'.AccessPermission::NOTIFICATIONS_VIEW)->name('notifikasi.open');
        Route::get('notifikasi/pengajuan/cuti/{cutiRequest}', [NotificationInboxController::class, 'cuti'])->middleware('can:'.AccessPermission::CUTI_VIEW)->name('notifikasi.cuti');
        Route::get('notifikasi/pengajuan/perubahan-shift/{perubahanShiftRequest}', [WebPerubahanShiftController::class, 'show'])->middleware('can:'.AccessPermission::CUTI_VIEW)->name('notifikasi.perubahan-shift');
        Route::post('notifikasi/pengajuan/perubahan-shift/{perubahanShiftRequest}/decision', [WebPerubahanShiftController::class, 'decide'])->middleware(['can:'.AccessPermission::CUTI_VIEW, 'can:'.AccessPermission::CUTI_APPROVE])->name('notifikasi.perubahan-shift.decide');
        Route::get('notifikasi/pengajuan/lembur/{lemburRequest}', [WebLemburController::class, 'show'])->middleware('can:'.AccessPermission::CUTI_VIEW)->name('notifikasi.lembur');
        Route::post('notifikasi/pengajuan/lembur/{lemburRequest}/decision', [WebLemburController::class, 'decide'])->middleware(['can:'.AccessPermission::CUTI_VIEW, 'can:'.AccessPermission::CUTI_APPROVE])->name('notifikasi.lembur.decide');
        Route::get('notifikasi/pengajuan/lembur/{lemburRequest}/lampiran/{attachment}', [MobileLemburController::class, 'attachment'])->whereNumber('attachment')->middleware('can:'.AccessPermission::CUTI_VIEW)->name('notifikasi.lembur.attachment');
        Route::post('notifikasi/push-subscriptions', [WebPushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->middleware('can:'.AccessPermission::NOTIFICATIONS_VIEW)->name('notifikasi.push.store');
        Route::get('notifikasi/butuh-approval', [NotifikasiController::class, 'index'])->middleware('can:'.AccessPermission::CUTI_VIEW)->name('notifikasi.index');
        Route::post('notifikasi/approve-many', [NotifikasiController::class, 'approveMany'])->middleware('can:'.AccessPermission::CUTI_VIEW)->middleware('can:'.AccessPermission::CUTI_APPROVE)->name('notifikasi.approve-many');
        Route::get('notifikasi/disetujui', [NotifikasiController::class, 'index'])->defaults('category', 'disetujui')->middleware('can:'.AccessPermission::CUTI_VIEW)->name('notifikasi.approved');
        Route::get('cuti', [CutiController::class, 'index'])->middleware(['can:cuti.access', 'can:'.AccessPermission::CUTI_VIEW])->name('cuti.index');
        Route::post('cuti/{cutiRequest}/decision', [CutiController::class, 'decide'])->middleware(['can:cuti.access', 'can:'.AccessPermission::CUTI_VIEW, 'can:'.AccessPermission::CUTI_APPROVE])->name('cuti.decide');
    });
