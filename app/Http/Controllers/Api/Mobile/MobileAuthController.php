<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak sesuai.',
            ]);
        }
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email akun belum diverifikasi. Hubungi administrator.',
            ], 403);
        }
        if (! $user->can(AccessPermission::MOBILE_ACCESS)) {
            return response()->json([
                'message' => 'Akun ini tidak memiliki akses ke aplikasi presensi.',
            ], 403);
        }

        $deviceName = trim($credentials['device_name']);
        $user->tokens()->where('name', $deviceName)->delete();
        $token = $user->createToken($deviceName, ['mobile'], now()->addDays(30));

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'can_access_account' => $user->can(AccessPermission::MOBILE_ACCOUNT),
                'permissions' => array_values(array_filter(AccessPermission::mobile(), fn (string $permission) => $user->can($permission))),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Anda telah keluar dari aplikasi.']);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'can_access_account' => $user->can(AccessPermission::MOBILE_ACCOUNT),
            'permissions' => array_values(array_filter(AccessPermission::mobile(), fn (string $permission) => $user->can($permission))),
        ]]);
    }
}
