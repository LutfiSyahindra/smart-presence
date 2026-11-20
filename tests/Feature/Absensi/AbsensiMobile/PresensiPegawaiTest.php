<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\AbsensiConfiguration;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Carbon\Carbon;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PresensiPegawaiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/presensi-pegawai';

    private User $head;

    private UnitKerja $unit;

    private UnitKerja $otherUnit;

    private Shift $shift;

    private Pegawai $employee;

    private Pegawai $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        Carbon::setTestNow('2026-11-16 17:00:00');
        $this->head = User::factory()->create();
        $this->head->assignRole('pegawai');
        $this->head->givePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->unit = UnitKerja::create(['kode' => 'IGD', 'nama' => 'Instalasi Gawat Darurat', 'aktif' => true]);
        $this->otherUnit = UnitKerja::create(['kode' => 'RI', 'nama' => 'Rawat Inap', 'aktif' => true]);
        $this->shift = Shift::create(['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $this->employee('Kepala Unit', $this->unit, $this->head);
        $this->employee = $this->employee('A Siti', $this->unit, User::factory()->create());
        $this->outsider = $this->employee('Z Pegawai Luar', $this->otherUnit, User::factory()->create());
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $name, UnitKerja $unit, ?User $user = null): Pegawai
    {
        $position = Jabatan::create(['unit_kerja_id' => $unit->id, 'kode' => 'J-'.(Jabatan::count() + 1), 'nama' => $name.' Jabatan', 'aktif' => true]);
        $employee = Pegawai::create(['nama' => $name, 'nik' => 'NIK-'.$position->id,
            'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id, 'shift_id' => $this->shift->id]);
        $employee->forceFill(['user_id' => $user?->id])->save();

        return $employee;
    }

    private function record(Pegawai $employee, array $attributes = []): Presensi
    {
        return Presensi::create([...['pegawai_id' => $employee->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-11-16', 'clock_in_at' => '2026-11-16 07:17:00', 'clock_out_at' => '2026-11-16 15:00:00'], ...$attributes]);
    }

    private function cuti(Pegawai $employee, array $attributes = []): CutiRequest
    {
        return CutiRequest::create([
            'pegawai_id' => $employee->id, 'user_id' => $employee->user_id,
            'nama_pegawai' => $employee->nama, 'nama_jabatan' => '-', 'nama_unit' => '-',
            'nama_cuti' => 'Cuti Tahunan', 'tanggal_mulai' => '2026-11-16', 'tanggal_selesai' => '2026-11-18',
            'alasan' => 'Keperluan keluarga', 'status' => 'approved', ...$attributes,
        ]);
    }

    private function approvalLine(array $employees, string $type = ApprovalLine::TYPE_PRESENSI, int $level = 1): ApprovalLine
    {
        $line = ApprovalLine::create(['type' => $type]);
        foreach ($employees as $employee) {
            $line->jabatans()->attach($employee->jabatan_id, ['type' => $type]);
        }
        if ($level === 2) {
            $line->steps()->create(['user_id' => User::factory()->create()->id, 'level' => 1]);
        }
        $line->steps()->create(['user_id' => $this->head->id, 'level' => $level]);

        return $line;
    }

    private function approvalScope(string $type = ApprovalLine::TYPE_PRESENSI): void
    {
        app(ConfigurationService::class)->update([
            'mobile_presensi_pegawai_scope' => AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE,
            'mobile_presensi_pegawai_approval_type' => $type,
        ], $this->head->id);
    }

    public function test_menu_and_routes_require_the_specific_permission_and_a_linked_active_unit(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
        $this->actingAs($this->head)->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', true));
        $this->head->revokePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', false));
        $this->get(self::URL)->assertForbidden();
        $this->head->givePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->unit->update(['aktif' => false]);
        $this->get(self::URL)->assertForbidden();
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', false));
        $this->unit->update(['aktif' => true]);
        $this->head->pegawai()->update(['unit_kerja_id' => null]);
        $this->get(self::URL)->assertForbidden();
    }

    public function test_list_and_summary_include_only_other_employees_in_the_same_unit(): void
    {
        $this->record($this->employee);
        $this->record($this->outsider);
        $this->record($this->head->pegawai);
        $this->employee('B Pegawai tanpa akun', $this->unit);
        $this->actingAs($this->head)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/PresensiPegawai')
            ->where('unitName', $this->unit->nama)->where('filters.date_from', '2026-11-16')
            ->where('overview.total', 1)->where('overview.present', 1)->where('overview.late', 1)
            ->where('records.total', 2)->where('records.data.0.pegawai.nama', 'A Siti')
            ->where('records.data.0.clock_in.time', '07:17')->where('records.data.0.status', 'late')
            ->where('records.data.0.shift.jam_masuk', '07:00')->where('records.data.0.late_minutes', 17)
            ->where('records.data.1.has_presensi', false));
    }

    public function test_search_and_client_supplied_scopes_cannot_expose_another_unit(): void
    {
        $this->record($this->employee);
        $this->record($this->outsider);
        $this->actingAs($this->head)->get(self::URL.'?'.http_build_query([
            'unit_kerja_id' => $this->otherUnit->id, 'pegawai_id' => $this->outsider->id,
            'pegawai_scope' => [$this->outsider->id], 'search' => 'Pegawai Luar',
        ]))->assertInertia(fn (Assert $page) => $page->where('records.total', 0)->where('overview.total', 0));
        $this->get(self::URL.'?search='.urlencode($this->employee->nik))
            ->assertInertia(fn (Assert $page) => $page->where('records.total', 1)->where('records.data.0.pegawai_id', $this->employee->id));
    }

    public function test_status_filters_and_summary_do_not_count_pending_attendance_as_present(): void
    {
        $this->record($this->employee, ['clock_in_approval_status' => 'pending']);
        $this->employee('B Belum presensi', $this->unit);
        $this->record($this->outsider);
        $this->actingAs($this->head)->get(self::URL.'?status=approval-pending')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.status', 'approval-pending')
            ->where('overview.total', 1)->where('overview.present', 0)->where('overview.late', 0));
        $this->get(self::URL.'?status=late')->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
        $this->get(self::URL.'?status=unrecorded')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai.nama', 'B Belum presensi')->where('records.data.0.has_presensi', false));
    }

    public function test_approved_leave_overrides_an_incomplete_scheduled_record_without_changing_its_shift(): void
    {
        $record = $this->record($this->employee, ['clock_in_at' => null, 'clock_out_at' => null]);
        $this->cuti($this->employee);

        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.id', $record->id)
            ->where('records.data.0.has_presensi', true)->where('records.data.0.status', 'cuti')
            ->where('records.data.0.cuti.nama', 'Cuti Tahunan')
            ->where('records.data.0.cuti.tanggal_mulai', '2026-11-16')
            ->where('records.data.0.cuti.tanggal_selesai', '2026-11-18'));
        $this->get(self::URL.'?status=incomplete')->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
        $this->get(self::URL.'?status=cuti')->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'cuti')->where('records.total', 1)->where('records.data.0.status', 'cuti'));
        $this->assertSame($this->shift->id, $record->fresh()->shift_id);
        $this->assertSame($this->shift->id, $this->employee->fresh()->shift_id);
        $this->assertDatabaseCount('presensis', 1);
    }

    public function test_approved_leave_without_attendance_is_listed_as_cuti_and_not_unrecorded(): void
    {
        $this->cuti($this->employee, ['tanggal_mulai' => '2026-11-18']);
        $noAccount = $this->employee('B Tanpa akun', $this->unit);
        $this->cuti($noAccount);
        $this->cuti($this->outsider);
        $this->employee('C Belum presensi', $this->unit);
        $period = '?date_from=2026-11-16&date_to=2026-11-18';

        $this->actingAs($this->head)->get(self::URL.$period)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 3)->where('records.data.0.has_presensi', false)
            ->where('records.data.0.status', 'cuti')->where('records.data.0.tanggal', '2026-11-18')
            ->where('records.data.1.status', 'cuti')->where('records.data.1.pegawai_id', $noAccount->id)
            ->where('records.data.2.status', null)->where('overview.total', 0));
        $this->get(self::URL.$period.'&status=unrecorded')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai.nama', 'C Belum presensi'));
        $this->get(self::URL.$period.'&status=cuti')->assertInertia(fn (Assert $page) => $page->where('records.total', 2));
        $this->get(self::URL.$period.'&status=cuti&search='.urlencode($this->outsider->nik))
            ->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
        $this->assertDatabaseCount('presensis', 0);
    }

    #[DataProvider('inactiveLeave')]
    public function test_only_approved_leave_covering_the_record_date_overrides_attendance(string $status, string $from, string $to): void
    {
        $this->record($this->employee, ['clock_in_at' => null, 'clock_out_at' => null]);
        $this->cuti($this->employee, ['status' => $status, 'tanggal_mulai' => $from, 'tanggal_selesai' => $to]);
        $this->actingAs($this->head)->get(self::URL.'?status=incomplete')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.status', 'incomplete')->where('records.data.0.cuti', null));
        $this->get(self::URL.'?status=cuti')->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
    }

    public static function inactiveLeave(): array
    {
        return [
            'pending' => ['pending', '2026-11-16', '2026-11-18'],
            'rejected' => ['rejected', '2026-11-16', '2026-11-18'],
            'cancelled' => ['cancelled', '2026-11-16', '2026-11-18'],
            'already ended' => ['approved', '2026-11-14', '2026-11-15'],
            'not started' => ['approved', '2026-11-17', '2026-11-18'],
        ];
    }

    public function test_leave_uses_each_attendance_date_in_a_period_including_overnight_and_boundary_dates(): void
    {
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->record($this->employee, ['tanggal_presensi' => '2026-11-15',
            'clock_in_at' => '2026-11-15 22:00:00', 'clock_out_at' => '2026-11-16 06:00:00']);
        foreach ([16, 18, 19] as $day) {
            $this->record($this->employee, ['tanggal_presensi' => "2026-11-{$day}", 'clock_in_at' => null, 'clock_out_at' => null]);
        }
        $this->cuti($this->employee);
        $period = '?date_from=2026-11-15&date_to=2026-11-19';

        $this->actingAs($this->head)->get(self::URL.$period)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 4)->where('records.data.0.status', 'incomplete')
            ->where('records.data.1.status', 'cuti')->where('records.data.2.status', 'cuti')
            ->where('records.data.3.status', 'on-time')->where('records.data.3.cuti', null)
            ->where('overview.present', 1)->where('overview.late', 0));
        $this->get(self::URL.$period.'&status=cuti')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 2)->where('records.data.0.tanggal', '2026-11-18')->where('records.data.1.tanggal', '2026-11-16'));
        $this->get(self::URL.$period.'&status=incomplete')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.tanggal', '2026-11-19'));
    }

    public function test_leave_takes_precedence_in_filters_and_summary_and_cancellation_restores_attendance_status(): void
    {
        $this->record($this->employee, ['clock_out_at' => null, 'overtime_minutes' => 60]);
        $cuti = $this->cuti($this->employee);
        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.status', 'cuti')->where('overview.total', 1)
            ->where('overview.present', 0)->where('overview.late', 0)->where('overview.overtime', 0)->where('overview.day_off', 0));
        foreach (['present', 'late', 'on-time', 'incomplete', 'overtime', 'day-off', 'unrecorded'] as $status) {
            $this->get(self::URL.'?status='.$status)->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
        }
        $cuti->update(['status' => 'cancelled']);
        $this->get(self::URL.'?status=incomplete')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.status', 'incomplete')->where('records.data.0.cuti', null)
            ->where('overview.present', 1)->where('overview.late', 1)->where('overview.overtime', 1));
        $this->get(self::URL.'?status=cuti')->assertInertia(fn (Assert $page) => $page->where('records.total', 0));
    }

    public function test_leave_filter_is_applied_before_pagination(): void
    {
        for ($index = 1; $index <= 13; $index++) {
            $employee = $this->employee(sprintf('B Cuti %02d', $index), $this->unit);
            $this->cuti($employee);
        }
        $this->actingAs($this->head)->get(self::URL.'?status=cuti')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 13)->where('records.last_page', 2)->has('records.data', 12));
        $this->get(self::URL.'?status=cuti&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 13)->has('records.data', 1)->where('records.data.0.status', 'cuti')
            ->where('records.data.0.pegawai.nama', 'B Cuti 13'));
    }

    public function test_history_preserves_night_shift_clock_dates_and_pagination(): void
    {
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $record = $this->record($this->employee, ['tanggal_presensi' => '2026-11-01',
            'clock_in_at' => '2026-11-01 22:00:00', 'clock_out_at' => '2026-11-02 06:00:00']);
        for ($day = 2; $day <= 14; $day++) {
            $date = sprintf('2026-11-%02d', $day);
            $this->record($this->employee, ['tanggal_presensi' => $date, 'clock_in_at' => "$date 22:00:00", 'clock_out_at' => null]);
        }
        $url = self::URL.'?date_from=2026-11-14&date_to=2026-11-01';
        $this->actingAs($this->head)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('filters.date_from', '2026-11-01')->where('filters.date_to', '2026-11-14')
            ->where('records.total', 14)->where('records.last_page', 2)->has('records.data', 12)->where('overview.total', 14));
        $this->get($url.'&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('records.current_page', 2)->where('records.data.1.id', $record->id)
            ->where('records.data.1.clock_out.date_time', '2026-11-02 06:00')->where('overview.total', 14));
    }

    public function test_photos_are_scoped_and_access_is_rechecked_after_a_unit_change(): void
    {
        $record = $this->record($this->employee, ['clock_in_photo_path' => 'presensi/in.jpg', 'clock_out_photo_path' => 'storage/presensi/out.jpg']);
        $other = $this->record($this->outsider, ['clock_in_photo_path' => 'presensi/other.jpg']);
        Storage::disk('local')->put('presensi/in.jpg', 'in-photo');
        Storage::disk('public')->put('presensi/out.jpg', 'out-photo');
        Storage::disk('local')->put('presensi/other.jpg', 'other-photo');
        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.clock_in.photo_url', self::URL."/{$record->id}/foto/in"));
        $this->get(self::URL."/{$record->id}/foto/in")->assertOk()->assertStreamedContent('in-photo');
        $this->get(self::URL."/{$record->id}/foto/out")->assertOk()->assertStreamedContent('out-photo');
        $this->get(self::URL."/{$other->id}/foto/in")->assertNotFound();
        $this->get(self::URL."/{$record->id}/foto/invalid")->assertNotFound();
        $this->employee->update(['unit_kerja_id' => $this->otherUnit->id]);
        $this->get(self::URL."/{$record->id}/foto/in")->assertNotFound();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('records.total', 0)->where('overview.total', 0));
        $this->head->revokePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->get(self::URL."/{$record->id}/foto/in")->assertForbidden();
    }

    public function test_super_admin_is_still_scoped_to_their_own_unit(): void
    {
        $this->head->assignRole('super-admin');
        $this->record($this->employee);
        $other = $this->record($this->outsider, ['clock_in_photo_path' => 'presensi/other.jpg']);
        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai_id', $this->employee->id));
        $this->get(self::URL."/{$other->id}/foto/in")->assertNotFound();
    }

    public function test_configured_roles_can_see_all_employees_in_both_modes_including_self_and_inactive_units(): void
    {
        $auditor = Role::create(['name' => 'auditor-absensi', 'guard_name' => 'web']);
        $otherRole = Role::create(['name' => 'sdm', 'guard_name' => 'web']);
        $this->head->assignRole($auditor);
        app(ConfigurationService::class)->update([
            'mobile_presensi_pegawai_all_role_ids' => [$otherRole->id, $auditor->id],
        ], $this->head->id);
        $noAccount = $this->employee('B Tanpa akun', $this->otherUnit);
        $noUnit = Pegawai::create(['nama' => 'C Tanpa unit']);
        $this->record($this->employee);
        $this->record($this->outsider);
        $this->record($this->head->pegawai);
        $this->otherUnit->update(['aktif' => false]);

        $this->actingAs($this->head)->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('canViewPresensiPegawai', true));
        foreach ([AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_UNIT, AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE] as $scope) {
            app(ConfigurationService::class)->update(['mobile_presensi_pegawai_scope' => $scope], $this->head->id);
            $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
                ->where('scopeMode', 'all')->where('records.total', 5)
                ->where('records.data.1.pegawai_id', $noAccount->id)->where('records.data.1.has_presensi', false)
                ->where('records.data.2.pegawai_id', $noUnit->id)->where('records.data.3.pegawai_id', $this->head->pegawai->id)
                ->where('records.data.4.pegawai_id', $this->outsider->id)->where('overview.total', 3)->where('overview.present', 3));
        }
        $this->head->pegawai()->delete();
        $this->get(self::URL)->assertOk();
    }

    public function test_full_access_uses_current_roles_and_configuration_for_lists_summary_search_and_photos(): void
    {
        $auditor = Role::create(['name' => 'auditor-absensi', 'guard_name' => 'web']);
        $this->head->assignRole($auditor);
        app(ConfigurationService::class)->update(['mobile_presensi_pegawai_all_role_ids' => [$auditor->id]], $this->head->id);
        $record = $this->record($this->outsider, ['clock_in_photo_path' => 'presensi/global.jpg']);
        $this->record($this->employee);
        Storage::disk('local')->put('presensi/global.jpg', 'global-photo');
        $path = "profile-photos/{$this->outsider->user_id}/profile.jpg";
        Storage::disk('local')->put($path, 'global-profile');
        $this->outsider->user->forceFill(['profile_photo_path' => $path])->save();
        $photoUrl = self::URL."/{$record->id}/foto/in";
        $profileUrl = self::URL."/pegawai/{$this->outsider->id}/foto-profil";
        $searchUrl = self::URL.'?search='.urlencode($this->outsider->nik);
        $this->actingAs($this->head)->get($searchUrl)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai_id', $this->outsider->id)->where('overview.total', 1));
        $this->get($photoUrl)->assertOk()->assertStreamedContent('global-photo');
        $this->get($profileUrl)->assertOk()->assertStreamedContent('global-profile');
        $auditor->update(['name' => 'auditor-baru']);
        $this->get($photoUrl)->assertOk();
        $this->head->removeRole($auditor);
        $this->get($photoUrl)->assertNotFound();
        $this->get($profileUrl)->assertNotFound();
        $this->get($searchUrl)->assertInertia(fn (Assert $page) => $page->where('records.total', 0)->where('overview.total', 0));
        $this->head->assignRole($auditor);
        app(ConfigurationService::class)->update(['mobile_presensi_pegawai_all_role_ids' => []], $this->head->id);
        $this->get($photoUrl)->assertNotFound();
        $this->get($profileUrl)->assertNotFound();
        $this->get(self::URL.'?'.http_build_query(['mobile_presensi_pegawai_all_role_ids' => [$auditor->id], 'scopeMode' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('scopeMode', 'unit-kerja')->where('records.total', 1));
    }

    public function test_full_access_still_requires_mobile_and_feature_permissions_and_is_revoked_with_role_deletion(): void
    {
        $auditor = Role::create(['name' => 'auditor-absensi', 'guard_name' => 'web']);
        $this->head->assignRole($auditor);
        $this->approvalScope();
        app(ConfigurationService::class)->update(['mobile_presensi_pegawai_all_role_ids' => [$auditor->id]], $this->head->id);
        $this->actingAs($this->head)->get(self::URL)->assertOk();
        $this->head->revokePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->get(self::URL)->assertForbidden();
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', false));
        $this->head->givePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->head->removeRole('pegawai');
        $this->get(self::URL)->assertForbidden();
        $this->head->givePermissionTo(AccessPermission::MOBILE_ACCESS);
        $this->get(self::URL)->assertOk();
        $auditor->delete();
        $this->get(self::URL)->assertForbidden();
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', false));
    }

    public function test_approval_scope_includes_assigned_positions_across_units_at_any_level_and_excludes_self(): void
    {
        $this->approvalScope();
        $noAccount = $this->employee('B Tanpa akun', $this->otherUnit);
        $this->approvalLine([$this->outsider, $noAccount, $this->head->pegawai], level: 2);
        $this->approvalLine([$this->employee], ApprovalLine::TYPE_CUTI);
        $this->record($this->outsider);
        $this->record($this->employee);
        $this->record($this->head->pegawai);

        $this->actingAs($this->head)->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('canViewPresensiPegawai', true));
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('scopeMode', 'approval-line')->where('approvalTypeLabel', 'Presensi (di luar radius)')
            ->where('records.total', 2)->where('records.data.0.pegawai_id', $noAccount->id)
            ->where('records.data.0.has_presensi', false)->where('records.data.1.pegawai_id', $this->outsider->id)
            ->where('overview.total', 1)->where('overview.late', 1));
        $this->get(self::URL.'?status=unrecorded')->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai_id', $noAccount->id));
    }

    public function test_selected_approval_category_and_scope_changes_apply_to_the_next_request(): void
    {
        $this->approvalLine([$this->outsider]);
        $this->approvalLine([$this->employee], ApprovalLine::TYPE_CUTI);
        $this->approvalScope();
        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai_id', $this->outsider->id));
        $this->approvalScope(ApprovalLine::TYPE_CUTI);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('approvalTypeLabel', 'Cuti')->where('records.total', 1)->where('records.data.0.pegawai_id', $this->employee->id));
        $this->approvalScope(ApprovalLine::TYPE_LEMBUR);
        $this->get(self::URL)->assertForbidden();
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', false));
        app(ConfigurationService::class)->update(['mobile_presensi_pegawai_scope' => 'unit-kerja'], $this->head->id);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('scopeMode', 'unit-kerja')->where('records.total', 1)->where('records.data.0.pegawai_id', $this->employee->id));
    }

    public function test_approval_scope_protects_filters_summary_and_photos_and_rechecks_assignments(): void
    {
        $this->approvalScope();
        $line = $this->approvalLine([$this->outsider]);
        $record = $this->record($this->outsider, ['clock_in_photo_path' => 'presensi/assigned.jpg']);
        $unassigned = $this->record($this->employee, ['clock_in_photo_path' => 'presensi/unassigned.jpg']);
        Storage::disk('local')->put('presensi/assigned.jpg', 'assigned');
        Storage::disk('local')->put('presensi/unassigned.jpg', 'unassigned');
        foreach ([$this->outsider, $this->employee] as $employee) {
            $path = "profile-photos/{$employee->user_id}/profile.jpg";
            Storage::disk('local')->put($path, 'profile');
            $employee->user->forceFill(['profile_photo_path' => $path])->save();
        }
        $photoUrl = self::URL."/{$record->id}/foto/in";
        $profileUrl = self::URL."/pegawai/{$this->outsider->id}/foto-profil";
        $this->actingAs($this->head)->get(self::URL.'?'.http_build_query([
            'unit_kerja_id' => $this->unit->id, 'pegawai_id' => $this->employee->id,
            'pegawai_scope' => [$this->employee->id], 'mobile_presensi_pegawai_scope' => 'unit-kerja',
            'mobile_presensi_pegawai_approval_type' => 'cuti',
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai_id', $this->outsider->id)
            ->where('records.data.0.clock_in.photo_url', $photoUrl)->where('overview.total', 1));
        $this->get(self::URL.'?search='.urlencode($this->employee->nik))->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 0)->where('overview.total', 0));
        $this->get($photoUrl)->assertOk()->assertStreamedContent('assigned');
        $this->get($profileUrl)->assertOk()->assertStreamedContent('profile');
        $this->get(self::URL."/{$unassigned->id}/foto/in")->assertNotFound();
        $this->get(self::URL."/pegawai/{$this->employee->id}/foto-profil")->assertNotFound();
        $line->steps()->update(['user_id' => User::factory()->create()->id]);
        $this->get($photoUrl)->assertForbidden();
        $this->get($profileUrl)->assertForbidden();
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page->where('canViewPresensiPegawai', false));
    }

    public function test_approval_viewers_need_permission_and_active_target_positions_but_no_personal_unit(): void
    {
        $this->approvalScope();
        $this->approvalLine([$this->outsider]);
        $this->head->pegawai()->update(['unit_kerja_id' => null]);
        $this->actingAs($this->head)->get(self::URL)->assertOk();
        $this->head->pegawai()->delete();
        $this->get(self::URL)->assertOk();
        $this->head->revokePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->get(self::URL)->assertForbidden();
        $this->head->givePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->outsider->jabatan->update(['aktif' => false]);
        $this->get(self::URL)->assertForbidden();
        $this->outsider->jabatan->update(['aktif' => true]);
        $this->otherUnit->update(['aktif' => false]);
        $this->get(self::URL)->assertForbidden();
    }

    public function test_super_admin_approval_scope_does_not_bypass_assignment_and_employee_moves(): void
    {
        $this->head->assignRole('super-admin');
        $this->approvalScope();
        $this->approvalLine([$this->outsider]);
        $record = $this->record($this->outsider, ['clock_in_photo_path' => 'presensi/assigned.jpg']);
        Storage::disk('local')->put('presensi/assigned.jpg', 'assigned');
        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 1)->where('records.data.0.pegawai_id', $this->outsider->id));
        $this->outsider->update(['jabatan_id' => $this->employee->jabatan_id, 'unit_kerja_id' => $this->unit->id]);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('records.total', 0)->where('overview.total', 0));
        $this->get(self::URL."/{$record->id}/foto/in")->assertNotFound();
    }

    public function test_profile_photo_is_shown_without_attendance_and_uses_the_current_photo_version(): void
    {
        $user = $this->employee->user;
        $path = "profile-photos/{$user->id}/profile.jpg";
        Storage::disk('local')->put($path, 'profile-photo');
        $user->forceFill(['profile_photo_path' => $path, 'profile_photo_updated_at' => now()])->save();
        $photoUrl = self::URL."/pegawai/{$this->employee->id}/foto-profil";
        $noAccount = $this->employee('B Tanpa akun', $this->unit);
        $this->actingAs($this->head)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.has_presensi', false)
            ->where('records.data.0.pegawai.profile_photo_url', $photoUrl.'?v='.now()->timestamp)
            ->where('records.data.1.pegawai_id', $noAccount->id)
            ->where('records.data.1.pegawai.profile_photo_url', null));
        $this->get($photoUrl)->assertOk()->assertStreamedContent('profile-photo')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', 'inline');
        $this->assertTrue($this->get($photoUrl)->headers->hasCacheControlDirective('no-store'));
        $user->forceFill(['profile_photo_updated_at' => now()->addMinute()])->save();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.pegawai.profile_photo_url', $photoUrl.'?v='.now()->addMinute()->timestamp));
    }

    public function test_profile_photos_require_permission_and_remain_scoped_to_the_current_unit(): void
    {
        foreach ([$this->employee, $this->outsider, $this->head->pegawai] as $employee) {
            $path = "profile-photos/{$employee->user_id}/profile.jpg";
            Storage::disk('local')->put($path, 'private-profile');
            $employee->user->forceFill(['profile_photo_path' => $path])->save();
        }
        $photoUrl = self::URL."/pegawai/{$this->employee->id}/foto-profil";
        $this->get($photoUrl)->assertRedirect('/login');
        $this->actingAs($this->head)->get($photoUrl)->assertOk();
        $this->get(self::URL."/pegawai/{$this->outsider->id}/foto-profil")->assertNotFound();
        $this->get(self::URL."/pegawai/{$this->head->pegawai->id}/foto-profil")->assertNotFound();
        $this->employee->update(['unit_kerja_id' => $this->otherUnit->id]);
        $this->get($photoUrl)->assertNotFound();
        $this->head->revokePermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW);
        $this->get($photoUrl)->assertForbidden();
    }

    public function test_missing_or_invalid_profile_photos_are_not_exposed(): void
    {
        $user = $this->employee->user;
        $otherPath = "profile-photos/{$this->outsider->user_id}/profile.jpg";
        Storage::disk('local')->put($otherPath, 'other-profile');
        $photoUrl = self::URL."/pegawai/{$this->employee->id}/foto-profil";
        $this->actingAs($this->head);
        foreach ([null, "profile-photos/{$user->id}/missing.jpg", $otherPath,
            "profile-photos/{$user->id}/../{$this->outsider->user_id}/profile.jpg", 'https://example.com/profile.jpg'] as $path) {
            $user->forceFill(['profile_photo_path' => $path])->save();
            $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('records.data.0.pegawai.profile_photo_url', null));
            $this->get($photoUrl)->assertNotFound();
        }
    }

    public function test_invalid_filters_are_rejected_and_empty_units_render_an_empty_state(): void
    {
        $this->actingAs($this->head)->getJson(self::URL.'?date_from=2026-02-30&status=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'status']);
        $this->getJson(self::URL.'?search[]=name')->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->employee->delete();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('records.total', 0)->where('overview.total', 0));
    }

    public function test_migration_and_seeder_reserve_the_permission_for_heads_and_administrators(): void
    {
        $role = Role::create(['name' => 'kepala-unit', 'guard_name' => 'web']);
        $migration = require database_path('migrations/2026_11_16_000001_add_mobile_presensi_pegawai_permission.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($role->fresh()->hasPermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW));
        $this->seed(AuthorizationSeeder::class);
        $this->assertFalse(Role::findByName('pegawai')->hasPermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW));
        $this->assertTrue(Role::findByName('super-admin')->hasPermissionTo(AccessPermission::MOBILE_PRESENSI_PEGAWAI_VIEW));
    }
}
