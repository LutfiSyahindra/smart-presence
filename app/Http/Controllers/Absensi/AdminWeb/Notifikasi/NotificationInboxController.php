<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Notifikasi;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Services\Notifications\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationInboxController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('absensi/admin-web/notifikasi/Inbox', [
            'notifications' => $request->user()->notifications()->latest()->paginate(20)->withQueryString()
                ->through(fn (DatabaseNotification $notification) => $this->serialize($notification)),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function feed(Request $request, WebPushService $push): JsonResponse
    {
        return response()->json([
            'items' => $request->user()->notifications()->latest()->limit(8)->get()->map(fn ($item) => $this->serialize($item)),
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'push_public_key' => $push->configured() ? config('webpush.public_key') : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['success' => true]) : back();
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();
        $url = $item->data['url'] ?? '';
        abort_unless(str_starts_with($url, '/absensi/admin-web/'), 404);

        return redirect($url);
    }

    public function cuti(Request $request, CutiRequest $cutiRequest): Response
    {
        $user = $request->user();
        $cutiRequest->load('approvals');
        abort_unless($user->hasRole('super-admin') || (int) $cutiRequest->user_id === (int) $user->id
            || $cutiRequest->approvals->contains('user_id', $user->id), 403);

        return Inertia::render('absensi/admin-web/notifikasi/Detail', [
            'cuti' => [
                'id' => $cutiRequest->id, 'nama_pegawai' => $cutiRequest->nama_pegawai,
                'jabatan' => $cutiRequest->nama_jabatan, 'unit' => $cutiRequest->nama_unit,
                'tanggal_mulai' => $cutiRequest->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $cutiRequest->tanggal_selesai->format('Y-m-d'),
                'alasan' => $cutiRequest->alasan, 'status' => $cutiRequest->status,
                'current_level' => $cutiRequest->current_level, 'nama_cuti' => $cutiRequest->nama_cuti,
                'jumlah_hari' => $cutiRequest->jumlah_hari, 'created_at' => $cutiRequest->created_at->format('d M Y, H:i'),
                'can_decide' => $cutiRequest->status === 'pending' && (int) $cutiRequest->user_id !== (int) $user->id
                    && $cutiRequest->approvals->contains(fn ($step) => (int) $step->user_id === (int) $user->id
                        && $step->level === $cutiRequest->current_level && $step->status === 'pending'),
                'approvals' => $cutiRequest->approvals->map(fn ($step) => [
                    'level' => $step->level, 'name' => $step->approver_name, 'status' => $step->status,
                    'note' => $step->note, 'decided_at' => $step->decided_at?->format('d M Y, H:i'),
                ])->values(),
            ],
        ]);
    }

    private function serialize(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id, 'title' => $notification->data['title'] ?? 'Notifikasi pengajuan',
            'message' => $notification->data['message'] ?? '', 'event' => $notification->data['event'] ?? '',
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
            'url' => route('absensi.admin-web.notifikasi.open', $notification->id, absolute: false),
        ];
    }
}
