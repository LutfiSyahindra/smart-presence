<?php

namespace App\Support;

use App\Models\ApprovalLine;
use App\Models\ApprovalLineStep;
use App\Models\LemburApproval;
use App\Models\User;

final class PengajuanAccess
{
    public static function allows(User $user): bool
    {
        return CutiAccess::allows($user)
            || ApprovalLineStep::query()->where('user_id', $user->id)
                ->whereHas('approvalLine', fn ($query) => $query->where('type', ApprovalLine::TYPE_LEMBUR))->exists()
            || LemburApproval::query()->where('user_id', $user->id)->exists();
    }
}
