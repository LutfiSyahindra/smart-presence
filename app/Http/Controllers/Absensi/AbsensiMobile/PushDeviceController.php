<?php

namespace App\Http\Controllers\Absensi\AbsensiMobile;

use App\Http\Controllers\Controller;
use App\Models\MobilePushDevice;
use App\Services\Notifications\MobilePushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushDeviceController extends Controller
{
    public function store(Request $request, MobilePushService $push): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'token' => ['required', 'string', 'min:32', 'max:4096', 'regex:/^[a-zA-Z0-9_:\-]+$/'],
        ]);
        if ($data['platform'] === 'ios') {
            abort_unless(ctype_xdigit($data['token']) && strlen($data['token']) % 2 === 0, 422);
            $data['token'] = strtolower($data['token']);
        }
        abort_unless($push->configured($data['platform']), 503, 'Push perangkat belum dikonfigurasi.');
        $device = MobilePushDevice::query()->updateOrCreate([
            'token_hash' => hash('sha256', $data['platform'].':'.$data['token']),
        ], ['user_id' => $request->user()->id, ...$data]);
        $previous = $request->session()->get('mobile_push_device_id');
        if ($previous && $previous !== $device->id) {
            MobilePushDevice::query()->whereKey($previous)->where('user_id', $request->user()->id)->delete();
        }
        $request->session()->put('mobile_push_device_id', $device->id);

        return response()->json(['success' => true]);
    }
}
