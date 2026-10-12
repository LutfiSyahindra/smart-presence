<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalLine extends Model
{
    public const TYPE_CUTI = 'cuti';

    public const TYPE_LEMBUR = 'lembur';

    protected $fillable = ['type'];

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_CUTI, self::TYPE_LEMBUR];
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
