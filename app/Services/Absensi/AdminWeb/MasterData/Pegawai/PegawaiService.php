<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Repositories\Absensi\AdminWeb\MasterData\Pegawai\PegawaiRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Role;

class PegawaiService
{
    public function __construct(
        private readonly PegawaiRepositoryInterface $pegawais,
        private readonly PegawaiSpreadsheetService $spreadsheets,
    ) {}

    /** @param array{search?: string|null, status?: string|null, unit_kerja?: int|string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;
        $filters['status'] = in_array($filters['status'] ?? null, ['Tetap', 'Tidak Tetap'], true)
            ? $filters['status']
            : null;
        $filters['unit_kerja'] = filter_var($filters['unit_kerja'] ?? null, FILTER_VALIDATE_INT) ?: null;

        return $this->pegawais->paginate($filters)->through(fn (Pegawai $pegawai) => [
            'id' => $pegawai->id,
            'nik' => $pegawai->nik,
            'nama' => $pegawai->nama,
            'profesi' => $pegawai->profesi,
            'unit_kerja_id' => $pegawai->unit_kerja_id,
            'unit_kerja' => $pegawai->unitKerja?->nama,
            'jabatan_id' => $pegawai->jabatan_id,
            'jabatan' => $pegawai->jabatan?->nama,
            'jenis_kelamin' => $pegawai->jenis_kelamin,
            'status_pegawai' => $pegawai->status_pegawai,
            'tanggal_mulai_bekerja' => $pegawai->tanggal_mulai_bekerja?->format('Y-m-d'),
            'tanggal_mulai_bekerja_label' => $pegawai->tanggal_mulai_bekerja?->translatedFormat('d M Y'),
            'tanggal_lahir' => $pegawai->tanggal_lahir?->format('Y-m-d'),
            'tanggal_lahir_label' => $pegawai->tanggal_lahir?->translatedFormat('d M Y'),
            'usia' => $pegawai->tanggal_lahir?->age,
            'nomor_telepon' => $pegawai->nomor_telepon,
            'roles' => $pegawai->roles->pluck('name')->values()->all(),
            'created_at' => $pegawai->created_at?->format('d M Y'),
            'updated_at' => $pegawai->updated_at?->format('d M Y, H:i'),
        ]);
    }

    /** @return array{total: int, tetap: int, tidak_tetap: int, joined_this_year: int} */
    public function overview(): array
    {
        return $this->pegawais->overview();
    }

    /** @return array<int, array{id: int, nama: string, jabatans: array<int, array{id: int, nama: string}>}> */
    public function formOptions(): array
    {
        return UnitKerja::query()
            ->where('aktif', true)
            ->with(['jabatans' => fn ($query) => $query->where('aktif', true)->select('id', 'unit_kerja_id', 'nama')])
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn (UnitKerja $unit) => [
                'id' => (int) $unit->id,
                'nama' => $unit->nama,
                'jabatans' => $unit->jabatans->map(fn ($jabatan) => [
                    'id' => (int) $jabatan->id,
                    'nama' => $jabatan->nama,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function template(): Spreadsheet
    {
        return $this->spreadsheets->template();
    }

    public function import(UploadedFile $file): int
    {
        $items = $this->spreadsheets->read($file);
        $existingNiks = $this->pegawais->existingNiks(array_values(array_filter(array_column($items, 'nik'))));
        if ($existingNiks !== []) {
            $rows = collect($items)
                ->whereIn('nik', $existingNiks)
                ->map(fn (array $item) => $item['source_row'])
                ->implode(', ');

            throw ValidationException::withMessages([
                'file' => "NIK pada baris {$rows} sudah terdaftar. Perbarui file lalu coba kembali.",
            ]);
        }

        $existing = $this->pegawais->existingPhoneNumbers(array_values(array_filter(array_column($items, 'nomor_telepon'))));

        if ($existing !== []) {
            $rows = collect($items)
                ->whereIn('nomor_telepon', $existing)
                ->map(fn (array $item) => $item['source_row'])
                ->implode(', ');

            throw ValidationException::withMessages([
                'file' => "Nomor telfon pada baris {$rows} sudah terdaftar. Perbarui file lalu coba kembali.",
            ]);
        }

        $items = $this->resolveOrganization($items);

        DB::transaction(function () use ($items): void {
            Role::findOrCreate('pegawai', 'web');

            foreach ($items as $item) {
                unset($item['source_row']);
                $pegawai = $this->pegawais->create($item);
                $pegawai->assignRole('pegawai');
            }
        });

        return count($items);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Pegawai
    {
        return DB::transaction(function () use ($data): Pegawai {
            Role::findOrCreate('pegawai', 'web');
            $pegawai = $this->pegawais->create($data);
            $pegawai->assignRole('pegawai');

            return $pegawai->load(['unitKerja', 'jabatan', 'roles']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Pegawai $pegawai, array $data): Pegawai
    {
        return DB::transaction(function () use ($pegawai, $data): Pegawai {
            $pegawai = $this->pegawais->update($pegawai, $data);
            Role::findOrCreate('pegawai', 'web');
            $pegawai->syncRoles(['pegawai']);

            return $pegawai->load(['unitKerja', 'jabatan', 'roles']);
        });
    }

    public function delete(Pegawai $pegawai): void
    {
        $this->pegawais->delete($pegawai);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function resolveOrganization(array $items): array
    {
        if (collect($items)->every(fn (array $item) => blank($item['unit_kerja'] ?? null))) {
            return array_map(function (array $item): array {
                unset($item['unit_kerja'], $item['jabatan']);

                return $item;
            }, $items);
        }

        $units = UnitKerja::query()
            ->with('jabatans:id,unit_kerja_id,nama')
            ->get()
            ->keyBy(fn (UnitKerja $unit) => str($unit->nama)->lower()->squish()->toString());
        $errors = [];

        foreach ($items as &$item) {
            $unitKey = str((string) ($item['unit_kerja'] ?? ''))->lower()->squish()->toString();
            $jabatanKey = str((string) ($item['jabatan'] ?? ''))->lower()->squish()->toString();
            $unit = $units->get($unitKey);

            if ($unit === null) {
                $errors[] = "Baris {$item['source_row']}: unit kerja {$item['unit_kerja']} belum tersedia di master.";

                continue;
            }

            $jabatan = $unit->jabatans->first(
                fn ($jabatan) => str($jabatan->nama)->lower()->squish()->toString() === $jabatanKey,
            );

            if ($jabatan === null) {
                $errors[] = "Baris {$item['source_row']}: jabatan {$item['jabatan']} belum tersedia pada unit {$unit->nama}.";

                continue;
            }

            $item['unit_kerja_id'] = $unit->id;
            $item['jabatan_id'] = $jabatan->id;
            unset($item['unit_kerja'], $item['jabatan']);
        }
        unset($item);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'file' => implode(' ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' Periksa juga baris lainnya.' : ''),
            ]);
        }

        return $items;
    }
}
