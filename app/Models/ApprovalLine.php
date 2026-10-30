<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalLine extends Model
{
    public const TYPE_CUTI = 'cuti';

    public const TYPE_LEMBUR = 'lembur';

    public const TYPE_PERUBAHAN_SHIFT = 'perubahan-shift';

    public const TYPE_PRESENSI = 'presensi';

    protected $fillable = ['type'];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::typeLabels());
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_CUTI => 'Cuti',
            self::TYPE_LEMBUR => 'Lembur',
            self::TYPE_PERUBAHAN_SHIFT => 'Perubahan Shift',
            self::TYPE_PRESENSI => 'Presensi (di luar radius)',
        ];
    }

    public function jabatans(): BelongsToMany
    {
        return $this->belongsToMany(Jabatan::class, 'approval_line_jabatan')->withPivot('type')->orderBy('jabatans.nama');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalLineStep::class)->orderBy('level');
    }
}
