<?php

namespace Tests\Feature;

use App\Models\MobileAppRelease;
use App\Models\User;
use App\Services\Mobile\ApkInspector;
use App\Services\Mobile\AppReleaseService;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class MobileAppReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/admin-web/setting/app-releases';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AuthorizationSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
        Storage::fake('local');
        $this->travelTo(now()->setDateTime(2026, 10, 26, 10, 0, 0));
    }

    private function metadata(int $code = 2): array
    {
        return ['package_name' => 'id.rsarsy.presensi', 'version_code' => $code,
            'version_name' => '1.1.0', 'min_sdk' => 24, 'certificate_sha256' => str_repeat('a', 64)];
    }

    private function inspector(int $code = 2): void
    {
        $this->instance(ApkInspector::class, Mockery::mock(ApkInspector::class)
            ->shouldReceive('inspect')->andReturn($this->metadata($code))->getMock());
    }

    private function payload(array $overrides = []): array
    {
        return [...['release_notes' => 'Perbaikan GPS dan pembaruan aplikasi.', 'minimum_version_code' => 1, 'required_at' => null], ...$overrides];
    }

    private function draft(int $code = 2, array $overrides = []): MobileAppRelease
    {
        $path = 'mobile-releases/test-'.$code.'.apk';
        Storage::disk('local')->put($path, 'verified-apk-'.$code);

        return MobileAppRelease::create([
            ...$this->metadata($code), ...$this->payload(), 'path' => $path,
            'size_bytes' => strlen('verified-apk-'.$code), 'sha256' => hash('sha256', 'verified-apk-'.$code),
            'uploaded_by' => $this->admin->id, ...$overrides,
        ]);
    }

    private function publish(MobileAppRelease $release): void
    {
        $this->inspector($release->version_code);
        $this->actingAs($this->admin)->post(self::URL.'/'.$release->id.'/publish')->assertSessionHasNoErrors();
    }

    public function test_only_authorized_admins_can_view_upload_publish_and_change_active_policy(): void
    {
        $release = $this->draft();
        $this->get(self::URL)->assertRedirect();
        $employee = User::factory()->create();
        $employee->assignRole('pegawai');
        $this->actingAs($employee)->get(self::URL)->assertForbidden();
        $this->post(self::URL, $this->payload())->assertForbidden();
        $this->post(self::URL.'/'.$release->id.'/publish')->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::APP_RELEASES_VIEW]);
        $this->actingAs($viewer)->get(self::URL)->assertOk();
        $this->post(self::URL, $this->payload())->assertForbidden();
        $viewer->givePermissionTo(AccessPermission::APP_RELEASES_MANAGE);
        $this->put(self::URL.'/'.$release->id, $this->payload())->assertSessionHasNoErrors();
        $this->post(self::URL.'/'.$release->id.'/publish')->assertForbidden();
        $this->publish($release);
        $this->actingAs($viewer)->put(self::URL.'/'.$release->id, $this->payload())->assertForbidden();
        $this->post(self::URL.'/'.$release->id.'/withdraw')->assertForbidden();
    }

    public function test_upload_creates_private_draft_with_version_from_apk_and_audit_actor(): void
    {
        $this->inspector();
        $this->actingAs($this->admin)->post(self::URL, [
            ...$this->payload(), 'apk' => UploadedFile::fake()->createWithContent('presensi.apk', 'signed-apk-content'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $release = MobileAppRelease::sole();
        $this->assertSame(2, $release->version_code);
        $this->assertSame($this->admin->id, $release->uploaded_by);
        $this->assertNull($release->published_at);
        Storage::disk('local')->assertExists($release->path);
        $this->get('/mobile-app/android/release?version_code=1')->assertJsonPath('release', null);
        $this->get('/mobile-app/android/releases/'.$release->id.'/download')->assertNotFound();
        $this->get(self::URL.'/'.$release->id.'/download')->assertDownload('arsy-presensi-2.apk');
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->component('absensi/admin-web/setting/app-release/Index')->where('releases.data.0.status', 'draft'));
    }

    public function test_duplicate_and_oversize_or_non_apk_uploads_are_rejected_without_storing_extra_files(): void
    {
        $this->draft();
        $this->inspector();
        $this->actingAs($this->admin)->post(self::URL, [...$this->payload(), 'apk' => UploadedFile::fake()->create('same.apk', 1)])
            ->assertSessionHasErrors('apk');
        $this->post(self::URL, [...$this->payload(), 'apk' => UploadedFile::fake()->create('app.zip', 1)])->assertSessionHasErrors('apk');
        $this->post(self::URL, [...$this->payload(), 'apk' => UploadedFile::fake()->create('huge.apk', 102401)])->assertSessionHasErrors('apk');
        $this->assertCount(1, Storage::disk('local')->allFiles('mobile-releases'));
        $this->assertSame(1, MobileAppRelease::count());
    }

    public function test_publication_replaces_download_and_old_or_draft_files_remain_private(): void
    {
        $first = $this->draft();
        $other = $this->draft(3);
        $this->publish($first);
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('androidRelease.version_code', 2)->where('androidApkUrl', '/mobile-app/android/releases/'.$first->id.'/download'));
        $this->get('/mobile-app/android/releases/'.$first->id.'/download')->assertDownload('arsy-presensi-2.apk');
        $this->get('/mobile-app/android/releases/'.$other->id.'/download')->assertNotFound();
        $this->get('/mobile-app/android/release?version_code=1&sdk_int=36')
            ->assertJsonPath('update_available', true)->assertJsonPath('update_required', false)
            ->assertJsonMissingPath('release.path')->assertHeader('Cache-Control', 'no-store, private');
        $this->publish($other);
        $this->get('/mobile-app/android/releases/'.$first->id.'/download')->assertNotFound();
        $this->assertSame($this->admin->id, $other->fresh()->published_by);
        $this->post(self::URL.'/'.$first->id.'/publish')->assertSessionHasErrors('release');
        $this->put(self::URL.'/'.$first->id, $this->payload())->assertSessionHasErrors('release');
    }

    public function test_required_update_respects_grace_time_timezone_sdk_and_actual_installed_version(): void
    {
        $release = $this->draft();
        $this->actingAs($this->admin)->put(self::URL.'/'.$release->id, $this->payload([
            'minimum_version_code' => 2, 'required_at' => '2026-10-26T11:00:00+07:00',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('11:00', $release->fresh()->required_at->format('H:i'));
        $this->publish($release);
        $this->get('/mobile-app/android/release?version_code=1&sdk_int=36')->assertJsonPath('update_required', false);
        $this->travelTo(now()->setTime(11, 1));
        $this->get('/mobile-app/android/release?version_code=1&sdk_int=36')->assertJsonPath('update_required', true);
        $this->get('/mobile-app/android/release?version_code=2&sdk_int=36')->assertJsonPath('update_available', false)->assertJsonPath('update_required', false);
        $this->get('/mobile-app/android/release?version_code=3&sdk_int=36')->assertJsonPath('update_available', false);
        $this->get('/mobile-app/android/release?version_code=1&sdk_int=23')->assertJsonPath('compatible', false)->assertJsonPath('update_required', false);
        $this->get('/mobile-app/android/release?version_code=0')->assertJsonPath('update_available', true)->assertJsonPath('update_required', false);
        $this->put(self::URL.'/'.$release->id, $this->payload(['minimum_version_code' => 3]))->assertSessionHasErrors('minimum_version_code');
    }

    public function test_withdrawal_keeps_audit_and_never_reactivates_old_download_or_downgrade(): void
    {
        $release = $this->draft(3);
        $oldDraft = $this->draft(2);
        $this->publish($release);
        $this->post(self::URL.'/'.$release->id.'/withdraw')->assertSessionHasNoErrors();
        $this->assertNotNull($release->fresh()->withdrawn_at);
        $this->assertSame($this->admin->id, $release->fresh()->withdrawn_by);
        config()->set('mobile.android_apk_url', 'https://example.com/obsolete.apk');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('androidApkUrl', null));
        $this->get('/mobile-app/android/release?version_code=1')->assertJsonPath('release', null);
        $this->get('/mobile-app/android/releases/'.$release->id.'/download')->assertNotFound();
        $this->post(self::URL.'/'.$oldDraft->id.'/publish')->assertSessionHasErrors('release');
        $this->assertSame(3, DB::table('mobile_app_distribution')->value('highest_version_code'));
    }

    public function test_missing_or_modified_file_cannot_be_published_and_missing_active_file_does_not_force_updates(): void
    {
        $release = $this->draft();
        Storage::disk('local')->put($release->path, 'tampered');
        $this->actingAs($this->admin)->post(self::URL.'/'.$release->id.'/publish')->assertSessionHasErrors('release');
        Storage::disk('local')->put($release->path, 'verified-apk-2');
        $this->publish($release);
        Storage::disk('local')->delete($release->path);
        $this->get('/mobile-app/android/release?version_code=1')->assertJsonPath('release', null)->assertJsonPath('update_required', false);
        $this->get('/mobile-app/android/releases/'.$release->id.'/download')->assertNotFound();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('androidApkUrl', null));
    }

    public function test_policy_can_be_enabled_immediately_and_disabled_on_active_release(): void
    {
        $release = $this->draft();
        $this->publish($release);
        $this->put(self::URL.'/'.$release->id, $this->payload(['minimum_version_code' => 2]))->assertSessionHasNoErrors();
        $this->get('/mobile-app/android/release?version_code=1&sdk_int=36')->assertJsonPath('update_required', true);
        $this->put(self::URL.'/'.$release->id, $this->payload())->assertSessionHasNoErrors();
        $this->get('/mobile-app/android/release?version_code=1&sdk_int=36')->assertJsonPath('update_required', false);
    }

    public function test_public_endpoint_validates_version_and_does_not_require_login(): void
    {
        $this->getJson('/mobile-app/android/release?version_code=-1')->assertUnprocessable();
        $this->getJson('/mobile-app/android/release?version_code=1&sdk_int=invalid')->assertUnprocessable();
        $this->getJson('/mobile-app/android/release?version_code=1')->assertOk()->assertJsonPath('release', null);
    }

    public function test_draft_can_be_discarded_and_replaced_but_published_history_cannot_be_deleted(): void
    {
        $draft = $this->draft();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([AccessPermission::ADMIN_ACCESS, AccessPermission::APP_RELEASES_VIEW]);
        $this->actingAs($viewer)->delete(self::URL.'/'.$draft->id)->assertForbidden();
        $this->actingAs($this->admin)->delete(self::URL.'/'.$draft->id)->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($draft->path);
        $this->assertModelMissing($draft);
        $this->inspector();
        $this->post(self::URL, [...$this->payload(), 'apk' => UploadedFile::fake()->createWithContent('replacement.apk', 'signed-apk-content')])
            ->assertSessionHasNoErrors();
        $replacement = MobileAppRelease::sole();
        $this->publish($replacement);
        $this->delete(self::URL.'/'.$replacement->id)->assertSessionHasErrors('release');
        $this->post(self::URL.'/'.$replacement->id.'/withdraw')->assertSessionHasNoErrors();
        $this->delete(self::URL.'/'.$replacement->id)->assertSessionHasErrors('release');
        $this->assertModelExists($replacement);
    }

    public function test_release_announcements_follow_committed_public_changes_and_never_drafts_or_rollbacks(): void
    {
        $release = $this->draft();
        $this->inspector();
        $signals = [];
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->andReturnUsing(function ($channels, $event, $payload) use (&$signals) {
            $this->assertSame('mobile.app-releases', (string) $channels[0]);
            $this->assertSame('mobile.app-release.changed', $event);
            $this->assertSame(['version'], array_keys($payload));
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $payload['version']);
            $signals[] = $payload;
        });
        Broadcast::shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
        config(['mobile-realtime.enabled' => true]);
        $service = app(AppReleaseService::class);
        $service->update($release, $this->payload());
        $this->assertCount(0, $signals);

        DB::transaction(function () use ($service, $release, &$signals) {
            $service->publish($release, $this->admin);
            $this->assertCount(0, $signals, 'No signal before the enclosing transaction commits.');
        });
        $this->assertCount(1, $signals);
        $service->update($release, $this->payload(['minimum_version_code' => 2]));
        $this->assertCount(2, $signals);
        $service->withdraw($release, $this->admin);
        $this->assertCount(3, $signals);

        $next = $this->draft(3);
        $this->inspector(3);
        $service = app(AppReleaseService::class);
        DB::beginTransaction();
        try {
            $service->publish($next, $this->admin);
        } finally {
            DB::rollBack();
        }
        $this->assertCount(3, $signals);
        $this->assertNull($service->active());
    }

    public function test_release_publication_succeeds_when_realtime_is_unavailable(): void
    {
        $release = $this->draft();
        $this->inspector();
        $broadcaster = Mockery::mock();
        $broadcaster->shouldReceive('broadcast')->once()->andThrow(new \RuntimeException('offline'));
        Broadcast::shouldReceive('connection')->with('reverb')->andReturn($broadcaster);
        Log::spy();
        config(['mobile-realtime.enabled' => true]);
        $service = app(AppReleaseService::class);
        $service->publish($release, $this->admin);
        $this->assertSame($release->id, $service->active()->id);
        Log::shouldHaveReceived('warning')->once();
    }
}
