<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift;

use App\Exceptions\HolidayCalendarException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\SaveHariLiburRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\SyncHariLiburRequest;
use App\Models\HariLibur;
use App\Services\Absensi\NationalHolidaySyncService;
use Illuminate\Http\RedirectResponse;

class HariLiburController extends Controller
{
    public function sync(SyncHariLiburRequest $request, NationalHolidaySyncService $service): RedirectResponse
    {
        $year = (int) $request->validated('year');
        $redirect = to_route('absensi.admin-web.master-data.shift.index', ['holiday_year' => $year]);
        try {
            $result = $service->sync($year);
        } catch (HolidayCalendarException $exception) {
            return $redirect->with('error', $exception->getMessage());
        }

        return $redirect->with('success', "Kalender {$year} berhasil disinkronkan: {$result['created']} ditambahkan, {$result['updated']} diperbarui, {$result['deleted']} dihapus. {$result['preserved']} entri manual dipertahankan.");
    }

    public function store(SaveHariLiburRequest $request): RedirectResponse
    {
        $hariLibur = HariLibur::query()->create($request->validated());

        return $this->redirect($hariLibur, 'Hari libur berhasil ditambahkan.');
    }

    public function update(SaveHariLiburRequest $request, HariLibur $hariLibur): RedirectResponse
    {
        $hariLibur->fill($request->validated())->forceFill(['source' => null, 'synced_at' => null])->save();

        return $this->redirect($hariLibur, 'Hari libur berhasil diperbarui.');
    }

    public function destroy(HariLibur $hariLibur): RedirectResponse
    {
        $hariLibur->delete();

        return $this->redirect($hariLibur, 'Hari libur berhasil dihapus.');
    }

    private function redirect(HariLibur $hariLibur, string $message): RedirectResponse
    {
        return to_route('absensi.admin-web.master-data.shift.index', ['holiday_year' => $hariLibur->tanggal->year])
            ->with('success', $message);
    }
}
