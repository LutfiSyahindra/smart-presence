<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\OfficeLocation;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        $this->user = User::factory()->create([
            'email' => 'pegawai@rsarsy.test',
            'password' => 'password',
        ]);
        $this->user->assignRole('pegawai');

        $shift = Shift::query()->create([
            'nama' => 'Shift Pagi',
            'jam_masuk' => '07:00',
            'jam_keluar' => '15:00',
        ]);
        $pegawai = Pegawai::query()->create([
            'nama' => 'Perawat API',
            'email' => $this->user->email,
            'shift_id' => $shift->id,
        ]);
        $pegawai->forceFill(['user_id' => $this->user->id])->save();

        OfficeLocation::query()->create([
            'name' => 'Gedung Utama RSARSY',
            'address' => 'Jl. Rumah Sakit No. 1',
            'latitude' => -6.2,
            'longitude' => 106.8166667,
            'radius_meters' => 100,
        ]);
    }

    public function test_employee_can_login_and_receive_a_mobile_token(): void
    {
        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'Test Android',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $this->user->email)
            ->assertJsonPath('user.can_access_account', true)
            ->assertJsonPath('expires_at', null)
            ->assertJsonStructure(['token', 'token_type', 'expires_at']);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'Test Android',
            'expires_at' => null,
        ]);
    }

    public function test_mobile_token_survives_inactivity_until_explicit_logout(): void
    {
        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'Test Android',
        ])->assertOk()->json('token');

        $this->travel(60)->days();
        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/mobile/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('user.id', $this->user->id);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/mobile/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/mobile/v1/auth/user')->assertUnauthorized();
    }

    public function test_login_on_a_device_with_the_same_name_preserves_the_other_device_session(): void
    {
        $credentials = [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'Same Android Model',
        ];
        $firstToken = $this->postJson('/api/mobile/v1/auth/login', $credentials)->assertOk()->json('token');
        $secondToken = $this->postJson('/api/mobile/v1/auth/login', $credentials)->assertOk()->json('token');

        Auth::forgetGuards();
        $this->withToken($firstToken)->getJson('/api/mobile/v1/auth/user')->assertOk();
        Auth::forgetGuards();
        $this->withToken($secondToken)->postJson('/api/mobile/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withToken($firstToken)->getJson('/api/mobile/v1/auth/user')->assertOk();
    }

    public function test_invalid_mobile_credentials_are_rejected(): void
    {
        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'incorrect',
            'device_name' => 'Test Android',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_authenticated_employee_can_load_home_and_attendance_challenge(): void
    {
        $profilePhotoPath = "profile-photos/{$this->user->id}/wajah.jpg";
        Storage::disk('local')->put(
            $profilePhotoPath,
            UploadedFile::fake()->image('wajah.jpg', 800, 800)->getContent(),
        );
        $this->user->forceFill([
            'profile_photo_path' => $profilePhotoPath,
            'profile_photo_updated_at' => now(),
        ])->save();
        Sanctum::actingAs($this->user, ['mobile']);

        $this->getJson('/api/mobile/v1/home')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Perawat API')
            ->assertJsonPath('data.schedule.shift_name', 'Shift Pagi')
            ->assertJsonPath('data.attendance.status', 'ready_to_clock_in');

        $this->getJson('/api/mobile/v1/attendance/clock-in')
            ->assertOk()
            ->assertJsonPath('data.office.name', 'Gedung Utama RSARSY')
            ->assertJsonPath('data.location_challenge.min_samples', 1)
            ->assertJsonPath('data.location_challenge.collection_seconds', 0)
            ->assertJsonStructure(['data' => ['location_challenge' => ['token', 'server_time']]]);
    }

    public function test_mobile_profile_upload_is_validated_before_replacing_the_private_photo(): void
    {
        Sanctum::actingAs($this->user, ['mobile']);
        config([
            'face-verification.fastapi.url' => 'http://face-service.test',
            'face-verification.fastapi.token' => str_repeat('test', 8),
        ]);
        Http::preventStrayRequests();
        Http::fake(['http://face-service.test/api/v1/profile/validate' => Http::response(['valid' => true])]);

        $this->post('/api/mobile/v1/account/photo', [
            'photo' => UploadedFile::fake()->image('wajah.jpg', 800, 800),
        ], ['Accept' => 'application/json'])->assertOk();

        Http::assertSentCount(1);
        $oldPath = $this->user->refresh()->profile_photo_path;
        $this->assertNotNull($oldPath);
        Storage::disk('local')->assertExists($oldPath);

        foreach ([422, 503] as $status) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['error' => [
                'code' => $status === 422 ? 'invalid_profile_photo' : 'service_unavailable',
                'message' => 'Foto belum lolos pemeriksaan.',
            ]], $status)]);

            $this->post('/api/mobile/v1/account/photo', [
                'photo' => UploadedFile::fake()->image('baru.jpg', 800, 800),
            ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');

            $this->assertSame($oldPath, $this->user->refresh()->profile_photo_path);
            $this->assertSame([$oldPath], Storage::disk('local')->allFiles("profile-photos/{$this->user->id}"));
        }
    }

    public function test_mobile_api_requires_authentication(): void
    {
        $this->getJson('/api/mobile/v1/home')->assertUnauthorized();
    }
}
