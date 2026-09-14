<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Pegawai;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
    private const TEMPLATE_HEADERS = [
        'NIK',
        'Nama Pegawai',
        'Profesi',
        'Unit Kerja',
        'Jabatan',
        'Jenis Kelamin',
        'Status Pegawai',
        'Tanggal Mulai Bekerja',
        'Tanggal Lahir',
        'Nomor Telepon',
    ];

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Template Import Pegawai')
            ->setSubject('Master Data Pegawai');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');
        $sheet->fromArray([self::TEMPLATE_HEADERS]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:J1');

        foreach ([
            'A' => 20,
            'B' => 34,
            'C' => 28,
            'D' => 30,
            'E' => 38,
            'F' => 18,
            'G' => 18,
            'H' => 24,
            'I' => 20,
            'J' => 23,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '14B8A6']]],
        ]);
        $sheet->getStyle('A2:A'.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('H2:I'.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('J2:J'.(self::MAX_ROWS + 1))->getNumberFormat()->setFormatCode('@');

        foreach ([
            'A1' => 'NIK wajib unik. Pertahankan tanda titik atau spasi jika memang bagian dari NIK.',
            'D1' => 'Isi sesuai nama unit kerja pada master Unit dan Jabatan.',
            'E1' => 'Jabatan harus berada di dalam unit kerja yang dipilih.',
            'F1' => 'Pilihan: Laki-laki atau Perempuan.',
            'G1' => 'Pilihan: Tetap atau Tidak Tetap.',
            'H1' => 'Wajib. Gunakan format tanggal DD/MM/YYYY.',
            'I1' => 'Opsional. Gunakan format tanggal DD/MM/YYYY.',
            'J1' => 'Opsional. Gunakan nomor Indonesia, contoh 081234567890.',
        ] as $cell => $note) {
            $sheet->getComment($cell)->getText()->createTextRun($note);
        }

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->setCellValue('A1', 'PETUNJUK PENGISIAN');
        $guide->setCellValue('A3', '1. Jangan mengubah nama atau urutan kolom pada sheet Data Pegawai.');
        $guide->setCellValue('A4', '2. Isi maksimal '.self::MAX_ROWS.' pegawai dalam satu file.');
        $guide->setCellValue('A5', '3. Unit kerja dan jabatan harus sesuai dengan master Unit dan Jabatan.');
        $guide->setCellValue('A6', '4. Status pegawai hanya boleh Tetap atau Tidak Tetap.');
        $guide->setCellValue('A7', '5. Tanggal mulai bekerja wajib diisi menggunakan format DD/MM/YYYY.');
        $guide->setCellValue('A8', '6. Tanggal lahir dan nomor telepon boleh dikosongkan.');
        $guide->setCellValue('A10', 'Contoh: 001.101.026 | Siti Aminah | Ners | Unit Rawat Jalan | Staff Unit Rawat Jalan | Perempuan | Tetap | 01/09/2026');
        $guide->getColumnDimension('A')->setWidth(120);
        $guide->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => '0F766E']],
        ]);
        $guide->getStyle('A3:A10')->getAlignment()->setWrapText(true);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @return list<array<string, mixed>> */
    public function read(UploadedFile $file): array
    {
        $spreadsheet = null;

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $headers = [];

            foreach (range(1, $highestColumn) as $column) {
                $header = $this->canonicalHeader((string) $sheet->getCell([$column, 1])->getValue());
                if ($header !== '') {
                    $headers[$header] = $column;
                }
            }

            $legacy = isset($headers['nama'], $headers['tanggal_lahir'], $headers['nomor_telepon'])
                && ! isset($headers['nik']);
            $expanded = collect(['nik', 'nama', 'unit_kerja', 'jabatan', 'jenis_kelamin', 'status_pegawai', 'tanggal_mulai_bekerja'])
                ->every(fn (string $header) => isset($headers[$header]));

            if (! $legacy && ! $expanded) {
                throw ValidationException::withMessages([
                    'file' => 'Header Excel tidak sesuai. Gunakan template resmi atau daftar tenaga kerja dengan kolom NIK, Nama, Unit Kerja, Jabatan, Jenis Kelamin, Status Karyawan, dan Tanggal Mulai Bekerja.',
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
            $nikRows = [];

            for ($row = 2; $row <= $highestRow; $row++) {
                $raw = [];

                foreach ($headers as $name => $column) {
                    $cell = $sheet->getCell([$column, $row]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $errors[] = "Baris {$row}: formula tidak diizinkan; masukkan nilai biasa.";

                        continue 2;
                    }

                    $raw[$name] = $cell->getValue();
                }

                if (collect($raw)->every(fn (mixed $value) => $this->isBlank($value))) {
                    continue;
                }

                $item = [
                    'nik' => $this->normalizeText($raw['nik'] ?? null),
                    'nama' => $this->normalizeText($raw['nama'] ?? null),
                    'profesi' => $this->normalizeText($raw['profesi'] ?? null),
                    'unit_kerja' => $this->normalizeText($raw['unit_kerja'] ?? null),
                    'jabatan' => $this->normalizeText($raw['jabatan'] ?? null),
                    'jenis_kelamin' => $this->normalizeText($raw['jenis_kelamin'] ?? null),
                    'status_pegawai' => $this->normalizeText($raw['status_pegawai'] ?? null),
                    'tanggal_mulai_bekerja' => $this->normalizeDate($raw['tanggal_mulai_bekerja'] ?? null),
                    'tanggal_lahir' => $this->normalizeDate($raw['tanggal_lahir'] ?? null),
                    'nomor_telepon' => $this->normalizePhone($raw['nomor_telepon'] ?? null),
                    'source_row' => $row,
                ];

                $rules = [
                    'nama' => ['required', 'string', 'max:150'],
                    'tanggal_lahir' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
                    'nomor_telepon' => ['nullable', 'regex:/^0[0-9]{8,14}$/'],
                ];

                if ($legacy) {
                    $rules['tanggal_lahir'][0] = 'required';
                    $rules['nomor_telepon'][0] = 'required';
                } else {
                    $rules += [
                        'nik' => ['required', 'string', 'max:50'],
                        'profesi' => ['nullable', 'string', 'max:150'],
                        'unit_kerja' => ['required', 'string', 'max:150'],
                        'jabatan' => ['required', 'string', 'max:150'],
                        'jenis_kelamin' => ['required', 'in:Laki-laki,Perempuan'],
                        'status_pegawai' => ['required', 'in:Tetap,Tidak Tetap'],
                        'tanggal_mulai_bekerja' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
                    ];
                }

                $validator = Validator::make($item, $rules, [
                    'nik.required' => 'NIK wajib diisi',
                    'nama.required' => 'nama pegawai wajib diisi',
                    'nama.max' => 'nama pegawai maksimal 150 karakter',
                    'unit_kerja.required' => 'unit kerja wajib diisi',
                    'jabatan.required' => 'jabatan wajib diisi',
                    'jenis_kelamin.required' => 'jenis kelamin wajib diisi',
                    'jenis_kelamin.in' => 'jenis kelamin harus Laki-laki atau Perempuan',
                    'status_pegawai.required' => 'status pegawai wajib diisi',
                    'status_pegawai.in' => 'status pegawai harus Tetap atau Tidak Tetap',
                    'tanggal_mulai_bekerja.required' => 'tanggal mulai bekerja wajib diisi dengan format DD/MM/YYYY',
                    'tanggal_mulai_bekerja.date_format' => 'tanggal mulai bekerja harus berformat DD/MM/YYYY',
                    'tanggal_mulai_bekerja.after_or_equal' => 'tanggal mulai bekerja tidak boleh sebelum tahun 1900',
                    'tanggal_mulai_bekerja.before_or_equal' => 'tanggal mulai bekerja tidak boleh melewati hari ini',
                    'tanggal_lahir.required' => 'tanggal lahir wajib diisi dengan format DD/MM/YYYY',
                    'tanggal_lahir.date_format' => 'tanggal lahir harus berformat DD/MM/YYYY',
                    'tanggal_lahir.after_or_equal' => 'tanggal lahir tidak boleh sebelum tahun 1900',
                    'tanggal_lahir.before_or_equal' => 'tanggal lahir tidak boleh melewati hari ini',
                    'nomor_telepon.required' => 'nomor telepon wajib diisi',
                    'nomor_telepon.regex' => 'nomor telepon harus 9-15 digit dan diawali 0 atau +62',
                ]);

                if ($validator->fails()) {
                    $errors[] = "Baris {$row}: ".$validator->errors()->first().'.';

                    continue;
                }

                if ($item['nik'] !== null && isset($nikRows[$item['nik']])) {
                    $errors[] = "Baris {$row}: NIK sama dengan baris {$nikRows[$item['nik']]}.";

                    continue;
                }

                if ($item['nomor_telepon'] !== null && isset($phoneRows[$item['nomor_telepon']])) {
                    $errors[] = "Baris {$row}: nomor telepon sama dengan baris {$phoneRows[$item['nomor_telepon']]}.";

                    continue;
                }

                if ($item['nik'] !== null) {
                    $nikRows[$item['nik']] = $row;
                }
                if ($item['nomor_telepon'] !== null) {
                    $phoneRows[$item['nomor_telepon']] = $row;
                }

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

    private function canonicalHeader(string $value): string
    {
        return match ($this->normalizeHeader($value)) {
            'nik', 'employee id' => 'nik',
            'nama', 'nama pegawai', 'full name' => 'nama',
            'profesi' => 'profesi',
            'unit kerja' => 'unit_kerja',
            'jabatan' => 'jabatan',
            'jenis kelamin' => 'jenis_kelamin',
            'status karyawan', 'status pegawai' => 'status_pegawai',
            'tanggal mulai bekerja', 'join date' => 'tanggal_mulai_bekerja',
            'tanggal lahir' => 'tanggal_lahir',
            'nomor telfon', 'nomor telepon' => 'nomor_telepon',
            default => '',
        };
    }

    private function normalizeText(mixed $value): ?string
    {
        $value = str((string) $value)->squish()->toString();

        return $value !== '' ? $value : null;
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

    private function normalizePhone(mixed $value): ?string
    {
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }

        $phone = preg_replace('/[^0-9+]/', '', trim((string) $value)) ?? '';
        if ($phone === '') {
            return null;
        }

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
