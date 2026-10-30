<?php

use App\Models\User;
use App\Support\AccessPermission;
use App\Support\PengajuanAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('mobile.data', fn (User $user): bool => $user->hasVerifiedEmail() && $user->can(AccessPermission::MOBILE_ACCESS));

Broadcast::channel('notifications.{userId}', fn (User $user, string $userId): bool => (string) $user->id === $userId && $user->hasVerifiedEmail()
    && (($user->can(AccessPermission::MOBILE_ACCESS) && $user->can(AccessPermission::MOBILE_NOTIFICATIONS))
        || (PengajuanAccess::allows($user) && $user->can(AccessPermission::NOTIFICATIONS_VIEW))));
