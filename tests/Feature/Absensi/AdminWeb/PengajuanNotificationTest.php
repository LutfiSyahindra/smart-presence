<?php

namespace Tests\Feature\Absensi\AdminWeb;

use App\Jobs\SendWebPushNotification;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\UnitKerja;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Services\Notifications\WebPushService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PushRequest;
use GuzzleHttp\Psr7\Response as PushResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PengajuanNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/notifikasi';

    private User $admin;

    private User $employee;

    private User $first;

    private User $second;

    private MasterCuti $master;

    private CutiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 19)->startOfDay());
        $this->seed(AuthorizationSeeder::class);
        config(['webpush.public_key' => null, 'webpush.private_key' => null]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        $this->employee = User::factory()->create();
        $this->employee->assignRole('pegawai');
        $this->first = User::factory()->create();
        $this->second = User::factory()->create();
        $unit = UnitKerja::query()->create(['kode' => 'TEST', 'nama' => 'Unit Test', 'aktif' => true]);
        $jabatan = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'STF', 'nama' => 'Staf', 'aktif' => true]);
        $pegawai = Pegawai::query()->create(['nama' => 'Pemohon Mobile', 'jabatan_id' => $jabatan->id, 'unit_kerja_id' => $unit->id]);
        $pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $this->master = MasterCuti::query()->create(['kode' => 'KHUSUS', 'nama' => 'Cuti Khusus', 'durasi_hari' => 10, 'aktif' => true]);
        PegawaiCuti::query()->create(['master_cuti_id' => $this->master->id, 'pegawai_id' => $pegawai->id, 'tahun' => 0, 'jumlah_hari' => 10]);
        $line = ApprovalLine::query()->create(['type' => 'cuti']);
        $line->jabatans()->attach($jabatan->id, ['type' => 'cuti']);
        foreach ([$this->first, $this->second] as $index => $user) {
            $line->steps()->create(['user_id' => $user->id, 'level' => $index + 1]);
        }
        $this->service = app(CutiService::class);
    }

    private function submit(): CutiRequest
    {
        return $this->service->submit($this->employee, [
            'master_cuti_id' => $this->master->id, 'tanggal_mulai' => '2026-10-26',
            'tanggal_selesai' => '2026-10-27', 'alasan' => 'Keperluan keluarga',
        ]);
    }

    private function pushConfig(): void
    {
        config(['webpush.public_key' => 'public-test-key', 'webpush.private_key' => 'private-test-key']);
    }

    private function subscriptionPayload(string $suffix = 'test'): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix,
            'keys' => [
                'p256dh' => rtrim(strtr(base64_encode(chr(4).str_repeat('a', 64)), '+/', '-_'), '='),
                'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
            ],
        ];
    }

    private function subscription(string $suffix = 'test', ?User $user = null): WebPushSubscription
    {
        $data = $this->subscriptionPayload($suffix);

        return WebPushSubscription::query()->create([
            'user_id' => ($user ?? $this->first)->id, 'endpoint_hash' => hash('sha256', $data['endpoint']),
            'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
        ]);
    }

    public function test_mobile_submission_saves_inbox_and_queues_push_for_admin_and_current_approver_only(): void
    {
        Bus::fake([SendWebPushNotification::class]);
        $this->actingAs($this->employee)->post('/absensi/mobile/cuti', [
            'master_cuti_id' => $this->master->id, 'tanggal_mulai' => '2026-10-26',
            'tanggal_selesai' => '2026-10-27', 'alasan' => 'Keperluan keluarga',
        ])->assertSessionHasNoErrors();
        $cuti = CutiRequest::query()->sole();
        foreach ([$this->admin, $this->first] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame('submitted', $notification->data['event']);
            $this->assertSame($cuti->id, $notification->data['request_id']);
            $this->assertSame('cuti', $notification->data['request_type']);
            Bus::assertDispatched(SendWebPushNotification::class, fn ($job) => $job->notificationId === $notification->id && $job->afterCommit);
        }
        $this->assertSame(0, $this->second->notifications()->count());
        $this->assertSame(0, $this->employee->notifications()->count());
        Bus::assertDispatchedTimes(SendWebPushNotification::class, 2);
    }

    public function test_all_leave_types_and_web_submissions_use_the_same_notifications(): void
    {
        $this->master->update(['nama' => 'Cuti Tahunan', 'jenis' => 'tahunan']);
        PegawaiCuti::query()->update(['tahun' => 2026]);
        $this->employee->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->actingAs($this->employee)->post('/absensi/admin-web/cuti', [
            'master_cuti_id' => $this->master->id, 'tanggal_mulai' => '2026-10-26',
            'tanggal_selesai' => '2026-10-27', 'alasan' => 'Cuti tahunan',
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString('Cuti Tahunan', $this->first->notifications()->sole()->data['message']);
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_next_approver_is_notified_only_when_their_turn_starts_and_duplicates_are_rejected(): void
    {
        $cuti = $this->submit();
        $this->service->decide($cuti, $this->first, ['decision' => 'approved']);
        $this->assertSame('approval_required', $this->second->notifications()->sole()->data['event']);
        $this->assertSame(2, $this->admin->notifications()->count());
        try {
            $this->service->decide($cuti, $this->first, ['decision' => 'approved']);
            $this->fail('Approver sebelumnya tidak boleh memproses ulang.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $this->second->notifications()->count());
        $this->service->decide($cuti, $this->second, ['decision' => 'approved']);
        $this->assertSame(1, $this->first->notifications()->where('data->event', 'approved')->count());
        $this->assertSame(3, $this->admin->notifications()->count());
    }

    public function test_rejections_and_cancellations_remain_in_the_inbox(): void
    {
        $cuti = $this->submit();
        $this->service->decide($cuti, $this->first, ['decision' => 'rejected', 'note' => 'Jadwal penuh']);
        $this->assertSame('rejected', $this->second->notifications()->sole()->data['event']);
        $cancelled = $this->submit();
        $this->service->cancel($cancelled, $this->employee);
        $this->assertSame(2, $this->first->notifications()->where('data->event', 'cancelled')->count() + $this->second->notifications()->where('data->event', 'cancelled')->count());
        $this->actingAs($this->admin)->get(self::URL.'/semua')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('absensi/admin-web/notifikasi/Inbox')->has('notifications.data', 4));
    }

    public function test_rollback_does_not_leave_notifications_or_push_jobs_and_commit_releases_jobs(): void
    {
        config(['queue.default' => 'database']);
        DB::beginTransaction();
        $this->submit();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('cuti_requests', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->submit();
        // The super-admin also has mobile access: one native job accompanies the two web jobs.
        $this->assertDatabaseCount('jobs', 3);
    }

    public function test_failed_submission_does_not_send_notifications(): void
    {
        $this->submit();
        try {
            $this->submit();
            $this->fail('Tanggal pengajuan tumpang tindih harus ditolak.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('notifications', 2);
        }
    }

    public function test_feed_read_status_and_detail_are_scoped_to_the_signed_in_user(): void
    {
        $cuti = $this->submit();
        $mine = $this->first->notifications()->sole();
        $other = $this->admin->notifications()->sole();
        $this->actingAs($this->first)->getJson(self::URL.'/feed')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1)->assertJsonPath('push_public_key', null);
        $this->get(self::URL.'/open/'.$other->id)->assertNotFound();
        $this->get(self::URL.'/open/'.$mine->id)->assertRedirect($mine->data['url']);
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->get($mine->data['url'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/notifikasi/Detail')->where('cuti.id', $cuti->id)->where('cuti.can_decide', true));
        $this->actingAs($this->second)->get($mine->data['url'])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('cuti.can_decide', false));
        $stranger = User::factory()->create();
        $stranger->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->actingAs($stranger)->get($mine->data['url'])->assertForbidden();
        $this->getJson(self::URL.'/feed')->assertJsonCount(0, 'items');
    }

    public function test_mark_all_read_preserves_other_users_notifications(): void
    {
        $this->submit();
        $this->actingAs($this->first)->postJson(self::URL.'/read-all')->assertOk();
        $this->assertSame(0, $this->first->unreadNotifications()->count());
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
    }

    public function test_routes_require_verified_admin_or_assigned_approver(): void
    {
        $this->getJson(self::URL.'/feed')->assertUnauthorized();
        $this->actingAs($this->employee)->getJson(self::URL.'/feed')->assertForbidden();
        $this->postJson(self::URL.'/push-subscriptions', $this->subscriptionPayload())->assertForbidden();
        $this->first->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->first)->get(self::URL.'/semua')->assertRedirect(route('verification.notice'));
    }

    public function test_subscriptions_are_idempotent_reassigned_on_account_switch_and_deleted_at_logout(): void
    {
        $this->pushConfig();
        $data = $this->subscriptionPayload();
        $this->actingAs($this->first)->postJson(self::URL.'/push-subscriptions', $data)->assertOk();
        $this->postJson(self::URL.'/push-subscriptions', $data)->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->actingAs($this->second)->postJson(self::URL.'/push-subscriptions', $data)->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertDatabaseHas('web_push_subscriptions', ['user_id' => $this->second->id]);
        $this->subscription('other-device', $this->second);
        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertDatabaseHas('web_push_subscriptions', ['endpoint_hash' => hash('sha256', $this->subscriptionPayload('other-device')['endpoint'])]);
    }

    public function test_unconfigured_push_and_invalid_or_internal_endpoints_are_rejected(): void
    {
        $this->actingAs($this->first)->postJson(self::URL.'/push-subscriptions', $this->subscriptionPayload())->assertStatus(503);
        $this->pushConfig();
        foreach (['http://fcm.googleapis.com/send/test', 'https://127.0.0.1/test', 'https://fcm.googleapis.com.evil.test/send/test', 'https://fcm.googleapis.com:8443/test'] as $endpoint) {
            $this->postJson(self::URL.'/push-subscriptions', array_replace($this->subscriptionPayload(), ['endpoint' => $endpoint]))->assertUnprocessable();
        }
        $this->postJson(self::URL.'/push-subscriptions', array_replace_recursive($this->subscriptionPayload(), ['keys' => ['p256dh' => 'invalid']]))->assertUnprocessable();
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_push_sends_the_saved_notification_and_removes_expired_subscriptions(): void
    {
        $this->submit();
        $this->pushConfig();
        $notification = $this->first->notifications()->sole();
        $valid = $this->subscription('valid');
        $expired = $this->subscription('expired');
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->withArgs(function (SubscriptionInterface $subscription, string $payload) use ($valid, $notification) {
            $data = json_decode($payload, true);

            return $subscription->getEndpoint() === $valid->endpoint && $data['id'] === $notification->id
                && $data['url'] === self::URL.'/open/'.$notification->id;
        })->andReturn(new MessageSentReport(new PushRequest('POST', $valid->endpoint), new PushResponse(201)));
        $client->shouldReceive('sendOneNotification')->once()->withArgs(fn (SubscriptionInterface $subscription) => $subscription->getEndpoint() === $expired->endpoint)
            ->andReturn(new MessageSentReport(new PushRequest('POST', $expired->endpoint), new PushResponse(410), false));
        $push = Mockery::mock(WebPushService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $push->shouldReceive('client')->once()->andReturn($client);
        (new SendWebPushNotification($notification->id))->handle($push);
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertNotNull($notification->fresh());
    }

    public function test_transient_push_failure_can_retry_without_removing_in_app_notification(): void
    {
        $this->submit();
        $this->pushConfig();
        $notification = $this->first->notifications()->sole();
        $subscription = $this->subscription();
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->andReturn(new MessageSentReport(new PushRequest('POST', $subscription->endpoint), new PushResponse(503), false));
        $push = Mockery::mock(WebPushService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $push->shouldReceive('client')->once()->andReturn($client);
        try {
            (new SendWebPushNotification($notification->id))->handle($push);
            $this->fail('Push harus dapat dicoba ulang.');
        } catch (RuntimeException) {
            $this->assertNotNull($notification->fresh());
            $this->assertNotNull($subscription->fresh());
        }
    }

    public function test_push_payload_is_encrypted_and_signed_with_vapid_before_delivery(): void
    {
        $this->submit();
        $keys = VAPID::createVapidKeys();
        $browserKeys = VAPID::createVapidKeys();
        config(['webpush.public_key' => $keys['publicKey'], 'webpush.private_key' => $keys['privateKey']]);
        $subscription = $this->subscription();
        $subscription->update(['public_key' => $browserKeys['publicKey']]);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new PushResponse(201)]));
        $handler->push(Middleware::history($history));
        $client = new WebPush(['VAPID' => [
            'subject' => config('webpush.subject'), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey'],
        ]], [], 10, ['handler' => $handler]);
        $push = Mockery::mock(WebPushService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $push->shouldReceive('client')->once()->andReturn($client);
        $notification = $this->first->notifications()->sole();
        (new SendWebPushNotification($notification->id))->handle($push);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('Pemohon Mobile', (string) $request->getBody());
    }

    public function test_vapid_generator_fills_empty_windows_env_values_and_never_rotates_existing_keys(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vapid-test-');
        $originalPath = app()->environmentPath();
        $originalFile = app()->environmentFile();
        try {
            file_put_contents($path, "APP_NAME=Test\r\nWEBPUSH_VAPID_PUBLIC_KEY=\r\nWEBPUSH_VAPID_PRIVATE_KEY=\"\"\r\n");
            app()->useEnvironmentPath(dirname($path))->loadEnvironmentFrom(basename($path));
            $this->artisan('notifications:vapid')->assertSuccessful();
            $contents = file_get_contents($path);
            $this->assertMatchesRegularExpression('/WEBPUSH_VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}\r\n/', $contents);
            $this->assertMatchesRegularExpression('/WEBPUSH_VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}\r\n/', $contents);
            $this->artisan('notifications:vapid')->assertFailed();
            $this->assertSame($contents, file_get_contents($path));
        } finally {
            app()->useEnvironmentPath($originalPath)->loadEnvironmentFrom($originalFile);
            unlink($path);
        }
    }
}
