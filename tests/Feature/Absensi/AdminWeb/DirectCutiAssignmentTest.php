<?php

namespace Tests\Feature\Absensi\AdminWeb;

use App\Models\CutiRequest;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\CutiScheduleService;
use App\Support\AccessPermission;
use Carbon\Carbon;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectCutiAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/presensi/set-pegawai';

    private User $admin;

    private User $employee;

    private Pegawai $pegawai;

    private MasterCuti $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-20 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::PRESENSI_MANAGE]);
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->pegawai = Pegawai::query()->create(['nama' => 'Pegawai Cuti Langsung']);
        $this->pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $this->annual = MasterCuti::query()->create([
            'kode' => 'CT', 'nama' => 'Cuti Tahunan', 'jenis' => 'tahunan', 'durasi_hari' => 12, 'aktif' => true,
        ]);
        PegawaiCuti::query()->create([
            'master_cuti_id' => $this->annual->id, 'pegawai_id' => $this->pegawai->id, 'tahun' => 2026, 'jumlah_hari' => 12,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'pegawai_id' => $this->pegawai->id, 'master_cuti_id' => $this->annual->id,
            'tanggal_mulai' => '2026-11-23', 'tanggal_selesai' => '2026-11-25', 'alasan' => 'Keperluan keluarga',
        ], $overrides);
    }

    private function existingRequest(string $status, array $overrides = []): CutiRequest
    {
        return CutiRequest::query()->create(array_merge([
            'user_id' => $this->employee->id, 'pegawai_id' => $this->pegawai->id, 'nama_pegawai' => $this->pegawai->nama,
            'nama_jabatan' => '-', 'nama_unit' => '-', 'master_cuti_id' => $this->annual->id, 'nama_cuti' => $this->annual->nama,
            'tanggal_mulai' => '2026-11-01', 'tanggal_selesai' => '2026-11-10', 'jumlah_hari' => 10,
            'tahun_cuti' => 2026, 'is_tahunan' => true, 'alasan' => 'Cuti sebelumnya', 'status' => $status,
        ], $overrides));
    }

    public function test_direct_assignment_spends_balance_and_appears_in_mobile_activity_with_admin_audit(): void
    {
        // Direct assignment does not need an approval line or job.
        $this->actingAs($this->admin)->from(self::URL)->post(self::URL.'/cuti', $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect(self::URL);
        $cuti = CutiRequest::query()->sole();
        $this->assertSame('approved', $cuti->status);
        $this->assertSame($this->employee->id, $cuti->user_id);
        $this->assertSame(3, $cuti->jumlah_hari);
        $this->assertNull($cuti->current_level);
        $this->assertNotNull($cuti->decided_at);
        $step = $cuti->approvals()->sole();
        $this->assertSame($this->admin->id, $step->user_id);
        $this->assertSame('approved', $step->status);
        $this->assertNotNull($step->decided_at);
        $this->assertDatabaseCount('presensis', 0);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->employee->id]);

        $this->get(self::URL.'?date_from=2026-11-24&date_to=2026-11-24')->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.cuti.nama', 'Cuti Tahunan')->where('records.data.0.has_presensi', false));
        $this->get(self::URL.'?date_from=2026-11-01&date_to=2026-11-30')->assertInertia(fn (Assert $page) => $page
            ->where('records.data.0.cuti.nama', 'Cuti Tahunan')->where('records.data.0.tanggal', '2026-11-23'));
        $this->getJson(self::URL.'/cuti-options?pegawai_id='.$this->pegawai->id)->assertOk()->assertJsonPath('options.0.sisa', 9);
        $this->actingAs($this->employee)->get('/absensi/mobile/cuti?month=2026-11')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.data.0.id', $cuti->id)->where('requests.data.0.status', 'approved')
                ->where('requests.data.0.jumlah_hari', 3)->where('requests.data.0.approvals.0.name', $this->admin->name)
                ->where('requests.data.0.approvals.0.note', 'Cuti diberikan langsung melalui Set Presensi Pegawai.')
                ->where('balance.available', 9)->where('balance.used', 3)->where('balance.pending', 0));
        $this->get('/absensi/mobile/cuti/'.$cuti->id)->assertOk();
        foreach (['2026-11-23', '2026-11-25'] as $date) {
            $this->assertTrue(app(CutiScheduleService::class)->isOnCuti($this->pegawai, at: Carbon::parse($date)));
        }
        $this->assertFalse(app(CutiScheduleService::class)->isOnCuti($this->pegawai, at: Carbon::parse('2026-11-26')));

        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload())->assertSessionHasErrors('tanggal_mulai');
        $this->assertDatabaseCount('cuti_requests', 1);
    }

    #[DataProvider('reservedStatuses')]
    public function test_pending_and_approved_leave_are_reserved_before_direct_assignment(string $status): void
    {
        $this->existingRequest($status);
        $this->actingAs($this->admin)->getJson(self::URL.'/cuti-options?pegawai_id='.$this->pegawai->id)
            ->assertOk()->assertJsonPath('options.0.sisa', 2);
        $this->post(self::URL.'/cuti', $this->payload())->assertSessionHasErrors('tanggal_selesai');
        $this->assertDatabaseCount('cuti_requests', 1);
        $this->assertDatabaseCount('cuti_approvals', 0);
    }

    #[DataProvider('reservedStatuses')]
    public function test_overlap_including_boundary_dates_is_rejected(string $status): void
    {
        $this->existingRequest($status, ['tanggal_mulai' => '2026-11-22', 'tanggal_selesai' => '2026-11-23', 'jumlah_hari' => 2]);
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload())->assertSessionHasErrors('tanggal_mulai');
        $this->assertDatabaseCount('cuti_requests', 1);
    }

    public static function reservedStatuses(): array
    {
        return [['pending'], ['approved']];
    }

    public function test_rejected_and_cancelled_requests_do_not_reserve_dates_or_balance(): void
    {
        foreach (['rejected', 'cancelled'] as $status) {
            $this->existingRequest($status, ['tanggal_mulai' => '2026-11-23', 'tanggal_selesai' => '2026-11-25', 'jumlah_hari' => 3]);
        }
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload())->assertSessionHasNoErrors();
        $this->getJson(self::URL.'/cuti-options?pegawai_id='.$this->pegawai->id)->assertJsonPath('options.0.sisa', 9);
    }

    public function test_inactive_and_unallocated_leave_types_are_rejected(): void
    {
        $this->annual->update(['aktif' => false]);
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload())->assertSessionHasErrors('master_cuti_id');
        $this->getJson(self::URL.'/cuti-options?pegawai_id='.$this->pegawai->id)->assertJsonCount(0, 'options');
        $this->annual->update(['aktif' => true]);
        PegawaiCuti::query()->delete();
        $this->post(self::URL.'/cuti', $this->payload())->assertSessionHasErrors('master_cuti_id');
        $this->assertDatabaseCount('cuti_requests', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_dates_and_missing_fields_cannot_create_leave(array $overrides, string $error): void
    {
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload($overrides))->assertSessionHasErrors($error);
        $this->assertDatabaseCount('cuti_requests', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['tanggal_selesai' => '2026-11-22'], 'tanggal_selesai'],
            [['tanggal_mulai' => '2026-02-30'], 'tanggal_mulai'],
            [['tanggal_mulai' => '2026-12-31', 'tanggal_selesai' => '2027-01-01'], 'tanggal_selesai'],
            [['tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-01-02'], 'master_cuti_id'],
            [['alasan' => '  '], 'alasan'],
            [['master_cuti_id' => 99999], 'master_cuti_id'],
        ];
    }

    public function test_historical_assignment_uses_the_selected_year_and_calendar_days(): void
    {
        PegawaiCuti::query()->create([
            'master_cuti_id' => $this->annual->id, 'pegawai_id' => $this->pegawai->id, 'tahun' => 2025, 'jumlah_hari' => 5,
        ]);
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload([
            'tanggal_mulai' => '2025-10-31', 'tanggal_selesai' => '2025-11-02',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cuti_requests', ['tahun_cuti' => 2025, 'jumlah_hari' => 3, 'status' => 'approved']);
        $this->actingAs($this->employee)->get('/absensi/mobile/cuti?month=2025-11')->assertInertia(fn (Assert $page) => $page
            ->where('balance.available', 2)->where('balance.used', 3)->where('requests.total', 1));
        $this->get('/absensi/mobile/cuti?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('balance.available', 12));
    }

    public function test_ordinary_and_unlimited_leave_keep_existing_quota_rules(): void
    {
        $ordinary = MasterCuti::query()->create(['kode' => 'KH', 'nama' => 'Cuti Khusus', 'durasi_hari' => 2, 'aktif' => true]);
        PegawaiCuti::query()->create([
            'master_cuti_id' => $ordinary->id, 'pegawai_id' => $this->pegawai->id, 'tahun' => 0, 'jumlah_hari' => 2,
        ]);
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload(['master_cuti_id' => $ordinary->id]))
            ->assertSessionHasErrors('tanggal_selesai');
        $ordinary->update(['tanpa_kuota' => true, 'durasi_hari' => 0]);
        $this->post(self::URL.'/cuti', $this->payload(['master_cuti_id' => $ordinary->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cuti_requests', ['master_cuti_id' => $ordinary->id, 'is_tahunan' => false, 'tahun_cuti' => null, 'jumlah_hari' => 3]);
        $this->actingAs($this->employee)->get('/absensi/mobile/cuti?month=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('balance.available', 12)->where('balance.used', 0));
    }

    public function test_existing_attendance_is_preserved_and_blocks_direct_leave(): void
    {
        $shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00']);
        $record = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id, 'shift_id' => $shift->id,
            'tanggal_presensi' => '2026-11-24', 'clock_in_at' => '2026-11-24 07:00:00',
        ]);
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload())->assertSessionHasErrors('tanggal_mulai');
        $this->assertDatabaseCount('cuti_requests', 0);
        $this->assertSame('2026-11-24 07:00:00', $record->fresh()->clock_in_at->format('Y-m-d H:i:s'));
    }

    public function test_employee_without_account_and_unauthorized_users_are_rejected(): void
    {
        $withoutAccount = Pegawai::query()->create(['nama' => 'Tanpa Akun']);
        $this->actingAs($this->admin)->post(self::URL.'/cuti', $this->payload(['pegawai_id' => $withoutAccount->id]))
            ->assertSessionHasErrors('pegawai_id');
        $this->getJson(self::URL.'/cuti-options?pegawai_id='.$withoutAccount->id)->assertNotFound();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::PRESENSI_VIEW]);
        foreach ([$viewer, $this->employee] as $user) {
            $this->actingAs($user)->post(self::URL.'/cuti', $this->payload())->assertForbidden();
            $this->getJson(self::URL.'/cuti-options?pegawai_id='.$this->pegawai->id)->assertForbidden();
        }
        $this->assertDatabaseCount('cuti_requests', 0);
    }
}
