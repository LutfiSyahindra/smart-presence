<?php

namespace App\Services\Absensi\AdminWeb\MasterData\Lembur;

use App\Models\LemburBalanceAdjustment;
use App\Models\Pegawai;
use App\Services\Absensi\AbsensiMobile\LemburService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LemburBalanceService
{
    public const MAX_MINUTES = 525600;

    public function __construct(private readonly LemburService $lembur) {}

    /** Set available balance, leaving approved and reserved usage intact. */
    public function set(array $items, int $actor, string $source, string $note): int
    {
        return DB::transaction(function () use ($items, $actor, $source, $note) {
            $ids = array_column($items, 'pegawai_id');
            $employees = Pegawai::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($employees->count() !== count($ids) || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages([$source === 'excel' ? 'file' : 'pegawai_id' => 'Data pegawai telah berubah atau berulang. Muat ulang halaman.']);
            }
            $balances = $this->lembur->balances($ids);
            foreach ($items as $item) {
                $minutes = $item['minutes'];
                if (! is_int($minutes) || $minutes < 0 || $minutes > self::MAX_MINUTES) {
                    throw ValidationException::withMessages([$source === 'excel' ? 'file' : 'minutes' => 'Saldo harus berupa menit bulat antara 0 dan '.self::MAX_MINUTES.'.']);
                }
                $before = $balances[$item['pegawai_id']]['available_minutes'];
                LemburBalanceAdjustment::query()->create([
                    'pegawai_id' => $item['pegawai_id'], 'before_minutes' => $before, 'after_minutes' => $minutes,
                    'delta_minutes' => $minutes - $before, 'created_by' => $actor, 'source' => $source, 'note' => $note,
                ]);
            }

            return count($items);
        });
    }
}
