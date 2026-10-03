<?php

namespace App\Policies;

use App\Models\ProviderSettlementTerm;
use App\Models\ProviderUser;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 结算条款策略：只读。
 *
 * 条款（汇率 / 抽成 / 手续费 / 冻结期）是**平台与法务口径**，版本化保存，
 * 服务商可以查看+申请调整，但不能自行修改 —— 否则等于自己改抽成。
 * 申请调整走「服务商资料页的申请动作」，只往 providers.metadata 里落一条请求记录。
 */
class ProviderSettlementTermPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return $this->isProviderUser($user);
    }

    public function view(User|ProviderUser $user, ProviderSettlementTerm $term): bool
    {
        return $this->ownsProvider($user, $term->provider_id);
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, ProviderSettlementTerm $term): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, ProviderSettlementTerm $term): bool
    {
        return false;
    }
}
