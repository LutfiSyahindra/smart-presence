<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LemburBalanceAdjustment extends Model
{
    protected $fillable = ['pegawai_id', 'delta_minutes', 'before_minutes', 'after_minutes', 'created_by', 'source', 'note'];

    protected function casts(): array
    {
        return ['delta_minutes' => 'integer', 'before_minutes' => 'integer', 'after_minutes' => 'integer'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
