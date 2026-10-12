<?php

namespace App\Services\Absensi\AdminWeb\Setting\User;

use App\Models\ApprovalLineStep;
use App\Models\CutiApproval;
use App\Models\User;
use App\Repositories\Absensi\AdminWeb\Setting\Permission\PermissionRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\Role\RoleRepositoryInterface;
use App\Repositories\Absensi\AdminWeb\Setting\User\UserRepositoryInterface;
use App\Services\Absensi\AdminWeb\Setting\AuthorizationFormDataService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    private const SUPER_ADMIN = 'super-admin';

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly RoleRepositoryInterface $roles,
        private readonly PermissionRepositoryInterface $permissions,
        private readonly AuthorizationFormDataService $formData,
    ) {}

    /** @param array{search?: string|null, role?: string|null} $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $filters = [
            'search' => trim((string) ($filters['search'] ?? '')) ?: null,
            'role' => trim((string) ($filters['role'] ?? '')) ?: null,
        ];

        return $this->users->paginate($filters)->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'roles' => $user->roles->pluck('name')->values()->all(),
            'direct_permissions' => $user->permissions->pluck('name')->values()->all(),
            'role_ids' => $user->roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'permission_ids' => $user->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'created_at' => $user->created_at?->format('d M Y'),
        ]);
    }

    /**
     * @return array{total: int, verified: int, with_roles: int, with_direct_permissions: int}
     */
    public function overview(): array
    {
        return $this->users->overview();
    }

    /** @return array{roles: array<int, array{id: int, name: string}>, permissionGroups: array<int, mixed>} */
    public function formOptions(): array
    {
        return [
            'roles' => $this->formData->roles(),
            'permissionGroups' => $this->formData->permissionGroups(),
        ];
    }

    /** @return array<string, mixed> */
    public function editData(User $user): array
    {
        $user = $this->users->findOrFail((int) $user->getKey());

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_ids' => $user->roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'permission_ids' => $user->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->users->create(Arr::only($data, ['name', 'email', 'password']));
            $this->users->syncRoles($user, $this->roles->namesByIds($data['role_ids'] ?? []));
            $this->users->syncPermissions($user, $this->permissions->namesByIds($data['permission_ids'] ?? []));

            return $user->load(['roles', 'permissions']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            if ($user->pegawai()->exists()
                && array_key_exists('email', $data)
                && $data['email'] !== $user->email) {
                throw ValidationException::withMessages([
                    'email' => 'Email akun pegawai tidak dapat diubah selama masih terhubung ke master pegawai.',
                ]);
            }

            $roleNames = $this->roles->namesByIds($data['role_ids'] ?? []);

            if ($user->hasRole(self::SUPER_ADMIN)
                && ! in_array(self::SUPER_ADMIN, $roleNames, true)
                && $this->users->countWithRole(self::SUPER_ADMIN) <= 1) {
                throw ValidationException::withMessages([
                    'role_ids' => 'Super admin terakhir tidak boleh diturunkan perannya.',
                ]);
            }

            $attributes = Arr::only($data, ['name', 'email', 'password']);
            if (blank($attributes['password'] ?? null)) {
                unset($attributes['password']);
            }

            $user = $this->users->update($user, $attributes);
            $this->users->syncRoles($user, $roleNames);
            $this->users->syncPermissions($user, $this->permissions->namesByIds($data['permission_ids'] ?? []));

            return $user->load(['roles', 'permissions']);
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'user' => 'Anda tidak dapat menghapus akun yang sedang digunakan.',
            ]);
        }

        DB::transaction(function () use ($user) {
            if (ApprovalLineStep::query()->where('user_id', $user->id)->exists()
                || CutiApproval::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'waiting'])->exists()) {
                throw ValidationException::withMessages([
                    'user' => 'Pengguna masih menjadi approver pada approval line atau pengajuan cuti yang berjalan. Selesaikan pengajuan dan ubah approval line terlebih dahulu.',
                ]);
            }

            if ($user->hasRole(self::SUPER_ADMIN) && $this->users->countWithRole(self::SUPER_ADMIN) <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'Super admin terakhir tidak dapat dihapus.',
                ]);
            }

            $this->users->delete($user);
        });
    }
}
