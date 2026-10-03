<?php

namespace App\Policies;

use App\Models\ProviderPointsLedger;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 服务商 Points 账本策略：只读自己的账本。
 *
 * 账本是**不可变**的（修正只能红冲），服务商在后台看不到也不能触发任何写操作；
 * 记账只发生在计费链路与结算 Action 内。
 */
class ProviderPointsLedgerPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return $this->isProviderUser($user);
    }

    public function view(User|ProviderUser $user, ProviderPointsLedger $ledger): bool
    {
        return $this->ownsProvider($user, $ledger->provider_id);
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, ProviderPointsLedger $ledger): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, ProviderPointsLedger $ledger): bool
    {
        return false;
    }
}
