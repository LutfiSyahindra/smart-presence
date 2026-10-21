<?php

namespace Tests\Feature\Absensi\AdminWeb\MasterData;

use App\Exceptions\HolidayCalendarException;
use App\Models\HariLibur;
use App\Models\HolidayCalendarSync;
use App\Models\User;
use App\Services\Absensi\NationalHolidaySyncService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NationalHolidaySyncTest extends TestCase
{
    use RefreshDatabase;

    private string $baseUrl = '/absensi/admin-web/master-data/shift';

    private string $apiUrl = 'https://calendar.example.test/holidays/2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-21 12:00:00');
        config(['holidays.api_url' => 'https://calendar.example.test']);
        Http::preventStrayRequests();
    }

    public function test_admin_can_sync_a_year_and_see_source_and_sync_time(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        Http::fake([$this->apiUrl => Http::response($this->payload())]);
        $this->actingAs($admin)->post($this->baseUrl.'/holidays/sync', ['year' => 2026])
            ->assertRedirect($this->baseUrl.'?holiday_year=2026')->assertSessionHas('success');
        $this->assertDatabaseHas('hari_liburs', ['tanggal' => '2026-08-17', 'jenis' => HariLibur::NASIONAL, 'source' => NationalHolidaySyncService::SOURCE]);
        $this->assertDatabaseHas('hari_liburs', ['tanggal' => '2026-12-24', 'jenis' => HariLibur::CUTI_BERSAMA, 'source' => NationalHolidaySyncService::SOURCE]);
        $this->get($this->baseUrl.'?holiday_year=2026')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('can.sync', true)->has('holidays', 2)
            ->where('holidays.0.source', NationalHolidaySyncService::SOURCE)
            ->where('holidaySync.provider_name', NationalHolidaySyncService::PROVIDER_NAME)
            ->where('holidaySync.references.0.url', 'https://www.kemenkopmk.go.id/kalender.pdf')
            ->where('holidaySync.synced_at', now()->toIso8601String()));
        Http::assertSent(fn ($request) => $request->url() === $this->apiUrl && $request->method() === 'GET');
    }

    public function test_sync_is_idempotent_and_preserves_manual_data_while_replacing_old_api_dates(): void
    {
        $service = app(NationalHolidaySyncService::class);
        $updatedPayload = $this->payload();
        $updatedPayload['data'][0]['name'] = 'Kemerdekaan RI';
        Http::fakeSequence($this->apiUrl)->push($this->payload())->push($this->payload())->push($updatedPayload);
        $this->assertSame(['created' => 2, 'updated' => 0, 'deleted' => 0, 'preserved' => 0], $service->sync(2026));
        $id = HariLibur::query()->whereDate('tanggal', '2026-08-17')->value('id');
        $this->assertSame(['created' => 0, 'updated' => 0, 'deleted' => 0, 'preserved' => 0], $service->sync(2026));
        HariLibur::query()->whereDate('tanggal', '2026-12-24')->firstOrFail()->forceFill(['source' => null, 'nama' => 'Koreksi Admin'])->save();
        $stale = HariLibur::query()->create(['tanggal' => '2026-12-23', 'nama' => 'Tanggal Lama', 'jenis' => HariLibur::CUTI_BERSAMA]);
        $stale->forceFill(['source' => NationalHolidaySyncService::SOURCE])->save();
        $otherYear = HariLibur::query()->create(['tanggal' => '2027-01-01', 'nama' => 'Tahun Depan', 'jenis' => HariLibur::NASIONAL]);
        $otherYear->forceFill(['source' => NationalHolidaySyncService::SOURCE])->save();
        $manual = HariLibur::query()->create(['tanggal' => '2026-10-21', 'nama' => 'Libur Manual', 'jenis' => HariLibur::NASIONAL]);
        $this->assertSame(['created' => 0, 'updated' => 1, 'deleted' => 1, 'preserved' => 1], $service->sync(2026));
        $this->assertDatabaseHas('hari_liburs', ['id' => $id, 'nama' => 'Kemerdekaan RI']);
        $this->assertDatabaseHas('hari_liburs', ['nama' => 'Koreksi Admin', 'source' => null]);
        $this->assertDatabaseHas('hari_liburs', ['id' => $manual->id]);
        $this->assertDatabaseHas('hari_liburs', ['id' => $otherYear->id]);
        $this->assertDatabaseMissing('hari_liburs', ['id' => $stale->id]);
    }

    public function test_multiple_holidays_on_the_same_date_are_grouped_without_losing_types(): void
    {
        $payload = $this->payload();
        $payload['data'][] = ['date' => '2026-08-17', 'name' => 'Perayaan Nasional', 'type' => 'national_holiday'];
        $payload['data'][] = ['date' => '2026-08-17', 'name' => 'Cuti Bersama Uji', 'type' => 'joint_leave'];
        $payload['meta']['count'] = 4;
        Http::fake([$this->apiUrl => Http::response($payload)]);
        app(NationalHolidaySyncService::class)->sync(2026);
        $this->assertDatabaseCount('hari_liburs', 3);
        $this->assertDatabaseHas('hari_liburs', ['tanggal' => '2026-08-17', 'nama' => 'Hari Kemerdekaan / Perayaan Nasional', 'jenis' => HariLibur::NASIONAL]);
        $this->assertDatabaseHas('hari_liburs', ['tanggal' => '2026-08-17', 'jenis' => HariLibur::CUTI_BERSAMA]);
    }

    public function test_admin_editing_an_imported_date_protects_it_from_future_syncs(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        Http::fake([$this->apiUrl => Http::response($this->payload())]);
        app(NationalHolidaySyncService::class)->sync(2026);
        $holiday = HariLibur::query()->whereDate('tanggal', '2026-08-17')->firstOrFail();
        $this->actingAs($admin)->put("{$this->baseUrl}/holidays/{$holiday->id}", [
            'tanggal' => '2026-08-17', 'nama' => 'Koreksi Lokal', 'jenis' => HariLibur::NASIONAL,
        ])->assertSessionHasNoErrors();
        app(NationalHolidaySyncService::class)->sync(2026);
        $this->assertDatabaseHas('hari_liburs', ['id' => $holiday->id, 'nama' => 'Koreksi Lokal', 'source' => null, 'synced_at' => null]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_or_unavailable_year_data_never_changes_the_local_calendar(array|string|null $payload): void
    {
        $existing = HariLibur::query()->create(['tanggal' => '2026-08-17', 'nama' => 'Tersimpan', 'jenis' => HariLibur::NASIONAL]);
        $existing->forceFill(['source' => NationalHolidaySyncService::SOURCE])->save();
        Http::fake([$this->apiUrl => Http::response($payload)]);
        try {
            app(NationalHolidaySyncService::class)->sync(2026);
            $this->fail('Invalid payload should not be imported.');
        } catch (HolidayCalendarException) {
            $this->assertDatabaseCount('hari_liburs', 1);
            $this->assertDatabaseHas('hari_liburs', ['id' => $existing->id, 'nama' => 'Tersimpan']);
            $this->assertDatabaseCount('holiday_calendar_syncs', 0);
        }
    }

    public static function invalidPayloads(): array
    {
        $valid = ['meta' => ['year' => 2026, 'count' => 1], 'data' => [['date' => '2026-01-01', 'name' => 'Libur', 'type' => 'national_holiday']]];

        return [
            'empty year' => [['meta' => ['year' => 2026, 'count' => 0], 'data' => []]],
            'html error' => ['<html>Error</html>'],
            'null' => [null],
            'wrong year metadata' => [array_replace_recursive($valid, ['meta' => ['year' => 2027]])],
            'truncated response' => [array_replace_recursive($valid, ['meta' => ['count' => 2]])],
            'invalid date' => [array_replace_recursive($valid, ['data' => [['date' => '2026-02-30']]])],
            'another year' => [array_replace_recursive($valid, ['data' => [['date' => '2027-01-01']]])],
            'unknown type' => [array_replace_recursive($valid, ['data' => [['type' => 'observance']]])],
            'missing name' => [array_replace_recursive($valid, ['data' => [['name' => '  ']]])],
        ];
    }

    public function test_connection_and_http_failures_preserve_existing_data_and_sync_timestamp(): void
    {
        $state = HolidayCalendarSync::query()->create(['year' => 2026, 'source_url' => $this->apiUrl, 'synced_at' => now()->subDay()]);
        $previousTime = $state->synced_at->toIso8601String();
        Http::fakeSequence($this->apiUrl)->pushFailedConnection()->push([], 503)->push([], 404)->push([], 302);
        foreach (range(1, 4) as $attempt) {
            try {
                app(NationalHolidaySyncService::class)->sync(2026);
                $this->fail('Failed requests should not be imported.');
            } catch (HolidayCalendarException) {
                $this->assertSame($previousTime, $state->fresh()->synced_at->toIso8601String());
                $this->assertDatabaseCount('hari_liburs', 0);
            }
        }
    }

    public function test_sync_requires_all_shift_management_permissions_and_a_valid_year(): void
    {
        $this->seed(AuthorizationSeeder::class);
        Http::fake();
        $this->post($this->baseUrl.'/holidays/sync', ['year' => 2026])->assertRedirect('/absensi/admin-web/login');
        $user = User::factory()->create();
        $user->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::SHIFT_VIEW, AccessPermission::SHIFT_UPDATE]);
        $this->actingAs($user)->get($this->baseUrl)->assertInertia(fn (Assert $page) => $page->where('can.sync', false));
        $this->post($this->baseUrl.'/holidays/sync', ['year' => 2026])->assertForbidden();
        $user->givePermissionTo([AccessPermission::SHIFT_CREATE, AccessPermission::SHIFT_DELETE]);
        $this->post($this->baseUrl.'/holidays/sync', ['year' => 'abc'])->assertSessionHasErrors('year');
        $this->post($this->baseUrl.'/holidays/sync', ['year' => 10000])->assertSessionHasErrors('year');
        Http::assertNothingSent();
    }

    public function test_source_failure_is_shown_as_a_flash_error(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        Http::fake([$this->apiUrl => Http::response([], 503)]);
        $this->actingAs($admin)->post($this->baseUrl.'/holidays/sync', ['year' => 2026])
            ->assertRedirect($this->baseUrl.'?holiday_year=2026')->assertSessionHas('error');
        $this->assertDatabaseCount('hari_liburs', 0);
    }

    public function test_command_syncs_current_year_even_if_next_year_is_unavailable(): void
    {
        Http::fake([
            $this->apiUrl => Http::response($this->payload()),
            'https://calendar.example.test/holidays/2027' => Http::response(['meta' => ['year' => 2027, 'count' => 0], 'data' => []]),
        ]);
        $this->artisan('holidays:sync', ['--next-year' => true])->assertFailed();
        $this->assertDatabaseCount('hari_liburs', 2);
        $this->assertDatabaseHas('holiday_calendar_syncs', ['year' => 2026]);
        $this->assertDatabaseMissing('holiday_calendar_syncs', ['year' => 2027]);
        $this->artisan('holidays:sync', ['year' => 2026])->assertSuccessful();
        $this->artisan('holidays:sync', ['year' => 'invalid'])->assertFailed();
        $this->assertDatabaseCount('hari_liburs', 2);
    }

    private function payload(): array
    {
        return [
            'meta' => ['year' => 2026, 'count' => 2, 'sources' => [['title' => 'SKB Libur Nasional', 'url' => 'https://www.kemenkopmk.go.id/kalender.pdf']]],
            'data' => [
                ['date' => '2026-08-17', 'name' => 'Hari Kemerdekaan', 'type' => 'national_holiday'],
                ['date' => '2026-12-24', 'name' => 'Cuti Bersama Natal', 'type' => 'joint_leave'],
            ],
        ];
    }
}
