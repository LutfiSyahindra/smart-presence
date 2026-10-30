<?php

namespace App\Providers;

use App\Contracts\FaceVerifier;
use App\Contracts\ProfilePhotoValidator;
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
use App\Services\Absensi\AbsensiMobile\FastApiFaceVerifier;
use App\Services\MobileDataChanges;
use App\Support\CutiAccess;
use App\Support\PengajuanAccess;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(MobileDataChanges::class);
        $this->app->scoped(\App\Services\Absensi\ConfigurationService::class);
        $this->app->bind(JabatanRepositoryInterface::class, JabatanRepository::class);
        $this->app->bind(UnitKerjaRepositoryInterface::class, UnitKerjaRepository::class);
        $this->app->bind(PegawaiRepositoryInterface::class, PegawaiRepository::class);
        $this->app->bind(ShiftRepositoryInterface::class, ShiftRepository::class);
        $this->app->bind(PresensiRepositoryInterface::class, PresensiRepository::class);
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(RoleRepositoryInterface::class, RoleRepository::class);
        $this->app->bind(PermissionRepositoryInterface::class, PermissionRepository::class);
        $this->app->singleton(FaceVerifier::class, function (Application $app): FaceVerifier {
            $driver = $app['config']->get('face-verification.driver');

            if ($driver === 'fastapi') {
                $fastApi = $app['config']->get('face-verification.fastapi');

                return new FastApiFaceVerifier(
                    baseUrl: (string) $fastApi['url'],
                    token: (string) $fastApi['token'],
                    connectTimeout: max(1, (int) $fastApi['connect_timeout']),
                    timeout: max(1, (int) $fastApi['timeout']),
                    minimumSimilarity: max(51, min(100, (float) $app['config']->get('face-verification.minimum_similarity', 51))),
                );
            }

            throw new \LogicException("Driver verifikasi wajah [{$driver}] tidak didukung.");
        });
        $this->app->bind(ProfilePhotoValidator::class, fn (Application $app): ProfilePhotoValidator => $app->make(FaceVerifier::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(fn (QueryExecuted $query) => app(MobileDataChanges::class)->record($query));
        $flush = fn () => app(MobileDataChanges::class)->flush();
        // Coalesce writes from an entire HTTP request / queue job / CLI command.
        $this->app->terminating($flush);
        Event::listen(CommandFinished::class, $flush);
        Event::listen(JobProcessed::class, $flush);
        Event::listen(JobExceptionOccurred::class, $flush);

        Gate::define('cuti.access', fn (User $user): bool => CutiAccess::allows($user));
        Gate::define('pengajuan.access', fn (User $user): bool => PengajuanAccess::allows($user));

        Gate::before(function (User $user): ?bool {
            return $user->hasRole('super-admin') ? true : null;
        });
    }
}
