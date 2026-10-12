<?php

namespace App\Support;

use App\Models\ApprovalLine;
use App\Models\ApprovalLineStep;
use App\Models\CutiApproval;
use App\Models\User;

final class CutiAccess
{
    public static function allows(User $user): bool
    {
        return $user->can(AccessPermission::ADMIN_ACCESS)
            || ApprovalLineStep::query()->where('user_id', $user->id)
                ->whereHas('approvalLine', fn ($query) => $query->where('type', ApprovalLine::TYPE_CUTI))->exists()
            || CutiApproval::query()->where('user_id', $user->id)->exists();
    }
}
