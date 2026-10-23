<?php

namespace App\Services\Notifications;

use App\Models\LemburRequest;
use App\Models\User;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;

class LemburNotificationService
{
    public function __construct(private readonly PengajuanNotificationService $notifications) {}

    public function send(LemburRequest $lembur, string $event, User $actor): void
    {
        $lembur->load('approvals');
        $ids = match ($event) {
            'submitted', 'approval_required' => $lembur->approvals->where('status', 'pending')->pluck('user_id'),
            default => $lembur->approvals->pluck('user_id')->push($lembur->user_id),
        };
        $recipients = User::query()->where(function ($query) use ($ids) {
            $query->whereIn('id', $ids)->orWhereHas('roles', fn ($roles) => $roles->where('name', 'super-admin')->where('guard_name', 'web'));
        })->where('id', '!=', $actor->id)->get()
            ->filter(fn (User $user) => PengajuanAccess::allows($user) || $user->can(AccessPermission::MOBILE_ACCESS));
        $title = match ($event) {
            'submitted' => 'Pengajuan lembur baru', 'approval_required' => 'Lembur menunggu persetujuan Anda',
            'approved' => 'Pengajuan lembur disetujui', 'rejected' => 'Pengajuan lembur ditolak', 'cancelled' => 'Pengajuan lembur dibatalkan',
        };
        if ($lembur->request_kind === 'usage') {
            $title = str_replace(['Pengajuan lembur', 'Lembur menunggu'], ['Penggunaan waktu lembur', 'Penggunaan waktu lembur menunggu'], $title);
        }
        $url = route('absensi.mobile.lembur.show', $lembur, absolute: false);
        $hours = intdiv($lembur->duration_minutes, 60);
        $minutes = $lembur->duration_minutes % 60;
        $activity = $lembur->request_kind === 'usage'
            ? 'penggunaan waktu lembur untuk '.($lembur->usage_type === 'late_arrival' ? 'telat masuk' : 'pulang cepat')
            : 'lembur';
        $this->notifications->send($recipients, [
            'request_type' => 'lembur', 'request_id' => $lembur->id, 'event' => $event, 'title' => $title,
            'message' => "{$lembur->nama_pegawai} mengajukan {$activity} {$hours}j {$minutes}m pada ".$lembur->tanggal_lembur->format('d/m/Y').'.',
            'url' => route('absensi.admin-web.notifikasi.lembur', $lembur, absolute: false), 'mobile_url' => $url,
        ]);
    }
}
