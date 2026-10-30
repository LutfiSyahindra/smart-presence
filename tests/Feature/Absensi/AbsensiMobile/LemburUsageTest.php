<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Contracts\FaceVerifier;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Repositories\Absensi\AdminWeb\Presensi\PresensiRepository;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Absensi\AdminWeb\Presensi\PresensiService;
use App\ValueObjects\FaceVerificationResult;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LemburUsageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/lembur';

    private User $employee;

    private User $first;

    private User $second;

    private Pegawai $pegawai;

    private Shift $shift;

    private ApprovalLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-23 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        Bus::fake();
        foreach (['employee', 'first', 'second'] as $name) {
            $this->{$name} = User::factory()->create(['name' => ucfirst($name)]);
            $this->{$name}->assignRole('pegawai');
        }
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'IGD', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia', 'unit_kerja_id' => $unit->id,
            'jabatan_id' => $position->id, 'shift_id' => $this->shift->id, 'tanggal_mulai_bekerja' => '2026-10-01']);
        $this->pegawai->forceFill(['user_id' => $this->employee->id, 'shift_assigned_at' => '2026-10-01'])->save();
        $this->line = ApprovalLine::query()->create(['type' => 'lembur']);
        $this->line->jabatans()->attach($position->id, ['type' => 'lembur']);
        foreach ([$this->first, $this->second] as $index => $approver) {
            $this->line->steps()->create(['user_id' => $approver->id, 'level' => $index + 1]);
        }
    }

    public function test_approved_overtime_cannot_be_cancelled_while_its_balance_is_reserved(): void
    {
        $source = $this->earn();
        $usage = $this->submit();
        $service = app(LemburService::class);
        app(\App\Services\Absensi\ConfigurationService::class)->update(['cancel_approved_lembur' => true], $this->first->id);
        $this->actingAs($this->employee)->post(self::URL.'/'.$source->id.'/cancel')->assertSessionHasErrors('cancel');
        $this->assertSame('approved', $source->fresh()->status);
        $this->approve($usage);
        $this->post(self::URL.'/'.$usage->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame(180, $service->balance($this->employee)['available_minutes']);
        $this->post(self::URL.'/'.$source->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame(0, $service->balance($this->employee)['earned_minutes']);
    }

    public function test_past_overtime_approval_obeys_configuration_at_each_level(): void
    {
        $request = app(LemburService::class)->submit($this->employee, [
            'tanggal_lembur' => '2026-10-22', 'shift_id' => $this->shift->id, 'before_minutes' => 0,
            'before_break_minutes' => 0, 'after_minutes' => 60, 'after_break_minutes' => 0,
            'compensation' => 'time_off', 'alasan' => 'Pelayanan tambahan.',
        ]);
        app(\App\Services\Absensi\ConfigurationService::class)->update(['approve_past_requests' => false], $this->first->id);
        $this->actingAs($this->first)->post(self::URL.'/'.$request->id.'/decision', ['decision' => 'approved'])->assertSessionHasErrors('decision');
        $this->post(self::URL.'/'.$request->id.'/decision', ['decision' => 'rejected', 'note' => 'Tidak disetujui.'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $request->fresh()->approvals->first()->status);
        app(\App\Services\Absensi\ConfigurationService::class)->update(['approve_past_requests' => true], $this->first->id);
        $this->approve($request);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_tolerance_adds_to_approved_late_arrival_usage(): void
    {
        $this->earn();
        $usage = $this->submit();
        $this->approve($usage);
        app(\App\Services\Absensi\ConfigurationService::class)->update(['clock_in_tolerance_minutes' => 10], $this->first->id);
        $record = Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-23', 'clock_in_at' => '2026-10-23 09:10:00', 'is_day_off' => false]);
        $this->assertSame(0, $record->lateMinutes());
        $this->assertTrue(Presensi::constrainArrivalStatus(Presensi::query(), false)->exists());
        $record->update(['clock_in_at' => '2026-10-23 09:11:00']);
        $this->assertSame(1, $record->lateMinutes());
        $this->assertTrue(Presensi::constrainArrivalStatus(Presensi::query(), true)->exists());
    }

    private function earn(array $overrides = []): LemburRequest
    {
        $request = app(LemburService::class)->submit($this->employee, array_replace([
            'tanggal_lembur' => '2026-10-22', 'shift_id' => $this->shift->id, 'before_minutes' => 0,
            'before_break_minutes' => 0, 'after_minutes' => 180, 'after_break_minutes' => 0,
            'compensation' => 'time_off', 'alasan' => 'Pelayanan tambahan.',
        ], $overrides));
        $this->approve($request);

        return $request->fresh();
    }

    private function approve(LemburRequest $request): void
    {
        foreach ([$this->first, $this->second] as $approver) {
            app(LemburService::class)->decide($request, $approver, ['decision' => 'approved']);
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['tanggal_lembur' => '2026-10-23', 'shift_id' => $this->shift->id,
            'usage_type' => 'late_arrival', 'duration_minutes' => 60, 'alasan' => 'Mengantar keluarga.'], $overrides);
    }

    private function submit(array $overrides = []): LemburRequest
    {
        $this->actingAs($this->employee)->from(self::URL.'/gunakan')->post(self::URL.'/gunakan', $this->payload($overrides))
            ->assertSessionHasNoErrors()->assertRedirect();

        return LemburRequest::query()->where('request_kind', 'usage')->latest('id')->firstOrFail();
    }

    public function test_one_hour_usage_reserves_balance_and_uses_the_overtime_approval_snapshot(): void
    {
        $source = $this->earn();
        $this->actingAs($this->employee)->get(self::URL.'/gunakan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/LemburUsageCreate')->where('balance.available_minutes', 180)
            ->where('shift.id', $this->shift->id)->where('approvalPreview.0.name', 'First')->has('approvalPreview', 2));
        $usage = $this->submit(['status' => 'approved', 'user_id' => $this->first->id, 'compensation' => 'pay']);
        $this->assertSame('pending', $usage->status);
        $this->assertSame($this->employee->id, $usage->user_id);
        $this->assertSame('time_off', $usage->compensation);
        $this->assertSame('08:00', $usage->usage_start_at->format('H:i'));
        $this->assertSame('09:00', $usage->usage_end_at->format('H:i'));
        $this->assertSame([$this->first->id, $this->second->id], $usage->approvals->pluck('user_id')->all());
        $this->assertDatabaseHas('lembur_usage_allocations', ['usage_request_id' => $usage->id, 'source_request_id' => $source->id, 'minutes' => 60]);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('balance.available_minutes', 120)
            ->where('balance.reserved_minutes', 60)->where('balance.used_minutes', 0)
            ->where('summary.approved_minutes', 180)->where('summary.submitted_minutes', 180)
            ->where('requests.data.0.request_kind', 'usage')->where('requests.data.0.start_time', '08:00')->has('requests.data.0.sources', 1));
        $this->actingAs($this->second)->post(self::URL.'/'.$usage->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->employee)->post(self::URL.'/'.$usage->id.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->line->steps()->delete();
        $this->line->steps()->create(['user_id' => $this->employee->id, 'level' => 1]);
        app(LemburService::class)->decide($usage, $this->first, ['decision' => 'approved']);
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['reserved_minutes']);
        app(LemburService::class)->decide($usage, $this->second, ['decision' => 'approved']);
        $this->assertSame(['earned_minutes' => 180, 'reserved_minutes' => 0, 'used_minutes' => 60, 'available_minutes' => 120], app(LemburService::class)->balance($this->employee));
        $notice = $this->employee->notifications()->get()->first(fn ($notice) => $notice->data['request_id'] === $usage->id && $notice->data['event'] === 'approved');
        $this->assertSame('Penggunaan waktu lembur disetujui', $notice?->data['title']);
        $this->actingAs($this->employee)->post(self::URL.'/'.$usage->id.'/cancel')->assertSessionHasErrors('cancel');
    }

    public function test_reserved_balance_cannot_be_spent_twice_and_cancel_or_rejection_restores_it(): void
    {
        $this->earn(['after_minutes' => 60]);
        $usage = $this->submit();
        $this->post(self::URL.'/gunakan', $this->payload(['tanggal_lembur' => '2026-10-24']))->assertSessionHasErrors('duration_minutes');
        $this->post(self::URL.'/gunakan', $this->payload())->assertSessionHasErrors('usage_type');
        $this->post(self::URL.'/'.$usage->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $usage = $this->submit(['usage_type' => 'early_departure']);
        app(LemburService::class)->decide($usage, $this->first, ['decision' => 'rejected', 'note' => 'Sesuaikan jadwal.']);
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $this->assertSame(['rejected', 'skipped'], $usage->fresh()->approvals->pluck('status')->all());
        $this->assertDatabaseCount('lembur_usage_allocations', 2);
        $this->submit();
    }

    public function test_only_completed_approved_time_off_overtime_belongs_to_the_balance(): void
    {
        $this->earn(['compensation' => 'pay']);
        $this->earn(['tanggal_lembur' => '2026-10-24']);
        app(LemburService::class)->submit($this->employee, [
            'tanggal_lembur' => '2026-10-21', 'shift_id' => $this->shift->id, 'before_minutes' => 0,
            'before_break_minutes' => 0, 'after_minutes' => 60, 'after_break_minutes' => 0,
            'compensation' => 'time_off', 'alasan' => 'Masih menunggu.',
        ]);
        $this->actingAs($this->employee)->post(self::URL.'/gunakan', $this->payload())->assertSessionHasErrors('duration_minutes');
        $source = $this->earn(['tanggal_lembur' => '2026-10-20', 'after_minutes' => 60]);
        $source->update(['pegawai_id' => null, 'user_id' => $this->second->id]);
        $this->assertSame(0, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $this->post(self::URL.'/gunakan', $this->payload(['source_request_id' => $source->id]))->assertSessionHasErrors('duration_minutes');
        $this->assertDatabaseCount('lembur_usage_allocations', 0);
    }

    public function test_balance_is_allocated_oldest_first_across_multiple_approved_requests(): void
    {
        $old = $this->earn(['tanggal_lembur' => '2026-09-30', 'after_minutes' => 30]);
        $new = $this->earn(['after_minutes' => 90]);
        $usage = $this->submit();
        $this->assertDatabaseHas('lembur_usage_allocations', ['usage_request_id' => $usage->id, 'source_request_id' => $old->id, 'minutes' => 30]);
        $this->assertDatabaseHas('lembur_usage_allocations', ['usage_request_id' => $usage->id, 'source_request_id' => $new->id, 'minutes' => 30]);
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['available_minutes']);
    }

    public function test_approved_usage_adjusts_attendance_and_admin_filters_but_pending_usage_does_not(): void
    {
        $this->earn();
        $usage = $this->submit();
        $record = Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-23', 'clock_in_at' => '2026-10-23 09:00', 'clock_out_at' => '2026-10-23 16:00']);
        $this->assertSame(60, $record->lateMinutes());
        app(LemburService::class)->decide($usage, $this->first, ['decision' => 'approved']);
        $this->assertSame(60, $record->lateMinutes());
        app(LemburService::class)->decide($usage, $this->second, ['decision' => 'approved']);
        $this->assertSame(0, $record->lateMinutes());
        $filters = ['date_from' => '2026-10-23', 'date_to' => '2026-10-23'];
        $repository = app(PresensiRepository::class);
        $this->assertSame(0, $repository->overview($filters)['late']);
        $this->assertSame(0, $repository->paginate($filters + ['status' => 'late'])->total());
        $this->assertSame(1, $repository->paginate($filters + ['status' => 'on-time'])->total());
        $this->actingAs($this->employee)->get('/absensi/mobile/presensi/log?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('days.22.late_minutes', 0)->where('days.22.lembur_late_allowance', 60)->where('summary.late', 0));
        $record->update(['clock_in_at' => '2026-10-23 09:15']);
        $this->assertSame(15, $record->lateMinutes());
        $this->assertSame(1, $repository->overview($filters)['late']);
        $this->assertSame(1, $repository->paginate($filters + ['status' => 'late'])->total());
        $this->assertSame(0, $repository->paginate($filters + ['status' => 'on-time'])->total());
        $early = $this->submit(['usage_type' => 'early_departure']);
        $this->approve($early);
        $record->update(['clock_out_at' => '2026-10-23 14:45']);
        $this->assertSame(15, $record->earlyDepartureMinutes());
        $this->shift->update(['jam_masuk' => '07:00']);
        $record->unsetRelation('shift');
        $this->assertSame(135, $record->lateMinutes());
        $this->assertSame(75, $record->earlyDepartureMinutes());
    }

    public function test_overnight_early_departure_uses_the_shift_start_date(): void
    {
        $this->earn();
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $usage = $this->submit(['usage_type' => 'early_departure']);
        $this->assertSame('2026-10-24 05:00', $usage->usage_start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-24 06:00', $usage->usage_end_at->format('Y-m-d H:i'));
        $this->approve($usage);
        $record = Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-23', 'clock_in_at' => '2026-10-23 22:00', 'clock_out_at' => '2026-10-24 05:00']);
        $this->assertSame(0, $record->earlyDepartureMinutes());
        $record->clock_out_at = '2026-10-24 04:30';
        $this->assertSame(30, $record->earlyDepartureMinutes());
    }

    public function test_invalid_durations_dates_shift_and_full_shift_usage_are_rejected(): void
    {
        $this->earn(['after_minutes' => 600]);
        $this->actingAs($this->employee);
        foreach ([0, -1, 1.5, 1440, 480] as $minutes) {
            $this->post(self::URL.'/gunakan', $this->payload(['duration_minutes' => $minutes]))->assertSessionHasErrors('duration_minutes');
        }
        $this->post(self::URL.'/gunakan', $this->payload(['tanggal_lembur' => '2026-10-22']))->assertSessionHasErrors('tanggal_lembur');
        $this->post(self::URL.'/gunakan', $this->payload(['usage_type' => 'leave']))->assertSessionHasErrors('usage_type');
        $this->post(self::URL.'/gunakan', $this->payload(['alasan' => '   ']))->assertSessionHasErrors('alasan');
        $other = Shift::query()->create(['nama' => 'Lain', 'jam_masuk' => '09:00', 'jam_keluar' => '17:00']);
        $this->post(self::URL.'/gunakan', $this->payload(['shift_id' => $other->id]))->assertSessionHasErrors('shift_id');
        $this->submit(['duration_minutes' => 300]);
        $this->post(self::URL.'/gunakan', $this->payload(['usage_type' => 'early_departure', 'duration_minutes' => 180]))->assertSessionHasErrors('duration_minutes');
        $this->assertDatabaseCount('lembur_usage_allocations', 1);
    }

    public function test_usage_is_visible_and_approved_through_the_existing_web_inbox(): void
    {
        $this->earn();
        $usage = $this->submit(['usage_type' => 'early_departure']);
        $this->first->givePermissionTo(['cuti.view', 'cuti.approve']);
        $this->second->givePermissionTo(['cuti.view', 'cuti.approve']);
        $web = '/absensi/admin-web/notifikasi';
        $this->actingAs($this->first)->get($web.'/butuh-approval')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.request_kind', 'usage')->where('requests.data.0.usage_type', 'early_departure'));
        $this->get($web.'/pengajuan/lembur/'.$usage->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/notifikasi/LemburDetail')->where('lembur.request_kind', 'usage')
            ->where('lembur.usage_start', '23 Oct 2026, 15:00')->where('lembur.can_decide', true)->has('lembur.sources', 1));
        $this->post($web.'/pengajuan/lembur/'.$usage->id.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->second)->post($web.'/pengajuan/lembur/'.$usage->id.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $usage->fresh()->status);
        $this->assertSame(60, app(LemburService::class)->balance($this->employee)['used_minutes']);
    }

    public function test_non_working_dates_do_not_reserve_balance(): void
    {
        $this->earn();
        $this->shift->update(['libur_sabtu' => true]);
        $this->actingAs($this->employee)->post(self::URL.'/gunakan', $this->payload(['tanggal_lembur' => '2026-10-24']))->assertSessionHasErrors('tanggal_lembur');
        CutiRequest::query()->create(['pegawai_id' => $this->pegawai->id, 'user_id' => $this->employee->id,
            'nama_pegawai' => 'Nadia', 'nama_jabatan' => 'Perawat', 'nama_unit' => 'IGD', 'tanggal_mulai' => '2026-10-23',
            'tanggal_selesai' => '2026-10-23', 'alasan' => 'Cuti keluarga', 'status' => 'approved']);
        $this->post(self::URL.'/gunakan', $this->payload())->assertSessionHasErrors('tanggal_lembur');
        $this->assertSame(180, app(LemburService::class)->balance($this->employee)['available_minutes']);
        $this->assertDatabaseCount('lembur_usage_allocations', 0);
    }

    private function prepareClockScreens(): void
    {
        Storage::fake('local');
        $path = 'profile-photos/'.$this->employee->id.'/profile.jpg';
        Storage::disk('local')->put($path, 'profile-photo');
        $this->employee->forceFill(['profile_photo_path' => $path, 'profile_photo_updated_at' => now()])->save();
        OfficeLocation::query()->create(['name' => 'RSARSY', 'latitude' => -6.2, 'longitude' => 106.8166667, 'radius_meters' => 100]);
    }

    private function assertClockInSchedules(string $start, string $end, ?string $note = null, string $date = '2026-10-23'): void
    {
        $this->actingAs($this->employee, 'web')->get('/absensi/mobile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('todaySchedule.start_time', $start)->where('todaySchedule.end_time', $end)->where('todaySchedule.schedule_note', $note));
        $this->get('/absensi/mobile/presensi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('today.date', $date)->where('today.start_time', $start)->where('today.end_time', $end));
        $this->get('/absensi/mobile/clock-in')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('schedule.date', $date)->where('schedule.start_time', $start)->where('schedule.end_time', $end)->where('schedule.schedule_note', $note));
        Sanctum::actingAs($this->employee, ['mobile']);
        $this->getJson('/api/mobile/v1/home')->assertOk()->assertJsonPath('data.schedule.start_time', $start)
            ->assertJsonPath('data.schedule.end_time', $end)->assertJsonPath('data.attendance.shift.start_time', $start);
        $this->getJson('/api/mobile/v1/attendance')->assertOk()->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.shift.start_time', $start)->assertJsonPath('data.shift.end_time', $end);
        $this->getJson('/api/mobile/v1/attendance/clock-in')->assertOk()->assertJsonPath('data.schedule.date', $date)
            ->assertJsonPath('data.schedule.start_time', $start)->assertJsonPath('data.schedule.end_time', $end);
    }

    private function postClockIn(): void
    {
        $this->mock(FaceVerifier::class)->shouldReceive('compare')->once()->andReturn(new FaceVerificationResult(true, 96.4, 'test'));
        $token = str_repeat('a', 64);
        $issuedAt = now()->getTimestampMs();
        $this->actingAs($this->employee, 'web')->withSession(['attendance.location_challenges.clock-in' => [
            'token_hash' => hash('sha256', $token), 'user_id' => $this->employee->id, 'issued_at' => $issuedAt,
        ]])->post('/absensi/mobile/clock-in', [
            'latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8, 'location_token' => $token,
            'outside_radius_confirmed' => false, 'photo' => UploadedFile::fake()->image('clock-in.jpg', 800, 800)->size(120),
            'location_samples' => [['latitude' => -6.2, 'longitude' => 106.8166667, 'accuracy' => 8, 'captured_at' => $issuedAt]],
        ])->assertSessionHasNoErrors()->assertRedirect('/absensi/mobile/clock-in/berhasil');
    }

    public function test_only_final_approval_changes_the_schedule_before_clock_in_for_the_employee_and_date(): void
    {
        $this->earn();
        $this->prepareClockScreens();
        $usage = $this->submit();
        $this->assertClockInSchedules('08:00', '16:00');
        app(LemburService::class)->decide($usage, $this->first, ['decision' => 'approved']);
        $this->assertClockInSchedules('08:00', '16:00');
        app(LemburService::class)->decide($usage, $this->second, ['decision' => 'approved']);
        $this->assertClockInSchedules('09:00', '16:00', 'Penggunaan lembur disetujui: telat masuk 60 menit.');
        $this->assertDatabaseCount('presensis', 0);
        $this->assertSame('08:00', substr($this->shift->fresh()->jam_masuk, 0, 5));
        $other = Pegawai::query()->create(['nama' => 'Pegawai lain', 'shift_id' => $this->shift->id]);
        $other->forceFill(['user_id' => $this->first->id])->save();
        $this->actingAs($this->first, 'web')->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('todaySchedule.start_time', '08:00')->where('todaySchedule.end_time', '16:00'));
        $this->travelTo('2026-10-24 09:00');
        $this->assertClockInSchedules('08:00', '16:00', date: '2026-10-24');
    }

    public function test_clock_out_success_log_and_admin_use_the_adjusted_schedule(): void
    {
        $this->earn();
        $this->prepareClockScreens();
        $this->approve($this->submit());
        $this->approve($this->submit(['usage_type' => 'early_departure']));
        $record = Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-23', 'clock_in_at' => '2026-10-23 09:00']);
        $this->actingAs($this->employee, 'web')->get('/absensi/mobile/clock-in/berhasil')->assertInertia(fn (Assert $page) => $page
            ->where('attendance.start_time', '09:00')->where('attendance.end_time', '15:00')->where('attendance.clock_in_time', '09:00')->where('attendance.late_minutes', 0));
        $this->get('/absensi/mobile/clock-out')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('schedule.start_time', '09:00')->where('schedule.end_time', '15:00'));
        Sanctum::actingAs($this->employee, ['mobile']);
        $this->getJson('/api/mobile/v1/attendance/clock-out')->assertOk()->assertJsonPath('data.schedule.start_time', '09:00')->assertJsonPath('data.schedule.end_time', '15:00');
        $record->update(['clock_out_at' => '2026-10-23 15:00']);
        $this->travelTo('2026-10-23 15:00');
        $this->actingAs($this->employee, 'web')->get('/absensi/mobile/clock-out/berhasil')->assertInertia(fn (Assert $page) => $page
            ->where('attendance.start_time', '09:00')->where('attendance.end_time', '15:00')->where('attendance.clock_out_time', '15:00')->where('attendance.early_departure_minutes', 0));
        $this->get('/absensi/mobile/presensi/log?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('days.22.schedule', '09:00 – 15:00')->where('days.22.clock_in', '09:00')->where('days.22.clock_out', '15:00'));
        $service = app(PresensiService::class);
        $row = $service->paginate($service->normalizeFilters(['date_from' => '2026-10-23', 'date_to' => '2026-10-23']))->items()[0];
        $this->assertSame(['nama' => 'Pagi', 'jam_masuk' => '09:00', 'jam_keluar' => '15:00'], $row['shift']);
    }

    public function test_shifted_clock_in_keeps_the_actual_timestamp_and_counts_only_excess_delay(): void
    {
        $this->earn();
        $this->prepareClockScreens();
        $this->approve($this->submit());
        $this->travelTo('2026-10-23 09:15');
        $this->postClockIn();
        $record = Presensi::query()->sole();
        $this->assertSame('2026-10-23 09:15', $record->clock_in_at->format('Y-m-d H:i'));
        $this->assertSame(15, $record->lateMinutes());
        $this->get('/absensi/mobile/clock-in/berhasil')->assertInertia(fn (Assert $page) => $page
            ->where('attendance.start_time', '09:00')->where('attendance.end_time', '16:00')->where('attendance.late_minutes', 15));
    }

    public function test_approved_arrival_after_midnight_keeps_the_original_night_shift_date(): void
    {
        $this->earn();
        $this->prepareClockScreens();
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->approve($this->submit(['duration_minutes' => 180]));
        $this->travelTo('2026-10-24 01:15');
        $this->assertClockInSchedules('01:00', '06:00', 'Penggunaan lembur disetujui: telat masuk 180 menit.');
        $this->assertDatabaseCount('presensis', 0);
        $this->postClockIn();
        $record = Presensi::query()->sole();
        $this->assertSame('2026-10-23', $record->tanggal_presensi->toDateString());
        $this->assertSame('2026-10-24 01:15', $record->clock_in_at->format('Y-m-d H:i'));
        $this->assertSame(15, $record->lateMinutes());
        $this->get('/absensi/mobile/clock-in/berhasil')->assertInertia(fn (Assert $page) => $page
            ->where('attendance.start_time', '01:00')->where('attendance.end_time', '06:00')->where('attendance.date', '2026-10-23'));
    }

    public function test_early_departure_changes_the_previous_night_shift_and_its_closing_time(): void
    {
        $this->earn();
        $this->prepareClockScreens();
        $this->shift->update(['jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $this->approve($this->submit(['usage_type' => 'early_departure']));
        Presensi::query()->create(['pegawai_id' => $this->pegawai->id, 'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-10-23', 'clock_in_at' => '2026-10-23 22:00']);
        $this->travelTo('2026-10-24 04:59');
        $this->actingAs($this->employee, 'web')->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('todaySchedule.start_time', '22:00')->where('todaySchedule.end_time', '05:00'));
        $this->get('/absensi/mobile/clock-out')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('schedule.date', '2026-10-23')->where('schedule.start_time', '22:00')->where('schedule.end_time', '05:00'));
        $this->get('/absensi/mobile/presensi/log?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('days.22.schedule', '22:00 – 05:00')->where('days.22.flags.no_clock_out', false));
        $this->travelTo('2026-10-24 05:01');
        $this->get('/absensi/mobile/presensi/log?month=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('days.22.flags.no_clock_out', true));
    }

    public function test_usage_requires_mobile_access_employee_and_a_valid_overtime_approval_line(): void
    {
        $this->get(self::URL.'/gunakan')->assertRedirect('/login');
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(self::URL.'/gunakan')->assertForbidden();
        $stranger->assignRole('pegawai');
        $this->get(self::URL.'/gunakan')->assertOk()->assertInertia(fn (Assert $page) => $page->where('balance.available_minutes', 0)->where('employee', null));
        $this->post(self::URL.'/gunakan', $this->payload())->assertSessionHasErrors('lembur');
        $this->earn();
        $this->actingAs($stranger)->get(self::URL.'/1')->assertForbidden();
        $this->line->steps()->delete();
        $this->actingAs($this->employee)->post(self::URL.'/gunakan', $this->payload())->assertSessionHasErrors('lembur');
    }
}
