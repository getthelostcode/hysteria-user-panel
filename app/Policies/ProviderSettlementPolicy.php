<?php

namespace App\Policies;

use App\Models\ProviderSettlement;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 结算单策略：可查看、可发起（create），创建后不可改。
 *
 * 发起结算只是「提交申请」，最终必须由 App\Actions\RequestSettlementAction 落库：
 * 它会在事务里汇总明细、校验门槛与余额、写账本、扣钱包。
 * 所以这里的 create 只代表「有发起资格」，不代表可以直接 INSERT。
 */
class ProviderSettlementPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return $this->isProviderUser($user);
    }

    public function view(User|ProviderUser $user, ProviderSettlement $settlement): bool
    {
        return $this->ownsProvider($user, $settlement->provider_id);
    }

    public function create(User|ProviderUser $user): bool
    {
        return $this->isProviderUser($user) && $user->provider?->isActive();
    }

    /** 已创建的结算单不可修改（含状态），状态流转只在平台侧 */
    public function update(User|ProviderUser $user, ProviderSettlement $settlement): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, ProviderSettlement $settlement): bool
    {
        return false;
    }

    public function deleteAny(User|ProviderUser $user): bool
    {
        return false;
    }
}
