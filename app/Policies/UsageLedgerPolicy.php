<?php

namespace App\Policies;

use App\Models\UsageLedger;
use App\Models\User;

/**
 * 计费明细策略：只读自己的账单。
 * 账单由计费服务写入，且不可变（修正走红冲），用户不得增删改。
 */
class UsageLedgerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UsageLedger $ledger): bool
    {
        return $user->id === $ledger->user_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UsageLedger $ledger): bool
    {
        return false;
    }

    public function delete(User $user, UsageLedger $ledger): bool
    {
        return false;
    }
}
