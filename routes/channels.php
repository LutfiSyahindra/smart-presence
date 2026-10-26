<?php

use App\Models\User;
use App\Support\AccessPermission;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('mobile.data', fn (User $user): bool => $user->hasVerifiedEmail() && $user->can(AccessPermission::MOBILE_ACCESS));
