<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Lembur;

use App\Models\Pegawai;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

class LemburBalanceSpreadsheetService
{
    private const MAX_ROWS = 1000;

    private const HEADERS = ['NIK', 'Nama Pegawai', 'Saldo Lembur (Menit)'];

    private function employees(array $selection): Builder
    {
        return Pegawai::query()->whereNotNull('nik')->where('nik', '!=', '')
            ->when(($selection['scope'] ?? 'all') === 'organization', fn (Builder $query) => $query
                ->when($selection['unit_id'] ?? null, fn (Builder $query, $unit) => $query->where('unit_kerja_id', $unit))
                ->when($selection['position_id'] ?? null, fn (Builder $query, $position) => $query->where('jabatan_id', $position)))
            ->when(($selection['scope'] ?? 'all') === 'employees', fn (Builder $query) => $query->whereIn('id', $selection['pegawai_ids'] ?? []));
    }

    public function template(array $selection = []): Spreadsheet
    {
        $employees = $this->employees($selection)->orderBy('nama')->orderBy('id')->limit(self::MAX_ROWS + 1)->get();
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['scope' => 'Tidak ada pegawai dengan NIK pada pilihan ini.']);
        }
        if ($employees->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['scope' => 'Pilihan melebihi 1.000 pegawai. Batasi berdasarkan unit, jabatan, atau pegawai tertentu.']);
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Saldo Lembur');
        $sheet->fromArray([self::HEADERS]);
        $sheet->freezePane('C2');
        $sheet->setAutoFilter('A1:C'.($employees->count() + 1));
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '087F78']],
        ]);
        foreach (['A' => 24, 'B' => 40, 'C' => 26] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach ($employees as $index => $pegawai) {
            $row = $index + 2;
            $sheet->setCellValueExplicit('A'.$row, $pegawai->nik, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, $pegawai->nama, DataType::TYPE_STRING);
        }
        $last = max(2, $employees->count() + 1);
        $sheet->getStyle('A2:A'.$last)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('C2:C'.$last)->applyFromArray([
            'font' => ['color' => ['rgb' => '0000FF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF6DD']],
            'numberFormat' => ['formatCode' => '0'],
        ]);
        $sheet->getComment('C1')->getText()->createTextRun('Isi jumlah menit bulat, misalnya 150 untuk 2 jam 30 menit. Kosong = dilewati; 0 = saldo nol.');
        $guide = $book->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->fromArray([
            ['PENGATURAN SALDO LEMBUR'],
            ['Isi kolom Saldo Lembur (Menit) untuk setiap pegawai dengan jumlah menit bulat.'],
            ['Contoh: 2 jam 30 menit diisi 150; 12 jam diisi 720. Nilai langsung disimpan sebagai menit.'],
            ['Angka yang diisi mengganti saldo TERSEDIA saat ini. Penggunaan aktif tetap dicatat terpisah.'],
            ['Kosongkan saldo untuk melewati pegawai; angka 0 mengatur saldo tersedia menjadi nol.'],
            ['NIK harus sesuai data dan cakupan pegawai yang dipilih di modal. Pertahankan nol di awal NIK; nama hanya referensi.'],
            ['Saldo: 0 sampai '.LemburBalanceService::MAX_MINUTES.' menit. Angka desimal tidak diperbolehkan. Maksimal 1.000 baris pegawai dan 5 MB per file.'],
            ['Jangan mengubah header, memakai formula, atau mengulang NIK yang sama.'],
            ['Seluruh file divalidasi sebelum disimpan; satu baris tidak valid membatalkan seluruh impor.'],
            ['Pengaturan saldo tersimpan dengan nama admin dan catatan impor.'],
        ]);
        $guide->getColumnDimension('A')->setWidth(110);
        $guide->getStyle('A1:A10')->getAlignment()->setWrapText(true);
        $guide->getStyle('A1')->getFont()->setBold(true);
        foreach (range(2, 10) as $row) {
            $guide->getRowDimension($row)->setRowHeight(32);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function read(UploadedFile $file, array $selection = []): array
    {
        $book = null;
        try {
            $reader = IOFactory::createReader(IOFactory::identify($file->getRealPath(), ['Xlsx', 'Xls']));
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheetByName('Saldo Lembur') ?? $book->getSheet(0);
            foreach (self::HEADERS as $index => $header) {
                if (trim((string) $sheet->getCell([$index + 1, 1])->getValue()) !== $header) {
                    throw ValidationException::withMessages(['file' => 'Header tidak sesuai. Gunakan template Excel Saldo Lembur dengan kolom Saldo Lembur (Menit).']);
                }
            }
            if ($sheet->getHighestDataRow() > self::MAX_ROWS + 1) {
                throw ValidationException::withMessages(['file' => 'Maksimal 1.000 baris pegawai per file.']);
            }
            $employees = Pegawai::query()->whereNotNull('nik')->get(['id', 'nik'])->groupBy('nik');
            $allowed = $this->employees($selection)->pluck('id')->flip();
            $items = $seen = $errors = [];
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                foreach (range(1, count(self::HEADERS)) as $column) {
                    if ($sheet->getCell([$column, $row])->getDataType() === DataType::TYPE_FORMULA) {
                        $errors[] = "Baris {$row}: formula tidak diizinkan.";

                        continue 2;
                    }
                }
                $value = $sheet->getCell([3, $row])->getValue();
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $nik = trim((string) $sheet->getCell([1, $row])->getValue());
                $matches = $employees->get($nik, collect());
                if ($nik === '' || $matches->count() !== 1) {
                    $errors[] = "Baris {$row}: NIK {$nik} tidak ditemukan atau tidak unik pada data pegawai.";

                    continue;
                }
                $pegawai = $matches->first();
                if (! $allowed->has($pegawai->id)) {
                    $errors[] = "Baris {$row}: NIK {$nik} berada di luar cakupan pegawai yang dipilih.";

                    continue;
                }
                if (isset($seen[$pegawai->id])) {
                    $errors[] = "Baris {$row}: NIK {$nik} ditulis lebih dari sekali.";

                    continue;
                }
                $seen[$pegawai->id] = true;
                $validator = Validator::make(['minutes' => $value], ['minutes' => ['required', 'integer', 'between:0,'.LemburBalanceService::MAX_MINUTES]]);
                if ($validator->fails()) {
                    $errors[] = "Baris {$row}: saldo harus berupa menit bulat antara 0 dan ".LemburBalanceService::MAX_MINUTES.'.';

                    continue;
                }
                $items[] = ['pegawai_id' => $pegawai->id, 'minutes' => (int) $value];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => implode(' ', array_slice($errors, 0, 10))]);
            }
            if ($items === []) {
                throw ValidationException::withMessages(['file' => 'Isi saldo lembur untuk minimal satu pegawai.']);
            }

            return $items;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'File Excel tidak dapat dibaca. Gunakan template yang tidak rusak atau diproteksi.']);
        } finally {
            $book?->disconnectWorksheets();
        }
    }
}
