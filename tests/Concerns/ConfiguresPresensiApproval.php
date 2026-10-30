<?php

namespace Tests\Concerns;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;

trait ConfiguresPresensiApproval
{
    private function configurePresensiApproval(Pegawai $pegawai): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'RAD', 'nama' => 'Unit Radius', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'RAD', 'nama' => 'Pegawai', 'aktif' => true]);
        $pegawai->update(['unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id]);
        $approver = User::factory()->create()->assignRole('pegawai');
        $line = ApprovalLine::query()->create(['type' => ApprovalLine::TYPE_PRESENSI]);
        $line->jabatans()->attach($position->id, ['type' => ApprovalLine::TYPE_PRESENSI]);
        $line->steps()->create(['user_id' => $approver->id, 'level' => 1]);
    }
}
