<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PegawaiCuti extends Model
{
    protected $fillable = ['master_cuti_id', 'pegawai_id', 'tahun', 'jumlah_hari', 'updated_by'];

    protected function casts(): array
    {
        return ['tahun' => 'integer', 'jumlah_hari' => 'integer'];
    }

    public function masterCuti(): BelongsTo
    {
        return $this->belongsTo(MasterCuti::class);
    }

    public function pegawai(): BelongsTo
    {
        return $this->belongsTo(Pegawai::class);
    }
}
