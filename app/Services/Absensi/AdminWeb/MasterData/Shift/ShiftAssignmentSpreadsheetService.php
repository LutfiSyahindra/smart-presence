<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Shift;

use App\Models\CutiRequest;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\PerubahanShiftRequest;
use App\Models\Presensi;
use App\Models\Shift;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Throwable;

class ShiftAssignmentSpreadsheetService
{
    private const MAX_EMPLOYEES = 5000;

    public function employees(array $data): Builder
    {
        return Pegawai::query()->whereHas('user')
            ->when($data['selection'] === 'filtered', fn ($query) => $query
                ->when($data['unit_kerja_id'] ?? null, fn ($query, $id) => $query->where('unit_kerja_id', $id))
                ->when($data['jabatan_id'] ?? null, fn ($query, $id) => $query->where('jabatan_id', $id)))
            ->when($data['selection'] === 'custom', fn ($query) => $query->whereKey($data['pegawai_ids']));
    }

    public function template(array $data): Spreadsheet
    {
        $employees = $this->selectedEmployees($data);
        $shifts = Shift::query()->orderBy('nama')->get();
        if ($shifts->isEmpty()) {
            $this->fail('Buat master shift terlebih dahulu.');
        }
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Jadwal Shift');
        $dates = $this->dates($data);
        $sheet->fromArray([['NIK', 'Nama Pegawai', 'Unit Kerja', 'Jabatan', 'ID Pegawai', ...$dates]]);
        $lastColumn = Coordinate::stringFromColumnIndex(5 + count($dates));
        $sheet->freezePane('F2');
        $sheet->getColumnDimension('E')->setVisible(false);
        $sheet->setAutoFilter("A1:{$lastColumn}".($employees->count() + 1));
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '087F78']],
        ]);
        foreach (['A' => 24, 'B' => 32, 'C' => 26, 'D' => 26] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        foreach ($dates as $index => $date) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 6))->setWidth(15);
        }
        foreach ($employees as $index => $employee) {
            $row = $index + 2;
            foreach ([$employee->nik, $employee->nama, $employee->unitKerja?->nama, $employee->jabatan?->nama, $employee->id] as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row], (string) $value, DataType::TYPE_STRING);
            }
        }
        $reference = $book->createSheet()->setTitle('Referensi Shift');
        $reference->fromArray([['Kode Shift', 'Nama Shift', 'Jam Masuk', 'Jam Keluar', 'Jenis']]);
        foreach ($shifts as $index => $shift) {
            foreach (['S'.$shift->id, $shift->nama, substr($shift->jam_masuk, 0, 5), substr($shift->jam_keluar, 0, 5), $shift->is_day_off ? 'OFF / Libur' : 'Hari kerja'] as $column => $value) {
                $reference->setCellValueExplicit([$column + 1, $index + 2], $value, DataType::TYPE_STRING);
            }
        }
        foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
            $reference->getColumnDimension($column)->setAutoSize(true);
        }
        $book->addNamedRange(new NamedRange('KodeShift', $reference, '$A$2:$A$'.($shifts->count() + 1)));
        $validation = $sheet->getCell('F2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
            ->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
            ->setErrorTitle('Kode shift tidak valid')->setError('Pilih kode dari Referensi Shift.')
            ->setFormula1('KodeShift')->setSqref('F2:'.$lastColumn.($employees->count() + 1));
        $guide = $book->createSheet()->setTitle('Petunjuk');
        $guide->fromArray([
            ['PETUNJUK ASSIGN SHIFT MASSAL'],
            ['Periode: '.$data['date_from'].' sampai '.$data['date_to']],
            ['Isi kode shift (contoh S1) pada kolom tanggal. Pilih kode dari dropdown atau sheet Referensi Shift.'],
            ['Satu baris untuk satu pegawai. Jangan mengubah NIK atau kolom ID Pegawai tersembunyi; pertahankan nol di awal NIK.'],
            ['Sel kosong dilewati dan tidak mengubah jadwal. Gunakan kode shift OFF untuk hari libur.'],
            ['Jangan mengubah header tanggal, NIK, atau memakai formula. Nama/unit/jabatan hanya referensi.'],
            ['Saat impor, gunakan periode dan pilihan pegawai yang sesuai dengan template. Maksimal 5.000 pegawai per file.'],
            ['Jadwal tersimpan per tanggal dan dapat dilihat di menu Presensi. Shift aktif/default pegawai tetap berlaku di tanggal lain.'],
            ['Jadwal yang sudah memiliki clock in/out, cuti disetujui, lembur atau perubahan shift aktif tidak dapat ditimpa.'],
            ['Seluruh file divalidasi sebelum disimpan; jika ada kesalahan, tidak ada perubahan yang disimpan.'],
        ]);
        $guide->getColumnDimension('A')->setWidth(110);
        $guide->getStyle('A1:A10')->getAlignment()->setWrapText(true);
        foreach (range(1, 10) as $row) {
            $guide->getRowDimension($row)->setRowHeight(32);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function import(UploadedFile $file, array $data, int $actorId): array
    {
        $items = $this->read($file, $data);

        return DB::transaction(function () use ($items, $data, $actorId) {
            $ids = array_values(array_unique(array_column($items, 'pegawai_id')));
            $employees = $this->employees($data)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $shifts = Shift::query()->whereKey(array_unique(array_column($items, 'shift_id')))->lockForUpdate()->get()->keyBy('id');
            $records = Presensi::query()->whereIn('pegawai_id', $ids)->whereBetween('tanggal_presensi', [$data['date_from'], $data['date_to']])
                ->lockForUpdate()->get()->keyBy(fn ($record) => $record->pegawai_id.'|'.$record->tanggal_presensi->toDateString());
            $lembur = LemburRequest::query()->whereIn('pegawai_id', $ids)->whereBetween('tanggal_lembur', [$data['date_from'], $data['date_to']])
                ->whereIn('status', ['pending', 'approved'])->get()->keyBy(fn ($item) => $item->pegawai_id.'|'.$item->tanggal_lembur->toDateString());
            $changes = PerubahanShiftRequest::query()->whereIn('pegawai_id', $ids)->whereBetween('tanggal_perubahan', [$data['date_from'], $data['date_to']])
                ->whereIn('status', ['pending', 'approved'])->get()->keyBy(fn ($item) => $item->pegawai_id.'|'.$item->tanggal_perubahan->toDateString());
            $leaves = CutiRequest::query()->whereIn('pegawai_id', $ids)->where('status', 'approved')
                ->whereDate('tanggal_mulai', '<=', $data['date_to'])->whereDate('tanggal_selesai', '>=', $data['date_from'])->get()->groupBy('pegawai_id');
            foreach ($items as $item) {
                $key = $item['pegawai_id'].'|'.$item['date'];
                $record = $records->get($key);
                if (! $employees->has($item['pegawai_id']) || ! $shifts->has($item['shift_id'])) {
                    $this->fail('Data pegawai atau shift telah berubah. Unduh ulang template.');
                }
                $hasLeave = $leaves->get($item['pegawai_id'], collect())->contains(fn ($leave) => $item['date'] >= $leave->tanggal_mulai->toDateString() && $item['date'] <= $leave->tanggal_selesai->toDateString());
                if ($record?->clock_in_at || $record?->clock_out_at || $hasLeave || $lembur->has($key) || $changes->has($key)) {
                    $this->fail("Baris {$item['row']}, tanggal {$item['date']}: sudah ada presensi, cuti, lembur, atau perubahan shift aktif. Selesaikan konflik jadwal terlebih dahulu.");
                }
            }
            $timestamp = now();
            foreach (array_chunk($items, 100) as $chunk) {
                Presensi::query()->upsert(array_map(fn ($item) => [
                    'pegawai_id' => $item['pegawai_id'],
                    'tanggal_presensi' => $item['date'],
                    'shift_id' => $item['shift_id'],
                    'is_day_off' => $shifts->get($item['shift_id'])->is_day_off,
                    'updated_by' => $actorId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $chunk), ['pegawai_id', 'tanggal_presensi'], ['shift_id', 'is_day_off', 'updated_by', 'updated_at']);
            }

            return ['employees' => count($ids), 'schedules' => count($items)];
        });
    }

    private function read(UploadedFile $file, array $data): array
    {
        $book = null;
        try {
            $reader = IOFactory::createReader(IOFactory::identify($file->getRealPath(), ['Xlsx', 'Xls']));
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly(['Jadwal Shift']);
            $info = collect($reader->listWorksheetInfo($file->getRealPath()))->firstWhere('worksheetName', 'Jadwal Shift');
            if (! $info || $info['totalRows'] > self::MAX_EMPLOYEES + 1 || $info['totalColumns'] > 36) {
                $this->fail('Gunakan sheet Jadwal Shift dari template resmi, maksimal 5.000 pegawai.');
            }
            $book = $reader->load($file->getRealPath());
            $sheet = $book->getSheetByName('Jadwal Shift');
            $headers = ['NIK', 'Nama Pegawai', 'Unit Kerja', 'Jabatan', 'ID Pegawai', ...$this->dates($data)];
            if ($sheet->getHighestDataColumn() !== Coordinate::stringFromColumnIndex(count($headers))) {
                $this->fail('Kolom tanggal tidak sesuai periode yang dipilih. Gunakan template dengan periode yang sama.');
            }
            foreach ($headers as $index => $header) {
                if ($sheet->getCell([$index + 1, 1])->getValue() !== $header) {
                    $this->fail('Header template atau periode tanggal tidak sesuai. Unduh ulang template untuk periode yang dipilih.');
                }
            }
            $employees = $this->selectedEmployees($data)->keyBy('id');
            $codes = Shift::query()->pluck('id')->mapWithKeys(fn ($id) => ['S'.$id => (int) $id]);
            $items = [];
            $seen = [];
            $errors = [];
            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $values = [];
                for ($column = 1; $column <= count($headers); $column++) {
                    $cell = $sheet->getCell([$column, $row]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $errors[] = "Baris {$row}: formula tidak diizinkan.";

                        continue 2;
                    }
                    $values[] = trim((string) $cell->getValue());
                }
                if (count(array_filter(array_slice($values, 5), fn ($value) => $value !== '')) === 0) {
                    continue;
                }
                $employee = $employees->get($values[4]);
                if (! $employee || trim((string) $employee->nik) !== $values[0]) {
                    $errors[] = "Baris {$row}: identitas pegawai tidak sesuai atau tidak termasuk pilihan pegawai berakun. Jangan mengubah NIK atau ID Pegawai.";

                    continue;
                }
                if (isset($seen[$employee->id])) {
                    $errors[] = "Baris {$row}: pegawai {$employee->nama} ditulis lebih dari sekali.";

                    continue;
                }
                $seen[$employee->id] = true;
                foreach (array_slice($values, 5) as $index => $code) {
                    if ($code === '') {
                        continue;
                    }
                    $date = $headers[$index + 5];
                    if (! $codes->has($code)) {
                        $errors[] = "Baris {$row}, tanggal {$date}: kode shift {$code} tidak tersedia. Gunakan kode pada Referensi Shift.";

                        continue;
                    }
                    $items[] = ['pegawai_id' => $employee->id, 'shift_id' => $codes->get($code), 'date' => $date, 'row' => $row];
                }
            }
            if ($errors !== []) {
                $this->fail(implode(' ', array_slice($errors, 0, 10)));
            }
            if ($items === []) {
                $this->fail('Isi kode shift untuk minimal satu pegawai dan satu tanggal.');
            }

            return $items;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('File Excel tidak dapat dibaca. Gunakan template resmi .xlsx atau .xls yang tidak rusak.');
        } finally {
            $book?->disconnectWorksheets();
        }
    }

    private function selectedEmployees(array $data): Collection
    {
        $employees = $this->employees($data)->with(['unitKerja:id,nama', 'jabatan:id,nama'])->orderBy('nama')->limit(self::MAX_EMPLOYEES + 1)->get();
        if ($employees->isEmpty() || $employees->count() > self::MAX_EMPLOYEES) {
            $this->fail('Pilih 1 sampai 5.000 pegawai berakun. Gunakan filter unit/jabatan untuk memperkecil pilihan.');
        }

        return $employees;
    }

    private function dates(array $data): array
    {
        return array_map(fn ($date) => $date->toDateString(), iterator_to_array(CarbonPeriod::create($data['date_from'], $data['date_to'])));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
