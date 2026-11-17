<?php

namespace Tests\Feature\Absensi\AdminWeb\Setting;

use App\Models\AbsensiConfiguration;
use App\Models\ApprovalLine;
use App\Models\Pegawai;
use App\Models\Presensi;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\ConfigurationService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
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

    private function assertStoredConfiguration(array $data): void
    {
        $this->assertDatabaseHas('absensi_configurations', [
            'id' => 1, ...$data,
            'mobile_presensi_pegawai_all_role_ids' => json_encode($data['mobile_presensi_pegawai_all_role_ids']),
        ]);
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
        $data['mobile_presensi_pegawai_scope'] = AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE;
        $data['mobile_presensi_pegawai_approval_type'] = ApprovalLine::TYPE_CUTI;
        $data['mobile_presensi_pegawai_all_role_ids'] = [Role::findByName('super-admin')->id];
        $this->from(self::URL)->put(self::URL, $data)->assertRedirect(self::URL)->assertSessionHasNoErrors();
        $this->assertStoredConfiguration(['updated_by' => $admin->id, ...$data]);
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

    public function test_admin_can_choose_each_approval_category_and_switch_back_to_unit_scope(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->has('approvalTypeOptions', count(ApprovalLine::types())));
        foreach (ApprovalLine::types() as $type) {
            $data = [...AbsensiConfiguration::DEFAULTS,
                'mobile_presensi_pegawai_scope' => AbsensiConfiguration::PRESENSI_PEGAWAI_SCOPE_APPROVAL_LINE,
                'mobile_presensi_pegawai_approval_type' => $type];
            $this->put(self::URL, $data)->assertSessionHasNoErrors();
            $this->assertStoredConfiguration($data);
        }
        $this->put(self::URL, AbsensiConfiguration::DEFAULTS)->assertSessionHasNoErrors();
        $this->assertStoredConfiguration(AbsensiConfiguration::DEFAULTS);
    }

    public function test_admin_can_select_multiple_web_roles_and_clear_full_attendance_access(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $auditor = Role::create(['name' => 'auditor-absensi', 'guard_name' => 'web']);
        $apiRole = Role::create(['name' => 'api-only', 'guard_name' => 'api']);
        $roleIds = [Role::findByName('super-admin')->id, $auditor->id];
        $this->actingAs($admin)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('roleOptions', fn ($roles) => collect($roles)->pluck('id')->contains($auditor->id)
                && ! collect($roles)->pluck('id')->contains($apiRole->id)));
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'mobile_presensi_pegawai_all_role_ids' => $roleIds])
            ->assertSessionHasNoErrors();
        $this->app->forgetScopedInstances();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('configuration.mobile_presensi_pegawai_all_role_ids', $roleIds));
        $auditor->delete();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('configuration.mobile_presensi_pegawai_all_role_ids', [$roleIds[0]]));
        $this->put(self::URL, AbsensiConfiguration::DEFAULTS)->assertSessionHasNoErrors();
        $this->assertStoredConfiguration(AbsensiConfiguration::DEFAULTS);
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
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'allow_self_approval' => 'yes'])->assertSessionHasErrors('allow_self_approval');
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'perubahan_shift_same_day' => 'yes'])->assertSessionHasErrors('perubahan_shift_same_day');
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'perubahan_shift_past_date' => 'yes'])->assertSessionHasErrors('perubahan_shift_past_date');
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'perubahan_shift_without_default' => 'yes'])->assertSessionHasErrors('perubahan_shift_without_default');
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'cuti_past_date' => 'yes'])->assertSessionHasErrors('cuti_past_date');
        $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'clock_out_without_clock_in' => 'yes'])
            ->assertSessionHasErrors('clock_out_without_clock_in');
        foreach (['all', '', null, ['unit-kerja']] as $scope) {
            $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'mobile_presensi_pegawai_scope' => $scope])
                ->assertSessionHasErrors('mobile_presensi_pegawai_scope');
        }
        foreach (['all', '', null, ['presensi']] as $type) {
            $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS, 'mobile_presensi_pegawai_approval_type' => $type])
                ->assertSessionHasErrors('mobile_presensi_pegawai_approval_type');
        }
        $apiRole = Role::create(['name' => 'api-only', 'guard_name' => 'api']);
        $roleId = Role::findByName('super-admin')->id;
        foreach ([null, 'super-admin', [999999], [$apiRole->id], [$roleId, $roleId], ['super-admin'], ['name' => $roleId]] as $roles) {
            $response = $this->put(self::URL, [...AbsensiConfiguration::DEFAULTS,
                'cancel_approved_cuti' => true, 'mobile_presensi_pegawai_all_role_ids' => $roles]);
            $response->assertSessionHasErrors();
        }
        $this->put(self::URL, [])->assertSessionHasErrors(array_keys(AbsensiConfiguration::DEFAULTS));
        $this->assertStoredConfiguration(AbsensiConfiguration::DEFAULTS);
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
