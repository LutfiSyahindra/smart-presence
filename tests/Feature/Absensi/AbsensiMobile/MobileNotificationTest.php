<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Jobs\SendMobilePushNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\ApprovalLine;
use App\Models\CutiRequest;
use App\Models\Jabatan;
use App\Models\MasterCuti;
use App\Models\MobilePushDevice;
use App\Models\Pegawai;
use App\Models\PegawaiCuti;
use App\Models\UnitKerja;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Absensi\AdminWeb\Cuti\CutiService;
use App\Services\Notifications\MobilePushService;
use App\Services\Notifications\WebPushService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use GuzzleHttp\Psr7\Request as PushRequest;
use GuzzleHttp\Psr7\Response as PushResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MobileNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/notifikasi';

    private User $employee;

    private User $first;

    private User $second;

    private MasterCuti $master;

    private CutiService $service;

    private string $rsaKey = '';

    private string $ecKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-19 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        config(['webpush.public_key' => null, 'webpush.private_key' => null,
            'mobilepush.fcm.credentials' => null, 'mobilepush.apns.private_key' => null]);
        Bus::fake([SendWebPushNotification::class, SendMobilePushNotification::class]);
        Http::preventStrayRequests();
        foreach (['employee', 'first', 'second'] as $property) {
            $this->{$property} = User::factory()->create();
            $this->{$property}->assignRole('pegawai');
        }
        $unit = UnitKerja::query()->create(['kode' => 'TEST', 'nama' => 'Unit Test', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'STF', 'nama' => 'Staf', 'aktif' => true]);
        $pegawai = Pegawai::query()->create(['nama' => 'Pemohon Rahasia', 'jabatan_id' => $position->id, 'unit_kerja_id' => $unit->id]);
        $pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $line = ApprovalLine::query()->create(['type' => 'cuti']);
        $line->jabatans()->attach($position->id, ['type' => 'cuti']);
        foreach ([$this->first, $this->second] as $index => $approver) {
            $line->steps()->create(['user_id' => $approver->id, 'level' => $index + 1]);
        }
        $this->master = MasterCuti::query()->create(['kode' => 'CT', 'nama' => 'Cuti Khusus', 'aktif' => true, 'durasi_hari' => 10]);
        PegawaiCuti::query()->create(['pegawai_id' => $pegawai->id, 'master_cuti_id' => $this->master->id, 'tahun' => 0, 'jumlah_hari' => 10]);
        $this->service = app(CutiService::class);
    }

    private function submit(): CutiRequest
    {
        return $this->service->submit($this->employee, ['master_cuti_id' => $this->master->id,
            'tanggal_mulai' => '2026-10-26', 'tanggal_selesai' => '2026-10-27', 'alasan' => 'Alasan rahasia']);
    }

    private function approve(): CutiRequest
    {
        $cuti = $this->submit();
        $this->service->decide($cuti, $this->first, ['decision' => 'approved']);

        return $this->service->decide($cuti, $this->second, ['decision' => 'approved']);
    }

    private function nativeConfig(): void
    {
        Storage::fake('local');
        openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), $this->rsaKey);
        openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), $this->ecKey);
        Storage::disk('local')->put('fcm-test.json', json_encode(['project_id' => 'test-project', 'client_email' => 'test@test-project.iam.gserviceaccount.com', 'private_key' => $this->rsaKey]));
        Storage::disk('local')->put('apns-test.p8', $this->ecKey);
        config(['mobilepush.fcm.credentials' => Storage::disk('local')->path('fcm-test.json'),
            'mobilepush.apns.private_key' => Storage::disk('local')->path('apns-test.p8'),
            'mobilepush.apns.team_id' => 'TESTTEAM01', 'mobilepush.apns.key_id' => 'TESTKEY001', 'mobilepush.apns.sandbox' => true]);
    }

    private function device(string $platform, string $suffix = 'a', ?User $user = null): MobilePushDevice
    {
        $token = $platform === 'ios' ? str_repeat($suffix, 64) : str_repeat($suffix, 80).':fcm';

        return MobilePushDevice::query()->create(['user_id' => ($user ?? $this->employee)->id,
            'platform' => $platform, 'token' => $token, 'token_hash' => hash('sha256', $platform.':'.$token)]);
    }

    public function test_mobile_only_requester_is_notified_once_after_the_final_approval(): void
    {
        $this->assertFalse($this->employee->can(AccessPermission::ADMIN_ACCESS));
        $cuti = $this->submit();
        $this->service->decide($cuti, $this->first, ['decision' => 'approved']);
        $this->assertSame(0, $this->employee->notifications()->count());
        $this->service->decide($cuti, $this->second, ['decision' => 'approved']);
        $notice = $this->employee->notifications()->sole();
        $this->assertSame('approved', $notice->data['event']);
        $this->assertSame('/absensi/mobile/cuti/'.$cuti->id, $notice->data['mobile_url']);
        Bus::assertDispatched(SendMobilePushNotification::class, fn ($job) => $job->notificationId === $notice->id && $job->afterCommit);
        Bus::assertDispatched(SendWebPushNotification::class, fn ($job) => $job->notificationId === $notice->id && $job->afterCommit);
        try {
            $this->service->decide($cuti, $this->second, ['decision' => 'approved']);
            $this->fail('A finished request must not be approved twice.');
        } catch (ValidationException) {
            $this->assertSame(1, $this->employee->notifications()->count());
        }
    }

    public function test_inbox_open_and_historical_detail_are_private_and_work_without_admin_access(): void
    {
        $cuti = $this->approve();
        $notice = $this->employee->notifications()->sole();
        $otherNotice = $this->first->notifications()->first();
        $this->actingAs($this->employee)->getJson(self::URL.'/feed')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('items.0.url', self::URL.'/open/'.$notice->id)->assertJsonPath('native_push.android', false);
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->component('absensi/absensi-mobile/Notifications')->has('notifications.data', 1));
        $this->get(self::URL.'/open/'.$otherNotice->id)->assertNotFound();
        $this->get(self::URL.'/open/'.$notice->id)->assertRedirect('/absensi/mobile/cuti/'.$cuti->id);
        $this->assertNotNull($notice->fresh()->read_at);
        $this->assertNull($otherNotice->fresh()->read_at);
        $this->travelTo('2026-12-01');
        $this->get('/absensi/mobile/cuti/'.$cuti->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('initialRequest.id', $cuti->id)->where('initialRequest.status', 'approved')->has('requests.data', 0));
        $stranger = User::factory()->create()->assignRole('pegawai');
        $this->actingAs($stranger)->get('/absensi/mobile/cuti/'.$cuti->id)->assertForbidden();
        $this->get(self::URL.'/open/'.$notice->id)->assertNotFound();
        $this->getJson(self::URL.'/feed')->assertJsonCount(0, 'items');
    }

    public function test_rejection_reaches_the_mobile_requester_and_mark_all_read_is_scoped(): void
    {
        $cuti = $this->submit();
        $this->service->decide($cuti, $this->first, ['decision' => 'rejected', 'note' => 'Jadwal perlu diubah']);
        $this->assertSame('rejected', $this->employee->notifications()->sole()->data['event']);
        $this->actingAs($this->employee)->postJson(self::URL.'/read-all')->assertOk();
        $this->assertSame(0, $this->employee->unreadNotifications()->count());
        $this->assertSame(1, $this->second->unreadNotifications()->count());
    }

    public function test_mobile_notification_endpoints_require_verified_mobile_access(): void
    {
        $this->getJson(self::URL.'/feed')->assertUnauthorized();
        $noAccess = User::factory()->create();
        $this->actingAs($noAccess)->getJson(self::URL.'/feed')->assertForbidden();
        $this->postJson(self::URL.'/devices', ['platform' => 'android', 'token' => str_repeat('a', 80)])->assertForbidden();
        $this->postJson(self::URL.'/push-subscriptions', [])->assertForbidden();
        $this->employee->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->employee)->getJson(self::URL.'/feed')->assertForbidden();
    }

    public function test_mobile_web_subscription_uses_mobile_audience_and_logout_removes_only_that_device(): void
    {
        config(['webpush.public_key' => 'test-public', 'webpush.private_key' => 'test-private']);
        $payload = ['endpoint' => 'https://web.push.apple.com/test/mobile', 'keys' => [
            'p256dh' => rtrim(strtr(base64_encode(chr(4).str_repeat('a', 64)), '+/', '-_'), '='),
            'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
        ]];
        $this->actingAs($this->employee)->postJson(self::URL.'/push-subscriptions', $payload)->assertOk();
        $subscription = WebPushSubscription::query()->sole();
        $this->assertSame('mobile', $subscription->audience);
        $this->assertSame($this->employee->id, $subscription->user_id);
        $this->employee->givePermissionTo(AccessPermission::ADMIN_ACCESS);
        $this->postJson('/absensi/admin-web/notifikasi/push-subscriptions', [
            ...$payload, 'endpoint' => 'https://web.push.apple.com/test/admin',
        ])->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 2);
        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_pwa_notification_click_resumes_after_login(): void
    {
        $this->approve();
        $notice = $this->employee->notifications()->sole();
        $url = self::URL.'/open/'.$notice->id;
        $this->get($url)->assertRedirect('/login');
        $this->post('/login', ['email' => $this->employee->email, 'password' => 'password'])->assertRedirect($url);
        $this->get($url)->assertRedirect($notice->data['mobile_url']);
    }

    public function test_native_tokens_rotate_transfer_between_accounts_and_logout_preserves_other_devices(): void
    {
        $this->nativeConfig();
        $token = str_repeat('a', 80).':fcm';
        $this->actingAs($this->employee)->postJson(self::URL.'/devices', ['platform' => 'android', 'token' => $token])->assertOk();
        $old = MobilePushDevice::query()->sole();
        $this->postJson(self::URL.'/devices', ['platform' => 'android', 'token' => str_repeat('b', 80)])->assertOk();
        $this->assertDatabaseMissing('mobile_push_devices', ['id' => $old->id]);
        $other = $this->device('ios');
        $this->actingAs($this->first)->postJson(self::URL.'/devices', ['platform' => 'android', 'token' => str_repeat('b', 80)])->assertOk();
        $this->assertDatabaseCount('mobile_push_devices', 2);
        $this->assertSame($this->first->id, MobilePushDevice::query()->where('platform', 'android')->sole()->user_id);
        $this->postJson(self::URL.'/devices', ['platform' => 'ios', 'token' => str_repeat('z', 64)])->assertUnprocessable();
        $this->post('/logout')->assertRedirect();
        $this->assertDatabaseHas('mobile_push_devices', ['id' => $other->id, 'user_id' => $this->employee->id]);
        $this->assertDatabaseCount('mobile_push_devices', 1);
    }

    public function test_fcm_and_apns_use_signed_tokens_and_generic_alerts_without_leave_details(): void
    {
        $this->nativeConfig();
        $this->approve();
        $notice = $this->employee->notifications()->sole();
        $this->device('android');
        $this->device('ios');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-oauth-token']),
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test-project/messages/1']),
            'https://api.sandbox.push.apple.com/*' => Http::response('', 200),
        ]);
        app(MobilePushService::class)->send($notice);
        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            $jws = (new CompactSerializer)->unserialize($request['assertion']);
            $verifier = new JWSVerifier(new AlgorithmManager([new RS256]));
            $this->assertTrue($verifier->verifyWithKey($jws, JWKFactory::createFromKey($this->rsaKey)->toPublic(), 0));
            $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', json_decode($jws->getPayload(), true)['scope']);

            return true;
        });
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://fcm.googleapis.com/')
            && $request->hasHeader('Authorization', 'Bearer test-oauth-token')
            && $request['message']['data']['url'] === self::URL.'/open/'.$notice->id
            && $request['message']['android']['notification']['channel_id'] === 'pengajuan'
            && $request['message']['notification']['body'] === 'Pengajuan telah disetujui. Buka aplikasi untuk melihat detail.');
        Http::assertSent(function ($request) use ($notice) {
            if (! str_starts_with($request->url(), 'https://api.sandbox.push.apple.com/')) {
                return false;
            }
            $jwt = substr($request->header('Authorization')[0], 7);
            $jws = (new CompactSerializer)->unserialize($jwt);
            $this->assertTrue((new JWSVerifier(new AlgorithmManager([new ES256])))->verifyWithKey($jws, JWKFactory::createFromKey($this->ecKey)->toPublic(), 0));
            $this->assertSame('TESTKEY001', $jws->getSignature(0)->getProtectedHeaderParameter('kid'));
            $this->assertSame('TESTTEAM01', json_decode($jws->getPayload(), true)['iss']);
            $this->assertSame(self::URL.'/open/'.$notice->id, $request['url']);
            $this->assertSame('alert', $request->header('apns-push-type')[0]);
            $this->assertSame('Pengajuan telah disetujui. Buka aplikasi untuk melihat detail.', $request['aps']['alert']['body']);

            return true;
        });
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('Pemohon Rahasia', $request->body());
            $this->assertStringNotContainsString('2026-10-26', $request->body());
            $this->assertStringNotContainsString('Alasan rahasia', $request->body());
        }
    }

    public function test_expired_native_tokens_are_deleted_but_provider_errors_keep_devices_for_retry(): void
    {
        $this->nativeConfig();
        $this->approve();
        $notice = $this->employee->notifications()->sole();
        $android = $this->device('android');
        $ios = $this->device('ios');
        $retry = false;
        Http::fake(function ($request) use (&$retry) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'test-oauth-token']);
            }
            if (str_starts_with($request->url(), 'https://fcm.googleapis.com/')) {
                return $retry ? Http::response([], 503) : Http::response(['error' => ['details' => [
                    ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
                ]]], 404);
            }

            return $retry ? Http::response('', 200) : Http::response(['reason' => 'Unregistered'], 410);
        });
        app(MobilePushService::class)->send($notice);
        $this->assertDatabaseMissing('mobile_push_devices', ['id' => $android->id]);
        $this->assertDatabaseMissing('mobile_push_devices', ['id' => $ios->id]);
        $this->device('android', 'b');
        $this->device('ios', 'b');
        $retry = true;
        try {
            app(MobilePushService::class)->send($notice);
            $this->fail('Provider errors should retry.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString(str_repeat('b', 80), $error->getMessage());
            $this->assertDatabaseCount('mobile_push_devices', 2);
            Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.sandbox.push.apple.com/'));
        }
    }

    public function test_revoked_mobile_access_suppresses_native_delivery(): void
    {
        $this->nativeConfig();
        $this->approve();
        $notice = $this->employee->notifications()->sole();
        $this->device('android');
        $this->employee->removeRole('pegawai');
        $notice->unsetRelation('notifiable');
        Http::fake();
        app(MobilePushService::class)->send($notice);
        Http::assertNothingSent();
    }

    public function test_mobile_web_push_uses_mobile_open_route_for_a_requester_without_admin_access(): void
    {
        $this->approve();
        $notice = $this->employee->notifications()->sole();
        config(['webpush.public_key' => 'test-public', 'webpush.private_key' => 'test-private']);
        $endpoint = 'https://web.push.apple.com/mobile-test';
        WebPushSubscription::query()->create(['user_id' => $this->employee->id, 'audience' => 'mobile', 'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint), 'public_key' => 'key', 'auth_token' => 'auth']);
        $client = Mockery::mock(WebPush::class);
        $client->shouldReceive('sendOneNotification')->once()->withArgs(function ($subscription, $payload) use ($notice) {
            $data = json_decode($payload, true);

            return $data['url'] === self::URL.'/open/'.$notice->id && ! str_contains($payload, 'Pemohon Rahasia');
        })->andReturn(new MessageSentReport(new PushRequest('POST', $endpoint), new PushResponse(201)));
        $push = new class($client) extends WebPushService
        {
            public function __construct(private WebPush $mockClient) {}

            protected function client(): WebPush
            {
                return $this->mockClient;
            }
        };
        $push->send($notice);
    }

    public function test_approval_rollback_saves_neither_final_notice_nor_push_jobs(): void
    {
        $cuti = $this->submit();
        $this->service->decide($cuti, $this->first, ['decision' => 'approved']);
        Bus::fake([SendWebPushNotification::class, SendMobilePushNotification::class]);
        DB::beginTransaction();
        $this->service->decide($cuti, $this->second, ['decision' => 'approved']);
        DB::rollBack();
        $this->assertSame('pending', $cuti->fresh()->status);
        $this->assertSame(0, $this->employee->notifications()->count());
        // Actual after-commit dispatch is exercised by PengajuanNotificationTest.
    }
}
