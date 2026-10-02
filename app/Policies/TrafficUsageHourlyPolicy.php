<?php

namespace App\Policies;

use App\Models\TrafficUsageHourly;
use App\Models\User;

/**
 * 流量用量策略：只读自己的流量数据。
 * 流量由服务商节点上报链路写入，用户不得增删改。
 */
class TrafficUsageHourlyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TrafficUsageHourly $usage): bool
    {
        return $user->id === $usage->user_id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TrafficUsageHourly $usage): bool
    {
        return false;
    }

    public function delete(User $user, TrafficUsageHourly $usage): bool
    {
        return false;
    }
}
