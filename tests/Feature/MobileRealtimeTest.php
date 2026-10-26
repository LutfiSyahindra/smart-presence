<?php

namespace Tests\Feature;

use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\User;
use App\Services\Absensi\AdminWeb\MasterData\Shift\ShiftAssignmentService;
use App\Services\MobileDataChanges;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MobileRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['mobile-realtime.enabled' => false]);
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->seed(AuthorizationSeeder::class);
    }

    private function expectSignal(int $times = 1): void
    {
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->times($times)->withArgs(function ($channels, $event, $payload) {
            $this->assertSame('private-mobile.data', (string) $channels[0]);
            $this->assertSame('mobile.data.changed', $event);
            $this->assertSame(['version'], array_keys($payload));
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $payload['version']);

            return true;
        });
        Broadcast::shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
        config(['mobile-realtime.enabled' => true]);
    }

    public function test_bulk_admin_shift_assignment_signals_once_and_mobile_reads_the_new_schedule(): void
    {
        $this->travelTo('2026-10-26 09:00:00');
        $admin = User::factory()->create()->assignRole('super-admin');
        $employee = User::factory()->create()->assignRole('pegawai');
        $morning = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $night = Shift::query()->create(['nama' => 'Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $pegawai = Pegawai::query()->create(['nama' => 'Pegawai', 'shift_id' => $morning->id]);
        $pegawai->forceFill(['user_id' => $employee->id])->save();
        $this->expectSignal();

        app(ShiftAssignmentService::class)->assign([$pegawai->id], $night->id, $admin->id);
        app(MobileDataChanges::class)->flush();
        app(MobileDataChanges::class)->flush();
        $this->actingAs($employee)->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('todaySchedule.shift_name', 'Malam')->where('todaySchedule.start_time', '22:00'));
        app(MobileDataChanges::class)->flush();
    }

    public function test_all_domain_tables_including_future_modules_trigger_a_coalesced_signal(): void
    {
        Schema::create('future_mobile_data', function ($table) {
            $table->id();
            $table->string('value');
        });
        $this->expectSignal();
        DB::transaction(function () {
            DB::table('future_mobile_data')->insert(['value' => 'sensitive original value']);
            DB::table('future_mobile_data')->update(['value' => 'sensitive updated value']);
            DB::table('future_mobile_data')->delete();
            // Nothing is advertised until the enclosing transaction is committed.
            app(MobileDataChanges::class)->flush();
        });
        app(MobileDataChanges::class)->flush();
        Schema::dropIfExists('future_mobile_data');
    }

    public function test_rolled_back_changes_do_not_emit_a_signal(): void
    {
        $this->expectSignal(0);
        DB::beginTransaction();
        User::factory()->create();
        DB::beginTransaction();
        User::factory()->create();
        DB::commit();
        DB::rollBack();
        app(MobileDataChanges::class)->flush();
    }

    public function test_operational_cache_writes_and_read_only_queries_cannot_cause_refresh_loops(): void
    {
        $this->expectSignal(0);
        Cache::store('database')->put('realtime-test', 'value', 60);
        Cache::store('database')->forget('realtime-test');
        DB::table('users')->count();
        app(MobileDataChanges::class)->flush();
    }

    public function test_notification_read_changes_also_invalidate_mobile_data(): void
    {
        $user = User::factory()->create();
        $notice = $user->notifications()->create(['id' => fake()->uuid(), 'type' => 'test', 'data' => ['mobile_url' => '/absensi/mobile']]);
        $this->expectSignal();
        $user->notifications()->whereKey($notice->id)->update(['read_at' => now()]);
        app(MobileDataChanges::class)->flush();
    }

    public function test_reverb_failure_does_not_undo_or_fail_a_saved_change(): void
    {
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->once()->andThrow(new RuntimeException('offline'));
        Broadcast::shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
        Log::shouldReceive('warning')->once();
        config(['mobile-realtime.enabled' => true]);
        $user = User::factory()->create();
        app(MobileDataChanges::class)->flush();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_only_verified_mobile_users_can_subscribe_and_no_secret_is_shared(): void
    {
        config([
            'broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'server-only-secret', 'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
        $body = ['socket_id' => '123.456', 'channel_name' => 'private-mobile.data'];
        $this->postJson('/broadcasting/auth', $body)->assertUnauthorized();
        $restricted = User::factory()->create();
        $this->actingAs($restricted)->postJson('/broadcasting/auth', $body)->assertForbidden();
        $employee = User::factory()->create()->assignRole('pegawai');
        $this->actingAs($employee)->postJson('/broadcasting/auth', $body)->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/broadcasting/auth', [...$body, 'channel_name' => 'private-other'])->assertForbidden();
        $employee->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/broadcasting/auth', $body)->assertForbidden();
        $employee->forceFill(['email_verified_at' => now()])->save();
        $this->get('/absensi/mobile')->assertInertia(fn (Assert $page) => $page
            ->where('mobileRealtime.key', 'test-key')->missing('mobileRealtime.secret'));
    }
}
