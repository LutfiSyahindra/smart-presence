<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CutiApproval extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'approver_name', 'level', 'status', 'note', 'decided_at'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
