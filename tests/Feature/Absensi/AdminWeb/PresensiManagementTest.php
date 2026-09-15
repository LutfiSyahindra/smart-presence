<?php

namespace Tests\Feature\Absensi\AdminWeb;

use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PresensiManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Pegawai $pegawai;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->pegawai = Pegawai::query()->create([
            'nik' => 'PEG-001',
            'nama' => 'Siti Rahma',
        ]);
        $this->pegawai->forceFill(['user_id' => $this->admin->id])->save();
        $this->shift = Shift::query()->create([
            'nama' => 'Shift Pagi',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
    }

    public function test_pages_are_protected_by_their_respective_permissions(): void
    {
        $this->get('/absensi/admin-web/presensi/pegawai')->assertRedirect('/absensi/admin-web/login');

        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::PRESENSI_VIEW]);

        $this->actingAs($user)
            ->get('/absensi/admin-web/presensi/pegawai?date_from=2026-09-15&date_to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/presensi/Index')
                ->where('manage', false)
                ->where('options', null));

        $this->get('/absensi/admin-web/presensi/set-pegawai')->assertForbidden();
    }

    public function test_history_exposes_shift_clock_location_photo_and_late_status(): void
    {
        $location = OfficeLocation::query()->create([
            'name' => 'Gedung Utama',
            'address' => 'Jl. Rumah Sakit No. 1',
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'radius_meters' => 100,
        ]);

        $presensi = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 07:17:00',
            'clock_out_at' => '2026-09-15 16:00:00',
            'clock_in_location_id' => $location->id,
            'clock_out_address' => 'Lobby IGD',
            'clock_out_latitude' => -6.201,
            'clock_out_longitude' => 106.817,
            'clock_in_photo_path' => 'presensi/wajah-masuk.jpg',
            'clock_out_photo_path' => 'presensi/wajah-pulang.jpg',
            'overtime_minutes' => 60,
        ]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/presensi/pegawai?date_from=2026-09-15&date_to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/presensi/Index')
                ->where('records.total', 1)
                ->where('records.data.0.pegawai.nama', 'Siti Rahma')
                ->where('records.data.0.shift.nama', 'Shift Pagi')
                ->where('records.data.0.clock_in.time', '07:17')
                ->where('records.data.0.clock_out.time', '16:00')
                ->where('records.data.0.clock_in.location.name', 'Gedung Utama')
                ->where('records.data.0.clock_out.location.address', 'Lobby IGD')
                ->where('records.data.0.clock_in.photo_url', "/absensi/admin-web/presensi/foto/{$presensi->id}/in")
                ->where('records.data.0.status', 'late')
                ->where('records.data.0.late_minutes', 17)
                ->where('records.data.0.overtime_minutes', 60)
                ->where('overview.present', 1)
                ->where('overview.late', 1)
                ->where('overview.overtime', 1));
    }

    public function test_face_photo_is_served_only_to_authorized_presensi_users(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('presensi/wajah-masuk.jpg', 'fake-image-content');
        $presensi = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_photo_path' => 'presensi/wajah-masuk.jpg',
        ]);
        $url = "/absensi/admin-web/presensi/foto/{$presensi->id}/in";

        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::PRESENSI_VIEW]);
        $this->actingAs($viewer)->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, max-age=300');

        $unauthorized = User::factory()->create();
        $unauthorized->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->actingAs($unauthorized)->get($url)->assertForbidden();
    }

    public function test_history_supports_date_search_and_status_filters(): void
    {
        $otherUser = User::factory()->create();
        $other = Pegawai::query()->create(['nik' => 'PEG-002', 'nama' => 'Budi Santoso']);
        $other->forceFill(['user_id' => $otherUser->id])->save();
        Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-14',
            'clock_in_at' => '2026-09-14 07:00:00',
            'clock_out_at' => '2026-09-14 15:00:00',
        ]);
        Presensi::query()->create([
            'pegawai_id' => $other->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 07:30:00',
            'clock_out_at' => '2026-09-15 15:00:00',
        ]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/presensi/pegawai?date_from=2026-09-14&date_to=2026-09-15&search=budi&status=late')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)
                ->where('records.data.0.pegawai.nama', 'Budi Santoso')
                ->where('filters.search', 'budi')
                ->where('filters.status', 'late')
                ->where('overview.total', 1));
    }

    public function test_pages_show_only_employees_with_users_and_use_empty_attendance_values(): void
    {
        $withoutUser = Pegawai::query()->create(['nik' => 'PEG-002', 'nama' => 'Pegawai Tanpa User']);
        Presensi::query()->create([
            'pegawai_id' => $withoutUser->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 07:00:00',
            'clock_out_at' => '2026-09-15 15:00:00',
        ]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/presensi/pegawai?date_from=2026-09-15&date_to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)
                ->where('records.data.0.id', null)
                ->where('records.data.0.has_presensi', false)
                ->where('records.data.0.pegawai.nama', 'Siti Rahma')
                ->where('records.data.0.shift', null)
                ->where('records.data.0.clock_in.time', null)
                ->where('records.data.0.clock_out.time', null)
                ->where('records.data.0.status', null)
                ->where('overview.total', 0));

        $this->get('/absensi/admin-web/presensi/set-pegawai?date_from=2026-09-15&date_to=2026-09-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('records.total', 1)
                ->has('options.pegawai', 1)
                ->where('options.pegawai.0.nama', 'Siti Rahma'));
    }

    public function test_manual_attendance_rejects_an_employee_without_a_user(): void
    {
        $withoutUser = Pegawai::query()->create(['nik' => 'PEG-002', 'nama' => 'Pegawai Tanpa User']);

        $this->actingAs($this->admin)->post('/absensi/admin-web/presensi/set-pegawai', [
            'pegawai_id' => $withoutUser->id,
            'tanggal_presensi' => '2026-09-15',
            'shift_id' => $this->shift->id,
            'is_day_off' => false,
            'overtime_minutes' => 0,
        ])->assertSessionHasErrors('pegawai_id');
    }

    public function test_admin_can_create_an_overnight_manual_attendance(): void
    {
        $night = Shift::query()->create([
            'nama' => 'Shift Malam',
            'jam_masuk' => '22:00',
            'jam_keluar' => '06:00',
        ]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/presensi/set-pegawai', [
            'pegawai_id' => $this->pegawai->id,
            'tanggal_presensi' => '2026-09-15',
            'shift_id' => $night->id,
            'clock_in_time' => '21:55',
            'clock_out_time' => '06:10',
            'is_day_off' => false,
            'overtime_minutes' => 30,
            'notes' => 'Input manual admin',
        ])->assertRedirect('/absensi/admin-web/presensi/set-pegawai')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('presensis', [
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $night->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 21:55:00',
            'clock_out_at' => '2026-09-16 06:10:00',
            'overtime_minutes' => 30,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_admin_can_correct_times_overtime_and_set_a_day_off(): void
    {
        $presensi = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
            'clock_in_at' => '2026-09-15 07:20:00',
            'clock_out_at' => '2026-09-15 15:00:00',
        ]);

        $this->actingAs($this->admin)->put("/absensi/admin-web/presensi/set-pegawai/{$presensi->id}", [
            'pegawai_id' => $this->pegawai->id,
            'tanggal_presensi' => '2026-09-15',
            'shift_id' => $this->shift->id,
            'clock_in_time' => '07:00',
            'clock_out_time' => '17:00',
            'is_day_off' => false,
            'overtime_minutes' => 120,
            'notes' => 'Koreksi disetujui',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('presensis', [
            'id' => $presensi->id,
            'clock_in_at' => '2026-09-15 07:00:00',
            'clock_out_at' => '2026-09-15 17:00:00',
            'overtime_minutes' => 120,
        ]);

        $this->put("/absensi/admin-web/presensi/set-pegawai/{$presensi->id}", [
            'pegawai_id' => $this->pegawai->id,
            'tanggal_presensi' => '2026-09-15',
            'shift_id' => $this->shift->id,
            'clock_in_time' => '07:00',
            'clock_out_time' => '17:00',
            'is_day_off' => true,
            'overtime_minutes' => 120,
            'notes' => 'Libur pengganti',
        ])->assertRedirect()->assertSessionHas('success');

        $presensi->refresh();
        $this->assertTrue($presensi->is_day_off);
        $this->assertNull($presensi->clock_in_at);
        $this->assertNull($presensi->clock_out_at);
        $this->assertSame(0, $presensi->overtime_minutes);
    }

    public function test_duplicate_daily_record_and_unauthorized_mutations_are_rejected(): void
    {
        $presensi = Presensi::query()->create([
            'pegawai_id' => $this->pegawai->id,
            'shift_id' => $this->shift->id,
            'tanggal_presensi' => '2026-09-15',
        ]);

        $payload = [
            'pegawai_id' => $this->pegawai->id,
            'tanggal_presensi' => '2026-09-15',
            'shift_id' => $this->shift->id,
            'is_day_off' => false,
            'overtime_minutes' => 0,
        ];

        $this->actingAs($this->admin)
            ->post('/absensi/admin-web/presensi/set-pegawai', $payload)
            ->assertSessionHasErrors('tanggal_presensi');

        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::PRESENSI_VIEW]);
        $this->actingAs($user)->post('/absensi/admin-web/presensi/set-pegawai', $payload)->assertForbidden();
        $this->put("/absensi/admin-web/presensi/set-pegawai/{$presensi->id}", $payload)->assertForbidden();
    }
}
