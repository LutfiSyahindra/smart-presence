<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $isSuperAdmin = $user->hasRole('super-admin');
        $canViewUsers = $isSuperAdmin || $user->can(AccessPermission::USERS_VIEW);
        $canViewRoles = $isSuperAdmin || $user->can(AccessPermission::ROLES_VIEW);
        $canViewPermissions = $isSuperAdmin || $user->can(AccessPermission::PERMISSIONS_VIEW);
        $grantedPermissions = $user->getAllPermissions()->count();
        $availablePermissions = Permission::query()->where('guard_name', 'web')->count();

        return Inertia::render('Dashboard', [
            'overview' => [
                'users' => $canViewUsers ? User::query()->count() : null,
                'roles' => $canViewRoles ? Role::query()->where('guard_name', 'web')->count() : null,
                'permissions' => $canViewPermissions ? $availablePermissions : null,
                'access_count' => $isSuperAdmin ? $availablePermissions : $grantedPermissions,
                'access_total' => $availablePermissions,
            ],
            'recentUsers' => $canViewUsers
                ? User::query()
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'name', 'email', 'email_verified_at', 'created_at'])
                    ->map(fn (User $recentUser): array => [
                        'id' => $recentUser->id,
                        'name' => $recentUser->name,
                        'email' => $recentUser->email,
                        'verified' => $recentUser->email_verified_at !== null,
                        'created_at' => $recentUser->created_at?->locale('id')->diffForHumans(),
                    ])
                : [],
        ]);
    }
}
