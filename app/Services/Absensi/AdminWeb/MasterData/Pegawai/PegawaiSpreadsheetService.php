<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Pegawai;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

class PegawaiSpreadsheetService
{
    private const MAX_ROWS = 1000;

    /** @var list<string> */
    private const EXPECTED_HEADERS = ['nama pegawai', 'tanggal lahir', 'nomor telfon'];

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Template Import Pegawai')
            ->setSubject('Master Data Pegawai');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');
        $sheet->fromArray([
            ['Nama Pegawai', 'Tanggal Lahir', 'Nomor Telfon'],
        ]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:C1');
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(23);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '14B8A6']]],
        ]);
        $sheet->getStyle('B2:B'.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('C2:C'.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('@');

        foreach (['A1' => 'Nama lengkap pegawai.', 'B1' => 'Gunakan format tanggal DD/MM/YYYY.', 'C1' => 'Gunakan nomor Indonesia, contoh 081234567890.'] as $cell => $note) {
            $sheet->getComment($cell)->getText()->createTextRun($note);
        }

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->setCellValue('A1', 'PETUNJUK PENGISIAN');
        $guide->setCellValue('A3', '1. Jangan mengubah nama atau urutan kolom pada sheet Data Pegawai.');
        $guide->setCellValue('A4', '2. Isi maksimal '.self::MAX_ROWS.' pegawai dalam satu file.');
        $guide->setCellValue('A5', '3. Tanggal lahir menggunakan format DD/MM/YYYY.');
        $guide->setCellValue('A6', '4. Nomor telfon wajib unik dan diawali 0 atau +62.');
        $guide->setCellValue('A8', 'Contoh: Siti Aminah | 17/08/1995 | 081234567890');
        $guide->getColumnDimension('A')->setWidth(86);
        $guide->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => '0F766E']],
        ]);
        $guide->getStyle('A3:A8')->getAlignment()->setWrapText(true);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @return list<array{nama: string, tanggal_lahir: string, nomor_telepon: string, source_row: int}>
     */
    public function read(UploadedFile $file): array
    {
        $spreadsheet = null;

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();

            $headers = [];
            foreach (range(1, 3) as $column) {
                $headers[] = $this->normalizeHeader((string) $sheet->getCell([$column, 1])->getValue());
            }

            if ($headers !== self::EXPECTED_HEADERS && $headers !== ['nama pegawai', 'tanggal lahir', 'nomor telepon']) {
                throw ValidationException::withMessages([
                    'file' => 'Header Excel tidak sesuai. Gunakan template resmi dengan urutan: Nama Pegawai, Tanggal Lahir, Nomor Telfon.',
                ]);
            }

            $highestRow = $sheet->getHighestDataRow();
            if ($highestRow - 1 > self::MAX_ROWS) {
                throw ValidationException::withMessages([
                    'file' => 'Maksimal '.self::MAX_ROWS.' baris pegawai dapat diimpor dalam satu file.',
                ]);
            }

            $items = [];
            $errors = [];
            $phoneRows = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $nameCell = $sheet->getCell([1, $row]);
                $dateCell = $sheet->getCell([2, $row]);
                $phoneCell = $sheet->getCell([3, $row]);

                $nameRaw = $nameCell->getValue();
                $dateRaw = $dateCell->getValue();
                $phoneRaw = $phoneCell->getValue();

                if ($this->isBlank($nameRaw) && $this->isBlank($dateRaw) && $this->isBlank($phoneRaw)) {
                    continue;
                }

                if ($nameCell->getDataType() === DataType::TYPE_FORMULA || $dateCell->getDataType() === DataType::TYPE_FORMULA || $phoneCell->getDataType() === DataType::TYPE_FORMULA) {
                    $errors[] = "Baris {$row}: formula tidak diizinkan; masukkan nilai biasa.";

                    continue;
                }

                $item = [
                    'nama' => str((string) $nameRaw)->squish()->toString(),
                    'tanggal_lahir' => $this->normalizeDate($dateRaw),
                    'nomor_telepon' => $this->normalizePhone($phoneRaw),
                    'source_row' => $row,
                ];

                $validator = Validator::make($item, [
                    'nama' => ['required', 'string', 'max:150'],
                    'tanggal_lahir' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
                    'nomor_telepon' => ['required', 'regex:/^0[0-9]{8,14}$/'],
                ], [
                    'nama.required' => 'nama pegawai wajib diisi',
                    'nama.max' => 'nama pegawai maksimal 150 karakter',
                    'tanggal_lahir.required' => 'tanggal lahir wajib diisi dengan format DD/MM/YYYY',
                    'tanggal_lahir.date_format' => 'tanggal lahir harus berformat DD/MM/YYYY',
                    'tanggal_lahir.after_or_equal' => 'tanggal lahir tidak boleh sebelum tahun 1900',
                    'tanggal_lahir.before_or_equal' => 'tanggal lahir tidak boleh melewati hari ini',
                    'nomor_telepon.required' => 'nomor telfon wajib diisi',
                    'nomor_telepon.regex' => 'nomor telfon harus 9-15 digit dan diawali 0 atau +62',
                ]);

                if ($validator->fails()) {
                    $errors[] = "Baris {$row}: ".$validator->errors()->first().'.';

                    continue;
                }

                if (isset($phoneRows[$item['nomor_telepon']])) {
                    $errors[] = "Baris {$row}: nomor telfon sama dengan baris {$phoneRows[$item['nomor_telepon']]}.";

                    continue;
                }

                $phoneRows[$item['nomor_telepon']] = $row;
                $items[] = $item;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'file' => implode(' ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' Periksa juga baris lainnya.' : ''),
                ]);
            }

            if ($items === []) {
                throw ValidationException::withMessages([
                    'file' => 'File belum berisi data pegawai. Isi data mulai dari baris kedua.',
                ]);
            }

            return $items;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'File Excel tidak dapat dibaca. Pastikan file tidak rusak dan menggunakan format .xlsx atau .xls.',
            ]);
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    private function normalizeHeader(string $value): string
    {
        return str($value)
            ->replace("\u{FEFF}", '')
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->toString();
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        $value = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizePhone(mixed $value): string
    {
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }

        $phone = preg_replace('/[^0-9+]/', '', trim((string) $value)) ?? '';

        if (str_starts_with($phone, '+62')) {
            return '0'.substr($phone, 3);
        }

        if (str_starts_with($phone, '62')) {
            return '0'.substr($phone, 2);
        }

        if (str_starts_with($phone, '8')) {
            return '0'.$phone;
        }

        return $phone;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
