<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Setting\Permission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\Setting\Permission\StorePermissionRequest;
use App\Http\Requests\Absensi\AdminWeb\Setting\Permission\UpdatePermissionRequest;
use App\Services\Absensi\AdminWeb\Setting\Permission\PermissionService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/setting/permission/Index', [
            'permissions' => $this->service->paginate($request->only('search')),
            'overview' => $this->service->overview(),
            'filters' => $request->only('search'),
            'can' => [
                'create' => $request->user()->can(AccessPermission::PERMISSIONS_CREATE),
                'update' => $request->user()->can(AccessPermission::PERMISSIONS_UPDATE),
                'delete' => $request->user()->can(AccessPermission::PERMISSIONS_DELETE),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('absensi/admin-web/setting/permission/Create');
    }

    public function store(StorePermissionRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('absensi.admin-web.setting.permissions.index')->with('success', 'Permission berhasil ditambahkan.');
    }

    public function edit(Permission $permission): Response
    {
        return Inertia::render('absensi/admin-web/setting/permission/Edit', [
            'permission' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'is_system' => AccessPermission::isSystem($permission->name),
            ],
        ]);
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): RedirectResponse
    {
        $this->service->update($permission, $request->validated());

        return to_route('absensi.admin-web.setting.permissions.index')->with('success', 'Permission berhasil diperbarui.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        $this->service->delete($permission);

        return to_route('absensi.admin-web.setting.permissions.index')->with('success', 'Permission berhasil dihapus.');
    }
}
