<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Setting\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Absensi\AdminWeb\Setting\User\StoreUserRequest;
use App\Http\Requests\Absensi\AdminWeb\Setting\User\UpdateUserRequest;
use App\Models\User;
use App\Services\Absensi\AdminWeb\Setting\User\UserService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    public function index(Request $request): Response
    {
        $formOptions = $this->service->formOptions();

        return Inertia::render('absensi/admin-web/setting/user/Index', [
            'users' => $this->service->paginate($request->only(['search', 'role'])),
            'overview' => $this->service->overview(),
            'filters' => $request->only(['search', 'role']),
            ...$formOptions,
            'can' => [
                'create' => $request->user()->can(AccessPermission::USERS_CREATE),
                'update' => $request->user()->can(AccessPermission::USERS_UPDATE),
                'delete' => $request->user()->can(AccessPermission::USERS_DELETE),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('absensi/admin-web/setting/user/Create', $this->service->formOptions());
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return to_route('absensi.admin-web.setting.users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('absensi/admin-web/setting/user/Edit', [
            'user' => $this->service->editData($user),
            ...$this->service->formOptions(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->service->update($user, $request->validated());

        return to_route('absensi.admin-web.setting.users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->service->delete($user, $request->user());

        return to_route('absensi.admin-web.setting.users.index')->with('success', 'Pengguna berhasil dihapus.');
    }
}
