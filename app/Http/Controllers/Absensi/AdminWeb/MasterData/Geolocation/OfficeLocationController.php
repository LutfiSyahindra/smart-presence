<?php

namespace App\Http\Controllers\Absensi\AdminWeb\MasterData\Geolocation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Geolocation\StoreOfficeLocationRequest;
use App\Http\Requests\Absensi\AdminWeb\MasterData\Geolocation\UpdateOfficeLocationRequest;
use App\Models\OfficeLocation;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfficeLocationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/master-data/geolocation/Index', [
            'locations' => OfficeLocation::query()
                ->orderBy('name')
                ->get()
                ->map(fn (OfficeLocation $location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'address' => $location->address,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'radius_meters' => $location->radius_meters,
                    'updated_at' => $location->updated_at?->toISOString(),
                ]),
            'can' => [
                'create' => $request->user()->can(AccessPermission::GEOLOCATION_CREATE),
                'update' => $request->user()->can(AccessPermission::GEOLOCATION_UPDATE),
                'delete' => $request->user()->can(AccessPermission::GEOLOCATION_DELETE),
            ],
        ]);
    }

    public function store(StoreOfficeLocationRequest $request): RedirectResponse
    {
        OfficeLocation::query()->create($request->validated());

        return to_route('absensi.admin-web.master-data.geolocation.index')
            ->with('success', 'Lokasi kantor berhasil ditambahkan.');
    }

    public function update(UpdateOfficeLocationRequest $request, OfficeLocation $officeLocation): RedirectResponse
    {
        $officeLocation->update($request->validated());

        return to_route('absensi.admin-web.master-data.geolocation.index')
            ->with('success', 'Lokasi kantor berhasil diperbarui.');
    }

    public function destroy(OfficeLocation $officeLocation): RedirectResponse
    {
        $officeLocation->delete();

        return to_route('absensi.admin-web.master-data.geolocation.index')
            ->with('success', 'Lokasi kantor berhasil dihapus.');
    }
}
