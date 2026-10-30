<?php

namespace Tests\Feature\Absensi\AdminWeb\Setting;

use App\Models\AbsensiConfiguration;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConfigurationManagementTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/setting/konfigurasi';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
    }

    public function test_admin_can_save_all_settings_and_read_persisted_values(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/setting/konfigurasi/Index')->where('configuration', AbsensiConfiguration::DEFAULTS)->where('can.update', true));
        $data = array_fill_keys(array_keys(AbsensiConfiguration::DEFAULTS), true);
        $data['clock_in_tolerance_minutes'] = 10;
        $data['approve_past_requests'] = false;
        $this->from(self::URL)->put(self::URL, $data)->assertRedirect(self::URL)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('absensi_configurations', ['id' => 1, 'updated_by' => $admin->id, ...$data]);
        $this->app->forgetScopedInstances();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('configuration', $data));
    }

    public function test_configuration_requires_separate_view_and_update_permissions(): void
    {
        $this->get(self::URL)->assertRedirect('/absensi/admin-web/login');
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->actingAs($user)->get(self::URL)->assertForbidden();
        $this->put(self::URL, AbsensiConfiguration::DEFAULTS)->assertForbidden();
        $user->givePermissionTo(AccessPermission::CONFIGURATION_VIEW);
        $this->get(self::URL)->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.update', false)
            ->where('portal.admin_home_url', self::URL));
        $this->put(self::URL, AbsensiConfiguration::DEFAULTS)->assertForbidden();
        $user->givePermissionTo(AccessPermission::CONFIGURATION_UPDATE);
        $this->put(self::URL, AbsensiConfiguration::DEFAULTS)->assertSessionHasNoErrors();
    }

    public function test_invalid_configuration_is_rejected_without_partial_updates(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        foreach ([-1, 1441, 1.5, null, 'invalid'] as $minutes) {
            $this->actingAs($admin)->put(self::URL, [...AbsensiConfiguration::DEFAULTS,
                'cancel_approved_cuti' => true, 'clock_in_tolerance_minutes' => $minutes])->assertSessionHasErrors('clock_in_tolerance_minutes');
        }
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'cancel_approved_cuti' => 'yes'])->assertSessionHasErrors('cancel_approved_cuti');
        $this->put(self::URL, [])->assertSessionHasErrors(array_keys(AbsensiConfiguration::DEFAULTS));
        $this->assertDatabaseHas('absensi_configurations', ['id' => 1, ...AbsensiConfiguration::DEFAULTS]);
    }

    public function test_tolerance_agrees_with_late_and_on_time_filters_including_midnight(): void
    {
        $admin = User::factory()->create();
        app(ConfigurationService::class)->update(['clock_in_tolerance_minutes' => 10], $admin->id);
        $pegawai = Pegawai::query()->create(['nama' => 'Pegawai']);
        foreach ([['08:00', '16:00', '2026-10-30 08:10:00', 0],
            ['08:00', '16:00', '2026-10-30 08:11:00', 1],
            ['23:55', '07:00', '2026-10-31 00:05:00', 0],
            ['23:55', '07:00', '2026-10-31 00:06:00', 1]] as [$start, $end, $clock, $late]) {
            Presensi::query()->delete();
            $shift = Shift::query()->create(['nama' => 'Shift '.$start.' '.$late, 'jam_masuk' => $start, 'jam_keluar' => $end]);
            $record = Presensi::query()->create(['pegawai_id' => $pegawai->id, 'shift_id' => $shift->id,
                'tanggal_presensi' => '2026-10-30', 'clock_in_at' => $clock, 'is_day_off' => false]);
            $this->assertSame($late, $record->lateMinutes());
            $this->assertSame($late > 0, Presensi::constrainArrivalStatus(Presensi::query(), true)->exists());
            $this->assertSame($late === 0, Presensi::constrainArrivalStatus(Presensi::query(), false)->exists());
        }
    }
}
