<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerubahanShiftApproval extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'approver_name', 'level', 'status', 'note', 'decided_at'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
