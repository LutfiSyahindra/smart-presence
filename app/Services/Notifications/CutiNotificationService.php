<?php

namespace App\Services\Notifications;

use App\Models\CutiRequest;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\CutiAccess;

class CutiNotificationService
{
    public function __construct(private readonly PengajuanNotificationService $notifications) {}

    public function send(CutiRequest $cuti, string $event, User $actor): void
    {
        $cuti->loadMissing('approvals');
        $ids = match ($event) {
            'submitted', 'approval_required' => $cuti->approvals->where('status', 'pending')->pluck('user_id'),
            default => $cuti->approvals->pluck('user_id')->push($cuti->user_id),
        };
        $recipients = User::query()->where(function ($query) use ($ids) {
            $query->whereIn('id', $ids)->orWhereHas('roles', fn ($roles) => $roles->where('name', 'super-admin')->where('guard_name', 'web'));
        })->where('id', '!=', $actor->id)->get()
            ->filter(fn (User $user) => CutiAccess::allows($user) || $user->can(AccessPermission::MOBILE_ACCESS));

        $title = match ($event) {
            'submitted' => 'Pengajuan cuti baru',
            'approval_required' => 'Pengajuan cuti menunggu approval berikutnya',
            'approved' => 'Pengajuan cuti disetujui',
            'rejected' => 'Pengajuan cuti ditolak',
            'cancelled' => 'Pengajuan cuti dibatalkan',
        };

        $this->notifications->send($recipients, [
            'request_type' => 'cuti', 'request_id' => $cuti->id, 'event' => $event,
            'title' => $title,
            'message' => "{$cuti->nama_pegawai} mengajukan {$cuti->nama_cuti} pada ".
                $cuti->tanggal_mulai->format('d/m/Y').' s.d. '.$cuti->tanggal_selesai->format('d/m/Y').'.',
            'url' => route('absensi.admin-web.notifikasi.cuti', $cuti, absolute: false),
            'mobile_url' => route('absensi.mobile.cuti.show', $cuti, absolute: false),
        ]);
    }
}
