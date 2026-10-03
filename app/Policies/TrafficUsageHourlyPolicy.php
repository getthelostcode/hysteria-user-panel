<?php

namespace App\Policies;

use App\Models\ProviderUser;
use App\Models\TrafficUsageHourly;
use App\Models\User;
use App\Policies\Concerns\ResolvesProviderActor;

/**
 * 流量用量策略（双面板）。
 * 用户面板：只读自己的流量数据。
 * 服务商后台：只读自己服务商节点的流量数据（provider_id 匹配）。
 * 流量由服务商节点上报链路写入，两个面板都不得增删改。
 */
class TrafficUsageHourlyPolicy
{
    use ResolvesProviderActor;

    public function viewAny(User|ProviderUser $user): bool
    {
        return true;
    }

    public function view(User|ProviderUser $user, TrafficUsageHourly $usage): bool
    {
        return $this->isProviderUser($user)
            ? $this->ownsProvider($user, $usage->provider_id)
            : $user->id === $usage->user_id;
    }

    public function create(User|ProviderUser $user): bool
    {
        return false;
    }

    public function update(User|ProviderUser $user, TrafficUsageHourly $usage): bool
    {
        return false;
    }

    public function delete(User|ProviderUser $user, TrafficUsageHourly $usage): bool
    {
        return false;
    }
}
