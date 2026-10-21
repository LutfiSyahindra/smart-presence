<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobilePushDevice extends Model
{
    protected $fillable = ['user_id', 'platform', 'token_hash', 'token'];

    protected $hidden = ['token', 'token_hash'];
}
