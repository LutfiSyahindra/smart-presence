<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Models\OfficeLocation;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GeolocationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_access_is_protected_by_authentication_and_permission(): void
    {
        $this->get('/absensi/admin-web/master-data/geolocation')->assertRedirect('/absensi/admin-web/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/absensi/admin-web/master-data/geolocation')->assertForbidden();

        $user->givePermissionTo([
            AccessPermission::ADMIN_ACCESS,
            AccessPermission::GEOLOCATION_VIEW,
        ]);

        $this->get('/absensi/admin-web/master-data/geolocation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/geolocation/Index')
                ->has('locations', 0)
                ->where('can.create', false)
                ->where('can.update', false)
                ->where('can.delete', false));
    }

    public function test_super_admin_can_create_update_and_delete_office_location(): void
    {
        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/geolocation', [
            'name' => '  Kantor   Pusat  ',
            'address' => '  Jl. Kesehatan   No. 1  ',
            'latitude' => -6.2000000,
            'longitude' => 106.8166667,
            'radius_meters' => 150,
        ])->assertRedirect('/absensi/admin-web/master-data/geolocation');

        $location = OfficeLocation::query()->where('name', 'Kantor Pusat')->firstOrFail();
        $this->assertSame('Jl. Kesehatan No. 1', $location->address);
        $this->assertSame(-6.2, $location->latitude);
        $this->assertSame(106.8166667, $location->longitude);
        $this->assertSame(150, $location->radius_meters);

        $this->put("/absensi/admin-web/master-data/geolocation/{$location->id}", [
            'name' => 'Kantor Cabang',
            'address' => '',
            'latitude' => -7.257472,
            'longitude' => 112.75209,
            'radius_meters' => 250,
        ])->assertRedirect('/absensi/admin-web/master-data/geolocation');

        $this->assertDatabaseHas('office_locations', [
            'id' => $location->id,
            'name' => 'Kantor Cabang',
            'address' => null,
            'radius_meters' => 250,
        ]);

        $this->delete("/absensi/admin-web/master-data/geolocation/{$location->id}")
            ->assertRedirect('/absensi/admin-web/master-data/geolocation');
        $this->assertDatabaseMissing('office_locations', ['id' => $location->id]);
    }

    public function test_coordinates_radius_and_unique_name_are_validated(): void
    {
        OfficeLocation::query()->create([
            'name' => 'Kantor Pusat',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 100,
        ]);

        $this->actingAs($this->admin)->post('/absensi/admin-web/master-data/geolocation', [
            'name' => 'Kantor Pusat',
            'latitude' => -91,
            'longitude' => 181,
            'radius_meters' => 0,
        ])->assertSessionHasErrors(['name', 'latitude', 'longitude', 'radius_meters']);

        $this->assertDatabaseCount('office_locations', 1);
    }

    public function test_index_returns_saved_locations_in_name_order(): void
    {
        OfficeLocation::query()->create([
            'name' => 'Kantor Surabaya',
            'address' => 'Surabaya',
            'latitude' => -7.257472,
            'longitude' => 112.75209,
            'radius_meters' => 200,
        ]);
        OfficeLocation::query()->create([
            'name' => 'Kantor Jakarta',
            'address' => 'Jakarta',
            'latitude' => -6.2,
            'longitude' => 106.8166667,
            'radius_meters' => 100,
        ]);

        $this->actingAs($this->admin)
            ->get('/absensi/admin-web/master-data/geolocation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/admin-web/master-data/geolocation/Index')
                ->has('locations', 2)
                ->where('locations.0.name', 'Kantor Jakarta')
                ->where('locations.0.latitude', -6.2)
                ->where('locations.0.radius_meters', 100)
                ->where('locations.1.name', 'Kantor Surabaya'));
    }

    public function test_action_routes_require_the_corresponding_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::GEOLOCATION_VIEW);
        $location = OfficeLocation::query()->create([
            'name' => 'Kantor Pusat',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 100,
        ]);
        $payload = [
            'name' => 'Kantor Cabang',
            'latitude' => -7.2,
            'longitude' => 112.7,
            'radius_meters' => 100,
        ];

        $this->actingAs($user)->post('/absensi/admin-web/master-data/geolocation', $payload)->assertForbidden();
        $this->put("/absensi/admin-web/master-data/geolocation/{$location->id}", $payload)->assertForbidden();
        $this->delete("/absensi/admin-web/master-data/geolocation/{$location->id}")->assertForbidden();
    }
}
