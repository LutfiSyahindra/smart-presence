<?php

namespace App\Support;

use App\Models\AbsensiConfiguration;
use App\Models\ApprovalLine;
use App\Models\Pegawai;
use App\Models\User;
use App\Services\Absensi\ConfigurationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PresensiPegawaiAccess
{
    public static function allows(User $user): bool
    {
        if (! $user->can(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW)) {
            return false;
        }

        return match (self::scope($user)) {
            AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_ALL => true,
            AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_UNIT => $user->pegawai()
                ->whereHas('unitKerja', fn (Builder $query) => $query->where('aktif', true))->exists(),
            AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE => self::approvalLines($user)
                ->whereHas('jabatans', fn (Builder $query) => $query->where('aktif', true)
                    ->whereHas('unitKerja', fn (Builder $query) => $query->where('aktif', true)))->exists(),
            default => false,
        };
    }

    /** @return Builder<Pegawai> */
    public static function employees(User $user): Builder
    {
        // Scope is always resolved on the server, including for super-admins.
        $scope = self::scope($user);
        if ($scope === AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_ALL) {
            return Pegawai::query();
        }

        $query = Pegawai::query()
            ->where(fn (Builder $query) => $query->whereNull('pegawais.user_id')->orWhere('pegawais.user_id', '!=', $user->id));

        return match ($scope) {
            AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_UNIT => $query->whereIn('pegawais.unit_kerja_id',
                $user->pegawai()->select('unit_kerja_id')->whereHas('unitKerja', fn (Builder $query) => $query->where('aktif', true))),
            AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE => $query
                ->whereHas('unitKerja', fn (Builder $query) => $query->where('aktif', true))
                ->whereHas('jabatan', fn (Builder $query) => $query->where('aktif', true)
                    ->whereHas('unitKerja', fn (Builder $query) => $query->where('aktif', true)))
                ->whereIn('pegawais.jabatan_id', DB::table('approval_line_jabatan')->select('jabatan_id')
                    ->where('type', app(ConfigurationService::class)->current()->mobile_presensi_pegawai_approval_type)
                    ->whereIn('approval_line_id', self::approvalLines($user)->select('approval_lines.id'))),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public static function scope(User $user): string
    {
        $configuration = app(ConfigurationService::class)->current();
        $roleIds = $configuration->mobile_presensi_pegawai_all_role_ids ?? [];

        if ($roleIds !== [] && $user->roles()->where('guard_name', 'web')->whereIn('roles.id', $roleIds)->exists()) {
            return AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_ALL;
        }

        return $configuration->mobile_presensi_pegawai_scope;
    }

    /** @return Builder<ApprovalLine> */
    private static function approvalLines(User $user): Builder
    {
        $type = app(ConfigurationService::class)->current()->mobile_presensi_pegawai_approval_type;

        return ApprovalLine::query()->where('type', $type)
            ->whereHas('steps', fn (Builder $query) => $query->where('user_id', $user->id))
            ->when(! in_array($type, ApprovalLine::types(), true), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }
}
