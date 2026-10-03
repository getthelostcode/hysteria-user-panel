<?php

namespace App\Policies;

use App\Models\ProviderUser;
use App\Models\UsageLedger;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 计费明细策略（双面板）。
 * 用户面板：只读自己的账单。
 * 服务商后台：只读自己服务商的账单（服务商看得到「自己的流量被算成多少钱」）。
 * 账单由计费服务写入且不可变（修正走红冲），两个面板都不得增删改。
 */
class UsageLedgerPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, UsageLedger $ledger): bool
    {
        return $this->isProviderUser($user)
            ? $this->ownsProvider($user, $ledger->provider_id)
            : $user->id === $ledger->user_id;
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, UsageLedger $ledger): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, UsageLedger $ledger): bool
    {
        return false;
    }
}
