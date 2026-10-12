<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalLineStep extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'level'];

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }

    public function approvalLine(): BelongsTo
    {
        return $this->belongsTo(ApprovalLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
