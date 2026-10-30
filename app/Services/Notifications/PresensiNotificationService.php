<?php

namespace App\Services\Notifications;

use App\Models\PresensiRequest;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;

class PresensiNotificationService
{
    public function __construct(private readonly PengajuanNotificationService $notifications) {}

    public function send(PresensiRequest $request, string $event, User $actor): void
    {
        $request->load('approvals');
        $ids = in_array($event, ['submitted', 'approval_required'], true)
            ? $request->approvals->where('status', 'pending')->pluck('user_id')
            : $request->approvals->pluck('user_id')->push($request->user_id);
        $recipients = User::query()->where(fn ($query) => $query->whereIn('id', $ids)
            ->orWhereHas('roles', fn ($roles) => $roles->where('name', 'super-admin')->where('guard_name', 'web')))
            ->whereNotNull('email_verified_at')->where('id', '!=', $actor->id)->get()
            ->filter(fn (User $user) => $user->can(AccessPermission::MOBILE_ACCESS) || PengajuanAccess::allows($user));
        $this->notifications->send($recipients, [
            'request_type' => 'presensi', 'request_id' => $request->id, 'event' => $event,
            'title' => match ($event) {
                'submitted' => 'Presensi di luar radius baru', 'approval_required' => 'Presensi menunggu persetujuan Anda',
                'approved' => 'Presensi di luar radius disetujui', 'rejected' => 'Presensi di luar radius ditolak',
            },
            'message' => "{$request->nama_pegawai}: {$request->action} di luar radius pada ".$request->recorded_at->format('d/m/Y H:i').'.',
            'url' => route('absensi.admin-web.notifikasi.presensi', $request, absolute: false),
            'mobile_url' => route('absensi.mobile.presensi-approval.show', $request, absolute: false),
        ]);
    }
}
