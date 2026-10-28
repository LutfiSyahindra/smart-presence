<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppRelease extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer', 'min_sdk' => 'integer', 'size_bytes' => 'integer',
            'minimum_version_code' => 'integer', 'required_at' => 'datetime',
            'published_at' => 'datetime', 'withdrawn_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }
}
