<?php

namespace App\Policies;

use App\Models\ProviderSettlementItem;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 结算明细策略：只读。
 * uk_psi_usage 保证一条计费明细只能被结算一次，服务商侧绝不能写入或删除。
 */
class ProviderSettlementItemPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return $this->isProviderUser($user);
    }

    public function view(User|ProviderUser $user, ProviderSettlementItem $item): bool
    {
        return $this->ownsProvider($user, $item->provider_id);
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, ProviderSettlementItem $item): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, ProviderSettlementItem $item): bool
    {
        return false;
    }
}
