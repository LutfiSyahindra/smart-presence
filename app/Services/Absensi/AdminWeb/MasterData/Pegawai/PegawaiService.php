<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Pegawai;

use App\Models\Pegawai;
use App\Repositories\Absensi\AdminWeb\MasterData\Pegawai\PegawaiRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class PegawaiService
{
    public function __construct(
        private readonly PegawaiRepositoryInterface $pegawais,
        private readonly PegawaiSpreadsheetService $spreadsheets,
    ) {}

    /** @param array{search?: string|null, birthday?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters['search'] = trim((string) ($filters['search'] ?? '')) ?: null;
        $filters['birthday'] = ($filters['birthday'] ?? null) === 'this_month' ? 'this_month' : null;

        return $this->pegawais->paginate($filters)->through(fn (Pegawai $pegawai) => [
            'id' => $pegawai->id,
            'nama' => $pegawai->nama,
            'tanggal_lahir' => $pegawai->tanggal_lahir?->format('Y-m-d'),
            'tanggal_lahir_label' => $pegawai->tanggal_lahir?->translatedFormat('d M Y'),
            'usia' => $pegawai->tanggal_lahir?->age,
            'nomor_telepon' => $pegawai->nomor_telepon,
            'created_at' => $pegawai->created_at?->format('d M Y'),
            'updated_at' => $pegawai->updated_at?->format('d M Y, H:i'),
        ]);
    }

    /** @return array{total: int, birthdays_this_month: int, added_this_month: int, average_age: int} */
    public function overview(): array
    {
        return $this->pegawais->overview();
    }

    public function template(): Spreadsheet
    {
        return $this->spreadsheets->template();
    }

    public function import(UploadedFile $file): int
    {
        $items = $this->spreadsheets->read($file);
        $existing = $this->pegawais->existingPhoneNumbers(array_column($items, 'nomor_telepon'));

        if ($existing !== []) {
            $rows = collect($items)
                ->whereIn('nomor_telepon', $existing)
                ->map(fn (array $item) => $item['source_row'])
                ->implode(', ');

            throw ValidationException::withMessages([
                'file' => "Nomor telfon pada baris {$rows} sudah terdaftar. Perbarui file lalu coba kembali.",
            ]);
        }

        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                unset($item['source_row']);
                $this->pegawais->create($item);
            }
        });

        return count($items);
    }

    /** @param array{nama: string, tanggal_lahir: string, nomor_telepon: string} $data */
    public function create(array $data): Pegawai
    {
        return $this->pegawais->create($data);
    }

    /** @param array{nama: string, tanggal_lahir: string, nomor_telepon: string} $data */
    public function update(Pegawai $pegawai, array $data): Pegawai
    {
        return $this->pegawais->update($pegawai, $data);
    }

    public function delete(Pegawai $pegawai): void
    {
        $this->pegawais->delete($pegawai);
    }
}
