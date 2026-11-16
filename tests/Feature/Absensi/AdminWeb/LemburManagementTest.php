<?php

namespace Tests\Feature\Absensi\AdminWeb;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LemburManagementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/lembur';

    private User $admin;

    private User $employee;

    private User $approver;

    private Pegawai $pegawai;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-12 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        Bus::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['name' => 'Admin SDM']);
        $this->admin->assignRole('super-admin');
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->approver = User::factory()->create();
        $this->approver->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE]);
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia Putri', 'nik' => 'NIK-001',
            'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id, 'shift_id' => $this->shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $line = ApprovalLine::query()->create(['type' => 'lembur']);
        $line->jabatans()->attach($position->id, ['type' => 'lembur']);
        $line->steps()->create(['user_id' => $this->approver->id, 'level' => 1]);
        $this->actingAs($this->admin);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['pegawai_id' => $this->pegawai->id, 'tanggal_lembur' => '2026-11-10',
            'shift_id' => $this->shift->id, 'before_minutes' => 0, 'before_break_minutes' => 0,
            'after_minutes' => 180, 'after_break_minutes' => 0, 'compensation' => 'time_off', 'alasan' => 'Pelayanan tambahan'], $overrides);
    }

    private function overtime(array $overrides = [], bool $approved = false): LemburRequest
    {
        $lembur = app(LemburService::class)->submit($this->employee, $this->payload($overrides));
        if ($approved) {
            app(LemburService::class)->decide($lembur, $this->approver, ['decision' => 'approved']);
        }

        return $lembur->fresh();
    }

    private function usage(array $overrides = [], bool $approved = false): LemburRequest
    {
        $usage = app(LemburService::class)->submitUsage($this->employee, array_replace([
            'tanggal_lembur' => '2026-11-12', 'shift_id' => $this->shift->id,
            'usage_type' => 'late_arrival', 'duration_minutes' => 60, 'alasan' => 'Keperluan keluarga',
        ], $overrides));
        if ($approved) {
            app(LemburService::class)->decide($usage, $this->approver, ['decision' => 'approved']);
        }

        return $usage->fresh();
    }

    public function test_lembur_defaults_to_employee_usage_in_the_current_month(): void
    {
        $this->overtime(['tanggal_lembur' => '2026-10-28', 'after_minutes' => 720], approved: true);
        $this->overtime(approved: true);
        $this->usage(['tanggal_lembur' => '2026-10-31'], approved: true);
        $this->usage(['tanggal_lembur' => '2026-11-01'], approved: true);
        $pending = $this->usage(['usage_type' => 'early_departure', 'duration_minutes' => 45]);
        $last = $this->usage(['tanggal_lembur' => '2026-11-30', 'duration_minutes' => 30], approved: true);
        $this->usage(['tanggal_lembur' => '2026-12-01']);
        $rejected = $this->usage(['tanggal_lembur' => '2026-11-13']);
        app(LemburService::class)->decide($rejected, $this->approver, ['decision' => 'rejected', 'note' => 'Tidak sesuai jadwal']);
        $cancelled = $this->usage(['tanggal_lembur' => '2026-11-14']);
        app(LemburService::class)->cancel($cancelled, $this->employee);

        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/lembur/Index')
            ->where('filters.request_kind', 'usage')->where('filters.start_date', '2026-11-01')->where('filters.end_date', '2026-11-30')
            ->where('requests.total', 5)->where('requests.data.0.id', $last->id)
            ->where('requests.data.0.name', 'Nadia Putri')->where('requests.data.0.request_kind', 'usage')
            ->where('requests.data.3.id', $pending->id)->where('requests.data.3.usage_type', 'early_departure')
            ->where('requests.data.3.usage_start', '12 Nov 2026, 15:15')->where('requests.data.3.usage_end', '12 Nov 2026, 16:00')
            ->where('overview.total', 5)->where('overview.pending', 1)->where('overview.usage_minutes', 90)->where('overview.overtime_minutes', 0));
    }

    public function test_employee_usage_range_includes_both_dates_and_supports_other_filters(): void
    {
        $this->overtime(['tanggal_lembur' => '2026-10-28', 'after_minutes' => 720], approved: true);
        $this->overtime(approved: true);
        $this->usage(['tanggal_lembur' => '2026-11-09']);
        $first = $this->usage(['tanggal_lembur' => '2026-11-10', 'duration_minutes' => 45], approved: true);
        $last = $this->usage(approved: true);
        $this->usage(['tanggal_lembur' => '2026-11-13']);
        $range = '?start_date=2026-11-10&end_date=2026-11-12';

        $this->get(self::URL.$range)->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 2)->where('requests.data.0.id', $last->id)->where('requests.data.1.id', $first->id)
            ->where('overview.total', 2)->where('overview.pending', 0)->where('overview.usage_minutes', 105));
        $this->get(self::URL.'?start_date=2026-11-10&end_date=2026-11-10')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.id', $first->id)->where('overview.usage_minutes', 45));
        $this->get(self::URL.$range.'&search=NIK-001&status=approved&unit=IGD&pegawai_id='.$this->pegawai->id)
            ->assertInertia(fn (Assert $page) => $page->where('requests.total', 2)->where('overview.usage_minutes', 105));
        $this->get(self::URL.$range.'&status=pending')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 0)->where('overview.total', 0)->where('overview.usage_minutes', 0));
        $this->get(self::URL.'?start_date=2026-12-01&end_date=2026-12-31')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 0)->where('overview.total', 0)->where('overview.usage_minutes', 0));
    }

    public function test_usage_pagination_preserves_date_range_and_summarizes_all_filtered_rows(): void
    {
        $this->overtime(['tanggal_lembur' => '2026-10-28', 'after_minutes' => 720], approved: true);
        foreach (range(1, 16) as $day) {
            $this->usage(['tanggal_lembur' => sprintf('2026-11-%02d', $day), 'duration_minutes' => 15], approved: $day % 2 === 0);
        }
        $range = '?start_date=2026-11-01&end_date=2026-11-16&request_kind=usage';
        $this->get(self::URL.$range)->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 16)->has('requests.data', 15)->where('overview.total', 16)
            ->where('overview.pending', 8)->where('overview.usage_minutes', 120)
            ->where('requests.next_page_url', fn ($url) => str_contains($url, 'start_date=2026-11-01')
                && str_contains($url, 'end_date=2026-11-16') && str_contains($url, 'request_kind=usage')));
        $this->get(self::URL.$range.'&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 16)->has('requests.data', 1)->where('requests.data.0.date', '2026-11-01')
            ->where('overview.total', 16)->where('overview.usage_minutes', 120));
    }

    public function test_admin_can_monitor_all_requests_with_filters_and_pagination(): void
    {
        $this->overtime(approved: true);
        $this->overtime(['tanggal_lembur' => '2026-10-31']);
        foreach (range(13, 28) as $day) {
            $this->overtime(['tanggal_lembur' => '2026-11-'.$day]);
        }
        $this->get(self::URL.'?request_kind=all')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/lembur/Index')->where('requests.total', 17)
            ->has('requests.data', 15)->where('overview.pending', 16)->where('overview.overtime_minutes', 180)
            ->where('filters.start_date', '2026-11-01')->where('filters.end_date', '2026-11-30')->where('filters.request_kind', 'all'));
        $this->get(self::URL.'?request_kind=all&status=pending&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 16)->has('requests.data', 1)
            ->where('requests.prev_page_url', fn ($url) => str_contains($url, 'status=pending') && str_contains($url, 'request_kind=all')));
        $this->get(self::URL.'?search=NIK-001&status=approved&request_kind=overtime&compensation=time_off&unit=IGD&pegawai_id='.$this->pegawai->id)
            ->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('overview.total', 1));
        $this->get(self::URL.'?search=absent')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        $this->get(self::URL.'?request_kind=all&start_date=2026-10-01&end_date=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
    }

    public function test_modal_detail_returns_usage_sources_balance_and_approval_permissions_as_json(): void
    {
        $source = $this->overtime(approved: true);
        $usage = $this->usage(['usage_type' => 'early_departure', 'duration_minutes' => 45]);

        $this->getJson(self::URL.'/'.$usage->id)->assertOk()
            ->assertJsonPath('lembur.id', $usage->id)->assertJsonPath('lembur.name', 'Nadia Putri')
            ->assertJsonPath('lembur.request_kind', 'usage')->assertJsonPath('lembur.usage_type', 'early_departure')
            ->assertJsonPath('lembur.usage_start', '12 Nov 2026, 15:15')->assertJsonPath('lembur.usage_end', '12 Nov 2026, 16:00')
            ->assertJsonPath('lembur.sources.0.id', $source->id)->assertJsonPath('lembur.sources.0.minutes', 45)
            ->assertJsonPath('lembur.approvals.0.status', 'pending')->assertJsonPath('can.cancel', true)->assertJsonPath('can.decide', false)
            ->assertJsonPath('balance.earned_minutes', 180)->assertJsonPath('balance.reserved_minutes', 45)->assertJsonPath('balance.available_minutes', 135);

        $this->approver->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::LEMBUR_VIEW]);
        $this->actingAs($this->approver)->getJson(self::URL.'/'.$usage->id)->assertOk()
            ->assertJsonPath('can.decide', true)->assertJsonPath('can.cancel', false);
        $this->from(self::URL)->post(self::URL.'/'.$usage->id.'/decision', ['decision' => 'approved'])->assertRedirect(self::URL);
        $this->getJson(self::URL.'/'.$usage->id)->assertOk()->assertJsonPath('lembur.status', 'approved')
            ->assertJsonPath('can.decide', false)->assertJsonPath('balance.used_minutes', 45)->assertJsonPath('balance.reserved_minutes', 0);
    }

    public function test_admin_records_employee_overtime_with_audit_and_existing_approval_workflow(): void
    {
        $this->post(self::URL, $this->payload(['before_minutes' => 60, 'before_break_minutes' => 15,
            'after_break_minutes' => 30, 'status' => 'approved', 'user_id' => $this->admin->id]))->assertSessionHasNoErrors();
        $lembur = LemburRequest::query()->sole();
        $this->assertSame($this->employee->id, $lembur->user_id);
        $this->assertSame($this->admin->id, $lembur->recorded_by);
        $this->assertSame(195, $lembur->duration_minutes);
        $this->assertSame('pending', $lembur->status);
        $this->assertSame($this->approver->id, $lembur->approvals->sole()->user_id);
        $this->assertSame(1, $this->approver->notifications()->count());
        $this->get(self::URL.'/'.$lembur->id)->assertInertia(fn (Assert $page) => $page
            ->where('lembur.recorded_by', 'Admin SDM')->where('can.decide', false)->where('can.cancel', true));
        $this->actingAs($this->employee)->get('/absensi/mobile/lembur?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('requests.data.0.duration_minutes', 195));
    }

    public function test_invalid_employee_breaks_and_overlapping_intervals_are_rejected(): void
    {
        $unlinked = Pegawai::query()->create(['nama' => 'Tanpa akun']);
        $this->postJson(self::URL, $this->payload(['pegawai_id' => $unlinked->id]))->assertUnprocessable()->assertJsonValidationErrors('pegawai_id');
        $this->postJson(self::URL, $this->payload(['after_break_minutes' => 180]))->assertUnprocessable()->assertJsonValidationErrors('after_break_minutes');
        $this->postJson(self::URL, $this->payload(['after_minutes' => 0]))->assertUnprocessable()->assertJsonValidationErrors('before_minutes');
        $this->postJson(self::URL, $this->payload(['alasan' => '   ']))->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->overtime();
        $this->postJson(self::URL, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('tanggal_lembur');
        $this->assertDatabaseCount('lembur_requests', 1);
    }

    public function test_recording_requires_configured_approval_line_and_valid_work_shift(): void
    {
        $off = Shift::query()->create(['nama' => 'Libur', 'is_day_off' => true, 'jam_masuk' => '00:00', 'jam_keluar' => '00:00']);
        $this->postJson(self::URL, $this->payload(['shift_id' => $off->id]))->assertUnprocessable()->assertJsonValidationErrors('shift_id');
        ApprovalLine::query()->delete();
        $this->postJson(self::URL, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('lembur');
        $this->assertDatabaseCount('lembur_requests', 0);
    }

    public function test_module_permissions_are_required_for_every_endpoint(): void
    {
        $lembur = $this->overtime();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::LEMBUR_VIEW]);
        $this->actingAs($viewer)->get(self::URL)->assertOk();
        $this->get(self::URL.'/'.$lembur->id)->assertInertia(fn (Assert $page) => $page->where('can.cancel', false)->where('can.decide', false));
        $this->getJson(self::URL.'/'.$lembur->id)->assertOk()->assertJsonPath('can.cancel', false)->assertJsonPath('can.decide', false);
        $this->post(self::URL, $this->payload())->assertForbidden();
        $this->post(self::URL.'/'.$lembur->id.'/cancel', ['note' => 'Koreksi'])->assertForbidden();
        $this->post(self::URL.'/'.$lembur->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $viewer->revokePermissionTo(AccessPermission::LEMBUR_VIEW);
        $viewer->givePermissionTo([AccessPermission::LEMBUR_CREATE, AccessPermission::LEMBUR_CANCEL]);
        $this->get(self::URL)->assertForbidden();
        $this->get(self::URL.'/'.$lembur->id)->assertForbidden();
        $this->getJson(self::URL.'/'.$lembur->id)->assertForbidden();
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/0')->assertForbidden();
        $this->post(self::URL, $this->payload())->assertForbidden();
        $this->post(self::URL.'/'.$lembur->id.'/cancel', ['note' => 'Koreksi'])->assertForbidden();
        $this->actingAs($this->employee)->get(self::URL)->assertForbidden();
        $this->actingAs($this->approver)->get(self::URL)->assertForbidden();
    }

    public function test_admin_cancellation_requires_reason_and_preserves_approval_history(): void
    {
        $lembur = $this->overtime();
        $this->postJson(self::URL.'/'.$lembur->id.'/cancel', ['note' => '   '])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->post('/absensi/mobile/lembur/'.$lembur->id.'/cancel')->assertForbidden();
        $this->from(self::URL.'/'.$lembur->id)->post(self::URL.'/'.$lembur->id.'/cancel', ['note' => ' Koreksi jadwal pegawai '])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $lembur->fresh()->status);
        $this->assertSame($this->admin->id, $lembur->fresh()->cancelled_by);
        $this->assertSame('Koreksi jadwal pegawai', $lembur->fresh()->cancellation_note);
        $this->assertSame('skipped', $lembur->fresh()->approvals->sole()->status);
        $this->assertSame(1, $this->employee->notifications()->where('data->event', 'cancelled')->count());
        $this->postJson(self::URL.'/'.$lembur->id.'/cancel', ['note' => 'Ulang'])->assertUnprocessable()->assertJsonValidationErrors('cancel');
        $this->get(self::URL.'/'.$lembur->id)->assertInertia(fn (Assert $page) => $page->where('lembur.cancelled_by', 'Admin SDM')->where('can.cancel', false));
    }

    public function test_approved_source_is_protected_until_active_usage_is_cancelled(): void
    {
        $source = $this->overtime(approved: true);
        $this->postJson(self::URL.'/'.$source->id.'/cancel', ['note' => 'Koreksi'])->assertUnprocessable()->assertJsonValidationErrors('cancel');
        app(ConfigurationService::class)->update(['cancel_approved_lembur' => true], $this->admin->id);
        $usage = app(LemburService::class)->submitUsage($this->employee, [
            'tanggal_lembur' => '2026-11-13', 'shift_id' => $this->shift->id,
            'usage_type' => 'late_arrival', 'duration_minutes' => 60, 'alasan' => 'Keperluan keluarga',
        ]);
        $this->postJson(self::URL.'/'.$source->id.'/cancel', ['note' => 'Koreksi'])->assertUnprocessable()->assertJsonValidationErrors('cancel');
        $this->assertSame('approved', $source->fresh()->status);
        $this->get(self::URL.'?pegawai_id='.$this->pegawai->id.'&request_kind=usage')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1));
        $this->get('/absensi/admin-web/master-data/lembur')->assertInertia(fn (Assert $page) => $page->where('employees.data.0.earned_minutes', 180)->where('employees.data.0.reserved_minutes', 60)
            ->where('employees.data.0.available_minutes', 120));
        app(LemburService::class)->decide($usage, $this->approver, ['decision' => 'approved']);
        $this->get(self::URL.'/'.$usage->id)->assertInertia(fn (Assert $page) => $page->where('balance.used_minutes', 60)->where('lembur.sources.0.id', $source->id));
        $this->post(self::URL.'/'.$usage->id.'/cancel', ['note' => 'Batal memakai saldo'])->assertSessionHasNoErrors();
        $this->assertSame(180, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $this->post(self::URL.'/'.$source->id.'/cancel', ['note' => 'Koreksi sumber saldo'])->assertSessionHasNoErrors();
        $this->assertSame(0, app(LemburService::class)->balance($this->employee)['available_minutes']);
    }

    public function test_all_time_balances_match_mobile_and_exclude_unearned_or_pay_overtime(): void
    {
        $this->overtime(['tanggal_lembur' => '2026-10-31'], approved: true);
        $this->overtime(['tanggal_lembur' => '2026-11-09', 'compensation' => 'pay'], approved: true);
        $this->overtime(['tanggal_lembur' => '2026-11-13'], approved: true);
        $this->overtime(['tanggal_lembur' => '2026-11-08']);
        $this->get(self::URL.'?request_kind=overtime&status=pending')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1));
        $this->get('/absensi/admin-web/master-data/lembur')->assertInertia(fn (Assert $page) => $page->where('employees.data.0.earned_minutes', 180)->where('employees.data.0.available_minutes', 180));
        $this->assertSame(180, app(LemburService::class)->balance($this->employee)['available_minutes']);
    }

    public function test_only_current_assigned_approver_can_decide_even_for_super_admin(): void
    {
        $lembur = $this->overtime();
        $this->post(self::URL.'/'.$lembur->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->approver->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::LEMBUR_VIEW]);
        $this->actingAs($this->approver)->get(self::URL.'/'.$lembur->id)->assertInertia(fn (Assert $page) => $page->where('can.decide', true));
        $this->postJson(self::URL.'/'.$lembur->id.'/decision', ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->post(self::URL.'/'.$lembur->id.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $lembur->fresh()->status);
    }

    public function test_attachments_are_available_to_authorized_master_viewers(): void
    {
        $this->post(self::URL, $this->payload(['attachments' => [UploadedFile::fake()->create('laporan.pdf', 10, 'application/pdf')]]))->assertSessionHasNoErrors();
        $lembur = LemburRequest::query()->sole();
        $this->get(self::URL.'/'.$lembur->id)->assertInertia(fn (Assert $page) => $page->where('lembur.attachments.0.name', 'laporan.pdf'));
        $this->getJson(self::URL.'/'.$lembur->id)->assertOk()->assertJsonPath('lembur.attachments.0.name', 'laporan.pdf')
            ->assertJsonPath('lembur.attachments.0.url', self::URL.'/'.$lembur->id.'/lampiran/0');
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/0')->assertOk()->assertDownload('laporan.pdf');
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/1')->assertNotFound();
        Storage::disk('local')->delete($lembur->attachments[0]['path']);
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/0')->assertNotFound();
    }

    public function test_filters_reject_invalid_values_and_employee_ledger_has_separate_pagination(): void
    {
        foreach ([['start_date' => 'invalid'], ['start_date' => '2026-11-12', 'end_date' => '2026-11-01'],
            ['status' => 'invalid'], ['request_kind' => 'invalid'], ['pegawai_id' => 99999], ['page' => -1]] as $filter) {
            $this->getJson(self::URL.'?'.http_build_query($filter))->assertUnprocessable();
        }
        foreach (range(1, 16) as $number) {
            Pegawai::query()->create(['nama' => 'Pegawai '.$number]);
        }
        $this->get('/absensi/admin-web/master-data/lembur?page=2')->assertInertia(fn (Assert $page) => $page
            ->where('employees.total', 17)->has('employees.data', 2)->where('employees.data.0.available_minutes', 0));
    }
}
