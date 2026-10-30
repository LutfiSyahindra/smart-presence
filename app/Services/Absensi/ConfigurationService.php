<?php

namespace App\Services\Absensi;

use App\Models\AbsensiConfiguration;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ConfigurationService
{
    private ?AbsensiConfiguration $configuration = null;

    public function current(): AbsensiConfiguration
    {
        // Defaults also support isolated timing calculations without a database schema.
        return $this->configuration ??= (Schema::hasTable('absensi_configurations')
            ? AbsensiConfiguration::query()->find(1) : null)
            ?? new AbsensiConfiguration(AbsensiConfiguration::DEFAULTS);
    }

    public function update(array $data, int $userId): void
    {
        $configuration = AbsensiConfiguration::query()->findOrFail(1);
        $configuration->update([...$data, 'updated_by' => $userId]);
        $this->configuration = $configuration;
    }

    public function assertCanDecide(CarbonInterface $date): void
    {
        if (! $this->current()->approve_past_requests && $date->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages([
                'decision' => 'Konfigurasi tidak mengizinkan persetujuan atau penolakan pengajuan yang tanggalnya sudah lewat.',
            ]);
        }
    }
}
