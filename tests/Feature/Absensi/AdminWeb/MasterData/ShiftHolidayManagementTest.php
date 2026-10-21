<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\HariLibur;
use App\Models\Shift;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftHolidayManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $baseUrl = '/absensi/admin-web/master-data/shift';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_shift_policies_can_be_saved_independently_in_bulk_and_updated(): void
    {
        $this->actingAs($this->admin)->post($this->baseUrl, ['shifts' => [
            ['nama' => 'Administrasi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00', 'libur_sabtu' => true, 'libur_minggu' => true, 'libur_nasional' => true],
            ['nama' => 'Pelayanan', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00', 'libur_cuti_bersama' => true],
        ]])->assertSessionHasNoErrors();
        $administrasi = Shift::query()->where('nama', 'Administrasi')->firstOrFail();
        $pelayanan = Shift::query()->where('nama', 'Pelayanan')->firstOrFail();
        $this->assertTrue($administrasi->libur_sabtu);
        $this->assertTrue($administrasi->libur_nasional);
        $this->assertFalse($administrasi->libur_cuti_bersama);
        $this->assertFalse($pelayanan->libur_sabtu);
        $this->assertTrue($pelayanan->libur_cuti_bersama);

        $this->put("{$this->baseUrl}/{$administrasi->id}", [
            'nama' => 'Administrasi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00',
            'libur_sabtu' => false, 'libur_minggu' => true, 'libur_nasional' => false, 'libur_cuti_bersama' => true,
        ])->assertSessionHasNoErrors();
        $this->get($this->baseUrl.'?search=Administrasi')->assertInertia(fn (Assert $page) => $page
            ->where('shifts.data.0.libur_sabtu', false)
            ->where('shifts.data.0.libur_minggu', true)
            ->where('shifts.data.0.libur_nasional', false)
            ->where('shifts.data.0.libur_cuti_bersama', true));
    }

    public function test_omitting_policies_keeps_existing_shifts_working_every_day(): void
    {
        $this->actingAs($this->admin)->post($this->baseUrl, [
            'nama' => 'Layanan 24 Jam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00',
        ])->assertSessionHasNoErrors();
        $shift = Shift::query()->firstOrFail();
        $this->assertFalse($shift->libur_sabtu);
        $this->assertFalse($shift->libur_minggu);
        $this->assertFalse($shift->libur_nasional);
        $this->assertFalse($shift->libur_cuti_bersama);
    }

    public function test_invalid_policies_reject_the_entire_bulk_request(): void
    {
        $this->actingAs($this->admin)->post($this->baseUrl, ['shifts' => [
            ['nama' => 'Pagi', 'jam_masuk' => '07:00', 'jam_keluar' => '15:00'],
            ['nama' => 'Siang', 'jam_masuk' => '15:00', 'jam_keluar' => '23:00', 'libur_nasional' => 'semua'],
        ]])->assertSessionHasErrors('shifts.1.libur_nasional');
        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_holiday_calendar_supports_create_update_delete_and_year_filter(): void
    {
        $this->actingAs($this->admin)->post($this->baseUrl.'/holidays', [
            'tanggal' => '2026-10-21', 'nama' => ' Libur  Uji ', 'jenis' => HariLibur::NASIONAL,
        ])->assertRedirect($this->baseUrl.'?holiday_year=2026')->assertSessionHasNoErrors();
        $holiday = HariLibur::query()->firstOrFail();
        $this->assertSame('Libur Uji', $holiday->nama);
        $this->put("{$this->baseUrl}/holidays/{$holiday->id}", [
            'tanggal' => '2027-01-04', 'nama' => 'Cuti Bersama Uji', 'jenis' => HariLibur::CUTI_BERSAMA,
        ])->assertRedirect($this->baseUrl.'?holiday_year=2027')->assertSessionHasNoErrors();
        $this->get($this->baseUrl.'?holiday_year=2026')->assertInertia(fn (Assert $page) => $page->has('holidays', 0));
        $this->get($this->baseUrl.'?holiday_year=2027')->assertInertia(fn (Assert $page) => $page
            ->where('holidayYear', 2027)->has('holidays', 1)
            ->where('holidays.0.tanggal', '2027-01-04')
            ->where('holidays.0.jenis', HariLibur::CUTI_BERSAMA));
        $this->delete("{$this->baseUrl}/holidays/{$holiday->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hari_liburs', 0);
    }

    public function test_calendar_rejects_duplicate_date_and_type_but_allows_different_types(): void
    {
        $payload = ['tanggal' => '2026-10-21', 'nama' => 'Libur Uji', 'jenis' => HariLibur::NASIONAL];
        $this->actingAs($this->admin)->post($this->baseUrl.'/holidays', $payload)->assertSessionHasNoErrors();
        $this->post($this->baseUrl.'/holidays', $payload)->assertSessionHasErrors('tanggal');
        $holiday = HariLibur::query()->firstOrFail();
        $this->put("{$this->baseUrl}/holidays/{$holiday->id}", $payload)->assertSessionHasNoErrors();
        $this->post($this->baseUrl.'/holidays', array_replace($payload, ['jenis' => HariLibur::CUTI_BERSAMA]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('hari_liburs', 2);
    }

    public function test_calendar_validates_dates_and_types_and_requires_action_permissions(): void
    {
        $this->actingAs($this->admin)->post($this->baseUrl.'/holidays', [
            'tanggal' => '2026-02-30', 'nama' => ' ', 'jenis' => 'sabtu',
        ])->assertSessionHasErrors(['tanggal', 'nama', 'jenis']);
        $holiday = HariLibur::query()->create(['tanggal' => '2026-10-21', 'nama' => 'Uji', 'jenis' => HariLibur::NASIONAL]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::SHIFT_VIEW]);
        $this->actingAs($viewer)->get($this->baseUrl)->assertOk();
        $this->post($this->baseUrl.'/holidays', [])->assertForbidden();
        $this->put("{$this->baseUrl}/holidays/{$holiday->id}", [])->assertForbidden();
        $this->delete("{$this->baseUrl}/holidays/{$holiday->id}")->assertForbidden();
    }
}
