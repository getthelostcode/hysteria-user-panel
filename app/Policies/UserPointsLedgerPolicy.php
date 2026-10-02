<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPointsLedger;

/**
 * 积分流水策略：**只读**，且只能看自己的。
 * 账本不可变 —— 任何人（包括用户自己）都不能创建 / 修改 / 删除分录。
 */
class UserPointsLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserPointsLedger $ledger): bool
    {
        return $user->id === $ledger->user_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserPointsLedger $ledger): bool
    {
        return false;
    }

    public function delete(User $user, UserPointsLedger $ledger): bool
    {
        return false;
    }
}
