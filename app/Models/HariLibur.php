<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class HariLibur extends Model
{
    public const NASIONAL = 'libur_nasional';

    public const CUTI_BERSAMA = 'cuti_bersama';

    protected $fillable = ['tanggal', 'nama', 'jenis'];

    protected function casts(): array
    {
        return ['tanggal' => 'date', 'synced_at' => 'datetime'];
    }

    public function setTanggalAttribute(string $value): void
    {
        $this->attributes['tanggal'] = Carbon::parse($value)->toDateString();
    }
}
