<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Models\Jabatan;
use App\Models\Pegawai;
use App\Models\UnitKerja;
use App\Models\User;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\UnitJabatanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([AuthorizationSeeder::class, UnitJabatanSeeder::class]);
        Storage::fake('local');

        $this->employee = User::factory()->create([
            'name' => 'M. Lutfi Syahindra Solihin',
            'email' => 'lutfi@rsarsy.test',
        ]);
        $this->employee->assignRole('pegawai');

        $unit = UnitKerja::query()->firstOrFail();
        $jabatan = Jabatan::query()->where('unit_kerja_id', $unit->id)->firstOrFail();
        $pegawai = Pegawai::query()->create([
            'unit_kerja_id' => $unit->id,
            'jabatan_id' => $jabatan->id,
            'nik' => 'PEG-001',
            'nama' => 'M. Lutfi Syahindra Solihin, S.Kom',
            'profesi' => 'Staff SIRS',
            'nomor_telepon' => '081234567890',
            'email' => 'lutfi@rsarsy.test',
        ]);
        $pegawai->forceFill(['user_id' => $this->employee->id])->save();
    }

    public function test_employee_can_open_premium_account_page_with_employment_profile(): void
    {
        $this->actingAs($this->employee)
            ->get('/absensi/mobile/akun')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('absensi/absensi-mobile/Account')
                ->where('profile.name', 'M. Lutfi Syahindra Solihin, S.Kom')
                ->where('profile.profession', 'Staff SIRS')
                ->where('profile.nik', 'PEG-001')
                ->where('profile.has_employee_profile', true)
                ->where('profile.photo_url', null));
    }

    public function test_employee_can_upload_a_private_profile_photo(): void
    {
        $response = $this->actingAs($this->employee)
            ->from('/absensi/mobile/akun')
            ->post('/absensi/mobile/akun/foto-profil', [
                'photo' => UploadedFile::fake()->image('wajah.jpg', 800, 1000)->size(900),
            ]);

        $response->assertRedirect('/absensi/mobile/akun')
            ->assertSessionHas('success');

        $user = $this->employee->refresh();
        $this->assertNotNull($user->profile_photo_path);
        $this->assertNotNull($user->profile_photo_updated_at);
        $this->assertStringStartsWith("profile-photos/{$user->id}/", $user->profile_photo_path);
        Storage::disk('local')->assertExists($user->profile_photo_path);

        $this->get('/absensi/mobile/akun/foto-profil')
            ->assertOk()
            ->assertHeader('cache-control', 'private, max-age=86400')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_replacing_profile_photo_removes_the_previous_private_file(): void
    {
        $oldPath = "profile-photos/{$this->employee->id}/foto-lama.jpg";
        Storage::disk('local')->put($oldPath, 'old-photo');
        $this->employee->forceFill([
            'profile_photo_path' => $oldPath,
            'profile_photo_updated_at' => now()->subDay(),
        ])->save();

        $this->actingAs($this->employee)
            ->post('/absensi/mobile/akun/foto-profil', [
                'photo' => UploadedFile::fake()->image('foto-baru.png', 900, 1200)->size(800),
            ])
            ->assertSessionHasNoErrors();

        $newPath = $this->employee->refresh()->profile_photo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_photo_must_have_a_supported_format_and_face_ready_resolution(): void
    {
        $this->actingAs($this->employee)
            ->from('/absensi/mobile/akun')
            ->post('/absensi/mobile/akun/foto-profil', [
                'photo' => UploadedFile::fake()->image('terlalu-kecil.jpg', 240, 240)->size(100),
            ])
            ->assertRedirect('/absensi/mobile/akun')
            ->assertSessionHasErrors('photo');

        $this->assertNull($this->employee->refresh()->profile_photo_path);
    }

    public function test_a_profile_photo_is_only_served_to_its_owner(): void
    {
        $path = "profile-photos/{$this->employee->id}/wajah.jpg";
        Storage::disk('local')->put($path, 'private-photo');
        $this->employee->forceFill([
            'profile_photo_path' => $path,
            'profile_photo_updated_at' => now(),
        ])->save();

        $otherEmployee = User::factory()->create();
        $otherEmployee->assignRole('pegawai');

        $this->actingAs($otherEmployee)
            ->get('/absensi/mobile/akun/foto-profil')
            ->assertNotFound();
    }

    public function test_account_routes_require_the_mobile_account_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::MOBILE_ACCESS);

        $this->actingAs($user)->get('/absensi/mobile/akun')->assertForbidden();
        $this->actingAs($user)->post('/absensi/mobile/akun/foto-profil')->assertForbidden();
        $this->actingAs($user)->get('/absensi/mobile/akun/foto-profil')->assertForbidden();
    }
}
