<?php

namespace App\Policies;

use App\Models\ProviderPricingRule;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 定价规则策略（**版本化：只增不改不删**）。
 *
 * 服务商可以：查看 + 新增（改价 = 新增一条 + 截断旧记录，走 UpdatePricingAction）。
 * 服务商不可以：编辑 / 删除任何已存在的规则 —— 否则历史账单的定价快照就失去追溯依据。
 */
class ProviderPricingRulePolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, ProviderPricingRule $rule): bool
    {
        return $this->isProviderUser($user)
            ? $this->ownsProvider($user, $rule->provider_id)
            : false;
    }

    public function create(User|ProviderUser $user): bool
    {
        // 新增必须走 App\Actions\UpdatePricingAction（区间冲突校验 + 截断旧价）
        return $this->isProviderUser($user) && $user->provider?->isActive();
    }

    public function update(User|ProviderUser $user, ProviderPricingRule $rule): bool
    {
        return false;   // 改价只能新增记录
    }

    public function delete(User|ProviderUser $user, ProviderPricingRule $rule): bool
    {
        return false;
    }

    public function deleteAny(User|ProviderUser $user): bool
    {
        return false;
    }
}
