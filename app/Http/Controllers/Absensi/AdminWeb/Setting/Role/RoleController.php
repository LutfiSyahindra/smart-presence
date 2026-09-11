<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Setting\Role;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\Setting\Role\StoreRoleRequest;
use App\Http\Requests\Absensi\AdminWeb\Setting\Role\UpdateRoleRequest;
use App\Services\Absensi\AdminWeb\Setting\Role\RoleService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/setting/role/Index', [
            'roles' => $this->service->paginate($request->only('search')),
            'overview' => $this->service->overview(),
            'filters' => $request->only('search'),
            'permissionGroups' => $this->service->permissionGroups(),
            'can' => [
                'create' => $request->user()->can(AccessPermission::ROLES_CREATE),
                'update' => $request->user()->can(AccessPermission::ROLES_UPDATE),
                'delete' => $request->user()->can(AccessPermission::ROLES_DELETE),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('absensi/admin-web/setting/role/Create', [
            'permissionGroups' => $this->service->permissionGroups(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('absensi.admin-web.setting.roles.index')->with('success', 'Role berhasil ditambahkan.');
    }

    public function edit(Role $role): Response
    {
        return Inertia::render('absensi/admin-web/setting/role/Edit', [
            'role' => $this->service->editData($role),
            'permissionGroups' => $this->service->permissionGroups(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->service->update($role, $request->validated());

        return to_route('absensi.admin-web.setting.roles.index')->with('success', 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->service->delete($role);

        return to_route('absensi.admin-web.setting.roles.index')->with('success', 'Role berhasil dihapus.');
    }
}
