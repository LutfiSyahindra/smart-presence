<?php

namespace Tests\Feature\Absensi\AbsensiMobile;

use App\Jobs\SendMobilePushNotification;
use App\Jobs\SendWebPushNotification;
use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\LemburRequest;
use App\Models\Pegawai;
use App\Models\Shift;
use App\Models\UnitKerja;
use App\Models\User;
use App\Services\Absensi\AbsensiMobile\LemburService;
use App\Services\Notifications\LemburNotificationService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class LemburRequestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/absensi/mobile/lembur';

    private User $employee;

    private User $first;

    private User $second;

    private User $stranger;

    private Pegawai $pegawai;

    private Shift $shift;

    private ApprovalLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-22 09:00:00');
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('local');
        Bus::fake([SendWebPushNotification::class, SendMobilePushNotification::class]);
        foreach (['employee', 'first', 'second', 'stranger'] as $name) {
            $this->{$name} = User::factory()->create(['name' => ucfirst($name)]);
            $this->{$name}->assignRole('pegawai');
        }
        $unit = UnitKerja::query()->create(['kode' => 'IGD', 'nama' => 'Instalasi Gawat Darurat', 'aktif' => true]);
        $position = Jabatan::query()->create(['unit_kerja_id' => $unit->id, 'kode' => 'PRW', 'nama' => 'Perawat', 'aktif' => true]);
        $this->shift = Shift::query()->create(['nama' => 'Pagi', 'jam_masuk' => '08:00', 'jam_keluar' => '16:00']);
        $this->pegawai = Pegawai::query()->create(['nama' => 'Nadia Putri', 'unit_kerja_id' => $unit->id, 'jabatan_id' => $position->id, 'shift_id' => $this->shift->id]);
        $this->pegawai->forceFill(['user_id' => $this->employee->id])->save();
        $this->line = ApprovalLine::query()->create(['type' => 'lembur']);
        $this->line->jabatans()->attach($position->id, ['type' => 'lembur']);
        foreach ([$this->first, $this->second] as $index => $approver) {
            $this->line->steps()->create(['user_id' => $approver->id, 'level' => $index + 1]);
        }
        $cuti = ApprovalLine::query()->create(['type' => 'cuti']);
        $cuti->jabatans()->attach($position->id, ['type' => 'cuti']);
        $cuti->steps()->create(['user_id' => $this->stranger->id, 'level' => 1]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'tanggal_lembur' => '2026-10-23', 'shift_id' => $this->shift->id, 'before_minutes' => 90,
            'before_break_minutes' => 15, 'after_minutes' => 120, 'after_break_minutes' => 30,
            'compensation' => 'pay', 'alasan' => 'Menyelesaikan serah terima dan laporan pelayanan.',
        ], $overrides);
    }

    private function submit(array $overrides = []): LemburRequest
    {
        $data = $this->payload($overrides);
        $this->actingAs($this->employee)->from(self::URL.'/ajukan')->post(self::URL, $data)
            ->assertSessionHasNoErrors()->assertRedirect(self::URL.'?month='.substr($data['tanggal_lembur'], 0, 7));

        return LemburRequest::query()->latest('id')->firstOrFail();
    }

    public function test_form_and_submission_use_the_lembur_line_and_server_calculated_duration(): void
    {
        $this->actingAs($this->employee)->get(self::URL.'/ajukan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('absensi/absensi-mobile/LemburCreate')->where('unavailableReason', null)
            ->where('defaultShiftId', $this->shift->id)->has('shifts', 1)->where('employee.name', 'Nadia Putri')
            ->has('approvalPreview', 2)->where('approvalPreview.0.name', 'First')->where('approvalPreview.1.name', 'Second'));
        $lembur = $this->submit(['duration_minutes' => 9999, 'status' => 'approved', 'current_level' => 3, 'user_id' => $this->first->id]);
        $this->assertSame(165, $lembur->duration_minutes);
        $this->assertSame($this->employee->id, $lembur->user_id);
        $this->assertSame('pending', $lembur->status);
        $this->assertSame(1, $lembur->current_level);
        $this->assertSame('06:30', $lembur->before_start_at->format('H:i'));
        $this->assertSame('18:00', $lembur->after_end_at->format('H:i'));
        $this->assertSame([$this->first->id, $this->second->id], $lembur->approvals->pluck('user_id')->all());
        $this->assertSame(['pending', 'waiting'], $lembur->approvals->pluck('status')->all());
        $this->assertSame(1, $this->first->notifications()->count());
        $this->assertSame(0, $this->second->notifications()->count());
        $this->assertSame(0, $this->stranger->notifications()->count());
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('summary.submitted_minutes', 165)
            ->where('summary.approved_minutes', 0)->where('requests.data.0.can_cancel', true));
    }

    public function test_only_current_snapshot_approver_can_decide_in_order_and_final_approval_notifies_owner(): void
    {
        $lembur = $this->submit();
        $url = self::URL.'/'.$lembur->id.'/decision';
        foreach ([$this->employee, $this->second, $this->stranger] as $user) {
            $this->actingAs($user)->post($url, ['decision' => 'approved'])->assertForbidden();
        }
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin)->post($url, ['decision' => 'approved'])->assertForbidden();
        // Editing the live line does not reroute an already submitted request.
        $this->line->steps()->delete();
        $this->line->steps()->create(['user_id' => $this->stranger->id, 'level' => 1]);
        $this->actingAs($this->first)->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page
            ->where('allPeriods', true)->where('approvalCount', 1)->where('requests.data.0.can_decide', true));
        $this->from(self::URL.'?scope=delegation')->post($url, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $lembur->fresh()->status);
        $this->assertSame(2, $lembur->fresh()->current_level);
        $this->assertSame(1, $this->second->notifications()->count());
        $this->post($url, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->second)->post($url, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $lembur->fresh()->status);
        $this->assertNull($lembur->fresh()->current_level);
        $this->assertSame(['approved', 'approved'], $lembur->fresh()->approvals->pluck('status')->all());
        $this->assertSame('approved', $this->employee->notifications()->latest()->first()->data['event']);
        $this->actingAs($this->employee)->get(self::URL)->assertInertia(fn (Assert $page) => $page
            ->where('summary.approved_minutes', 165)->where('requests.data.0.can_cancel', false));
    }

    public function test_rejection_requires_a_note_stops_future_steps_and_allows_resubmission(): void
    {
        $lembur = $this->submit();
        $url = self::URL.'/'.$lembur->id.'/decision';
        $this->actingAs($this->first)->post($url, ['decision' => 'rejected', 'note' => '   '])->assertSessionHasErrors('note');
        $this->post($url, ['decision' => 'invalid'])->assertSessionHasErrors('decision');
        $this->post($url, ['decision' => 'rejected', 'note' => '  Mohon sesuaikan jadwal.  '])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $lembur->fresh()->status);
        $this->assertSame(['rejected', 'skipped'], $lembur->fresh()->approvals->pluck('status')->all());
        $this->assertSame('Mohon sesuaikan jadwal.', $lembur->fresh()->approvals->first()->note);
        $this->actingAs($this->second)->post($url, ['decision' => 'approved'])->assertForbidden();
        $this->submit();
        $this->assertDatabaseCount('lembur_requests', 2);
    }

    public function test_cancellation_is_owner_only_and_preserves_completed_steps(): void
    {
        $lembur = $this->submit();
        $url = self::URL.'/'.$lembur->id;
        $this->actingAs($this->first)->post($url.'/cancel')->assertForbidden();
        $this->post($url.'/decision', ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->employee)->post($url.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $lembur->fresh()->status);
        $this->assertSame(['approved', 'skipped'], $lembur->fresh()->approvals->pluck('status')->all());
        $this->post($url.'/cancel')->assertSessionHasErrors('cancel');
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('summary.submitted_minutes', 0));
        $this->actingAs($this->second)->post($url.'/decision', ['decision' => 'approved'])->assertForbidden();
    }

    public function test_foreign_users_cannot_read_requests_or_private_attachments(): void
    {
        $lembur = $this->submit(['attachments' => [UploadedFile::fake()->createWithContent('catatan.txt', 'Dokumen pendukung')]]);
        $file = $lembur->attachments[0];
        Storage::disk('local')->assertExists($file['path']);
        $this->actingAs($this->stranger)->get(self::URL.'/'.$lembur->id)->assertForbidden();
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/0')->assertForbidden();
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/0?preview=1')->assertForbidden();
        $this->get(self::URL)->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
        foreach ([$this->employee, $this->first, $this->second] as $user) {
            $this->actingAs($user)->get(self::URL.'/'.$lembur->id)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('initialRequest.attachments.0.name', 'catatan.txt')
                ->where('initialRequest.attachments.0.preview_url', self::URL.'/'.$lembur->id.'/lampiran/0?preview=1')
                ->missing('initialRequest.attachments.0.path'));
            $this->get(self::URL.'/'.$lembur->id.'/lampiran/0')->assertOk()->assertDownload('catatan.txt');
            $this->get(self::URL.'/'.$lembur->id.'/lampiran/0?preview=1')->assertOk()
                ->assertHeader('Content-Disposition', 'inline; filename=catatan.txt')
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertStreamedContent('Dokumen pendukung');
        }
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/5')->assertNotFound();
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/5?preview=1')->assertNotFound();
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/-1')->assertNotFound();
        Storage::disk('local')->delete($file['path']);
        $this->get(self::URL.'/'.$lembur->id.'/lampiran/0?preview=1')->assertNotFound();
    }

    public function test_attachment_preview_serves_supported_files_inline_and_keeps_other_formats_downloadable(): void
    {
        $lembur = $this->submit();
        $files = [
            ['name' => 'surat.PDF', 'content' => "%PDF-1.4\n", 'type' => 'application/pdf'],
            ['name' => 'foto.png', 'content' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jD1sAAAAASUVORK5CYII='), 'type' => 'image/png'],
            ['name' => 'rekap.csv', 'content' => "Nama,Jam\nNadia,2", 'type' => 'text/plain; charset=UTF-8'],
            ['name' => 'catatan.txt', 'content' => '<script>alert("lampiran")</script>', 'type' => 'text/plain; charset=UTF-8'],
            ['name' => 'dokumen.docx', 'content' => 'Dokumen Office', 'type' => null],
        ];
        $attachments = [];
        foreach ($files as $index => $file) {
            $path = 'lembur-attachments/'.$this->employee->id.'/'.$file['name'];
            Storage::disk('local')->put($path, $file['content']);
            $attachments[] = ['path' => $path, 'name' => $file['name'], 'size' => strlen($file['content'])];
        }
        $lembur->update(['attachments' => $attachments]);

        foreach ($files as $index => $file) {
            $url = self::URL.'/'.$lembur->id.'/lampiran/'.$index;
            $response = $this->actingAs($this->first)->get($url.'?preview=1');
            if ($file['type']) {
                $response->assertOk()->assertHeader('Content-Type', $file['type'])
                    ->assertHeader('Content-Disposition', 'inline; filename='.$file['name'])
                    ->assertStreamedContent($file['content']);
            } else {
                $response->assertStatus(415);
                $this->get($url)->assertOk()->assertDownload($file['name']);
            }
        }
        $this->get(self::URL.'/'.$lembur->id)->assertInertia(fn (Assert $page) => $page
            ->where('initialRequest.attachments.4.preview_url', null));
    }

    public function test_missing_employee_or_lembur_line_and_invalid_approvers_block_submission(): void
    {
        $this->actingAs($this->stranger)->get(self::URL.'/ajukan')->assertInertia(fn (Assert $page) => $page->where('employee', null));
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $this->line->delete();
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $this->assertDatabaseCount('lembur_requests', 0);
        $this->line = ApprovalLine::query()->create(['type' => 'lembur']);
        $this->line->jabatans()->attach($this->pegawai->jabatan_id, ['type' => 'lembur']);
        $this->line->steps()->create(['user_id' => $this->employee->id, 'level' => 1]);
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $this->line->steps()->update(['user_id' => $this->first->id]);
        $this->first->forceFill(['email_verified_at' => null])->save();
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $this->first->forceFill(['email_verified_at' => now()])->save();
        $this->first->removeRole('pegawai');
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $this->assertDatabaseCount('lembur_requests', 0);
    }

    public function test_inactive_position_or_unit_is_rechecked_on_submit(): void
    {
        $position = $this->pegawai->jabatan;
        $position->update(['aktif' => false]);
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $position->update(['aktif' => true]);
        $position->unitKerja->update(['aktif' => false]);
        $this->post(self::URL, $this->payload())->assertSessionHasErrors('lembur');
        $this->assertDatabaseCount('lembur_requests', 0);
    }

    public function test_invalid_duration_break_date_and_compensation_are_rejected(): void
    {
        $this->actingAs($this->employee);
        foreach ([
            ['before_minutes' => -1], ['after_minutes' => 1.5], ['before_minutes' => 1441],
            ['before_minutes' => 0, 'after_minutes' => 0, 'before_break_minutes' => 0, 'after_break_minutes' => 0],
            ['before_break_minutes' => 90], ['after_break_minutes' => 121], ['before_minutes' => 0, 'before_break_minutes' => 1],
            ['tanggal_lembur' => '2026-02-30'], ['compensation' => 'invalid'], ['alasan' => '   '],
            ['alasan' => str_repeat('a', 201)], ['shift_id' => 999999], ['before_minutes' => 900, 'after_minutes' => 300],
        ] as $overrides) {
            $this->post(self::URL, $this->payload($overrides))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('lembur_requests', 0);
    }

    public function test_night_shift_uses_next_day_and_overlap_checks_cross_date_boundaries(): void
    {
        $night = Shift::query()->create(['nama' => 'Malam', 'jam_masuk' => '22:00', 'jam_keluar' => '06:00']);
        $lembur = $this->submit(['tanggal_lembur' => '2026-10-31', 'shift_id' => $night->id, 'before_minutes' => 0, 'before_break_minutes' => 0, 'after_minutes' => 60, 'after_break_minutes' => 0]);
        $this->assertSame('2026-11-01 06:00', $lembur->after_start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 07:00', $lembur->after_end_at->format('Y-m-d H:i'));
        $this->actingAs($this->employee)->post(self::URL, $this->payload(['tanggal_lembur' => '2026-11-01', 'before_minutes' => 90, 'before_break_minutes' => 0, 'after_minutes' => 0, 'after_break_minutes' => 0]))->assertSessionHasErrors('tanggal_lembur');
        // Exactly adjacent intervals are allowed.
        $this->submit(['tanggal_lembur' => '2026-11-01', 'before_minutes' => 60, 'before_break_minutes' => 0, 'after_minutes' => 0, 'after_break_minutes' => 0]);
        $this->get(self::URL.'?month=2026-10')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('summary.submitted_minutes', 60));
        $this->get(self::URL.'?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1));
    }

    public function test_duplicate_submission_is_rejected_but_other_employees_and_nonoverlapping_segments_are_allowed(): void
    {
        $this->submit();
        $this->actingAs($this->employee)->post(self::URL, $this->payload())->assertSessionHasErrors('tanggal_lembur');
        $this->assertDatabaseCount('lembur_requests', 1);
        $this->actingAs($this->employee)->post(self::URL, $this->payload(['before_minutes' => 0, 'before_break_minutes' => 0]))->assertSessionHasErrors('tanggal_lembur');
        $this->submit(['tanggal_lembur' => '2026-10-24', 'before_minutes' => 60, 'before_break_minutes' => 0, 'after_minutes' => 0, 'after_break_minutes' => 0]);
        $this->submit(['tanggal_lembur' => '2026-10-24', 'before_minutes' => 0, 'before_break_minutes' => 0, 'after_minutes' => 60, 'after_break_minutes' => 0]);
        $this->assertDatabaseCount('lembur_requests', 3);
    }

    public function test_upload_limits_and_content_types_are_validated_before_any_storage(): void
    {
        $this->actingAs($this->employee);
        $this->post(self::URL, $this->payload(['attachments' => array_map(fn ($i) => UploadedFile::fake()->createWithContent("file{$i}.txt", 'Dokumen'), range(1, 6))]))->assertSessionHasErrors('attachments');
        $this->post(self::URL, $this->payload(['attachments' => [UploadedFile::fake()->create('besar.pdf', 10241, 'application/pdf')]]))->assertSessionHasErrors('attachments.0');
        $this->post(self::URL, $this->payload(['attachments' => [UploadedFile::fake()->createWithContent('palsu.jpg', '<?php echo 1;')->mimeType('text/x-php')]]))->assertSessionHasErrors('attachments.0');
        $this->post(self::URL, $this->payload(['attachments' => [UploadedFile::fake()->create('script.php', 10, 'application/pdf')]]))->assertSessionHasErrors('attachments.0');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('lembur_requests', 0);
        $lembur = $this->submit(['attachments' => array_map(fn ($i) => UploadedFile::fake()->createWithContent("file{$i}.txt", 'Dokumen'), range(1, 5))]);
        $this->assertCount(5, $lembur->attachments);
    }

    public function test_failed_transaction_cleans_up_uploaded_files_and_does_not_create_a_request(): void
    {
        $this->mock(LemburNotificationService::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('Simulated failure'));
        try {
            app(LemburService::class)->submit($this->employee, $this->payload(['attachments' => [UploadedFile::fake()->createWithContent('dokumen.txt', 'Dokumen')]]));
            $this->fail('Submission should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('lembur_requests', 0);
        $this->assertDatabaseCount('lembur_approvals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_summaries_ignore_status_filter_and_pagination_while_delegation_spans_all_months(): void
    {
        $rows = collect(range(1, 12))->map(fn ($day) => $this->submit(['tanggal_lembur' => '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT)]));
        $rows[0]->update(['status' => 'approved']);
        $rows[1]->update(['status' => 'rejected']);
        $rows[2]->update(['status' => 'cancelled']);
        $this->actingAs($this->employee)->get(self::URL.'?status=approved')->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)->where('summary.count', 12)->where('summary.submitted_minutes', 1815)->where('summary.approved_minutes', 165));
        $this->get(self::URL.'?page=2')->assertInertia(fn (Assert $page) => $page->has('requests.data', 2)->where('requests.total', 12)->where('summary.submitted_minutes', 1815));
        $this->submit(['tanggal_lembur' => '2026-11-01']);
        $this->actingAs($this->first)->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page
            ->where('allPeriods', true)->where('requests.total', 13)->where('approvalCount', 10));
        $this->actingAs($this->stranger)->get(self::URL.'?scope=delegation')->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    }

    public function test_inbox_counts_and_notification_links_open_the_correct_request(): void
    {
        $lembur = $this->submit();
        $this->actingAs($this->first)->get('/absensi/mobile/notifikasi?tab=approvals')->assertInertia(fn (Assert $page) => $page
            ->where('pendingApprovalCount', 1)->where('cutiPendingApprovalCount', 0)->where('lemburPendingApprovalCount', 1));
        $notice = $this->first->notifications()->firstOrFail();
        $this->get('/absensi/mobile/notifikasi/open/'.$notice->id)->assertRedirect(self::URL.'/'.$lembur->id);
        $this->assertNotNull($notice->fresh()->read_at);
        $this->get('/absensi/mobile/notifikasi/lembur')->assertRedirect(self::URL.'?scope=delegation');
        $this->actingAs($this->stranger)->get('/absensi/mobile/notifikasi/open/'.$notice->id)->assertNotFound();
    }
}
