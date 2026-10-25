<?php

namespace App\Services\Notifications;

use App\Models\PerubahanShiftRequest;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;

class PerubahanShiftNotificationService
{
    public function __construct(private readonly PengajuanNotificationService $notifications) {}

    public function send(PerubahanShiftRequest $request, string $event, User $actor): void
    {
        $request->load('approvals');
        $ids = in_array($event, ['submitted', 'approval_required'], true)
            ? $request->approvals->where('status', 'pending')->pluck('user_id')
            : $request->approvals->pluck('user_id')->push($request->user_id);
        $recipients = User::query()->where(fn ($query) => $query->whereIn('id', $ids)
            ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'super-admin')->where('guard_name', 'web')))
            ->whereNotNull('email_verified_at')->where('id', '!=', $actor->id)->get()
            ->filter(fn (User $user) => $user->can(AccessPermission::MOBILE_ACCESS) || PengajuanAccess::allows($user));
        $date = $request->tanggal_perubahan->format('d/m/Y');
        $description = "{$request->nama_pegawai}: {$request->original_shift['name']} ke {$request->requested_shift['name']} pada {$date}.";
        $this->notifications->send($recipients, [
            'request_type' => 'perubahan-shift', 'request_id' => $request->id, 'event' => $event,
            'title' => match ($event) {
                'submitted' => 'Pengajuan perubahan shift baru', 'approval_required' => 'Perubahan shift menunggu persetujuan Anda',
                'approved' => 'Pengajuan perubahan shift disetujui', 'rejected' => 'Pengajuan perubahan shift ditolak',
                'cancelled' => 'Pengajuan perubahan shift dibatalkan',
            },
            'message' => $event === 'cancelled' ? 'Dibatalkan oleh pemohon. '.$description : $description,
            'url' => route('absensi.admin-web.notifikasi.perubahan-shift', $request, absolute: false),
            'mobile_url' => route('absensi.mobile.perubahan-shift.show', $request, absolute: false),
        ]);
    }
}
