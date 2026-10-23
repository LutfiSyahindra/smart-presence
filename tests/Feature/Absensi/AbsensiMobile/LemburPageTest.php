<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LemburPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/lembur';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-22 09:00:00');
    }

    private function employee(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate(AccessPermission::MOBILE_ACCESS, 'web'));

        return $user;
    }

    public function test_mobile_user_can_open_lembur_and_choose_a_month(): void
    {
        $this->actingAs($this->employee())->get(self::URL)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/absensi-mobile/Lembur')
                ->where('month', '2026-10')->where('today', '2026-10-22'));

        $this->get(self::URL.'?month=2027-02')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('month', '2027-02'));
    }

    public function test_lembur_requires_authenticated_verified_mobile_access(): void
    {
        $this->get(self::URL)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(self::URL)->assertForbidden();
        $user = $this->employee();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get(self::URL)->assertRedirect('/verify-email');
    }

    public function test_invalid_periods_are_rejected_and_boundary_months_are_supported(): void
    {
        $this->actingAs($this->employee());
        foreach (['invalid', '2026-13', '2026-1', '1899-12', '2101-01'] as $month) {
            $this->from(self::URL)->get(self::URL.'?month='.$month)->assertSessionHasErrors('month');
        }
        foreach (['1900-01', '2100-12'] as $month) {
            $this->get(self::URL.'?month='.$month)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('month', $month));
        }
    }
}
