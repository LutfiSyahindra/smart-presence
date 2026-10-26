<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSubmission;
use App\Support\AccessPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceSubmissionController extends Controller
{
    public function show(Request $request, AttendanceSubmission $attendanceSubmission): Response|JsonResponse|RedirectResponse
    {
        abort_unless((int) $attendanceSubmission->user_id === (int) $request->user()->id, 404);
        $this->authorizeAction($request, $attendanceSubmission->action);
        $payload = $attendanceSubmission->statusPayload();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['data' => $payload])->header('Cache-Control', 'private, no-store');
        }

        if ($payload['success_url']) {
            return redirect($payload['success_url']);
        }

        return Inertia::render('absensi/absensi-mobile/AttendanceSubmission', ['submission' => $payload]);
    }

    public function latest(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:clock-in,clock-out']]);
        $this->authorizeAction($request, $data['action']);
        $submission = AttendanceSubmission::query()->visibleTo($request->user())
            ->where('action', $data['action'])->latest('received_at')->latest('id')->first();

        return response()->json(['data' => $submission?->statusPayload()])->header('Cache-Control', 'private, no-store');
    }

    private function authorizeAction(Request $request, string $action): void
    {
        abort_unless($request->user()->can($action === 'clock-in' ? AccessPermission::MOBILE_CLOCK_IN : AccessPermission::MOBILE_CLOCK_OUT), 403);
    }
}
