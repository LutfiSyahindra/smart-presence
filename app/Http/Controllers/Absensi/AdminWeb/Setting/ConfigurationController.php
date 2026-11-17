<?php

namespace App\Http\Controllers\Absensi\AdminWeb\Setting;

use App\Http\Controllers\Controller;
use App\Models\AbsensiConfiguration;
use App\Models\ApprovalLine;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class ConfigurationController extends Controller
{
    public function index(Request $request, ConfigurationService $configuration): Response
    {
        $roles = Role::query()->where('guard_name', 'web')->orderBy('name')->get(['id', 'name']);
        $settings = $configuration->current()->only(array_keys(AbsensiConfiguration::DEFAULTS));
        $settings['face_minimum_similarity'] = $configuration->faceMinimumSimilarity();
        $settings['mobile_presensi_pegawai_all_role_ids'] = array_values(array_intersect(
            $settings['mobile_presensi_pegawai_all_role_ids'] ?? [], $roles->modelKeys(),
        ));

        return Inertia::render('absensi/admin-web/setting/konfigurasi/Index', [
            'configuration' => $settings,
            'approvalTypeOptions' => collect(ApprovalLine::typeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'roleOptions' => $roles,
            'can' => ['update' => $request->user()->can(AccessPermission::CONFIGURATION_UPDATE)],
        ]);
    }

    public function update(Request $request, ConfigurationService $configuration): RedirectResponse
    {
        $data = $request->validate([
            'cancel_approved_cuti' => ['required', 'boolean'],
            'cuti_past_date' => ['required', 'boolean'],
            'cancel_approved_lembur' => ['required', 'boolean'],
            'cancel_approved_perubahan_shift' => ['required', 'boolean'],
            'perubahan_shift_after_clock_in' => ['required', 'boolean'],
            'perubahan_shift_same_day' => ['required', 'boolean'],
            'perubahan_shift_past_date' => ['required', 'boolean'],
            'perubahan_shift_without_default' => ['required', 'boolean'],
            'clock_in_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'clock_out_without_clock_in' => ['required', 'boolean'],
            'face_minimum_similarity' => ['required', 'numeric', 'min:1', 'max:100', 'decimal:0,2'],
            'approve_past_requests' => ['required', 'boolean'],
            'allow_self_approval' => ['required', 'boolean'],
            'mobile_presensi_pegawai_scope' => ['required', Rule::in([
                AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_UNIT,
                AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE,
            ])],
            'mobile_presensi_pegawai_approval_type' => ['required', Rule::in(ApprovalLine::types())],
            'mobile_presensi_pegawai_all_role_ids' => ['present', 'array', 'list'],
            'mobile_presensi_pegawai_all_role_ids.*' => ['required', 'integer', 'distinct',
                Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ], [
            'clock_in_tolerance_minutes.required' => 'Toleransi clock-in wajib diisi.',
            'clock_in_tolerance_minutes.integer' => 'Toleransi clock-in harus berupa menit bulat.',
            'clock_in_tolerance_minutes.min' => 'Toleransi clock-in minimal 0 menit.',
            'clock_in_tolerance_minutes.max' => 'Toleransi clock-in maksimal 1440 menit.',
            'face_minimum_similarity.required' => 'Batas minimal similarity wajah wajib diisi.',
            'face_minimum_similarity.numeric' => 'Batas minimal similarity wajah harus berupa angka.',
            'face_minimum_similarity.min' => 'Batas minimal similarity wajah paling rendah 1%.',
            'face_minimum_similarity.max' => 'Batas minimal similarity wajah paling tinggi 100%.',
            'face_minimum_similarity.decimal' => 'Batas minimal similarity wajah maksimal 2 angka desimal.',
            'mobile_presensi_pegawai_scope.required' => 'Pilih cakupan Presensi Pegawai mobile.',
            'mobile_presensi_pegawai_scope.in' => 'Cakupan harus berdasarkan kepala unit atau approval line.',
            'mobile_presensi_pegawai_approval_type.required' => 'Pilih kategori approval line untuk Presensi Pegawai mobile.',
            'mobile_presensi_pegawai_approval_type.in' => 'Kategori approval line tidak valid.',
            'mobile_presensi_pegawai_all_role_ids.present' => 'Daftar role akses seluruh presensi harus disertakan.',
            'mobile_presensi_pegawai_all_role_ids.array' => 'Daftar role akses seluruh presensi harus berupa pilihan role.',
            'mobile_presensi_pegawai_all_role_ids.*.exists' => 'Salah satu role yang dipilih sudah tidak tersedia.',
            'mobile_presensi_pegawai_all_role_ids.*.distinct' => 'Role yang sama hanya boleh dipilih sekali.',
        ]);
        $data['mobile_presensi_pegawai_all_role_ids'] = array_map('intval', $data['mobile_presensi_pegawai_all_role_ids']);
        $configuration->update($data, $request->user()->id);

        return back()->with('success', 'Konfigurasi absensi berhasil disimpan.');
    }
}
