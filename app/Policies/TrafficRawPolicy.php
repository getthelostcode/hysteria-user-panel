<?php

namespace App\Policies;

use App\Models\ProviderUser;
use App\Models\TrafficRaw;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 流量原始明细策略：只读，且只能读自己服务商的明细。
 *
 * 原始明细由服务商节点上报写入，任何面板都不允许增删改（分区表也不支持按 id 更新）。
 */
class TrafficRawPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, TrafficRaw $record): bool
    {
        return $this->ownsProvider($user, $record->provider_id);
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, TrafficRaw $record): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, TrafficRaw $record): bool
    {
        return false;
    }
}
