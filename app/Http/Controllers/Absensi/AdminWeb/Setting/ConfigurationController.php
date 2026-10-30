<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Setting;

use App\Http\Controllers\Controller;
use App\Models\AbsensiConfiguration;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfigurationController extends Controller
{
    public function index(Request $request, ConfigurationService $configuration): Response
    {
        return Inertia::render('absensi/admin-web/setting/konfigurasi/Index', [
            'configuration' => $configuration->current()->only(array_keys(AbsensiConfiguration::DEFAULTS)),
            'can' => ['update' => $request->user()->can(AccessPermission::CONFIGURATION_UPDATE)],
        ]);
    }

    public function update(Request $request, ConfigurationService $configuration): RedirectResponse
    {
        $data = $request->validate([
            'cancel_approved_cuti' => ['required', 'boolean'],
            'cancel_approved_lembur' => ['required', 'boolean'],
            'cancel_approved_perubahan_shift' => ['required', 'boolean'],
            'perubahan_shift_after_clock_in' => ['required', 'boolean'],
            'clock_in_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'approve_past_requests' => ['required', 'boolean'],
        ], [
            'clock_in_tolerance_minutes.required' => 'Toleransi clock-in wajib diisi.',
            'clock_in_tolerance_minutes.integer' => 'Toleransi clock-in harus berupa menit bulat.',
            'clock_in_tolerance_minutes.min' => 'Toleransi clock-in minimal 0 menit.',
            'clock_in_tolerance_minutes.max' => 'Toleransi clock-in maksimal 1440 menit.',
        ]);
        $configuration->update($data, $request->user()->id);

        return back()->with('success', 'Konfigurasi absensi berhasil disimpan.');
    }
}
