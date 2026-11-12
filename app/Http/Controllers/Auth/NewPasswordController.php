<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Show the password reset page.
     */
    public function create(Request $request): Response
    {
        $user = Password::broker()->getUser([
            'email' => $request->string('email')->toString(),
        ]);
        $pendingEmployee = $user !== null
            && $user->pegawai()->exists()
            && ! $user->hasVerifiedEmail();

        // An expired or replaced token must not turn an activation email into a password reset page.
        $invitation = $request->boolean('invitation') || $pendingEmployee;
        $invitationError = $invitation
            && (! $pendingEmployee || ! Password::broker()->tokenExists($user, (string) $request->route('token')))
                ? 'Tautan aktivasi sudah tidak berlaku atau tidak valid. Gunakan tautan dari email aktivasi terbaru. Jika masih bermasalah, hubungi administrator untuk meminta undangan baru.'
                : null;

        return Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'invitation' => $invitation,
            'invitationError' => $invitationError,
        ]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $activated = false;

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request, &$activated) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                if ($user->pegawai()->exists() && ! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
                    /** @var MustVerifyEmail $user */
                    event(new Verified($user));
                    $activated = true;
                }

                event(new PasswordReset($user));
            }
        );

        if ($status == Password::PasswordReset) {
            return to_route($activated ? 'home' : 'login')->with(
                'status',
                $activated
                    ? 'Akun berhasil diaktifkan dan email telah terverifikasi. Pasang aplikasi sesuai perangkat Anda, lalu masuk dengan password yang baru dibuat.'
                    : __($status),
            );
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}
