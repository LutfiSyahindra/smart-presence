<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayCalendarSync extends Model
{
    protected $fillable = ['year', 'source_url', 'source_references', 'synced_at'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'source_references' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
