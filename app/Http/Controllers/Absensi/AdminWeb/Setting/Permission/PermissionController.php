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
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'in:web,mobile'],
        ]);
        $filters['platform'] = $filters['platform'] ?? 'web';

        return Inertia::render('absensi/admin-web/setting/permission/Index', [
            'permissions' => $this->service->paginate($filters),
            'overview' => $this->service->overview(),
            'filters' => $filters,
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
        $permission = $this->service->create($request->validated());

        return to_route('absensi.admin-web.setting.permissions.index', ['platform' => AccessPermission::platform($permission->name)])->with('success', 'Permission berhasil ditambahkan.');
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

        return to_route('absensi.admin-web.setting.permissions.index', ['platform' => AccessPermission::platform($permission->name)])->with('success', 'Permission berhasil diperbarui.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        $this->service->delete($permission);

        return to_route('absensi.admin-web.setting.permissions.index', ['platform' => AccessPermission::platform($permission->name)])->with('success', 'Permission berhasil dihapus.');
    }
}
