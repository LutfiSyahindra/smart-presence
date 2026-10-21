<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\StoreShiftRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Shift\UpdateShiftRequest;
use App\Models\HariLibur;
use App\Models\HolidayCalendarSync;
use App\Models\Shift;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftService;
use App\Services\Absensi\NationalHolidaySyncService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $service) {}

    public function index(Request $request): Response
    {
        $year = filter_var($request->input('holiday_year', now()->year), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1900, 'max_range' => 9999],
        ]) ?: now()->year;

        return Inertia::render('absensi/admin-web/master-data/shift/Index', [
            'shifts' => $this->service->paginate($request->only('search')),
            'overview' => $this->service->overview(),
            'filters' => $request->only('search'),
            'holidayYear' => $year,
            'holidays' => fn () => HariLibur::query()->whereYear('tanggal', $year)
                ->orderBy('tanggal')->orderBy('jenis')->get()
                ->map(fn (HariLibur $holiday) => [
                    'id' => $holiday->id,
                    'tanggal' => $holiday->tanggal->toDateString(),
                    'nama' => $holiday->nama,
                    'jenis' => $holiday->jenis,
                    'source' => $holiday->source,
                    'synced_at' => $holiday->synced_at?->toIso8601String(),
                ]),
            'holidaySync' => function () use ($year) {
                $state = HolidayCalendarSync::query()->where('year', $year)->first();

                return [
                    'synced_at' => $state?->synced_at?->toIso8601String(),
                    'provider_name' => NationalHolidaySyncService::PROVIDER_NAME,
                    'provider_url' => NationalHolidaySyncService::PROVIDER_URL,
                    'references' => $state?->source_references ?? [],
                    'auto_sync' => (bool) config('holidays.auto_sync'),
                ];
            },
            'can' => [
                'create' => $request->user()->can(AccessPermission::SHIFT_CREATE),
                'update' => $request->user()->can(AccessPermission::SHIFT_UPDATE),
                'delete' => $request->user()->can(AccessPermission::SHIFT_DELETE),
                'sync' => $request->user()->can(AccessPermission::SHIFT_CREATE)
                    && $request->user()->can(AccessPermission::SHIFT_UPDATE)
                    && $request->user()->can(AccessPermission::SHIFT_DELETE),
            ],
        ]);
    }

    public function store(StoreShiftRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['shifts'])) {
            $count = $this->service->createMany($data['shifts'])->count();

            return to_route('absensi.admin-web.master-data.shift.index')
                ->with('success', "{$count} shift berhasil ditambahkan.");
        }

        $this->service->create($data);

        return to_route('absensi.admin-web.master-data.shift.index')->with('success', 'Shift berhasil ditambahkan.');
    }

    public function update(UpdateShiftRequest $request, Shift $shift): RedirectResponse
    {
        $this->service->update($shift, $request->validated());

        return to_route('absensi.admin-web.master-data.shift.index')->with('success', 'Shift berhasil diperbarui.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        $this->service->delete($shift);

        return to_route('absensi.admin-web.master-data.shift.index')->with('success', 'Shift berhasil dihapus.');
    }
}
