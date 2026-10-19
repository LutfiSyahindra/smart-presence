<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Cuti;

use App\Models\CutiRequest;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterCutiService
{
    public function save(array $data, ?MasterCuti $master = null): MasterCuti
    {
        return DB::transaction(function () use ($data, $master) {
            if ($master) {
                $master = MasterCuti::query()->lockForUpdate()->findOrFail($master->id);
                if ($master->jenis !== $data['jenis'] && ($master->allocations()->exists() || $master->requests()->exists())) {
                    throw ValidationException::withMessages(['jenis' => 'Kategori cuti yang sudah diberikan atau diajukan tidak dapat diubah.']);
                }
                $master->update($data);
                if ($master->jenis !== 'tahunan') {
                    $master->allocations()->update(['jumlah_hari' => $master->durasi_hari]);
                }
            } else {
                $master = MasterCuti::query()->create($data);
            }

            return $master;
        });
    }

    public function delete(MasterCuti $master): void
    {
        DB::transaction(function () use ($master) {
            $master = MasterCuti::query()->lockForUpdate()->findOrFail($master->id);
            if ($master->requests()->exists()) {
                throw ValidationException::withMessages(['master_cuti' => 'Jenis cuti sudah digunakan pada pengajuan. Nonaktifkan jenis cuti untuk mempertahankan riwayat.']);
            }
            $master->delete();
        });
    }

    private function employees(array $data): Collection
    {
        $employees = Pegawai::query()->whereHas('user')
            ->when($data['scope'] === 'selected', fn ($query) => $query->whereIn('id', $data['pegawai_ids']))
            ->orderBy('id')->lockForUpdate()->get();
        if ($employees->isEmpty() || ($data['scope'] === 'selected' && $employees->count() !== count($data['pegawai_ids']))) {
            throw ValidationException::withMessages(['pegawai_ids' => 'Pilih pegawai yang memiliki akun user.']);
        }

        return $employees;
    }

    public function allocate(MasterCuti $master, array $data, int $actor): int
    {
        return DB::transaction(function () use ($master, $data, $actor) {
            $employees = $this->employees($data);
            $master = MasterCuti::query()->findOrFail($master->id);
            if (! $master->aktif) {
                throw ValidationException::withMessages(['master_cuti' => 'Aktifkan jenis cuti sebelum memberikannya kepada pegawai.']);
            }
            foreach ($employees as $pegawai) {
                // Reassigning must preserve a manually configured annual allowance.
                PegawaiCuti::query()->firstOrCreate([
                    'master_cuti_id' => $master->id, 'pegawai_id' => $pegawai->id,
                    'tahun' => $master->jenis === 'tahunan' ? $data['tahun'] : 0,
                ], ['jumlah_hari' => $master->durasi_hari, 'updated_by' => $actor]);
            }

            return $employees->count();
        });
    }

    public function annualMaster(): MasterCuti
    {
        $master = MasterCuti::query()->where('jenis', 'tahunan')->first();
        if (! $master) {
            throw ValidationException::withMessages(['master_cuti' => 'Tambahkan jenis cuti dengan kategori Cuti Tahunan terlebih dahulu.']);
        }

        return $master;
    }

    public function usage(int $masterId, int $tahun, ?array $pegawaiIds = null): array
    {
        return CutiRequest::query()->where('master_cuti_id', $masterId)->where('tahun_cuti', $tahun)
            ->whereIn('status', ['pending', 'approved'])
            ->when($pegawaiIds !== null, fn ($query) => $query->whereIn('pegawai_id', $pegawaiIds))
            ->selectRaw('pegawai_id, status, SUM(jumlah_hari) AS days')->groupBy('pegawai_id', 'status')
            ->get()->groupBy('pegawai_id')->map(fn ($rows) => [
                'terpakai' => (int) ($rows->firstWhere('status', 'approved')?->days ?? 0),
                'menunggu' => (int) ($rows->firstWhere('status', 'pending')?->days ?? 0),
            ])->all();
    }

    public function setAnnual(array $data, int $actor): int
    {
        return DB::transaction(function () use ($data, $actor) {
            $employees = $this->employees($data);
            $master = $this->annualMaster();
            $items = $employees->map(fn ($pegawai) => ['pegawai_id' => $pegawai->id, 'jumlah_hari' => $data['jumlah_hari']])->all();

            return $this->writeAnnual($master, (int) $data['tahun'], $items, $actor, 'jumlah_hari');
        });
    }

    public function importAnnual(int $tahun, array $items, int $actor): int
    {
        return DB::transaction(function () use ($tahun, $items, $actor) {
            $this->employees(['scope' => 'selected', 'pegawai_ids' => array_column($items, 'pegawai_id')]);

            return $this->writeAnnual($this->annualMaster(), $tahun, $items, $actor, 'file');
        });
    }

    private function writeAnnual(MasterCuti $master, int $tahun, array $items, int $actor, string $errorKey): int
    {
        $usage = $this->usage($master->id, $tahun, array_column($items, 'pegawai_id'));
        foreach ($items as $item) {
            $used = $usage[$item['pegawai_id']] ?? ['terpakai' => 0, 'menunggu' => 0];
            $minimum = $used['terpakai'] + $used['menunggu'];
            if ($item['jumlah_hari'] < $minimum) {
                $source = isset($item['source_row']) ? "Baris {$item['source_row']}" : 'Pegawai #'.$item['pegawai_id'];
                throw ValidationException::withMessages([$errorKey => "{$source}: kuota tidak boleh kurang dari {$minimum} hari yang sudah disetujui atau sedang menunggu."]);
            }
        }
        foreach ($items as $item) {
            PegawaiCuti::query()->updateOrCreate([
                'master_cuti_id' => $master->id, 'pegawai_id' => $item['pegawai_id'], 'tahun' => $tahun,
            ], ['jumlah_hari' => $item['jumlah_hari'], 'updated_by' => $actor]);
        }

        return count($items);
    }

    public function options(Pegawai $pegawai): array
    {
        $allocations = PegawaiCuti::query()->with('masterCuti')->where('pegawai_id', $pegawai->id)
            ->whereHas('masterCuti', fn ($query) => $query->where('aktif', true))->orderBy('tahun')->get();
        $usage = CutiRequest::query()->where('pegawai_id', $pegawai->id)->where('is_tahunan', true)
            ->whereIn('status', ['pending', 'approved'])->selectRaw('master_cuti_id, tahun_cuti, SUM(jumlah_hari) AS days')
            ->groupBy('master_cuti_id', 'tahun_cuti')->get()->keyBy(fn ($row) => $row->master_cuti_id.':'.$row->tahun_cuti);

        return $allocations->map(fn ($allocation) => [
            'id' => $allocation->master_cuti_id, 'nama' => $allocation->masterCuti->nama,
            'is_tahunan' => $allocation->masterCuti->jenis === 'tahunan', 'tahun' => $allocation->tahun,
            'jumlah_hari' => $allocation->jumlah_hari,
            'sisa' => max(0, $allocation->jumlah_hari - (int) ($usage->get($allocation->master_cuti_id.':'.$allocation->tahun)?->days ?? 0)),
        ])->all();
    }
}
