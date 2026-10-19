<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Cuti;

use App\Models\Pegawai;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

class AnnualCutiSpreadsheetService
{
    private const MAX_ROWS = 1000;

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Cuti Tahunan');
        $sheet->fromArray([['NIK', 'Nama Pegawai', 'Jumlah Cuti Tahunan']]);
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6056C9']],
        ]);
        foreach (['A' => 24, 'B' => 38, 'C' => 28] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A2:A1001')->getNumberFormat()->setFormatCode('@');
        // Prepopulate identities only; empty allowances are skipped during import.
        foreach (Pegawai::query()->whereHas('user')->whereNotNull('nik')->orderBy('nama')->limit(self::MAX_ROWS)->get() as $index => $pegawai) {
            $sheet->setCellValueExplicit('A'.($index + 2), $pegawai->nik, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.($index + 2), $pegawai->nama, DataType::TYPE_STRING);
        }
        $guide = $book->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->fromArray([
            ['PETUNJUK CUTI TAHUNAN'],
            ['Isi kuota TOTAL cuti tahunan (0–366), termasuk hari yang sudah dipakai atau menunggu persetujuan.'],
            ['Tahun mengikuti pilihan tahun di halaman Master Cuti.'],
            ['NIK harus sesuai data pegawai yang memiliki akun user. Pertahankan nol di awal NIK.'],
            ['Nama Pegawai hanya untuk referensi; pencocokan menggunakan NIK.'],
            ['Kosongkan Jumlah Cuti Tahunan untuk melewati pegawai. Angka 0 mengatur kuota menjadi nol.'],
            ['Maksimal 1.000 pegawai per file. Jangan memakai formula atau mengubah header.'],
            ['Seluruh file divalidasi sebelum disimpan. Kuota manual sebelumnya diganti untuk pegawai yang diisi.'],
        ]);
        $guide->getColumnDimension('A')->setWidth(110);
        $guide->getStyle('A1:A8')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function read(UploadedFile $file): array
    {
        $book = null;
        try {
            $reader = IOFactory::createReader(IOFactory::identify($file->getRealPath(), ['Xlsx', 'Xls']));
            $reader->setReadDataOnly(true);
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheetByName('Cuti Tahunan') ?? $book->getSheet(0);
            $expected = ['NIK', 'Nama Pegawai', 'Jumlah Cuti Tahunan'];
            foreach ($expected as $index => $header) {
                if (trim((string) $sheet->getCell([$index + 1, 1])->getValue()) !== $header) {
                    throw ValidationException::withMessages(['file' => 'Header tidak sesuai. Gunakan template Excel Cuti Tahunan.']);
                }
            }
            if ($sheet->getHighestDataRow() > self::MAX_ROWS + 1) {
                throw ValidationException::withMessages(['file' => 'Maksimal 1.000 baris pegawai dalam satu file.']);
            }
            $employees = Pegawai::query()->whereHas('user')->whereNotNull('nik')->get()->keyBy('nik');
            $items = [];
            $seen = [];
            $errors = [];
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                foreach (range(1, 3) as $column) {
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
                $pegawai = $employees->get($nik);
                if (! $pegawai) {
                    $errors[] = "Baris {$row}: NIK {$nik} tidak ditemukan pada pegawai yang memiliki akun user.";

                    continue;
                }
                if (isset($seen[$pegawai->id])) {
                    $errors[] = "Baris {$row}: NIK {$nik} ditulis lebih dari sekali.";

                    continue;
                }
                $validator = Validator::make(['jumlah_hari' => $value], ['jumlah_hari' => ['required', 'integer', 'between:0,366']]);
                if ($validator->fails()) {
                    $errors[] = "Baris {$row}: jumlah cuti harus bilangan bulat antara 0 sampai 366.";

                    continue;
                }
                $seen[$pegawai->id] = true;
                $items[] = ['pegawai_id' => $pegawai->id, 'jumlah_hari' => (int) $value, 'source_row' => $row];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => implode(' ', array_slice($errors, 0, 10))]);
            }
            if ($items === []) {
                throw ValidationException::withMessages(['file' => 'Isi jumlah cuti tahunan untuk minimal satu pegawai.']);
            }

            return $items;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'File Excel tidak dapat dibaca. Gunakan template resmi yang tidak rusak atau diproteksi.']);
        } finally {
            $book?->disconnectWorksheets();
        }
    }
}
