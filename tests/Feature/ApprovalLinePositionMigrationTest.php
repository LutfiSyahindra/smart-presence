<?php

namespace Tests\Feature;

use App\Models\ApprovalLine;
use App\Models\Jabatan;
use App\Models\UnitKerja;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

class ApprovalLinePositionMigrationTest extends TestCase
{
    // Schema changes must run outside a transaction, as they do in artisan migrate.
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function () {
            // Wipe the isolated test database without rolling back unrelated migrations.
            $this->artisan('db:wipe')->assertExitCode(0);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_migration_preserves_existing_configuration_and_rollback_splits_shared_lines(): void
    {
        $unit = UnitKerja::query()->create(['kode' => 'SIRS', 'nama' => 'SIRS', 'aktif' => true]);
        $positions = collect(['STF', 'KPL'])->map(fn ($code) => Jabatan::query()->create([
            'unit_kerja_id' => $unit->id, 'kode' => $code, 'nama' => $code, 'aktif' => true,
        ]));
        $approver = User::factory()->create();
        $line = ApprovalLine::query()->create(['type' => ApprovalLine::TYPE_CUTI]);
        $line->jabatans()->sync($positions->mapWithKeys(fn ($position) => [$position->id => ['type' => ApprovalLine::TYPE_CUTI]])->all());
        $line->steps()->create(['user_id' => $approver->id, 'level' => 1]);

        $migration = require database_path('migrations/2026_10_08_000003_allow_multiple_positions_per_approval_line.php');
        $migration->down();
        $this->assertDatabaseCount('approval_lines', 2);
        foreach ($positions as $position) {
            $this->assertDatabaseHas('approval_lines', ['jabatan_id' => $position->id]);
        }
        $this->assertDatabaseCount('approval_line_steps', 2);

        $migration->up();
        $this->assertDatabaseCount('approval_line_jabatan', 2);
        foreach (ApprovalLine::query()->with(['jabatans', 'steps'])->get() as $restored) {
            $this->assertCount(1, $restored->jabatans);
            $this->assertSame([$approver->id], $restored->steps->pluck('user_id')->all());
        }
    }
}
