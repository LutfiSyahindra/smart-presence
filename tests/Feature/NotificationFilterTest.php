<?php

namespace Tests\Feature;

use App\Models\CutiRequest;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\PerubahanShiftRequest;
use App\Models\Presensi;
use App\Models\PresensiRequest;
use App\Models\User;
use App\Notifications\PengajuanNotification;
use App\Support\AccessPermission;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private User $owner;

    private const WEB = '/absensi/admin-web/notifikasi';

    private const MOBILE = '/absensi/mobile/notifikasi';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-16 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        $this->owner = User::factory()->create()->assignRole('pegawai');
        $this->approver = User::factory()->create()->assignRole('pegawai');
        $this->approver->givePermissionTo([AccessPermission::CUTI_VIEW, AccessPermission::CUTI_APPROVE, AccessPermission::NOTIFICATIONS_VIEW]);
    }

    private function submission(string $type, array $changes = [], bool $assigned = true): Model
    {
        $data = ['user_id' => $this->owner->id, 'nama_pegawai' => 'Nadia Putri', 'nama_unit' => 'IGD',
            'nama_jabatan' => 'Perawat', 'alasan' => 'Pengujian filter', 'status' => 'pending', 'current_level' => 1];
        $model = match ($type) {
            'cuti' => CutiRequest::class,
            'lembur' => LemburRequest::class,
            'perubahan-shift' => PerubahanShiftRequest::class,
            'presensi' => PresensiRequest::class,
        };
        $extra = match ($type) {
            'cuti' => ['tanggal_mulai' => '2026-10-30', 'tanggal_selesai' => '2026-11-02', 'nama_cuti' => 'Cuti Khusus', 'jumlah_hari' => 4],
            'lembur' => ['tanggal_lembur' => '2026-11-01', 'nama_shift' => 'Pagi', 'shift_start_at' => '2026-11-01 08:00:00',
                'shift_end_at' => '2026-11-01 16:00:00', 'after_start_at' => '2026-11-01 16:00:00',
                'after_end_at' => '2026-11-01 17:00:00', 'duration_minutes' => 60, 'compensation' => 'pay'],
            'perubahan-shift' => ['tanggal_perubahan' => '2026-11-01', 'original_shift' => ['name' => 'Pagi'], 'requested_shift' => ['name' => 'Malam']],
            'presensi' => ['presensi_id' => Presensi::query()->create([
                'pegawai_id' => Pegawai::query()->create(['nama' => 'Pegawai Presensi'])->id, 'tanggal_presensi' => '2026-11-01',
            ])->id, 'tanggal_presensi' => '2026-11-01', 'action' => 'clock-in', 'recorded_at' => '2026-11-01 08:00:00',
                'location' => [], 'photo_path' => 'test.jpg'],
        };
        $item = $model::query()->create([...$data, ...$extra, ...$changes]);
        if ($assigned) {
            $item->approvals()->create(['user_id' => $this->approver->id, 'approver_name' => 'Approver', 'level' => 1, 'status' => 'pending']);
        }

        return $item;
    }

    private function filters(array $changes = []): array
    {
        return [...['date_from' => '2026-11-01', 'date_to' => '2026-11-01', 'unit' => 'IGD', 'jabatan' => 'Perawat', 'employee_name' => 'Nadia'], ...$changes];
    }

    public function test_filters_cover_every_approval_category_and_keep_private_options_scoped(): void
    {
        foreach (['cuti', 'lembur', 'perubahan-shift', 'presensi'] as $type) {
            $match = $this->submission($type);
            $this->submission($type, ['nama_unit' => 'Rawat Inap']);
            $this->submission($type, ['nama_jabatan' => 'Dokter']);
            $this->submission($type, ['nama_pegawai' => 'Dewi']);
            $this->submission($type, ['nama_unit' => 'Unit Rahasia', 'nama_jabatan' => 'Jabatan Rahasia'], false);
            $this->submission($type, match ($type) {
                'cuti' => ['tanggal_mulai' => '2026-10-28', 'tanggal_selesai' => '2026-10-31'],
                'lembur' => ['tanggal_lembur' => '2026-11-02'],
                'perubahan-shift' => ['tanggal_perubahan' => '2026-11-02'],
                'presensi' => ['tanggal_presensi' => '2026-11-02'],
            });
            $mobile = match ($type) {
                'cuti', 'presensi' => self::MOBILE.'/'.$type,
                default => '/absensi/mobile/'.$type,
            };
            foreach ([self::WEB.'/butuh-approval', $mobile] as $url) {
                $filters = [...$this->filters(), 'request_type' => $type, 'scope' => 'delegation'];
                $this->actingAs($this->approver)->get($url.'?'.http_build_query($filters))->assertOk()
                    ->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)
                        ->where('requests.data.0.id', $match->id)->where('filters.date_from', '2026-11-01')
                        ->where('filterOptions.units', fn ($units) => ! $units->contains('Unit Rahasia'))
                        ->where('filterOptions.positions', fn ($positions) => ! $positions->contains('Jabatan Rahasia')));
            }
        }
    }

    public function test_approved_requests_use_the_same_filters(): void
    {
        foreach (['cuti', 'lembur', 'perubahan-shift', 'presensi'] as $type) {
            foreach (['IGD', 'Rawat Inap'] as $unit) {
                $item = $this->submission($type, ['status' => 'approved', 'decided_at' => now(), 'nama_unit' => $unit]);
                $item->approvals()->update(['status' => 'approved']);
            }
        }
        $this->actingAs($this->approver)->get(self::WEB.'/disetujui?'.http_build_query($this->filters()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.total', 4)->where('overview.approved', 8));
    }

    public function test_notification_history_combines_filters_for_old_payloads_and_inclusive_dates(): void
    {
        foreach (['cuti', 'lembur', 'perubahan-shift', 'presensi'] as $type) {
            $match = $this->submission($type);
            $other = $this->submission($type, ['nama_unit' => 'Rawat Inap']);
            foreach ([$match, $other] as $item) {
                $this->approver->notify(new PengajuanNotification(['request_type' => $type, 'request_id' => $item->id,
                    'title' => 'Pengajuan', 'message' => 'Riwayat lama tanpa metadata pegawai', 'event' => 'submitted', 'mobile_url' => '/mobile']));
            }
        }
        $matching = $this->approver->notifications()->where('data->request_id', 1)->get();
        foreach ($matching as $index => $notice) {
            $notice->forceFill(['created_at' => $index % 2 ? '2026-11-16 23:59:59' : '2026-11-16 00:00:00'])->save();
        }
        $this->owner->notify(new PengajuanNotification(['request_type' => 'cuti', 'request_id' => 1, 'mobile_url' => '/mobile']));
        $filters = $this->filters(['date_from' => '2026-11-16', 'date_to' => '2026-11-16']);
        foreach ([self::WEB.'/semua', self::MOBILE] as $url) {
            $this->actingAs($this->approver)->get($url.'?'.http_build_query($filters))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('notifications.total', 4)->where('unreadCount', 8));
            $this->get($url.'?'.http_build_query([...$filters, 'jabatan' => 'Tidak Ada']))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('notifications.total', 0)->where('unreadCount', 8));
        }
    }

    public function test_filters_run_before_pagination_and_are_kept_in_next_page_links(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $this->submission('cuti');
            $this->submission('cuti', ['nama_unit' => 'Rawat Inap']);
        }
        foreach ([self::WEB.'/butuh-approval', self::MOBILE.'/cuti'] as $url) {
            $this->actingAs($this->approver)->get($url.'?'.http_build_query($this->filters()))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('requests.total', 12)->has('requests.data', 10)
                    ->where('requests.next_page_url', fn ($url) => str_contains($url, 'unit=IGD') && str_contains($url, 'date_from=2026-11-01') && str_contains($url, 'employee_name=Nadia')));
        }
    }

    public function test_date_range_validation_and_one_sided_ranges(): void
    {
        $this->submission('cuti');
        $this->actingAs($this->approver);
        foreach ([self::WEB.'/semua', self::WEB.'/butuh-approval', self::WEB.'/disetujui', self::MOBILE,
            self::MOBILE.'/cuti', self::MOBILE.'/presensi', '/absensi/mobile/lembur', '/absensi/mobile/perubahan-shift'] as $url) {
            $this->getJson($url.'?date_from=2026-11-02&date_to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('date_to');
            $this->getJson($url.'?date_from=2026-11-99')->assertUnprocessable()->assertJsonValidationErrors('date_from');
            $this->get($url.'?date_to=2026-11-01')->assertOk()->assertSessionHasNoErrors();
            $this->get($url.'?date_from=2026-11-01')->assertOk()->assertSessionHasNoErrors();
        }
    }
}
