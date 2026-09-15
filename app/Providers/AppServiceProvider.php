<?php

namespace App\Providers;

use App\Models\User;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\JabatanRepository;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\JabatanRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\UnitKerjaRepository;
use App\Repositories\Absensi\AdminWeb\MasterData\Jabatan\UnitKerjaRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\MasterData\Pegawai\PegawaiRepository;
use App\Repositories\Absensi\AdminWeb\MasterData\Pegawai\PegawaiRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\MasterData\Shift\ShiftRepository;
use App\Repositories\Absensi\AdminWeb\MasterData\Shift\ShiftRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Presensi\PresensiRepository;
use App\Repositories\Absensi\AdminWeb\Presensi\PresensiRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\Permission\PermissionRepository;
use App\Repositories\Absensi\AdminWeb\Setting\Permission\PermissionRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\Role\RoleRepository;
use App\Repositories\Absensi\AdminWeb\Setting\Role\RoleRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\User\UserRepository;
use App\Repositories\Absensi\AdminWeb\Setting\User\UserRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JabatanRepositoryInterface::class, JabatanRepository::class);
        $this->app->bind(UnitKerjaRepositoryInterface::class, UnitKerjaRepository::class);
        $this->app->bind(PegawaiRepositoryInterface::class, PegawaiRepository::class);
        $this->app->bind(ShiftRepositoryInterface::class, ShiftRepository::class);
        $this->app->bind(PresensiRepositoryInterface::class, PresensiRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->hasRole('super-admin') ? true : null;
        });
    }
}
