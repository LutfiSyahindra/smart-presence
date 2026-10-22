<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\CutiRequest;
use App\Services\Notifications\MobilePushService;
use App\Services\Notifications\WebPushService;
use App\Support\AccessPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['notifications', 'approvals'])],
            'category' => ['nullable', Rule::in(['cuti'])],
        ]);
        if (($filters['category'] ?? null) === 'cuti') {
            return to_route('absensi.mobile.notifikasi.cuti');
        }

        return Inertia::render('absensi/absensi-mobile/Notifications', [
            'activeTab' => $request->user()->can(AccessPermission::MOBILE_CUTI_VIEW) ? ($filters['tab'] ?? 'notifications') : 'notifications',
            'unreadCount' => $this->query($request)->whereNull('read_at')->count(),
            'pendingApprovalCount' => $request->user()->can(AccessPermission::MOBILE_CUTI_VIEW)
                ? CutiRequest::query()->awaitingApprovalBy($request->user())->count() : 0,
            'today' => now()->toDateString(),
            'notifications' => $this->query($request)->latest()->paginate(20)->withQueryString()->through($this->serialize(...)),
        ]);
    }

    public function feed(Request $request, WebPushService $webPush, MobilePushService $nativePush): JsonResponse
    {
        return response()->json([
            'items' => $this->query($request)->latest()->limit(8)->get()->map($this->serialize(...)),
            'unread_count' => $this->query($request)->whereNull('read_at')->count(),
            'push_public_key' => $webPush->configured() ? config('webpush.public_key') : null,
            'native_push' => ['android' => $nativePush->configured('android'), 'ios' => $nativePush->configured('ios')],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->query($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $notice = $this->query($request)->findOrFail($notification);
        abort_unless(($notice->data['request_type'] ?? null) === 'cuti', 404);
        $cuti = CutiRequest::query()->findOrFail($notice->data['request_id']);
        abort_unless($request->user()->hasRole('super-admin') || (int) $cuti->user_id === (int) $request->user()->id
            || $cuti->approvals()->where('user_id', $request->user()->id)->exists(), 403);
        $notice->markAsRead();

        return to_route('absensi.mobile.cuti.show', $cuti);
    }

    private function query(Request $request)
    {
        return $request->user()->notifications()->whereNotNull('data->mobile_url');
    }

    private function serialize(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id, 'title' => $notification->data['title'] ?? 'Notifikasi pengajuan',
            'message' => $notification->data['message'] ?? '', 'event' => $notification->data['event'] ?? '',
            'read_at' => $notification->read_at?->toIso8601String(), 'created_at' => $notification->created_at->toIso8601String(),
            'url' => route('absensi.mobile.notifikasi.open', $notification->id, absolute: false),
        ];
    }
}
